<?php
/**
 * Characterization tests for PaperToQuiz\Application\ResultEmailService.
 *
 * Locks the behaviour of ResultEmailService::process() across its four
 * process_job branches: success, defer case A (future release), defer case B
 * (hidden with no release), and the retry/backoff ceiling. The plan 014
 * fixture intentionally drives the queue via direct $wpdb inserts and a
 * faked wp_mail through the `pre_wp_mail` filter.
 *
 * Bootstrapped against the real WordPress install (see tests/bootstrap.php).
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
use PaperToQuiz\Privacy\PrivacyManager;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ResultEmailServiceTest extends TestCase {

	private Database $db;
	private Crypto $crypto;
	private AttemptService $attempts;
	private ResultEmailService $emails;

	/** @var int[] */
	private array $attempt_ids = array();
	/** @var int[] */
	private array $revision_ids = array();
	/** @var int[] */
	private array $assessment_ids = array();
	/** @var list<callable> */
	private array $filter_handlers = array();
	/** @var list<callable> */
	private array $query_filter_handlers = array();

	public function setUp(): void {
		parent::setUp();

		$this->db         = new Database();
		$this->crypto     = new Crypto();
		$assets           = new AssetService($this->db, new EncryptedStorage());
		$assessments      = new AssessmentService($this->db, $assets);
		$this->attempts   = new AttemptService($this->db, $assessments, $this->crypto);
		$this->emails     = new ResultEmailService($this->db, $this->attempts);
	}

	public function tearDown(): void {
		$wpdb = $this->db->wpdb();

		// Detach any handlers we registered so they cannot leak into later tests.
		foreach ( $this->filter_handlers as $handler ) {
			remove_filter( 'pre_wp_mail', $handler, 10 );
		}
		$this->filter_handlers = array();
		foreach ( $this->query_filter_handlers as $handler ) {
			remove_filter( 'query', $handler, 10 );
		}
		$this->query_filter_handlers = array();

		// Clear any cron events our attempts may have scheduled.
		foreach ( $this->attempt_ids as $attempt_id ) {
			wp_clear_scheduled_hook( 'paper_to_quiz_process_result_emails', array( $attempt_id ) );
		}

		if ( $this->attempt_ids ) {
			$ids = implode( ',', array_map( 'intval', $this->attempt_ids ) );
			$prefix = $wpdb->prefix . 'paper_to_quiz_';
			// phpcs:disable WordPress.DB -- Direct cleanup of test fixtures by collected IDs.
			$wpdb->query( "DELETE FROM {$prefix}result_email_jobs WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}attempt_subject_scores WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}ranking_entries WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}answers WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}attempts WHERE id IN ({$ids})" );
			// phpcs:enable WordPress.DB
		}

		foreach ( $this->revision_ids as $revision_id ) {
			$wpdb->delete( $wpdb->prefix . 'paper_to_quiz_questions', array( 'revision_id' => $revision_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'paper_to_quiz_revisions', array( 'id' => $revision_id ), array( '%d' ) );
		}
		foreach ( $this->assessment_ids as $assessment_id ) {
			$wpdb->delete( $wpdb->prefix . 'paper_to_quiz_assessments', array( 'id' => $assessment_id ), array( '%d' ) );
		}

		parent::tearDown();
	}

	public function test_success_branch_sends_mail_and_marks_sent(): void {
		$suffix  = 'email-success-' . wp_generate_password( 6, false, false );
		$email   = 'ptq-' . $suffix . '@example.com';
		$context = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'summary',
				'release_utc'       => null,
			),
			$email,
			$suffix
		);

		$captured = null;
		$handler  = static function ( $pre, array $args ) use ( $email, &$captured ) {
			$captured = $args;
			return true; // Short-circuit wp_mail as a successful send.
		};
		add_filter( 'pre_wp_mail', $handler, 10, 2 );
		$this->filter_handlers[] = $handler;

		$this->emails->process();

		$job = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'sent', $job['status'], 'Sent job should flip status to "sent".' );
		$this->assertNotNull( $job['sent_at'], 'A sent job must record sent_at.' );
		$this->assertNotEmpty( $captured, 'wp_mail should have been invoked via the pre_wp_mail filter.' );
		// ResultEmailService passes the recipient as a string; accept either
		// string or array for robustness against upstream changes.
		$to = $captured['to'] ?? null;
		if ( is_array( $to ) ) {
			$this->assertContains( $email, $to, 'The captured mail should be addressed to the participant email.' );
		} else {
			$this->assertIsString( $to, 'wp_mail should have received a "to" argument.' );
			$this->assertStringContainsString( $email, $to, 'The captured mail should be addressed to the participant email.' );
		}
	}

	public function test_defer_case_a_future_release_keeps_pending_and_defers_to_release_plus_30(): void {
		$suffix     = 'email-defer-a-' . wp_generate_password( 6, false, false );
		$email      = 'ptq-' . $suffix . '@example.com';
		$release_ts = time() + HOUR_IN_SECONDS * 2;
		$context    = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'detailed',
				'release_utc'       => gmdate( 'Y-m-d H:i:s', $release_ts ),
			),
			$email,
			$suffix
		);

		$this->emails->process();

		$job = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'pending', $job['status'], 'A deferred job must remain pending (case A).' );
		$expected_next = gmdate( 'Y-m-d H:i:s', $release_ts + 30 );
		$this->assertSame( $expected_next, $job['next_run_at'], 'Deferred case A should set next_run_at = release_at + 30 seconds.' );
	}

	public function test_defer_case_b_hidden_without_release_defers_one_day_with_error(): void {
		$suffix  = 'email-defer-b-' . wp_generate_password( 6, false, false );
		$email   = 'ptq-' . $suffix . '@example.com';
		$context = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'hidden',
				'release_utc'       => null,
			),
			$email,
			$suffix
		);

		$before = time();
		$this->emails->process();
		$after = time();

		$job = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'pending', $job['status'], 'A deferred job must remain pending (case B).' );
		$this->assertNotEmpty( $job['last_error'], 'Deferred case B must populate last_error.' );

		$next_ts = strtotime( (string) $job['next_run_at'] . ' UTC' );
		$this->assertGreaterThanOrEqual( $before + DAY_IN_SECONDS, $next_ts, 'Case B next_run_at should be ~1 day from now.' );
		$this->assertLessThanOrEqual( $after + DAY_IN_SECONDS + 5, $next_ts, 'Case B next_run_at should be ~1 day from now.' );
	}

	public function test_retry_branch_increments_attempts_with_backoff(): void {
		$suffix  = 'email-retry-' . wp_generate_password( 6, false, false );
		$email   = 'ptq-' . $suffix . '@example.com';
		$context = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'summary',
				'release_utc'       => null,
			),
			$email,
			$suffix
		);

		// Force wp_mail to fail.
		$handler = static function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $handler, 10, 2 );
		$this->filter_handlers[] = $handler;

		$before = time();
		$this->emails->process();
		$after = time();

		$job = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'retry', $job['status'], 'A failing send should flip status to "retry".' );
		$this->assertSame( 1, (int) $job['attempt_count'], 'attempt_count should increment to 1 on first failure.' );
		$this->assertNotEmpty( $job['last_error'], 'A failing send must populate last_error.' );

		// 5 * MINUTE * 2**(1-1) = 5 minutes backoff.
		$next_ts = strtotime( (string) $job['next_run_at'] . ' UTC' );
		$this->assertGreaterThanOrEqual( $before + 5 * MINUTE_IN_SECONDS, $next_ts, 'Retry backoff should be ~5 minutes for the first retry.' );
		$this->assertLessThanOrEqual( $after + 5 * MINUTE_IN_SECONDS + 5, $next_ts, 'Retry backoff should be ~5 minutes for the first retry.' );
	}

	public function test_retry_ceiling_flips_to_failed_at_eighth_attempt(): void {
		$suffix  = 'email-ceiling-' . wp_generate_password( 6, false, false );
		$email   = 'ptq-' . $suffix . '@example.com';
		$context = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'summary',
				'release_utc'       => null,
			),
			$email,
			$suffix
		);

		// Pre-wind the attempt_count so the next failure crosses the >=8 ceiling.
		$wpdb = $this->db->wpdb();
		$wpdb->update(
			$this->db->table( 'result_email_jobs' ),
			array( 'attempt_count' => 7 ),
			array( 'id' => $context['job_id'] ),
			array( '%d' ),
			array( '%d' )
		);

		$handler = static function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $handler, 10, 2 );
		$this->filter_handlers[] = $handler;

		$this->emails->process();

		$job = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'failed', $job['status'], 'After the 8th failure the job must flip to "failed".' );
		$this->assertSame( 8, (int) $job['attempt_count'], 'The ceiling failure must record attempt_count = 8.' );
		$this->assertNotEmpty( $job['last_error'], 'A permanently failed job must keep last_error populated.' );
	}

	/**
	 * Claim exclusivity (plan 015). Once a job has been claimed, sent, and had
	 * its claimed_at cleared, a second process() tick must not re-select it and
	 * must not send a duplicate email. This is the serial re-run flavor of the
	 * concurrency race: the first process() completes the full claim -> send ->
	 * sent transition before the second one runs.
	 */
	public function test_claim_is_exclusive_across_two_process_invocations(): void {
		$suffix  = 'email-exclusive-' . wp_generate_password( 6, false, false );
		$email   = 'ptq-' . $suffix . '@example.com';
		$context = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'summary',
				'release_utc'       => null,
			),
			$email,
			$suffix
		);

		$sends = 0;
		$handler = static function () use ( &$sends ) {
			++$sends;
			return true; // Short-circuit wp_mail as a successful send.
		};
		add_filter( 'pre_wp_mail', $handler, 10, 2 );
		$this->filter_handlers[] = $handler;

		// First tick: claims the due job and sends.
		$this->emails->process();

		$after_first = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'sent', $after_first['status'], 'First process() should flip the job to "sent".' );
		$this->assertNull( $after_first['claimed_at'], 'A sent job must clear its claim.' );
		$this->assertSame( 1, $sends, 'wp_mail should fire exactly once on the first tick.' );

		// Second tick: the row is now "sent" so it is not re-claimable.
		$this->emails->process();

		$after_second = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'sent', $after_second['status'], 'Second process() must leave the row at "sent".' );
		$this->assertSame( 1, $sends, 'A claimed-and-sent job must not be sent again by a later process() tick.' );
	}

	/**
	 * Simulate another worker claiming a due row after this worker selects it but
	 * before its conditional UPDATE executes. A worker that affects zero rows
	 * must not re-select or send the competing worker's processing job.
	 */
	public function test_lost_claim_race_does_not_send_duplicate_email(): void {
		$suffix  = 'email-race-' . wp_generate_password( 6, false, false );
		$email   = 'ptq-' . $suffix . '@example.com';
		$context = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'summary',
				'release_utc'       => null,
			),
			$email,
			$suffix
		);

		$sends        = 0;
		$mail_handler = static function () use ( &$sends ) {
			++$sends;
			return true;
		};
		add_filter( 'pre_wp_mail', $mail_handler, 10, 2 );
		$this->filter_handlers[] = $mail_handler;

		$wpdb          = $this->db->wpdb();
		$query_handler = null;
		$query_handler = static function ( string $query ) use ( $wpdb, $context, &$query_handler ): string {
			if ( str_contains( $query, "SET status = 'processing', claimed_at" ) ) {
				remove_filter( 'query', $query_handler, 10 );
				$wpdb->update(
					$wpdb->prefix . 'paper_to_quiz_result_email_jobs',
					array(
						'status'     => 'processing',
						'claimed_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $context['job_id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
			return $query;
		};
		add_filter( 'query', $query_handler, 10 );
		$this->query_filter_handlers[] = $query_handler;

		$this->emails->process();

		$job = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'processing', $job['status'], 'The competing worker must retain its claim.' );
		$this->assertSame( 0, $sends, 'A worker that lost the claim must not send the email.' );
	}

	/**
	 * Stale-claim revival (plan 015). A worker that died mid-send leaves the row
	 * stranded in "processing"; release_stale_claims() must return it to
	 * "pending" with claimed_at cleared so the next tick reclaims it.
	 */
	public function test_release_stale_claims_revives_processing_row(): void {
		$suffix  = 'email-stale-' . wp_generate_password( 6, false, false );
		$email   = 'ptq-' . $suffix . '@example.com';
		$context = $this->seed_sendable_attempt(
			array(
				'result_visibility' => 'summary',
				'release_utc'       => null,
			),
			$email,
			$suffix
		);

		// Simulate a worker that claimed the row and then died mid-send: park it
		// in "processing" with a claimed_at 20 minutes in the past (past the
		// 15-minute TTL). Backdate next_run_at as well so a revived row is
		// immediately eligible for a follow-up claim.
		$wpdb = $this->db->wpdb();
		$wpdb->update(
			$this->db->table( 'result_email_jobs' ),
			array(
				'status'      => 'processing',
				'claimed_at'  => gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ),
				'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
			),
			array( 'id' => $context['job_id'] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->emails->release_stale_claims();

		$revived = $this->fetch_job( $context['job_id'] );
		$this->assertSame( 'pending', $revived['status'], 'A stale "processing" claim must be revived to "pending".' );
		$this->assertNull( $revived['claimed_at'], 'Reviving a stale claim must clear claimed_at.' );
	}

	public function test_privacy_tools_match_member_id_and_remove_ranking_identity(): void {
		$user = get_userdata( 1 );
		$this->assertInstanceOf( \WP_User::class, $user );
		$suffix  = 'privacy-member-' . wp_generate_password( 6, false, false );
		$context = $this->seed_sendable_attempt(
			array( 'result_visibility' => 'summary' ),
			'unused-' . $suffix . '@example.com',
			$suffix
		);

		$wpdb = $this->db->wpdb();
		$wpdb->update(
			$this->db->table( 'attempts' ),
			array(
				'wp_user_id'       => $user->ID,
				'participant_type' => 'member',
				'participant_data' => $this->crypto->encrypt_array( array() ),
				'ranking_eligible' => 1,
			),
			array( 'id' => $context['attempt_id'] )
		);
		$wpdb->insert(
			$this->db->table( 'ranking_entries' ),
			array(
				'revision_id'     => $context['revision_id'],
				'wp_user_id'      => $user->ID,
				'attempt_id'      => $context['attempt_id'],
				'score'           => 10000,
				'duration_seconds'=> 60,
				'submitted_at'    => current_time( 'mysql', true ),
			)
		);

		$privacy = new PrivacyManager( $this->db, $this->crypto, $this->attempts );
		$export  = $privacy->export( (string) $user->user_email );
		$this->assertCount( 1, $export['data'], 'Member attempts must be exported by their WordPress user email even when the participant email field is disabled.' );

		$erasure = $privacy->erase( (string) $user->user_email );
		$this->assertTrue( $erasure['items_removed'] );
		$attempt = $wpdb->get_row(
			$wpdb->prepare( 'SELECT wp_user_id, participant_data, anonymized_at FROM %i WHERE id = %d', $this->db->table( 'attempts' ), $context['attempt_id'] ),
			ARRAY_A
		);
		$this->assertNull( $attempt['wp_user_id'] );
		$this->assertNull( $attempt['participant_data'] );
		$this->assertNotNull( $attempt['anonymized_at'] );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE attempt_id = %d', $this->db->table( 'ranking_entries' ), $context['attempt_id'] ) ),
			'Privacy erasure must remove the ranking row that directly identifies the member.'
		);
	}

	/**
	 * Build a fully sendable attempt (assessment + revision + submitted guest
	 * attempt with a valid participant email) and a pending result_email_jobs
	 * row that is due now. Returns the job_id and the attempt_id.
	 *
	 * @param array<string,mixed> $revision_overrides Revision policy overrides (visibility/release).
	 */
	private function seed_sendable_attempt( array $revision_overrides, string $email, string $suffix ): array {
		$wpdb = $this->db->wpdb();
		$now  = current_time( 'mysql', true );

		// Assessment row so AttemptService::current_policy() can resolve it.
		$wpdb->insert(
			$this->db->table( 'assessments' ),
			array(
				'type'       => 'exam',
				'status'     => 'published',
				'created_by' => 1,
				'updated_by' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$assessment_id = (int) $wpdb->insert_id;
		$this->assessment_ids[] = $assessment_id;

		$revision_row = array(
			'assessment_id'         => $assessment_id,
			'revision_no'           => 1,
			'lifecycle'             => 'published',
			'title'                 => 'PTQ Email ' . $suffix,
			'description'           => '',
			'class_id'              => null,
			'subject_ids_json'      => '[]',
			'access_mode'           => 'guest_allowed',
			'options_json'          => wp_json_encode( array( 'A', 'B', 'C', 'D' ) ),
			'total_points'          => 10000,
			'duration_seconds'      => null,
			'window_start_utc'      => null,
			'window_end_utc'        => null,
			'results_release_at_utc' => $revision_overrides['release_utc'] ?? null,
			'allow_repeat'          => 1,
			'ranking_enabled'       => 0,
			'feedback_timing'       => 'after_submit',
			'result_visibility'     => $revision_overrides['result_visibility'] ?? 'summary',
			'participant_fields_json' => '{}',
			'retention_days'        => 365,
			'source_asset_id'       => null,
			'created_at'            => $now,
			'published_at'          => $now,
		);
		$wpdb->insert( $this->db->table( 'revisions' ), $revision_row );
		$revision_id = (int) $wpdb->insert_id;
		$this->revision_ids[] = $revision_id;

		$wpdb->update(
			$this->db->table( 'assessments' ),
			array(
				'published_revision_id'     => $revision_id,
				'current_draft_revision_id' => null,
			),
			array( 'id' => $assessment_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);

		$participant = array(
			'email'      => $email,
			'first_name' => 'PTQ',
			'last_name'  => 'Email',
		);

		$attempt_row = array(
			'public_id'         => wp_generate_uuid4(),
			'token_hash'        => hash_hmac( 'sha256', 'irrelevant', wp_salt( 'auth' ) ),
			'assessment_id'     => $assessment_id,
			'revision_id'       => $revision_id,
			'wp_user_id'        => null,
			'participant_type'  => 'guest',
			'participant_data'  => $this->crypto->encrypt_array( $participant ),
			'status'            => 'submitted',
			'submission_id'     => wp_generate_uuid4(),
			'integrity_status'  => 'on_time',
			'ranking_eligible'  => 0,
			'finish_requested_at' => $now,
			'started_at'        => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			'deadline_at'       => null,
			'last_activity_at'  => $now,
			'submitted_at'      => $now,
			'duration_seconds'  => 60,
			'correct_count'     => 1,
			'wrong_count'       => 0,
			'blank_count'       => 0,
			'score'             => 10000,
			'percentage'        => 100.0,
		);
		$wpdb->insert( $this->db->table( 'attempts' ), $attempt_row );
		$attempt_id = (int) $wpdb->insert_id;
		$this->attempt_ids[] = $attempt_id;

		// A due result_email_jobs row. next_run_at is 60s in the past so process() picks it up.
		$wpdb->insert(
			$this->db->table( 'result_email_jobs' ),
			array(
				'attempt_id'    => $attempt_id,
				'status'        => 'pending',
				'attempt_count' => 0,
				'next_run_at'   => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		$job_id = (int) $wpdb->insert_id;

		return array(
			'attempt_id' => $attempt_id,
			'job_id'     => $job_id,
			'revision_id'=> $revision_id,
		);
	}

	private function fetch_job( int $job_id ): array {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->db->table( 'result_email_jobs' ) . ' WHERE id = %d', $job_id ),
			ARRAY_A
		);
		$this->assertIsArray( $row, "Job {$job_id} must exist after process()." );
		return $row;
	}
}
