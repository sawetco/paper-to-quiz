<?php
/**
 * Failure-path tests for AttemptService answer and finalization writes.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Application\ResultEmailService;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Rest\PublicController;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class AttemptWriteTest extends TestCase {

	private Database $base_db;
	private Crypto $crypto;
	private array $fixture = array();
	private int $user_id = 0;
	private bool $failure_seen = false;

	public function setUp(): void {
		parent::setUp();

		$this->base_db = new Database();
		$this->crypto  = new Crypto();
	}

	public function tearDown(): void {
		$wpdb = $this->base_db->wpdb();
		if (! empty($this->fixture['attempt_id'])) {
			$attempt_id = (int) $this->fixture['attempt_id'];
			$wpdb->delete($this->base_db->table('result_email_jobs'), array('attempt_id' => $attempt_id), array('%d'));
			$wpdb->delete($this->base_db->table('attempt_subject_scores'), array('attempt_id' => $attempt_id), array('%d'));
			$wpdb->delete($this->base_db->table('ranking_entries'), array('attempt_id' => $attempt_id), array('%d'));
			$wpdb->delete($this->base_db->table('answers'), array('attempt_id' => $attempt_id), array('%d'));
			$wpdb->delete($this->base_db->table('attempts'), array('id' => $attempt_id), array('%d'));
		}
		if (! empty($this->fixture['revision_id'])) {
			$wpdb->delete($this->base_db->table('questions'), array('revision_id' => (int) $this->fixture['revision_id']), array('%d'));
			$wpdb->delete($this->base_db->table('revisions'), array('id' => (int) $this->fixture['revision_id']), array('%d'));
		}
		if (! empty($this->fixture['assessment_id'])) {
			$wpdb->delete($this->base_db->table('assessments'), array('id' => (int) $this->fixture['assessment_id']), array('%d'));
		}
		wp_set_current_user(0);
		if ($this->user_id) {
			$wpdb->delete($wpdb->usermeta, array('user_id' => $this->user_id), array('%d'));
			$wpdb->delete($wpdb->users, array('ID' => $this->user_id), array('%d'));
		}

		parent::tearDown();
	}

	public function test_answer_update_failure_returns_stable_error_and_preserves_answer(): void {
		$fixture = $this->seed_fixture(false);
		$this->seed_answer('A');
		[$db, $attempts] = $this->services_failing('attempt_answer_update');

		$result = $attempts->answer(
			$fixture['public_id'],
			$fixture['token'],
			$fixture['question_id'],
			'B',
			false,
			wp_generate_uuid4()
		);

		$this->assertTrue($this->failure_seen, 'The answer update failure was not injected.');
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('paper_to_quiz_answer_save_failed', $result->get_error_code());
		$this->assertSame(500, (int) ($result->get_error_data()['status'] ?? 0));
		$row = $db->wpdb()->get_row(
			$db->wpdb()->prepare('SELECT selected_option FROM ' . $db->table('answers') . ' WHERE attempt_id=%d AND question_id=%d', $fixture['attempt_id'], $fixture['question_id']),
			ARRAY_A
		);
		$this->assertSame('A', $row['selected_option'] ?? null);
	}

	public function test_answer_insert_failure_returns_stable_error_without_creating_row(): void {
		$fixture = $this->seed_fixture(false);
		[$db, $attempts] = $this->services_failing('attempt_answer_insert');

		$result = $attempts->answer(
			$fixture['public_id'],
			$fixture['token'],
			$fixture['question_id'],
			'A',
			false,
			wp_generate_uuid4()
		);

		$this->assertTrue($this->failure_seen, 'The answer insert failure was not injected.');
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('paper_to_quiz_answer_save_failed', $result->get_error_code());
		$this->assertSame(500, (int) ($result->get_error_data()['status'] ?? 0));
		$this->assertSame(
			0,
			(int) $db->wpdb()->get_var(
				$db->wpdb()->prepare('SELECT COUNT(*) FROM ' . $db->table('answers') . ' WHERE attempt_id=%d', $fixture['attempt_id'])
			)
		);
	}

	public function test_answer_update_zero_is_accepted_and_question_remains_unique(): void {
		$fixture = $this->seed_fixture(false);
		$this->seed_answer('A');
		$zero_seen = false;
		$db = new Database(
			$this->base_db->wpdb(),
			static function (string $operation, callable $write) use (&$zero_seen): mixed {
				if ('attempt_answer_update' === $operation && ! $zero_seen) {
					$zero_seen = true;
					return 0;
				}
				return $write();
			}
		);
		$assets   = new AssetService($db, new EncryptedStorage());
		$attempts = new AttemptService($db, new AssessmentService($db, $assets), $this->crypto);

		$first = $attempts->answer($fixture['public_id'], $fixture['token'], $fixture['question_id'], 'B', false, wp_generate_uuid4());
		$this->assertTrue($zero_seen);
		$this->assertIsArray($first);
		$this->assertTrue((bool) ($first['saved'] ?? false));

		$second = $attempts->answer($fixture['public_id'], $fixture['token'], $fixture['question_id'], 'C', false, wp_generate_uuid4());
		$this->assertIsArray($second);
		$this->assertTrue((bool) ($second['saved'] ?? false));
		$this->assertSame(
			1,
			(int) $db->wpdb()->get_var(
				$db->wpdb()->prepare('SELECT COUNT(*) FROM ' . $db->table('answers') . ' WHERE attempt_id=%d AND question_id=%d', $fixture['attempt_id'], $fixture['question_id'])
			)
		);
		$this->assertSame(
			'C',
			(string) $db->wpdb()->get_var(
				$db->wpdb()->prepare('SELECT selected_option FROM ' . $db->table('answers') . ' WHERE attempt_id=%d AND question_id=%d', $fixture['attempt_id'], $fixture['question_id'])
			)
		);
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public static function finalize_write_failures(): array {
		return array(
			array('finalize_answer_delete'),
			array('finalize_answer_insert'),
			array('finalize_answer_grade_update'),
			array('finalize_attempt_close'),
			array('finalize_subject_score_delete'),
			array('finalize_subject_score_insert'),
			array('finalize_ranking_insert'),
		);
	}

	/**
	 * @dataProvider finalize_write_failures
	 */
	public function test_finalize_write_failure_rolls_back_and_skips_completion(string $failed_operation): void {
		$fixture = $this->seed_fixture(true);
		$this->seed_answer('A');
		[$db, $attempts] = $this->services_failing($failed_operation);
		$completed = 0;
		$completion_hook = static function (int $attempt_id) use (&$completed): void {
			++$completed;
		};
		$email_service = new ResultEmailService($db, $attempts);
		add_action('paper_to_quiz_attempt_completed', $completion_hook, 10, 1);
		add_action('paper_to_quiz_attempt_completed', array($email_service, 'enqueue'), 20, 1);

		try {
			$result = $attempts->submit(
				$fixture['public_id'],
				$fixture['token'],
				false,
				wp_generate_uuid4(),
				array(array('question_id' => $fixture['question_id'], 'option' => 'B'))
			);

			$this->assertTrue($this->failure_seen, "The {$failed_operation} failure was not injected.");
			$this->assertInstanceOf(\WP_Error::class, $result);
			$this->assertSame('paper_to_quiz_attempt_finalize_failed', $result->get_error_code());
			$this->assertSame(500, (int) ($result->get_error_data()['status'] ?? 0));
			$this->assertStringContainsString('Support code:', $result->get_error_message());
			$this->assertSame(0, $completed, 'A rolled-back finalization must not fire the completion hook.');
		} finally {
			remove_action('paper_to_quiz_attempt_completed', $completion_hook, 10);
			remove_action('paper_to_quiz_attempt_completed', array($email_service, 'enqueue'), 20);
			wp_clear_scheduled_hook('paper_to_quiz_process_result_emails', array($fixture['attempt_id']));
		}

		$attempt_row = $db->wpdb()->get_row(
			$db->wpdb()->prepare('SELECT status,submission_id FROM ' . $db->table('attempts') . ' WHERE id=%d', $fixture['attempt_id']),
			ARRAY_A
		);
		$this->assertSame('in_progress', $attempt_row['status'] ?? null);
		$this->assertEmpty($attempt_row['submission_id'] ?? null);
		$answer_row = $db->wpdb()->get_row(
			$db->wpdb()->prepare('SELECT selected_option FROM ' . $db->table('answers') . ' WHERE attempt_id=%d AND question_id=%d', $fixture['attempt_id'], $fixture['question_id']),
			ARRAY_A
		);
		$this->assertSame('A', $answer_row['selected_option'] ?? null);
		foreach (array('attempt_subject_scores', 'ranking_entries', 'result_email_jobs') as $table) {
			$this->assertSame(
				0,
				(int) $db->wpdb()->get_var(
					$db->wpdb()->prepare('SELECT COUNT(*) FROM ' . $db->table($table) . ' WHERE attempt_id=%d', $fixture['attempt_id'])
				),
				"A failed {$failed_operation} must leave {$table} empty."
			);
		}
	}

	public function test_deferred_submission_survives_a_failed_worker_and_can_be_retried(): void {
		$fixture = $this->seed_fixture(true);
		$this->seed_answer('A');
		[$db, $failing] = $this->services_failing('finalize_attempt_close');
		$wpdb = $db->wpdb();
		$table = $db->table('attempts');
		$read = static fn (): array => $wpdb->get_row(
			$wpdb->prepare('SELECT * FROM %i WHERE id=%d', $table, $fixture['attempt_id']),
			ARRAY_A
		);
		$receipt = $failing->submit($fixture['public_id'], $fixture['token'], false, wp_generate_uuid4(), array(
			array('question_id' => $fixture['question_id'], 'option' => 'B'),
		), true);
		$this->assertIsArray($receipt);
		$this->assertSame('processing', $receipt['status']);
		$saved = $read();
		$failing->process_submissions();
		$this->assertTrue($this->failure_seen);
		$failed = $read();
		$this->assertSame($saved['pending_submission'], $failed['pending_submission']);
		$this->assertSame('in_progress', $failed['status']);
		$this->assertSame(1, (int) $failed['submission_failures']);
		$this->assertGreaterThan($saved['submission_retry_at'], $failed['submission_retry_at']);
		$this->assertSame('A', $wpdb->get_var($wpdb->prepare('SELECT selected_option FROM %i WHERE attempt_id=%d', $db->table('answers'), $fixture['attempt_id'])));

		$this->assertSame(1, $wpdb->update($table, array('submission_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)), array('id' => $fixture['attempt_id'])));
		[, $healthy] = $this->services_failing('unused_operation');
		$healthy->process_submissions();
		$final = $read();
		$this->assertSame('submitted', $final['status']);
		$this->assertSame($saved['finish_requested_at'], $final['submitted_at']);
		$this->assertNull($final['pending_submission']);
		$this->assertSame('B', $wpdb->get_var($wpdb->prepare('SELECT selected_option FROM %i WHERE attempt_id=%d', $db->table('answers'), $fixture['attempt_id'])));
	}

	public function test_incomplete_revision_preserves_the_durable_snapshot_for_retry(): void {
		$fixture = $this->seed_fixture(false);
		[$db, $attempts] = $this->services_failing('unused_operation');
		$wpdb = $db->wpdb();
		$this->assertSame(1, $wpdb->delete($db->table('questions'), array('id' => $fixture['question_id'])));
		$receipt = $attempts->submit($fixture['public_id'], $fixture['token'], false, wp_generate_uuid4(), array(), true);
		$this->assertIsArray($receipt);
		$this->assertSame('processing', $receipt['status']);

		$attempts->process_submissions();

		$row = $wpdb->get_row($wpdb->prepare('SELECT status, pending_submission, submission_failures FROM %i WHERE id=%d', $db->table('attempts'), $fixture['attempt_id']), ARRAY_A);
		$this->assertSame('in_progress', $row['status']);
		$this->assertNotNull($row['pending_submission']);
		$this->assertSame(1, (int) $row['submission_failures']);
	}

	public static function incomplete_grading_inputs(): iterable {
		yield 'missing unanswered question' => array(true, array());
		yield 'missing answer key' => array(false, array('correct_option' => null));
		yield 'gap in question order' => array(false, array('ordinal' => 3));
	}

	/** @dataProvider incomplete_grading_inputs */
	public function test_partial_grading_revision_keeps_answers_and_retries(bool $delete_question, array $changes): void {
		$fixture = $this->seed_fixture(true);
		$this->seed_answer('B');
		[$db, $attempts] = $this->services_failing('unused_operation');
		$wpdb = $db->wpdb();
		$questions = $db->table('questions');
		$attempt_id = $fixture['attempt_id'];
		$question_id = $fixture['question_id'];
		$this->assertSame(1, $wpdb->update($questions, array('points' => 5000), array('id' => $question_id)));
		$second_question = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $questions, $question_id), ARRAY_A);
		$this->assertIsArray($second_question);
		unset($second_question['id']);
		$second_question['ordinal'] = 2;
		$second_question['client_key'] = wp_generate_uuid4();
		$this->assertSame(1, $wpdb->insert($questions, $second_question));
		$second_question['id'] = (int) $wpdb->insert_id;
		$second_id = array('id' => $second_question['id']);
		$read = static fn (): array => $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $db->table('attempts'), $attempt_id), ARRAY_A);

		try {
			$receipt = $attempts->submit($fixture['public_id'], $fixture['token'], false, wp_generate_uuid4(), array(
				array('question_id' => $question_id, 'option' => 'A'),
			), true);
			$this->assertIsArray($receipt);
			$this->assertSame('processing', $receipt['status']);
			$saved = $read();
			if ($delete_question) {
				$this->assertSame(1, $wpdb->delete($questions, $second_id));
			} else {
				$this->assertSame(1, $wpdb->update($questions, $changes, $second_id));
			}
			$attempts->process_submissions();

			$pending = $read();
			$this->assertSame('in_progress', $pending['status']);
			$this->assertSame($saved['pending_submission'], $pending['pending_submission']);
			$this->assertSame(1, (int) $pending['submission_failures']);
			$this->assertSame('B', $wpdb->get_var($wpdb->prepare('SELECT selected_option FROM %i WHERE attempt_id=%d', $db->table('answers'), $attempt_id)));
			foreach (array('attempt_subject_scores', 'ranking_entries', 'result_email_jobs') as $table) {
				$this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE attempt_id=%d', $db->table($table), $attempt_id)));
			}

			$this->assertNotFalse($wpdb->delete($questions, $second_id));
			$this->assertSame(1, $wpdb->insert($questions, $second_question));
			$this->assertSame(1, $wpdb->update($db->table('attempts'), array('submission_retry_at' => gmdate('Y-m-d H:i:s', time() - 1)), array('id' => $attempt_id)));
			[, $healthy] = $this->services_failing('unused_operation');
			$healthy->process_submissions();
			$final = $read();
			$this->assertSame('submitted', $final['status']);
			$this->assertNull($final['pending_submission']);
			$this->assertSame($saved['finish_requested_at'], $final['submitted_at']);
			$this->assertSame(5000, (int) $final['score']);
			$this->assertSame(1, (int) $final['blank_count']);
		} finally {
			wp_clear_scheduled_hook('paper_to_quiz_process_result_emails', array($attempt_id));
		}
	}

	public function test_synchronous_grading_uses_the_request_receipt_time(): void {
		$fixture = $this->seed_fixture(false);
		$answer_write_started = null;
		$db = new Database($this->base_db->wpdb(), static function (string $operation, callable $write) use (&$answer_write_started): mixed {
			if ($operation === 'finalize_answer_insert') {
				$answer_write_started = time();
				usleep(1100000);
			}
			return $write();
		});
		$attempts = new AttemptService($db, new AssessmentService($db, new AssetService($db, new EncryptedStorage())), $this->crypto);
		try {
			$result = $attempts->submit($fixture['public_id'], $fixture['token'], false, wp_generate_uuid4(), array(
				array('question_id' => $fixture['question_id'], 'option' => 'A'),
			));
			$this->assertIsArray($result);
			$this->assertSame('submitted', $result['status']);
			$this->assertNotNull($answer_write_started);
			$wpdb = $db->wpdb();
			$row = $wpdb->get_row($wpdb->prepare('SELECT submitted_at, finish_requested_at FROM %i WHERE id=%d', $db->table('attempts'), $fixture['attempt_id']), ARRAY_A);
			$this->assertSame($row['finish_requested_at'], $row['submitted_at']);
			$this->assertLessThanOrEqual($answer_write_started, strtotime($row['submitted_at'] . ' UTC'));
		} finally {
			wp_clear_scheduled_hook('paper_to_quiz_process_result_emails', array($fixture['attempt_id']));
		}
	}

	public function test_personalized_read_responses_forbid_caching(): void {
		$fixture = $this->seed_fixture(true);
		$this->seed_answer('A');
		[, $attempts] = $this->services_failing('unused_operation');
		$controller = new PublicController($attempts);

		$bootstrap = new \WP_REST_Request('GET');
		$bootstrap->set_url_params(array('id' => $fixture['assessment_id']));
		$bootstrap_response = $controller->bootstrap($bootstrap);
		$this->assertInstanceOf(\WP_REST_Response::class, $bootstrap_response);
		$this->assertSame($this->user_id, $bootstrap_response->get_data()['current_user']['id']);
		$this->assertSame('private, no-store, max-age=0', $bootstrap_response->get_headers()['Cache-Control']);

		$state = new \WP_REST_Request('GET');
		$state->set_url_params(array('public_id' => $fixture['public_id']));
		$state->set_header('Authorization', 'Bearer ' . $fixture['token']);
		$state_response = $controller->state($state);
		$this->assertInstanceOf(\WP_REST_Response::class, $state_response);
		$this->assertSame('A', $state_response->get_data()['answers'][0]['selected_option']);
		$this->assertSame('private, no-store, max-age=0', $state_response->get_headers()['Cache-Control']);
	}

	public function test_detailed_review_hides_per_question_grading_when_answer_key_is_disabled(): void {
		$fixture = $this->seed_fixture(false);
		[$db, $attempts] = $this->services_failing('unused_operation');
		$wpdb = $db->wpdb();
		$revision = array('id' => $fixture['revision_id']);
		$this->assertSame(1, $wpdb->update($db->table('revisions'), array(
			'result_visibility' => 'detailed',
			'feedback_timing' => 'never',
		), $revision));
		try {
			$result = $attempts->submit($fixture['public_id'], $fixture['token'], false, wp_generate_uuid4(), array(
				array('question_id' => $fixture['question_id'], 'option' => 'A'),
			));
			$this->assertIsArray($result);
			$this->assertSame(10000, $result['score']);
			$this->assertFalse($result['answer_key_visible']);
			$this->assertSame('A', $result['answers'][0]['selected_option']);
			foreach (array('correct_option', 'is_correct', 'awarded_points') as $field) {
				$this->assertNull($result['answers'][0][$field]);
			}

			$this->assertSame(1, $wpdb->update($db->table('revisions'), array('feedback_timing' => 'after_submit'), $revision));
			$revealed = $attempts->result($fixture['public_id'], $fixture['token']);
			$this->assertIsArray($revealed);
			$this->assertTrue($revealed['answer_key_visible']);
			$this->assertSame('A', $revealed['answers'][0]['correct_option']);
			$this->assertSame(10000, (int) $revealed['answers'][0]['awarded_points']);
		} finally {
			wp_clear_scheduled_hook('paper_to_quiz_process_result_emails', array($fixture['attempt_id']));
		}
	}

	/**
	 * @return array{0:Database,1:AttemptService}
	 */
	private function services_failing(string $failed_operation): array {
		$this->failure_seen = false;
		$db = new Database(
			$this->base_db->wpdb(),
			function (string $operation, callable $write) use ($failed_operation): mixed {
				if ($operation === $failed_operation) {
					$this->failure_seen = true;
					return false;
				}
				return $write();
			}
		);
		$assets   = new AssetService($db, new EncryptedStorage());
		$attempts = new AttemptService($db, new AssessmentService($db, $assets), $this->crypto);

		return array($db, $attempts);
	}

	/**
	 * @return array{assessment_id:int,revision_id:int,question_id:int,attempt_id:int,public_id:string,token:string}
	 */
	private function seed_fixture(bool $ranked): array {
		$wpdb = $this->base_db->wpdb();
		$now  = current_time('mysql', true);
		if ($ranked) {
			$user = wp_insert_user(
				array(
					'user_login' => 'ptq_write_' . strtolower(wp_generate_password(8, false, false)),
					'user_pass'  => wp_generate_password(24),
					'role'       => 'subscriber',
				)
			);
			$this->assertFalse(is_wp_error($user), 'The ranked attempt fixture user could not be created.');
			$this->user_id = (int) $user;
			wp_set_current_user($this->user_id);
		} else {
			wp_set_current_user(0);
		}

		$this->assertSame(1, $wpdb->insert(
			$this->base_db->table('assessments'),
			array(
				'type'       => 'exam',
				'status'     => 'published',
				'created_by' => 1,
				'updated_by' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		));
		$assessment_id = (int) $wpdb->insert_id;
		$this->assertSame(1, $wpdb->insert(
			$this->base_db->table('revisions'),
			array(
				'assessment_id'           => $assessment_id,
				'revision_no'             => 1,
				'lifecycle'               => 'published',
				'title'                   => 'PTQ write attempt ' . wp_generate_password(8, false, false),
				'description'             => '',
				'class_id'                => null,
				'subject_ids_json'        => '[]',
				'access_mode'             => $ranked ? 'login_required' : 'guest_allowed',
				'options_json'            => wp_json_encode(array('A', 'B', 'C', 'D')),
				'total_points'            => 10000,
				'duration_seconds'        => null,
				'window_start_utc'        => null,
				'window_end_utc'          => null,
				'results_release_at_utc'  => null,
				'allow_repeat'            => $ranked ? 0 : 1,
				'ranking_enabled'         => $ranked ? 1 : 0,
				'feedback_timing'         => 'after_submit',
				'result_visibility'       => 'summary',
				'participant_fields_json' => '{"email":{"required":false}}',
				'retention_days'          => 365,
				'source_asset_id'         => null,
				'created_at'              => $now,
				'published_at'            => $now,
			)
		));
		$revision_id = (int) $wpdb->insert_id;
		$this->assertSame(1, $wpdb->insert(
			$this->base_db->table('questions'),
			array(
				'revision_id'     => $revision_id,
				'client_key'      => wp_generate_uuid4(),
				'ordinal'         => 1,
				'source_page'     => 1,
				'crop_x'          => '0.00000000',
				'crop_y'          => '0.00000000',
				'crop_width'      => '1.00000000',
				'crop_height'     => '1.00000000',
				'source_rotation' => 0,
				'main_asset_id'   => null,
				'thumb_asset_id'  => null,
				'subject_id'      => 0,
				'correct_option'  => 'A',
				'points'          => 10000,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		));
		$question_id = (int) $wpdb->insert_id;
		$this->assertSame(1, $wpdb->update(
			$this->base_db->table('assessments'),
			array('published_revision_id' => $revision_id),
			array('id' => $assessment_id),
			array('%d'),
			array('%d')
		));

		$token     = 'ptq-write-token-' . wp_generate_password(16, false, false);
		$public_id = wp_generate_uuid4();
		$this->assertSame(1, $wpdb->insert(
			$this->base_db->table('attempts'),
			array(
				'public_id'           => $public_id,
				'token_hash'          => hash_hmac('sha256', $token, wp_salt('auth')),
				'assessment_id'       => $assessment_id,
				'revision_id'         => $revision_id,
				'wp_user_id'          => $ranked ? $this->user_id : null,
				'participant_type'    => $ranked ? 'member' : 'guest',
				'participant_data'    => $this->crypto->encrypt_array(array('email' => 'ptq-write-' . strtolower(wp_generate_password(8, false, false)) . '@example.com')),
				'status'              => 'in_progress',
				'submission_id'       => null,
				'integrity_status'    => 'pending',
				'ranking_eligible'    => 0,
				'finish_requested_at' => null,
				'started_at'          => gmdate('Y-m-d H:i:s', time() - 60),
				'deadline_at'         => null,
				'last_activity_at'    => $now,
				'submitted_at'        => null,
				'duration_seconds'    => null,
				'correct_count'       => 0,
				'wrong_count'         => 0,
				'blank_count'         => 0,
				'score'               => 0,
				'percentage'          => 0,
			)
		));
		$attempt_id = (int) $wpdb->insert_id;
		$this->fixture = array(
			'assessment_id' => $assessment_id,
			'revision_id'   => $revision_id,
			'question_id'   => $question_id,
			'attempt_id'    => $attempt_id,
			'public_id'     => $public_id,
			'token'         => $token,
		);

		return $this->fixture;
	}

	private function seed_answer(string $option): void {
		$now = current_time('mysql', true);
		$this->assertSame(1, $this->base_db->wpdb()->insert(
			$this->base_db->table('answers'),
			array(
				'attempt_id'      => $this->fixture['attempt_id'],
				'question_id'     => $this->fixture['question_id'],
				'selected_option' => $option,
				'is_flagged'      => 0,
				'is_correct'      => null,
				'awarded_points'  => 0,
				'mutation_id'     => wp_generate_uuid4(),
				'answered_at'     => $now,
			)
		));
	}
}
