<?php
/**
 * Characterization tests for AttemptService ranking computation.
 *
 * Locks the 3-key tie-break documented in plan 014:
 *   score DESC, then duration_seconds ASC, then submitted_at ASC
 * and the INSERT IGNORE dedup behaviour of the ranking_entries table
 * (UNIQUE KEY revision_user).
 *
 * The ranking() method is private; we exercise it through the public
 * AttemptService::result() path by setting up member attempts whose
 * result_payload() includes the ranking block.
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

final class RankingTest extends TestCase {

	private Database $db;
	private Crypto $crypto;
	private AttemptService $attempts;

	/** @var int[] */
	private array $attempt_ids = array();
	/** @var int[] */
	private array $revision_ids = array();
	/** @var int[] */
	private array $assessment_ids = array();
	/** @var int[] */
	private array $user_ids = array();

	public function setUp(): void {
		parent::setUp();

		$this->db       = new Database();
		$this->crypto   = new Crypto();
		$assets         = new AssetService($this->db, new EncryptedStorage());
		$assessments    = new AssessmentService($this->db, $assets);
		$this->attempts = new AttemptService($this->db, $assessments, $this->crypto);
	}

	public function tearDown(): void {
		$wpdb   = $this->db->wpdb();
		$prefix = $wpdb->prefix . 'ptq_';

		wp_set_current_user( 0 );

		if ( $this->attempt_ids ) {
			$ids = implode( ',', array_map( 'intval', $this->attempt_ids ) );
			// phpcs:disable WordPress.DB -- Direct cleanup of test fixtures by collected IDs.
			$wpdb->query( "DELETE FROM {$prefix}result_email_jobs WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}attempt_subject_scores WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}ranking_entries WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}answers WHERE attempt_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$prefix}attempts WHERE id IN ({$ids})" );
			// phpcs:enable WordPress.DB
		}
		foreach ( $this->revision_ids as $revision_id ) {
			$wpdb->delete( $prefix . 'questions', array( 'revision_id' => $revision_id ), array( '%d' ) );
			$wpdb->delete( $prefix . 'revisions', array( 'id' => $revision_id ), array( '%d' ) );
		}
		foreach ( $this->assessment_ids as $assessment_id ) {
			$wpdb->delete( $prefix . 'assessments', array( 'id' => $assessment_id ), array( '%d' ) );
		}
		foreach ( $this->user_ids as $user_id ) {
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->users, array( 'ID' => $user_id ), array( '%d' ) );
		}

		parent::tearDown();
	}

	public function test_ranking_tie_break_order_matches_score_duration_then_submitted_at(): void {
		$suffix = 'rank-' . wp_generate_password( 6, false, false );
		list( $assessment_id, $revision_id ) = $this->seed_ranked_exam( $suffix );

		/*
		 * Build 4 member attempts whose (score, duration_seconds, submitted_at)
		 * tuples span every tier of the tie-break:
		 *
		 *   U1: score 9000, dur 600, submitted 10:00  -> rank 1 (highest score)
		 *   U2: score 8000, dur 300, submitted 09:00  -> rank 2 (beats U3 on submitted_at)
		 *   U3: score 8000, dur 300, submitted 10:00  -> rank 3 (beats U4 on duration)
		 *   U4: score 8000, dur 500, submitted 08:00  -> rank 4 (longest duration at this score)
		 *
		 * Total = 4. Percentile = ((total - rank + 1) / total) * 100.
		 */
		$specs = array(
			array( 'score' => 9000, 'duration' => 600, 'submitted' => '2026-01-01 10:00:00', 'expected_rank' => 1 ),
			array( 'score' => 8000, 'duration' => 300, 'submitted' => '2026-01-01 09:00:00', 'expected_rank' => 2 ),
			array( 'score' => 8000, 'duration' => 300, 'submitted' => '2026-01-01 10:00:00', 'expected_rank' => 3 ),
			array( 'score' => 8000, 'duration' => 500, 'submitted' => '2026-01-01 08:00:00', 'expected_rank' => 4 ),
		);

		$prepared = array();
		foreach ( $specs as $spec ) {
			$user_id = $this->create_member_user( $suffix );
			$attempt = $this->seed_member_attempt(
				$assessment_id,
				$revision_id,
				$user_id,
				array(
					'score'             => $spec['score'],
					'duration_seconds'  => $spec['duration'],
					'submitted_at'      => $spec['submitted'],
					'percentage'        => round( ( $spec['score'] / 10000 ) * 100, 2 ),
				),
				$suffix
			);
			$this->insert_ranking_entry( $revision_id, $user_id, $attempt['id'], $spec['score'], $spec['duration'], $spec['submitted'] );
			$prepared[] = array_merge( $spec, $attempt );
		}

		$total = count( $prepared );
		foreach ( $prepared as $case ) {
			wp_set_current_user( $case['user_id'] );

			$result = $this->attempts->result( $case['public_id'], $case['token'] );
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'ranking', $result, 'A ranking-eligible member must receive the ranking block.' );

			$ranking = $result['ranking'];
			$this->assertSame( $case['expected_rank'], (int) $ranking['rank'], "Rank mismatch for user #{$case['user_id']}." );
			$this->assertSame( $total, (int) $ranking['total'], 'Total should include every ranked attempt in the revision.' );

			$expected_percentile = round( ( ( $total - $case['expected_rank'] + 1 ) / $total ) * 100, 2 );
			$this->assertSame( $expected_percentile, (float) $ranking['percentile'], "Percentile mismatch for rank {$case['expected_rank']}." );
		}
	}

	public function test_insert_ignore_dedups_redundant_ranking_entry_writes(): void {
		$suffix = 'rank-dedup-' . wp_generate_password( 6, false, false );
		list( $assessment_id, $revision_id ) = $this->seed_ranked_exam( $suffix );
		$user_id = $this->create_member_user( $suffix );
		$attempt = $this->seed_member_attempt(
			$assessment_id,
			$revision_id,
			$user_id,
			array(
				'score'            => 7000,
				'duration_seconds' => 400,
				'submitted_at'     => '2026-02-02 12:00:00',
				'percentage'       => 70.0,
			),
			$suffix
		);
		$this->insert_ranking_entry( $revision_id, $user_id, $attempt['id'], 7000, 400, '2026-02-02 12:00:00' );

		$wpdb   = $this->db->wpdb();
		$before = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'ranking_entries' ) . ' WHERE revision_id = %d AND wp_user_id = %d',
				$revision_id,
				$user_id
			)
		);
		$this->assertSame( 1, $before, 'Initial entry count for the (revision, user) pair must be exactly 1.' );

		/*
		 * Re-run the same INSERT IGNORE statement finalize() would emit if the
		 * code path were entered a second time for this attempt. The UNIQUE KEY
		 * revision_user constraint must keep the row count at 1.
		 */
		$re_inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . $this->db->table( 'ranking_entries' ) . ' (revision_id,wp_user_id,attempt_id,score,duration_seconds,submitted_at) VALUES (%d,%d,%d,%d,%d,%s)',
				$revision_id,
				$user_id,
				$attempt['id'],
				7000,
				400,
				'2026-02-02 12:00:00'
			)
		);
		$this->assertSame( 0, (int) $re_inserted, 'A duplicate INSERT IGNORE must report zero affected rows.' );

		$after = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'ranking_entries' ) . ' WHERE revision_id = %d AND wp_user_id = %d',
				$revision_id,
				$user_id
			)
		);
		$this->assertSame( 1, $after, 'INSERT IGNORE must dedup a re-finalize: the row count must remain 1.' );
	}

	/**
	 * Build a published exam revision with ranking_enabled=1, login required,
	 * detailed visibility, and no result release date (so the payload cannot
	 * be forced to hidden while we are characterising ranking).
	 *
	 * @return array{0:int,1:int} [assessment_id, revision_id]
	 */
	private function seed_ranked_exam( string $suffix ): array {
		$wpdb = $this->db->wpdb();
		$now  = current_time( 'mysql', true );

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
			'assessment_id'           => $assessment_id,
			'revision_no'             => 1,
			'lifecycle'               => 'published',
			'title'                   => 'PTQ Ranking ' . $suffix,
			'description'             => '',
			'class_id'                => null,
			'subject_ids_json'        => '[]',
			'access_mode'             => 'login_required',
			'options_json'            => wp_json_encode( array( 'A', 'B', 'C', 'D' ) ),
			'total_points'            => 10000,
			'duration_seconds'        => null,
			'window_start_utc'        => null,
			'window_end_utc'          => null,
			'results_release_at_utc'  => null,
			'allow_repeat'            => 0,
			'ranking_enabled'         => 1,
			'feedback_timing'         => 'after_submit',
			'result_visibility'       => 'detailed',
			'participant_fields_json' => '{}',
			'retention_days'          => 365,
			'source_asset_id'         => null,
			'created_at'              => $now,
			'published_at'            => $now,
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

		return array( $assessment_id, $revision_id );
	}

	private function create_member_user( string $suffix ): int {
		$wpdb     = $this->db->wpdb();
		$login    = 'ptq_' . $suffix . '_' . wp_generate_password( 4, false, false );
		$email    = strtolower( $login ) . '@example.com';
		$user_id  = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 32 ),
				'first_name' => 'PTQ',
				'last_name'  => 'Member',
				'role'       => 'subscriber',
				'display_name' => $login,
			)
		);
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array{id:int,public_id:string,token:string,user_id:int}
	 */
	private function seed_member_attempt( int $assessment_id, int $revision_id, int $user_id, array $overrides, string $suffix ): array {
		$wpdb      = $this->db->wpdb();
		$now       = current_time( 'mysql', true );
		$token     = 'ptq-token-' . $suffix . '-' . wp_generate_password( 8, false, false );
		$public_id = wp_generate_uuid4();
		$score     = (int) ( $overrides['score'] ?? 5000 );
		$duration  = (int) ( $overrides['duration_seconds'] ?? 300 );
		$submitted = (string) ( $overrides['submitted_at'] ?? $now );

		$participant = array(
			'email'      => strtolower( 'ptq-' . $suffix . '-' . $user_id . '@example.com' ),
			'first_name' => 'PTQ',
			'last_name'  => 'Member',
		);

		$attempt_row = array(
			'public_id'           => $public_id,
			'token_hash'          => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
			'assessment_id'       => $assessment_id,
			'revision_id'         => $revision_id,
			'wp_user_id'          => $user_id,
			'participant_type'    => 'member',
			'participant_data'    => $this->crypto->encrypt_array( $participant ),
			'status'              => 'submitted',
			'submission_id'       => wp_generate_uuid4(),
			'integrity_status'    => 'on_time',
			'ranking_eligible'    => 1,
			'finish_requested_at' => $submitted,
			'started_at'          => gmdate( 'Y-m-d H:i:s', strtotime( $submitted . ' UTC' ) - $duration ),
			'deadline_at'         => null,
			'last_activity_at'    => $submitted,
			'submitted_at'        => $submitted,
			'duration_seconds'    => $duration,
			'correct_count'       => 0,
			'wrong_count'         => 0,
			'blank_count'         => 0,
			'score'               => $score,
			'percentage'          => (float) ( $overrides['percentage'] ?? 0.0 ),
		);
		$wpdb->insert( $this->db->table( 'attempts' ), $attempt_row );
		$attempt_id = (int) $wpdb->insert_id;
		$this->attempt_ids[] = $attempt_id;

		return array(
			'id'       => $attempt_id,
			'public_id'=> $public_id,
			'token'    => $token,
			'user_id'  => $user_id,
		);
	}

	private function insert_ranking_entry( int $revision_id, int $user_id, int $attempt_id, int $score, int $duration, string $submitted_at ): void {
		$wpdb = $this->db->wpdb();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . $this->db->table( 'ranking_entries' ) . ' (revision_id,wp_user_id,attempt_id,score,duration_seconds,submitted_at) VALUES (%d,%d,%d,%d,%d,%s)',
				$revision_id,
				$user_id,
				$attempt_id,
				$score,
				$duration,
				$submitted_at
			)
		);
	}
}
