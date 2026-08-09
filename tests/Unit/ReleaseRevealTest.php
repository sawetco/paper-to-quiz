<?php
/**
 * Characterization tests for the results_release_at_utc reveal behaviour.
 *
 * Locks the two states documented in plan 014:
 *   - While results_release_at_utc is in the future, result_payload() forces
 *     visibility='hidden' and release_pending=true, and omits score/answers.
 *   - Once results_release_at_utc is in the past, result_payload() returns
 *     the configured visibility tier (here: 'detailed') with score/answers.
 *
 * The release date is moved by $wpdb->update on the revision row, NOT by
 * mocking time() (see STOP conditions in plan 014).
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

final class ReleaseRevealTest extends TestCase {

	private Database $db;
	private Crypto $crypto;
	private AttemptService $attempts;

	/** @var int[] */
	private array $attempt_ids = array();
	/** @var int[] */
	private array $revision_ids = array();
	/** @var int[] */
	private array $assessment_ids = array();

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

		parent::tearDown();
	}

	public function test_result_is_hidden_with_release_pending_until_release_date_passes(): void {
		$suffix = 'release-' . wp_generate_password( 6, false, false );

		// Release 2 hours in the future: visibility forced to hidden while release_pending.
		$future_release = gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS );
		list( $assessment_id, $revision_id ) = $this->seed_published_exam( $suffix, $future_release );
		$attempt = $this->seed_submitted_guest_attempt( $assessment_id, $revision_id, $suffix );

		$hidden_payload = $this->attempts->result( $attempt['public_id'], $attempt['token'] );
		$this->assertIsArray( $hidden_payload );
		$this->assertSame( 'hidden', $hidden_payload['visibility'], 'A future release date must force visibility=hidden.' );
		$this->assertTrue( $hidden_payload['release_pending'], 'release_pending must be true before the release date.' );
		$this->assertArrayNotHasKey( 'score', $hidden_payload, 'Hidden payload must omit the score field.' );
		$this->assertArrayNotHasKey( 'answers', $hidden_payload, 'Hidden payload must omit the answers field.' );
		$this->assertArrayNotHasKey( 'subjects', $hidden_payload, 'Hidden payload must omit the subjects field.' );
		$this->assertArrayNotHasKey( 'document', $hidden_payload, 'Hidden payload must omit the document block.' );

		// Now roll the release date 1 hour into the past, do NOT mock time().
		$wpdb = $this->db->wpdb();
		$past_release = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$updated = $wpdb->update(
			$this->db->table( 'revisions' ),
			array( 'results_release_at_utc' => $past_release ),
			array( 'id' => $revision_id ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertNotFalse( $updated, 'The release date advancement must update the revision row.' );

		$revealed_payload = $this->attempts->result( $attempt['public_id'], $attempt['token'] );
		$this->assertIsArray( $revealed_payload );
		$this->assertSame( 'detailed', $revealed_payload['visibility'], 'After the release date passes, the configured visibility tier must be returned.' );
		$this->assertFalse( $revealed_payload['release_pending'], 'release_pending must flip to false once the release date passes.' );
		$this->assertArrayHasKey( 'score', $revealed_payload, 'A detailed reveal must expose the score.' );
		$this->assertSame( 10000, (int) $revealed_payload['score'], 'Revealed score must match the fixture.' );
		$this->assertArrayHasKey( 'answers', $revealed_payload, 'A detailed reveal must expose the answers array.' );
		$this->assertIsArray( $revealed_payload['answers'] );
		$this->assertArrayHasKey( 'subjects', $revealed_payload, 'A detailed reveal must expose the subjects array.' );
		$this->assertArrayHasKey( 'document', $revealed_payload, 'A detailed reveal must expose the document block.' );
	}

	/**
	 * Build a published exam revision with result_visibility='detailed' and
	 * the given results_release_at_utc.
	 *
	 * @return array{0:int,1:int} [assessment_id, revision_id]
	 */
	private function seed_published_exam( string $suffix, string $release_utc ): array {
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
			'title'                   => 'PTQ Release ' . $suffix,
			'description'             => '',
			'class_id'                => null,
			'subject_ids_json'        => '[]',
			'access_mode'             => 'guest_allowed',
			'options_json'            => wp_json_encode( array( 'A', 'B', 'C', 'D' ) ),
			'total_points'            => 10000,
			'duration_seconds'        => null,
			'window_start_utc'        => null,
			'window_end_utc'          => null,
			'results_release_at_utc'  => $release_utc,
			'allow_repeat'            => 1,
			'ranking_enabled'         => 0,
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

	/**
	 * @return array{id:int,public_id:string,token:string}
	 */
	private function seed_submitted_guest_attempt( int $assessment_id, int $revision_id, string $suffix ): array {
		$wpdb      = $this->db->wpdb();
		$now       = current_time( 'mysql', true );
		$token     = 'ptq-release-' . $suffix . '-' . wp_generate_password( 8, false, false );
		$public_id = wp_generate_uuid4();

		$participant = array(
			'email'      => 'ptq-' . $suffix . '@example.com',
			'first_name' => 'PTQ',
			'last_name'  => 'Release',
		);

		$attempt_row = array(
			'public_id'           => $public_id,
			'token_hash'          => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
			'assessment_id'       => $assessment_id,
			'revision_id'         => $revision_id,
			'wp_user_id'          => null,
			'participant_type'    => 'guest',
			'participant_data'    => $this->crypto->encrypt_array( $participant ),
			'status'              => 'submitted',
			'submission_id'       => wp_generate_uuid4(),
			'integrity_status'    => 'on_time',
			'ranking_eligible'    => 0,
			'finish_requested_at' => $now,
			'started_at'          => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			'deadline_at'         => null,
			'last_activity_at'    => $now,
			'submitted_at'        => $now,
			'duration_seconds'    => 60,
			'correct_count'       => 1,
			'wrong_count'         => 0,
			'blank_count'         => 0,
			'score'               => 10000,
			'percentage'          => 100.0,
		);
		$wpdb->insert( $this->db->table( 'attempts' ), $attempt_row );
		$attempt_id = (int) $wpdb->insert_id;
		$this->attempt_ids[] = $attempt_id;

		return array(
			'id'        => $attempt_id,
			'public_id' => $public_id,
			'token'     => $token,
		);
	}
}
