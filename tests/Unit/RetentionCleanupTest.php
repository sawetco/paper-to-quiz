<?php
/**
 * Regression tests for bounded, cutoff-driven retention cleanup.
 *
 * The fixtures are deliberately disposable: they are inserted directly into
 * the plugin tables and removed by ID during tearDown().
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
use PaperToQuiz\Infrastructure\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RetentionCleanupTest extends TestCase {

	private Database $db;
	private Crypto $crypto;
	private AttemptService $attempts;
	private mixed $settings_before;

	/** @var int[] */
	private array $attempt_ids = array();
	/** @var list<callable> */
	private array $query_filter_handlers = array();

	public function setUp(): void {
		parent::setUp();

		$this->db              = new Database();
		$this->crypto          = new Crypto();
		$assets               = new AssetService($this->db, new EncryptedStorage());
		$assessments          = new AssessmentService($this->db, $assets);
		$this->attempts        = new AttemptService($this->db, $assessments, $this->crypto);
		$this->settings_before = get_option(Settings::OPTION, false);
	}

	public function tearDown(): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->table('attempts');

		foreach ( $this->query_filter_handlers as $handler ) {
			remove_filter( 'query', $handler, 10 );
		}
		$this->query_filter_handlers = array();

		if ( $this->attempt_ids ) {
			$ids = implode( ',', array_map( 'intval', $this->attempt_ids ) );
			// phpcs:disable WordPress.DB -- Direct cleanup of test fixtures by collected IDs.
			$wpdb->query( 'DELETE FROM ' . $this->db->table('ranking_entries') . " WHERE attempt_id IN ({$ids})" );
			$wpdb->query( 'DELETE FROM ' . $this->db->table('answers') . " WHERE attempt_id IN ({$ids})" );
			$wpdb->query( 'DELETE FROM ' . $this->db->table('attempt_subject_scores') . " WHERE attempt_id IN ({$ids})" );
			$wpdb->query( 'DELETE FROM ' . $this->db->table('result_email_jobs') . " WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );
			// phpcs:enable WordPress.DB
		}

		if ( false === $this->settings_before ) {
			delete_option( Settings::OPTION );
		} else {
			update_option( Settings::OPTION, $this->settings_before );
		}

		parent::tearDown();
	}

	public function test_cutoff_includes_old_submissions_and_excludes_newer_or_unsubmitted_rows(): void {
		$this->set_retention_days( 1 );
		$now = time();

		$old_submitted = $this->seed_attempt(
			'submitted',
			gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS - 5 )
		);
		$old_auto = $this->seed_attempt(
			'auto_submitted',
			gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS - 4 )
		);
		$new_submitted = $this->seed_attempt(
			'submitted',
			gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS + 5 )
		);
		$in_progress = $this->seed_attempt( 'in_progress', null );
		$already_anonymized = $this->seed_attempt(
			'submitted',
			gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS - 3 ),
			true
		);

		$this->seed_ranking_entry( $old_submitted );

		$this->assertSame( 2, $this->attempts->anonymize_expired() );

		$old = $this->fetch_attempt( $old_submitted );
		$this->assertNull( $old['wp_user_id'], 'An expired member identity must be removed.' );
		$this->assertNull( $old['participant_data'], 'An expired participant payload must be removed.' );
		$this->assertNotNull( $old['anonymized_at'], 'An expired attempt must record anonymized_at.' );
		$this->assertSame( 0, $this->ranking_count( $old_submitted ), 'An expired attempt must lose its ranking identity.' );

		$auto = $this->fetch_attempt( $old_auto );
		$this->assertNotNull( $auto['anonymized_at'], 'Auto-submitted attempts must use the same retention policy.' );
		$this->assertNotNull( $this->fetch_attempt( $new_submitted )['participant_data'], 'A newer submission must remain identifiable.' );
		$this->assertNull( $this->fetch_attempt( $in_progress )['anonymized_at'], 'An in-progress attempt must not be selected.' );
		$this->assertNotNull( $this->fetch_attempt( $already_anonymized )['anonymized_at'], 'An already anonymized attempt must remain unchanged.' );

		$this->assertSame( 0, $this->attempts->anonymize_expired(), 'A repeated invocation must be idempotent after the due rows drain.' );
	}

	public function test_cutoff_boundary_is_inclusive_and_query_is_ordered_and_limited(): void {
		$this->set_retention_days( 1 );
		$now = time();
		$exact_id = $this->seed_attempt(
			'submitted',
			gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS )
		);
		$newer_id = $this->seed_attempt(
			'submitted',
			gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS )
		);

		$selects = array();
		$adjusted = false;
		$wpdb = $this->db->wpdb();
		$table = $this->db->table( 'attempts' );
		$handler = static function ( string $query ) use ( &$selects, &$adjusted, $wpdb, $table, $exact_id, $newer_id ): string {
			if ( str_contains( $query, 'SELECT id FROM' ) && str_contains( $query, 'anonymized_at IS NULL' ) ) {
				$selects[] = $query;
				if ( ! $adjusted && preg_match( "/submitted_at\\s*<=\\s*'([^']+)'/", $query, $matches ) ) {
					$adjusted = true;
					$wpdb->update( $table, array( 'submitted_at' => $matches[1] ), array( 'id' => $exact_id ), array( '%s' ), array( '%d' ) );
					$boundary = strtotime( $matches[1] . ' UTC' ) + 1;
					$wpdb->update( $table, array( 'submitted_at' => gmdate( 'Y-m-d H:i:s', $boundary ) ), array( 'id' => $newer_id ), array( '%s' ), array( '%d' ) );
				}
			}
			return $query;
		};
		add_filter( 'query', $handler, 10 );
		$this->query_filter_handlers[] = $handler;

		$this->assertSame( 1, $this->attempts->anonymize_expired(), 'The exact cutoff must be eligible.' );
		$this->assertCount( 1, $selects, 'One bounded ID query should run per cleanup invocation.' );
		$this->assertStringContainsString( 'submitted_at <=', $selects[0] );
		$this->assertStringContainsString( 'ORDER BY submitted_at ASC, id ASC', $selects[0] );
		$this->assertStringContainsString( 'LIMIT 100', $selects[0] );
		$this->assertNotNull( $this->fetch_attempt( $exact_id )['anonymized_at'] );
		$this->assertNull( $this->fetch_attempt( $newer_id )['anonymized_at'], 'A submission one second newer than the cutoff must remain.' );
	}

	public function test_cleanup_processes_one_fixed_batch_then_the_remainder(): void {
		$this->set_retention_days( 1 );
		$submitted = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$ids = array();
		for ( $index = 0; $index < 101; $index++ ) {
			$ids[] = $this->seed_attempt( 'submitted', $submitted );
		}

		$this->assertSame( 100, $this->attempts->anonymize_expired(), 'One cleanup invocation must process at most the fixed batch size.' );
		$this->assertSame( 100, $this->anonymized_count( $ids ) );
		$this->assertSame( 1, $this->remaining_identifiable_count( $ids ) );
		$this->assertSame( 1, $this->attempts->anonymize_expired(), 'A second invocation must drain the one-row remainder.' );
		$this->assertSame( 101, $this->anonymized_count( $ids ) );
	}

	public function test_a_failed_row_does_not_prevent_later_rows_from_being_processed(): void {
		$this->set_retention_days( 1 );
		$submitted = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$failed = $this->seed_attempt( 'submitted', $submitted );
		$successful = $this->seed_attempt( 'submitted', $submitted );
		$table = $this->db->table( 'attempts' );

		$handler = null;
		$handler = static function ( string $query ) use ( &$handler, $table, $failed ) {
			if (
				( str_contains( $query, 'UPDATE ' . $table ) || str_contains( $query, 'UPDATE `' . $table . '`' ) )
				&& str_contains( $query, 'participant_data' )
				&& str_contains( $query, '`id` = ' . $failed )
			) {
				remove_filter( 'query', $handler, 10 );
				return false;
			}
			return $query;
		};
		add_filter( 'query', $handler, 10 );
		$this->query_filter_handlers[] = $handler;

		$this->assertSame( 1, $this->attempts->anonymize_expired(), 'A failed row must not abort later rows in the same batch.' );
		$this->assertNull( $this->fetch_attempt( $failed )['anonymized_at'], 'The failed row must remain retryable.' );
		$this->assertNotNull( $this->fetch_attempt( $failed )['participant_data'] );
		$this->assertNotNull( $this->fetch_attempt( $successful )['anonymized_at'] );
	}

	private function set_retention_days( int $days ): void {
		$settings = Settings::get();
		$settings['retention_days'] = $days;
		update_option( Settings::OPTION, $settings );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fetch_attempt( int $attempt_id ): array {
		$row = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT wp_user_id,participant_data,anonymized_at FROM ' . $this->db->table( 'attempts' ) . ' WHERE id = %d',
				$attempt_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		return $row;
	}

	private function seed_attempt( string $status, ?string $submitted_at, bool $anonymized = false ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $this->db->wpdb()->insert(
			$this->db->table( 'attempts' ),
			array(
				'public_id'           => wp_generate_uuid4(),
				'token_hash'          => hash( 'sha256', wp_generate_uuid4() ),
				'assessment_id'       => 1,
				'revision_id'         => 1,
				'wp_user_id'          => 1,
				'participant_type'     => 'member',
				'participant_data'    => $this->crypto->encrypt_array( array( 'email' => 'retention@example.com' ) ),
				'status'              => $status,
				'submission_id'       => wp_generate_uuid4(),
				'integrity_status'    => 'on_time',
				'ranking_eligible'   => 1,
				'started_at'         => $now,
				'last_activity_at'   => $now,
				'submitted_at'       => $submitted_at,
				'anonymized_at'      => $anonymized ? $now : null,
			)
		);
		$this->assertSame( 1, $inserted, 'The retention fixture could not be inserted.' );
		$attempt_id = (int) $this->db->wpdb()->insert_id;
		$this->attempt_ids[] = $attempt_id;
		return $attempt_id;
	}

	private function seed_ranking_entry( int $attempt_id ): void {
		$inserted = $this->db->wpdb()->insert(
			$this->db->table( 'ranking_entries' ),
			array(
				'revision_id'      => 1,
				'wp_user_id'       => 1,
				'attempt_id'       => $attempt_id,
				'score'            => 100,
				'duration_seconds' => 60,
				'submitted_at'     => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 1, $inserted, 'The ranking fixture could not be inserted.' );
	}

	private function ranking_count( int $attempt_id ): int {
		return (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'ranking_entries' ) . ' WHERE attempt_id = %d',
				$attempt_id
			)
		);
	}

	/**
	 * @param int[] $attempt_ids
	 */
	private function anonymized_count( array $attempt_ids ): int {
		$ids = implode( ',', array_map( 'intval', $attempt_ids ) );
		return (int) $this->db->wpdb()->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs are collected and normalized as integers above.
			'SELECT COUNT(*) FROM ' . $this->db->table( 'attempts' ) . " WHERE id IN ({$ids}) AND anonymized_at IS NOT NULL"
		);
	}

	/**
	 * @param int[] $attempt_ids
	 */
	private function remaining_identifiable_count( array $attempt_ids ): int {
		$ids = implode( ',', array_map( 'intval', $attempt_ids ) );
		return (int) $this->db->wpdb()->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs are collected and normalized as integers above.
			'SELECT COUNT(*) FROM ' . $this->db->table( 'attempts' ) . " WHERE id IN ({$ids}) AND anonymized_at IS NULL"
		);
	}
}
