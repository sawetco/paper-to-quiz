<?php
/**
 * Characterization tests for serialized member attempt starts.
 *
 * Non-repeatable member starts serialize on the owning assessment row. These
 * tests cover the state re-check, existing-attempt responses, repeatable and
 * guest compatibility, rotation write failures, and a two-process race.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class NonRepeatAttemptTest extends TestCase {

	private Database $db;

	/** @var int[] */
	private array $assessment_ids = array();

	/** @var int[] */
	private array $revision_ids = array();

	/** @var int[] */
	private array $attempt_ids = array();

	/** @var int[] */
	private array $user_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->db = new Database();
	}

	public function tearDown(): void {
		$wpdb = $this->db->wpdb();

		wp_set_current_user(0);
		$attempt_ids = $this->attempt_ids;
		foreach ($this->assessment_ids as $assessment_id) {
			$attempt_ids = array_merge(
				$attempt_ids,
				array_map(
					'intval',
					$wpdb->get_col(
						$wpdb->prepare(
							'SELECT id FROM ' . $this->db->table('attempts') . ' WHERE assessment_id = %d',
							$assessment_id
						)
					) ?: array()
				)
			);
		}
		$attempt_ids = array_values(array_unique(array_filter(array_map('intval', $attempt_ids))));
		if ($attempt_ids) {
			$ids = implode(',', $attempt_ids);
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture IDs are normalized integers collected above.
			$wpdb->query("DELETE FROM {$wpdb->prefix}paper_to_quiz_result_email_jobs WHERE attempt_id IN ({$ids})");
			$wpdb->query("DELETE FROM {$wpdb->prefix}paper_to_quiz_attempt_subject_scores WHERE attempt_id IN ({$ids})");
			$wpdb->query("DELETE FROM {$wpdb->prefix}paper_to_quiz_ranking_entries WHERE attempt_id IN ({$ids})");
			$wpdb->query("DELETE FROM {$wpdb->prefix}paper_to_quiz_answers WHERE attempt_id IN ({$ids})");
			$wpdb->query("DELETE FROM {$wpdb->prefix}paper_to_quiz_attempts WHERE id IN ({$ids})");
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		foreach ($this->revision_ids as $revision_id) {
			$wpdb->delete($this->db->table('questions'), array('revision_id' => $revision_id), array('%d'));
			$wpdb->delete($this->db->table('revisions'), array('id' => $revision_id), array('%d'));
		}
		foreach ($this->assessment_ids as $assessment_id) {
			$wpdb->delete($this->db->table('assessments'), array('id' => $assessment_id), array('%d'));
		}
		foreach ($this->user_ids as $user_id) {
			$wpdb->delete($wpdb->usermeta, array('user_id' => $user_id), array('%d'));
			$wpdb->delete($wpdb->users, array('ID' => $user_id), array('%d'));
		}

		parent::tearDown();
	}

	public function test_non_repeat_member_concurrent_start_creates_exactly_one_attempt(): void {
		if (
			! function_exists('proc_open') ||
			! function_exists('proc_close') ||
			! defined('DB_USER') ||
			! defined('DB_PASSWORD') ||
			! defined('DB_NAME') ||
			! defined('DB_HOST')
		) {
			$this->markTestSkipped('The disposable WordPress CLI does not provide process forking and database credentials.');
		}

		$fixture = $this->seed_assessment('login_required', false);
		$user_id = $this->create_member_user('concurrent');
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paper-to-quiz-non-repeat-' . wp_generate_uuid4();
		$this->assertTrue(wp_mkdir_p($directory), 'The concurrency test directory could not be created.');
		$release = $directory . DIRECTORY_SEPARATOR . 'release';
		$first_result  = $directory . DIRECTORY_SEPARATOR . 'first-result.json';
		$second_result = $directory . DIRECTORY_SEPARATOR . 'second-result.json';
		$child_process = null;
		$child_pipes = array();
		$child_stderr = $directory . DIRECTORY_SEPARATOR . 'second-stderr.log';

		try {
			$interceptor = function (string $operation, callable $write) use (&$child_process, &$child_pipes, $fixture, $user_id, $second_result, $release, $child_stderr): mixed {
				if ('attempt_insert' === $operation) {
					$child_code = sprintf(
						'wp_set_current_user(%d);$db=new \\PaperToQuiz\\Infrastructure\\Database();$assets=new \\PaperToQuiz\\Application\\AssetService($db,new \\PaperToQuiz\\Infrastructure\\EncryptedStorage());$attempts=new \\PaperToQuiz\\Application\\AttemptService($db,new \\PaperToQuiz\\Application\\AssessmentService($db,$assets),new \\PaperToQuiz\\Infrastructure\\Crypto());$result=$attempts->start(%d,array());$payload=is_wp_error($result)?array("error"=>$result->get_error_code(),"status"=>(int)($result->get_error_data()["status"]??0)):array("result"=>array("public_id"=>(string)$result["public_id"],"token"=>(string)$result["token"]));file_put_contents(%s,wp_json_encode($payload));',
						$user_id,
						$fixture['assessment_id'],
						var_export($second_result, true)
					);
					$child_process = proc_open(
						array('/usr/local/bin/wp', '--path=/var/www/html', 'eval', $child_code),
						array(
							0 => array('pipe', 'r'),
							1 => array('file', $child_stderr, 'a'),
							2 => array('file', $child_stderr, 'a'),
						),
						$child_pipes
					);
					if (! is_resource($child_process)) {
						throw new \RuntimeException('The concurrency child could not be created.');
					}
					// Give the child time to pass its initial read and wait on FOR UPDATE.
					usleep(200000);
					file_put_contents($release, 'release');
				}
				return $write();
			};
			$first_service = $this->service($interceptor);
			wp_set_current_user($user_id);
			$first = $first_service->start($fixture['assessment_id'], array());
			$this->assertIsArray($first);
			$this->assertNotFalse(file_put_contents($first_result, wp_json_encode(array('result' => array('public_id' => (string) $first['public_id'])))));
			$this->assertIsResource($child_process);
			foreach ($child_pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}
			$child_status = proc_close($child_process);
			$child_process = null;
			$this->assertSame(0, $child_status, 'The concurrent child failed: ' . (string) @file_get_contents($child_stderr));

			$this->assertFileExists($first_result);
			$this->assertFileExists($second_result);
			$first_payload = array('result' => array('public_id' => (string) $first['public_id']));
			$second_payload = json_decode((string) file_get_contents($second_result), true);
			$this->assertIsArray($second_payload);
			$this->assertArrayHasKey('result', $second_payload, 'The concurrent child failed to resume the attempt: ' . (string) file_get_contents($second_result));
			$this->assertSame($first_payload['result']['public_id'], $second_payload['result']['public_id'], 'Concurrent starts must return the same serialized attempt.');
			$this->assertSame(1, $this->attempt_count($fixture['assessment_id']), 'Concurrent non-repeat starts must persist exactly one attempt.');
		} finally {
			if (is_resource($child_process)) {
				@file_put_contents($release, 'release');
				foreach ($child_pipes as $pipe) {
					if (is_resource($pipe)) {
						fclose($pipe);
					}
				}
				proc_close($child_process);
			}
			@unlink($release);
			@unlink($first_result);
			@unlink($second_result);
			@unlink($child_stderr);
			@rmdir($directory);
		}
	}

	public function test_non_repeat_member_rechecks_assessment_state_before_insert(): void {
		$fixture = $this->seed_assessment('login_required', false);
		$user_id = $this->create_member_user('state-check');
		wp_set_current_user($user_id);
		$service = $this->service();
		$wpdb = $this->db->wpdb();
		$assessment_table = $this->db->table('assessments');

		$mutate_status = static function (bool $allowed, int $assessment_id) use ($wpdb, $assessment_table, $fixture): bool {
			if ($assessment_id === $fixture['assessment_id']) {
				$wpdb->update($assessment_table, array('status' => 'archived'), array('id' => $assessment_id));
			}
			return $allowed;
		};
		add_filter('paper_to_quiz_can_access_assessment', $mutate_status, 10, 3);
		$status_result = $service->start($fixture['assessment_id'], array());
		remove_filter('paper_to_quiz_can_access_assessment', $mutate_status, 10);
		$this->assertInstanceOf(\WP_Error::class, $status_result);
		$this->assertSame('paper_to_quiz_not_available', $status_result->get_error_code());
		$this->assertSame(404, (int) ($status_result->get_error_data()['status'] ?? 0));
		$this->assertSame(0, $this->attempt_count($fixture['assessment_id']));

		$this->assertSame(1, $wpdb->update(
			$this->db->table('assessments'),
			array('status' => 'published', 'published_revision_id' => $fixture['revision_id']),
			array('id' => $fixture['assessment_id'])
		));
		$mutate_revision = static function (bool $allowed, int $assessment_id) use ($wpdb, $assessment_table, $fixture): bool {
			if ($assessment_id === $fixture['assessment_id']) {
				$wpdb->update($assessment_table, array('published_revision_id' => null), array('id' => $assessment_id));
			}
			return $allowed;
		};
		add_filter('paper_to_quiz_can_access_assessment', $mutate_revision, 10, 3);
		$revision_result = $service->start($fixture['assessment_id'], array());
		remove_filter('paper_to_quiz_can_access_assessment', $mutate_revision, 10);
		$this->assertInstanceOf(\WP_Error::class, $revision_result);
		$this->assertSame('paper_to_quiz_revision_changed', $revision_result->get_error_code());
		$this->assertSame(409, (int) ($revision_result->get_error_data()['status'] ?? 0));
		$this->assertSame(0, $this->attempt_count($fixture['assessment_id']));
	}

	public function test_existing_completed_member_start_returns_conflict_without_insert(): void {
		$fixture = $this->seed_assessment('login_required', false);
		$user_id = $this->create_member_user('completed');
		wp_set_current_user($user_id);
		$service = $this->service();

		$started = $service->start($fixture['assessment_id'], array());
		$this->assertIsArray($started);
		$attempt_id = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT id FROM ' . $this->db->table('attempts') . ' WHERE public_id = %s',
				$started['public_id']
			)
		);
		$this->attempt_ids[] = $attempt_id;
		$this->assertSame(1, $this->db->wpdb()->update(
			$this->db->table('attempts'),
			array('status' => 'submitted', 'submission_id' => wp_generate_uuid4()),
			array('id' => $attempt_id)
		));

		$result = $service->start($fixture['assessment_id'], array());
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('paper_to_quiz_repeat_not_allowed', $result->get_error_code());
		$this->assertSame(409, (int) ($result->get_error_data()['status'] ?? 0));
		$this->assertSame(1, $this->attempt_count($fixture['assessment_id']));
	}

	public function test_existing_in_progress_member_rotates_token_without_insert(): void {
		$fixture = $this->seed_assessment('login_required', false);
		$user_id = $this->create_member_user('resume');
		wp_set_current_user($user_id);
		$service = $this->service();

		$first = $service->start($fixture['assessment_id'], array());
		$this->assertIsArray($first);
		$second = $service->start($fixture['assessment_id'], array());
		$this->assertIsArray($second);
		$this->assertSame($first['public_id'], $second['public_id']);
		$this->assertNotSame($first['token'], $second['token']);
		$this->assertSame(1, $this->attempt_count($fixture['assessment_id']));
	}

	public function test_token_rotation_failure_rolls_back_and_returns_sanitized_error(): void {
		$fixture = $this->seed_assessment('login_required', false);
		$user_id = $this->create_member_user('rotation-failure');
		wp_set_current_user($user_id);
		$service = $this->service();
		$started = $service->start($fixture['assessment_id'], array());
		$this->assertIsArray($started);
		$attempt_id = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT id FROM ' . $this->db->table('attempts') . ' WHERE public_id = %s',
				$started['public_id']
			)
		);
		$this->attempt_ids[] = $attempt_id;
		$before = (string) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT token_hash FROM ' . $this->db->table('attempts') . ' WHERE id = %d', $attempt_id)
		);
		$failure_seen = false;
		$failing = $this->service(static function (string $operation, callable $write) use (&$failure_seen): mixed {
			if ('attempt_token_rotate' === $operation) {
				$failure_seen = true;
				return false;
			}
			return $write();
		});

		$result = $failing->start($fixture['assessment_id'], array());
		$this->assertTrue($failure_seen, 'The token rotation failure was not injected.');
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('paper_to_quiz_attempt_failed', $result->get_error_code());
		$this->assertSame(500, (int) ($result->get_error_data()['status'] ?? 0));
		$this->assertSame($before, (string) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT token_hash FROM ' . $this->db->table('attempts') . ' WHERE id = %d', $attempt_id)
		));
		$this->assertSame(1, $this->attempt_count($fixture['assessment_id']));
	}

	public function test_repeatable_members_and_guests_can_start_again(): void {
		$member_fixture = $this->seed_assessment('login_required', true);
		$user_id = $this->create_member_user('repeatable');
		wp_set_current_user($user_id);
		$member_service = $this->service();
		$member_first = $member_service->start($member_fixture['assessment_id'], array());
		$member_second = $member_service->start($member_fixture['assessment_id'], array());
		$this->assertIsArray($member_first);
		$this->assertIsArray($member_second);
		$this->assertNotSame($member_first['public_id'], $member_second['public_id']);
		$this->assertSame(2, $this->attempt_count($member_fixture['assessment_id']));

		$guest_fixture = $this->seed_assessment('guest_allowed', false);
		wp_set_current_user(0);
		$guest_service = $this->service();
		$guest_first = $guest_service->start($guest_fixture['assessment_id'], array());
		$guest_second = $guest_service->start($guest_fixture['assessment_id'], array());
		$this->assertIsArray($guest_first);
		$this->assertIsArray($guest_second);
		$this->assertNotSame($guest_first['public_id'], $guest_second['public_id']);
		$this->assertSame(2, $this->attempt_count($guest_fixture['assessment_id']));
	}

	/**
	 * @return array{assessment_id:int,revision_id:int}
	 */
	private function seed_assessment(string $access_mode, bool $allow_repeat): array {
		$wpdb = $this->db->wpdb();
		$now  = current_time('mysql', true);
		$this->assertSame(1, $wpdb->insert(
			$this->db->table('assessments'),
			array(
				'type'       => 'test',
				'status'     => 'published',
				'created_by' => 1,
				'updated_by' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		));
		$assessment_id = (int) $wpdb->insert_id;
		$this->assessment_ids[] = $assessment_id;
		$this->assertSame(1, $wpdb->insert(
			$this->db->table('revisions'),
			array(
				'assessment_id'           => $assessment_id,
				'revision_no'             => 1,
				'lifecycle'               => 'published',
				'title'                   => 'PTQ non-repeat ' . wp_generate_password(8, false, false),
				'description'             => '',
				'class_id'                => null,
				'subject_ids_json'        => '[]',
				'access_mode'             => $access_mode,
				'options_json'            => wp_json_encode(array('A', 'B', 'C', 'D')),
				'total_points'            => 10000,
				'duration_seconds'        => null,
				'window_start_utc'        => null,
				'window_end_utc'          => null,
				'results_release_at_utc'  => null,
				'allow_repeat'            => $allow_repeat ? 1 : 0,
				'ranking_enabled'         => 0,
				'feedback_timing'         => 'after_submit',
				'result_visibility'       => 'summary',
				'participant_fields_json' => '{"email":{"enabled":true,"required":false}}',
				'retention_days'          => 365,
				'source_asset_id'         => null,
				'created_at'              => $now,
				'published_at'            => $now,
			)
		));
		$revision_id = (int) $wpdb->insert_id;
		$this->revision_ids[] = $revision_id;
		$this->assertSame(1, $wpdb->update(
			$this->db->table('assessments'),
			array('published_revision_id' => $revision_id),
			array('id' => $assessment_id),
			array('%d'),
			array('%d')
		));

		return array('assessment_id' => $assessment_id, 'revision_id' => $revision_id);
	}

	private function create_member_user(string $label): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'ptq_non_repeat_' . sanitize_key($label) . '_' . strtolower(wp_generate_password(8, false, false)),
				'user_pass'  => wp_generate_password(24),
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse(is_wp_error($user_id), 'The member fixture user could not be created.');
		$this->user_ids[] = (int) $user_id;
		return (int) $user_id;
	}

	private function service(?callable $interceptor = null): AttemptService {
		$db = $interceptor ? new Database($this->db->wpdb(), $interceptor) : $this->db;
		$assets = new AssetService($db, new EncryptedStorage());
		return new AttemptService($db, new AssessmentService($db, $assets), new Crypto());
	}

	private function attempt_count(int $assessment_id): int {
		return (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT COUNT(*) FROM ' . $this->db->table('attempts') . ' WHERE assessment_id = %d', $assessment_id)
		);
	}

}
