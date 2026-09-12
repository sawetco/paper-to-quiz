<?php
/**
 * Cross-connection tests for draft mutation and publishing serialization.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class DraftPublishConcurrencyTest extends TestCase {
	private Database $db;
	private EncryptedStorage $storage;
	private int $assessment_id = 0;
	private int $revision_id = 0;
	private int $question_id = 0;
	private int $subject_id = 0;
	private int $class_id = 0;
	/** @var int[] */
	private array $asset_ids = array();
	/** @var string[] */
	private array $storage_keys = array();

	public function setUp(): void {
		parent::setUp();
		if (! function_exists('proc_open') || ! defined('DB_NAME')) {
			$this->markTestSkipped('The disposable WordPress CLI cannot run cross-process database tests.');
		}
		$this->db      = new Database();
		$this->storage = new EncryptedStorage();
		$engine        = strtoupper((string) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $this->db->table('assessments'))
		));
		if ('INNODB' !== $engine) {
			$this->markTestSkipped('Draft serialization requires transactional InnoDB tables.');
		}
		$this->seed_draft();
	}

	public function tearDown(): void {
		if (isset($this->db)) {
			$this->db->wpdb()->delete($this->db->table('questions'), array('revision_id' => $this->revision_id), array('%d'));
			$this->db->wpdb()->delete($this->db->table('revisions'), array('assessment_id' => $this->assessment_id), array('%d'));
			$this->db->wpdb()->delete($this->db->table('assessments'), array('id' => $this->assessment_id), array('%d'));
			$this->db->wpdb()->delete($this->db->table('terms'), array('id' => $this->subject_id), array('%d'));
			$this->db->wpdb()->delete($this->db->table('terms'), array('id' => $this->class_id), array('%d'));
			foreach ($this->asset_ids as $asset_id) {
				$this->db->wpdb()->delete($this->db->table('assets'), array('id' => $asset_id), array('%d'));
			}
			foreach ($this->storage_keys as $storage_key) {
				$this->storage->delete($storage_key);
			}
		}
		parent::tearDown();
	}

	public function test_publish_first_makes_waiting_delete_immutable(): void {
		$run = $this->coordinated_operation(
			'revision_publish',
			sprintf(
				'$db=new \\PaperToQuiz\\Infrastructure\\Database();$result=(new \\PaperToQuiz\\Application\\AssessmentService($db,new \\PaperToQuiz\\Application\\AssetService($db,new \\PaperToQuiz\\Infrastructure\\EncryptedStorage())))->delete_question(%d);',
				$this->question_id
			),
			fn (AssessmentService $service): array|\WP_Error => $service->publish($this->assessment_id, 1)
		);

		$this->assertIsArray($run['parent']);
		$this->assertSame('paper_to_quiz_immutable', $run['child']['error'] ?? '');
		$this->assertSame(409, (int) ($run['child']['status'] ?? 0));
		$this->assertSame('published', $this->revision_lifecycle());
		$this->assertSame(1, $this->question_count());
		$this->assertSame('A', $this->correct_option());
	}

	public function test_answer_key_first_is_visible_to_waiting_publish(): void {
		$run = $this->coordinated_operation(
			'answer_key_question_update',
			sprintf(
				'$db=new \\PaperToQuiz\\Infrastructure\\Database();$result=(new \\PaperToQuiz\\Application\\AssessmentService($db,new \\PaperToQuiz\\Application\\AssetService($db,new \\PaperToQuiz\\Infrastructure\\EncryptedStorage())))->publish(%d);',
				$this->assessment_id
			),
			fn (AssessmentService $service): array|\WP_Error => $service->update_answer_key(
				$this->revision_id,
				array(array('id' => $this->question_id, 'correct_option' => 'B', 'points' => 10000)),
				true
			)
		);

		$this->assertIsArray($run['parent']);
		$this->assertTrue($run['child']['success'] ?? false, 'The waiting publish did not succeed.');
		$this->assertSame('published', $this->revision_lifecycle());
		$this->assertSame('B', $this->correct_option());
	}

	/**
	 * @param callable(AssessmentService):mixed $parent_operation
	 * @return array{parent:mixed,child:array<string,mixed>}
	 */
	private function coordinated_operation(string $intercept, string $child_operation, callable $parent_operation): array {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ptq-draft-lock-' . wp_generate_uuid4();
		$this->assertTrue(wp_mkdir_p($directory));
		$ready  = $directory . DIRECTORY_SEPARATOR . 'ready';
		$result = $directory . DIRECTORY_SEPARATOR . 'result.json';
		$stderr = $directory . DIRECTORY_SEPARATOR . 'stderr.log';
		$process = null;
		$pipes = array();
		$spawned = false;

		try {
			$db = new Database(
				$this->db->wpdb(),
				function (string $operation, callable $write) use ($intercept, $child_operation, $ready, $result, $stderr, &$process, &$pipes, &$spawned): mixed {
					if ($intercept === $operation && ! $spawned) {
						$spawned = true;
						$code = 'file_put_contents(' . var_export($ready, true) . ',"ready");' . $child_operation
							. '$payload=is_wp_error($result)?array("error"=>$result->get_error_code(),"status"=>(int)($result->get_error_data()["status"]??0)):array("success"=>true);'
							. 'file_put_contents(' . var_export($result, true) . ',wp_json_encode($payload));';
						$process = proc_open(
							array('/usr/local/bin/wp', '--path=/var/www/html', 'eval', $code),
							array(0 => array('pipe', 'r'), 1 => array('file', $stderr, 'a'), 2 => array('file', $stderr, 'a')),
							$pipes
						);
						if (! is_resource($process)) {
							throw new \RuntimeException('The concurrency child could not be created.');
						}
						$deadline = microtime(true) + 10;
						while (! is_file($ready) && microtime(true) < $deadline) {
							usleep(10000);
						}
						if (! is_file($ready)) {
							throw new \RuntimeException('The concurrency child did not reach the lock attempt.');
						}
					}
					return $write();
				}
			);
			$service = new AssessmentService($db, new AssetService($db, $this->storage));
			$parent = $parent_operation($service);
			$this->assertIsResource($process);
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}
			$status = proc_close($process);
			$process = null;
			$this->assertSame(0, $status, 'The concurrency child failed: ' . (string) @file_get_contents($stderr));
			$child = json_decode((string) file_get_contents($result), true);
			$this->assertIsArray($child, 'The child returned no result: ' . (string) @file_get_contents($stderr));
			return array('parent' => $parent, 'child' => $child);
		} finally {
			if (is_resource($process)) {
				foreach ($pipes as $pipe) {
					if (is_resource($pipe)) {
						fclose($pipe);
					}
				}
				proc_terminate($process);
				proc_close($process);
			}
			@unlink($ready);
			@unlink($result);
			@unlink($stderr);
			@rmdir($directory);
		}
	}

	private function seed_draft(): void {
		$now = current_time('mysql', true);
		$this->assertSame(1, $this->db->wpdb()->insert($this->db->table('terms'), array('type' => 'subject', 'name' => 'Draft lock ' . wp_generate_uuid4(), 'slug' => 'draft-lock-' . wp_generate_password(8, false, false), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now)));
		$this->subject_id = (int) $this->db->wpdb()->insert_id;
		$this->assertSame(1, $this->db->wpdb()->insert($this->db->table('terms'), array('type' => 'class', 'name' => 'Draft class ' . wp_generate_uuid4(), 'slug' => 'draft-class-' . wp_generate_password(8, false, false), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now)));
		$this->class_id = (int) $this->db->wpdb()->insert_id;
		$assets  = new AssetService($this->db, $this->storage);
		$service = new AssessmentService($this->db, $assets);
		$created = $service->save(array('type' => 'test', 'title' => 'Draft lock ' . wp_generate_uuid4(), 'class_id' => $this->class_id, 'subject_ids' => array($this->subject_id), 'options' => array('A', 'B', 'C', 'D'), 'total_points' => 10000, 'allow_repeat' => true, 'feedback_timing' => 'after_submit', 'result_visibility' => 'summary'), null, 1);
		$this->assertIsArray($created);
		$this->assessment_id = (int) $created['assessment']['id'];
		$this->revision_id   = (int) $created['revision']['id'];
		$source = $assets->create_from_string('%PDF-1.4 synthetic', 'source_pdf', 'application/pdf');
		$main   = $assets->create_from_string('main', 'question_image', 'image/png', 10, 10);
		$thumb  = $assets->create_from_string('thumb', 'question_thumb', 'image/jpeg', 5, 5);
		foreach (array($source, $main, $thumb) as $asset_id) {
			$this->asset_ids[] = $asset_id;
			$this->storage_keys[] = (string) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT storage_key FROM ' . $this->db->table('assets') . ' WHERE id = %d', $asset_id));
		}
		$this->assertIsArray($service->set_source_asset($this->assessment_id, $source));
		$this->assertSame(1, $this->db->wpdb()->insert($this->db->table('questions'), array('revision_id' => $this->revision_id, 'client_key' => wp_generate_uuid4(), 'ordinal' => 1, 'source_page' => 1, 'crop_x' => 0.1, 'crop_y' => 0.1, 'crop_width' => 0.2, 'crop_height' => 0.2, 'source_rotation' => 0, 'main_asset_id' => $main, 'thumb_asset_id' => $thumb, 'subject_id' => $this->subject_id, 'correct_option' => 'A', 'points' => 10000, 'created_at' => $now, 'updated_at' => $now)));
		$this->question_id = (int) $this->db->wpdb()->insert_id;
	}

	private function revision_lifecycle(): string {
		return (string) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT lifecycle FROM ' . $this->db->table('revisions') . ' WHERE id = %d', $this->revision_id));
	}

	private function question_count(): int {
		return (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT COUNT(*) FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d', $this->revision_id));
	}

	private function correct_option(): string {
		return (string) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT correct_option FROM ' . $this->db->table('questions') . ' WHERE id = %d', $this->question_id));
	}
}
