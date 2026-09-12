<?php
/**
 * Transaction and asset-lifecycle tests for draft question deletion.
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

final class QuestionDeleteTest extends TestCase {
	private Database $db;
	private EncryptedStorage $storage;
	private int $assessment_id;
	private int $revision_id;
	private int $subject_id;
	/** @var int[] */
	private array $question_ids = array();
	/** @var int[] */
	private array $asset_ids = array();
	/** @var string[] */
	private array $storage_keys = array();

	public function setUp(): void {
		parent::setUp();
		$this->db      = new Database();
		$this->storage = new EncryptedStorage();
		$now           = current_time('mysql', true);
		$this->db->wpdb()->insert(
			$this->db->table('terms'),
			array(
				'type'       => 'subject',
				'name'       => 'Question delete ' . wp_generate_uuid4(),
				'slug'       => 'question-delete-' . wp_generate_password(8, false, false),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$this->subject_id = (int) $this->db->wpdb()->insert_id;

		$assets  = new AssetService($this->db, $this->storage);
		$service = new AssessmentService($this->db, $assets);
		$created = $service->save(
			array(
				'type'              => 'test',
				'title'             => 'Question delete ' . wp_generate_uuid4(),
				'subject_ids'       => array($this->subject_id),
				'options'           => array('A', 'B', 'C', 'D'),
				'total_points'      => 300,
				'allow_repeat'      => true,
				'feedback_timing'   => 'after_submit',
				'result_visibility' => 'summary',
			),
			null,
			1
		);
		$this->assertIsArray($created);
		$this->assessment_id = (int) $created['assessment']['id'];
		$this->revision_id   = (int) $created['revision']['id'];

		for ($ordinal = 1; $ordinal <= 3; ++$ordinal) {
			$main  = $assets->create_from_string('main-' . $ordinal, 'question_image', 'image/png', 10, 10);
			$thumb = $assets->create_from_string('thumb-' . $ordinal, 'question_thumb', 'image/jpeg', 5, 5);
			$assets->retain($main);
			$assets->retain($thumb);
			$this->track_asset($main);
			$this->track_asset($thumb);
			$this->db->wpdb()->insert(
				$this->db->table('questions'),
				array(
					'revision_id'     => $this->revision_id,
					'client_key'      => wp_generate_uuid4(),
					'ordinal'         => $ordinal,
					'source_page'     => 1,
					'crop_x'          => 0.1,
					'crop_y'          => 0.1,
					'crop_width'      => 0.2,
					'crop_height'     => 0.2,
					'source_rotation' => 0,
					'main_asset_id'   => $main,
					'thumb_asset_id'  => $thumb,
					'subject_id'      => $this->subject_id,
					'correct_option'  => 'A',
					'points'          => 100,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			$this->question_ids[] = (int) $this->db->wpdb()->insert_id;
		}
	}

	public function tearDown(): void {
		$this->db->wpdb()->delete($this->db->table('questions'), array('revision_id' => $this->revision_id), array('%d'));
		$this->db->wpdb()->delete($this->db->table('revisions'), array('assessment_id' => $this->assessment_id), array('%d'));
		$this->db->wpdb()->delete($this->db->table('assessments'), array('id' => $this->assessment_id), array('%d'));
		$this->db->wpdb()->delete($this->db->table('terms'), array('id' => $this->subject_id), array('%d'));
		foreach ($this->asset_ids as $asset_id) {
			$this->db->wpdb()->delete($this->db->table('assets'), array('id' => $asset_id), array('%d'));
		}
		foreach ($this->storage_keys as $storage_key) {
			$this->storage->delete($storage_key);
		}
		parent::tearDown();
	}

	public function test_delete_failure_rolls_back_rows_and_preserves_assets(): void {
		$result = $this->service_failing('question_delete')->delete_question($this->question_ids[1]);

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('paper_to_quiz_question_delete_failed', $result->get_error_code());
		$this->assertSame(array(1, 2, 3), $this->ordinals());
		$this->assertSame(2, $this->asset_ref_count($this->asset_ids[2]));
		$this->assertSame(2, $this->asset_ref_count($this->asset_ids[3]));
	}

	public function test_reorder_failure_restores_deleted_question_and_original_order(): void {
		$reorders = 0;
		$db = new Database(
			$this->db->wpdb(),
			static function (string $operation, callable $write) use (&$reorders): mixed {
				if ('question_reorder_update' === $operation && 2 === ++$reorders) {
					return false;
				}
				return $write();
			}
		);
		$result = (new AssessmentService($db, new AssetService($db, $this->storage)))
			->delete_question($this->question_ids[1]);

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame(array(1, 2, 3), $this->ordinals());
		$this->assertSame(2, $this->asset_ref_count($this->asset_ids[2]));
		$this->assertSame(2, $this->asset_ref_count($this->asset_ids[3]));
	}

	public function test_success_commits_contiguous_order_before_releasing_assets(): void {
		$result = (new AssessmentService($this->db, new AssetService($this->db, $this->storage)))
			->delete_question($this->question_ids[1]);

		$this->assertTrue($result);
		$this->assertSame(array(1, 2), $this->ordinals());
		$this->assertSame(1, $this->asset_ref_count($this->asset_ids[2]));
		$this->assertSame(1, $this->asset_ref_count($this->asset_ids[3]));
		$this->assertInstanceOf(
			\WP_Error::class,
			(new AssessmentService($this->db, new AssetService($this->db, $this->storage)))
				->delete_question($this->question_ids[1])
		);
	}

	private function service_failing(string $operation): AssessmentService {
		$db = new Database(
			$this->db->wpdb(),
			static fn (string $name, callable $write): mixed => $name === $operation ? false : $write()
		);
		return new AssessmentService($db, new AssetService($db, $this->storage));
	}

	/** @return int[] */
	private function ordinals(): array {
		return array_map(
			'intval',
			$this->db->wpdb()->get_col(
				$this->db->wpdb()->prepare(
					'SELECT ordinal FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d ORDER BY ordinal ASC',
					$this->revision_id
				)
			)
		);
	}

	private function track_asset(int $asset_id): void {
		$this->asset_ids[] = $asset_id;
		$this->storage_keys[] = (string) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT storage_key FROM ' . $this->db->table('assets') . ' WHERE id = %d', $asset_id)
		);
	}

	private function asset_ref_count(int $asset_id): int {
		return (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT ref_count FROM ' . $this->db->table('assets') . ' WHERE id = %d', $asset_id)
		);
	}
}
