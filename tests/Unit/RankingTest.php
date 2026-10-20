<?php
/**
 * Database and REST regressions for member and guest rankings.
 * Covers score/duration/submission ties, atomic guest writes, privacy,
 * masked-only search, pagination, access/release gates, and schema upgrades.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Application\RankingService;
use PaperToQuiz\Infrastructure\Installer;
use PaperToQuiz\Rest\PublicController;
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
	/** @var int[] */
	private array $subject_ids = array();

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
		$prefix = $wpdb->prefix . 'paper_to_quiz_';

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
		foreach ( $this->subject_ids as $subject_id ) {
			$wpdb->delete( $prefix . 'terms', array( 'id' => $subject_id ), array( '%d' ) );
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

	public function test_ranked_member_submit_closes_attempt_and_persists_subject_score_once(): void {
		$suffix = 'rank-submit-' . wp_generate_password( 6, false, false );
		list( $assessment_id, $revision_id, $subject_id, $question_id ) = $this->seed_ranked_exam_with_subject_question( $suffix );
		$user_id = $this->create_member_user( $suffix );
		wp_set_current_user( $user_id );

		$started = $this->attempts->start( $assessment_id, array() );
		$this->assertIsArray( $started, 'A logged-in member must be able to start the ranked exam.' );
		$this->assertArrayHasKey( 'public_id', $started );
		$this->assertArrayHasKey( 'token', $started );

		$public_id  = (string) $started['public_id'];
		$token      = (string) $started['token'];
		$wpdb       = $this->db->wpdb();
		$attempt_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $this->db->table( 'attempts' ) . ' WHERE public_id = %s',
				$public_id
			)
		);
		$this->assertGreaterThan( 0, $attempt_id, 'The started attempt must be persisted.' );
		$this->attempt_ids[] = $attempt_id;

		$submission_id = wp_generate_uuid4();
		$answers       = array( array( 'question_id' => $question_id, 'option' => 'A' ) );
		$result        = $this->attempts->submit( $public_id, $token, false, $submission_id, $answers );
		$this->assertIsArray( $result, 'A valid ranked member submission must return a result array.' );
		$this->assertSame( 'submitted', $result['status'] );

		$attempt = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status,ranking_eligible FROM ' . $this->db->table( 'attempts' ) . ' WHERE id = %d',
				$attempt_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $attempt );
		$this->assertSame( 'submitted', $attempt['status'] );
		$this->assertSame( 1, (int) $attempt['ranking_eligible'] );
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table( 'ranking_entries' ) . ' WHERE revision_id = %d AND attempt_id = %d',
					$revision_id,
					$attempt_id
				)
			),
			'A completed eligible attempt must have one overall ranking entry.'
		);
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table( 'attempt_subject_scores' ) . ' WHERE revision_id = %d AND attempt_id = %d AND subject_id = %d',
					$revision_id,
					$attempt_id,
					$subject_id
				)
			),
			'A completed attempt must have one score row for its question subject.'
		);

		$repeated = $this->attempts->submit( $public_id, $token, false, $submission_id, $answers );
		$this->assertIsArray( $repeated, 'A repeated submission must return the persisted result.' );
		$this->assertSame( 'submitted', $repeated['status'] );
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table( 'ranking_entries' ) . ' WHERE revision_id = %d AND attempt_id = %d',
					$revision_id,
					$attempt_id
				)
			),
			'Repeating an idempotent submit must not duplicate the ranking entry.'
		);
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table( 'attempt_subject_scores' ) . ' WHERE revision_id = %d AND attempt_id = %d',
					$revision_id,
					$attempt_id
				)
			),
			'Repeating an idempotent submit must not duplicate subject scores.'
		);
	}

	public function test_guest_submit_is_ranked_atomically_and_public_names_are_masked(): void {
		list($assessment_id, $revision_id, $subject_id, $question_id) = $this->seed_guest_exam();
		wp_set_current_user(0);
		$started = $this->attempts->start($assessment_id, array('first_name' => 'İpek Mehmet', 'last_name' => 'Yılmaz'));
		$this->assertIsArray($started);
		$attempt_id = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT id FROM %i WHERE public_id = %s', $this->db->table('attempts'), $started['public_id']));
		$this->attempt_ids[] = $attempt_id;
		$submission = wp_generate_uuid4();
		$answers = array(array('question_id' => $question_id, 'option' => 'A'));
		$result = $this->attempts->submit($started['public_id'], $started['token'], false, $submission, $answers);
		$this->assertIsArray($result);
		$this->assertSame(1, $result['ranking']['rank']);
		$this->assertSame(1, $result['subjects'][0]['ranking']['total']);
		$this->assertSame(1, $result['subjects'][0]['ranking']['rank']);
		$entry = $this->db->wpdb()->get_row($this->db->wpdb()->prepare('SELECT * FROM %i WHERE attempt_id = %d', $this->db->table('ranking_entries'), $attempt_id), ARRAY_A);
		$this->assertNull($entry['wp_user_id']);
		$this->assertSame('İpek M. Y.', $entry['public_label']);
		$this->assertSame('ipek m y', $entry['public_search']);
		$this->assertIsArray($this->attempts->submit($started['public_id'], $started['token'], false, $submission, $answers));
		$this->assertSame(1, (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT COUNT(*) FROM %i WHERE attempt_id = %d', $this->db->table('ranking_entries'), $attempt_id)));
		$this->assertInstanceOf(\WP_Error::class, $this->attempts->ranking_list($assessment_id, 1, 20, ''));
		$this->finish_exam($revision_id);
		$list = $this->attempts->ranking_list($assessment_id, 1, 20, 'IPEK');
		$this->assertSame(1, $list['total']);
		$this->assertSame(array('rank', 'display_name', 'score', 'duration_seconds'), array_keys($list['items'][0]));
		$this->assertSame(0, $this->attempts->ranking_list($assessment_id, 1, 20, 'Yılmaz')['total']);
		$this->assertTrue($this->attempts->anonymize_attempt($attempt_id));
		$this->assertSame(0, $this->attempts->ranking_list($assessment_id, 1, 20, '')['participant_total']);
		$this->assertFalse($this->attempts->result($started['public_id'], $started['token'])['ranking_eligible']);
	}

	public function test_guest_list_preserves_global_ranks_ties_and_constant_query_count(): void {
		list($assessment_id, $revision_id) = $this->seed_guest_exam();
		$specs = array(
			array('Ada', 9000, 60, '2026-01-01 10:00:00'),
			array('Ada', 9000, 60, '2026-01-01 10:00:00'),
			array('Özgür', 9000, 70, '2026-01-01 10:00:00'),
			array('İpek', 8000, 10, '2026-01-01 10:00:00'),
		);
		foreach ($specs as $spec) {
			$this->seed_guest_entry($assessment_id, $revision_id, ...$spec);
		}
		$rankings = new RankingService($this->db);
		$queries = $this->db->wpdb()->num_queries;
		$list = $rankings->listing($revision_id, 1, 2, '');
		$this->assertSame(3, $this->db->wpdb()->num_queries - $queries);
		$this->assertSame(array(1, 1), array_column($list['items'], 'rank'));
		$this->assertSame(array(3, 4), array_column($rankings->listing($revision_id, 2, 2, '')['items'], 'rank'));
		$this->assertSame(3, $rankings->listing($revision_id, 1, 20, 'OZGUR')['items'][0]['rank']);
		$this->assertSame(4, $rankings->listing($revision_id, 1, 20, 'ıpek')['items'][0]['rank']);
		$this->assertSame(0, $rankings->listing($revision_id, 1, 20, "%' OR 1=1 --")['total']);
		foreach (array('late_recovered', 'expired', 'in_progress', 'anonymous', 'ineligible') as $excluded) {
			$id = $this->seed_guest_entry($assessment_id, $revision_id, 'Excluded', 10000, 1, '2026-01-01 09:00:00');
			$changes = match ($excluded) {
				'late_recovered' => array('integrity_status' => 'late_recovered'),
				'expired', 'in_progress' => array('status' => $excluded),
				'anonymous' => array('anonymized_at' => current_time('mysql', true)),
				default => array('ranking_eligible' => 0),
			};
			$this->db->wpdb()->update($this->db->table('attempts'), $changes, array('id' => $id));
		}
		$this->assertSame(4, $rankings->listing($revision_id, 1, 100, '')['participant_total']);
	}

	public function test_identically_named_guests_and_automatic_submissions_remain_separate(): void {
		list($assessment_id, $revision_id, $subject_id, $question_id) = $this->seed_guest_exam();
		wp_set_current_user(0);
		foreach (array(false, true) as $automatic) {
			$started = $this->attempts->start($assessment_id, array('first_name' => 'Same', 'last_name' => 'Name'));
			$this->assertIsArray($started);
			$this->attempt_ids[] = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT id FROM %i WHERE public_id = %s', $this->db->table('attempts'), $started['public_id']));
			$result = $this->attempts->submit($started['public_id'], $started['token'], $automatic, wp_generate_uuid4(), array(array('question_id' => $question_id, 'option' => 'A')));
			$this->assertIsArray($result);
			$this->assertSame($automatic ? 'auto_submitted' : 'submitted', $result['status']);
			$this->assertTrue($result['ranking_eligible']);
		}
		$this->assertSame(2, $result['ranking']['total']);
		$this->assertSame(2, $result['subjects'][0]['ranking']['total']);
		$this->finish_exam($revision_id);
		$this->assertCount(2, $this->attempts->ranking_list($assessment_id, 1, 20, 'Same')['items']);
	}

	public function test_new_published_revision_does_not_rank_an_old_guest_attempt(): void {
		list($assessment_id, $old_revision, $subject_id, $question_id) = $this->seed_guest_exam();
		$wpdb = $this->db->wpdb();
		$wpdb->update($this->db->table('revisions'), array('ranking_enabled' => 0, 'ranking_list_enabled' => 0), array('id' => $old_revision));
		wp_set_current_user(0);
		$started = $this->attempts->start($assessment_id, array('first_name' => 'Old', 'last_name' => 'Guest'));
		$this->assertIsArray($started);
		$this->attempt_ids[] = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE public_id = %s', $this->db->table('attempts'), $started['public_id']));
		$next = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->db->table('revisions'), $old_revision), ARRAY_A);
		unset($next['id']);
		$next['revision_no'] = 2;
		$next['ranking_enabled'] = 1;
		$next['ranking_list_enabled'] = 1;
		$this->assertSame(1, $wpdb->insert($this->db->table('revisions'), $next));
		$new_revision = (int) $wpdb->insert_id;
		$this->revision_ids[] = $new_revision;
		$wpdb->update($this->db->table('assessments'), array('published_revision_id' => $new_revision), array('id' => $assessment_id));
		$result = $this->attempts->submit($started['public_id'], $started['token'], false, wp_generate_uuid4(), array(array('question_id' => $question_id, 'option' => 'A')));
		$this->assertFalse($result['ranking_eligible']);
		$this->assertArrayNotHasKey('ranking', $result);
		$this->assertFalse($result['ranking_list_available']);
		$this->seed_guest_entry($assessment_id, $new_revision, 'New', 9000, 30, current_time('mysql', true));
		$this->finish_exam($new_revision);
		$this->assertSame(1, $this->attempts->ranking_list($assessment_id, 1, 20, '')['participant_total']);
	}

	public function test_guest_ranking_write_failure_rolls_back_submission(): void {
		list($assessment_id, $revision_id, $subject_id, $question_id) = $this->seed_guest_exam();
		wp_set_current_user(0);
		$started = $this->attempts->start($assessment_id, array());
		$this->assertIsArray($started);
		$id = (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT id FROM %i WHERE public_id = %s', $this->db->table('attempts'), $started['public_id']));
		$this->attempt_ids[] = $id;
		$db = new Database($this->db->wpdb(), static fn (string $operation, callable $write): mixed => $operation === 'finalize_ranking_insert' ? false : $write());
		$assets = new AssetService($db, new EncryptedStorage());
		$attempts = new AttemptService($db, new AssessmentService($db, $assets), $this->crypto);
		$result = $attempts->submit($started['public_id'], $started['token'], false, wp_generate_uuid4(), array(array('question_id' => $question_id, 'option' => 'A')));
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('in_progress', $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT status FROM %i WHERE id = %d', $this->db->table('attempts'), $id)));
		foreach (array('ranking_entries', 'attempt_subject_scores', 'answers') as $table) {
			$this->assertSame(0, (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare('SELECT COUNT(*) FROM %i WHERE attempt_id = %d', $this->db->table($table), $id)));
		}
	}

	public function test_large_public_list_has_bounded_queries_and_does_not_decrypt_participants(): void {
		list($assessment_id, $revision_id) = $this->seed_guest_exam();
		for ($index = 0; $index < 500; ++$index) {
			$id = $this->seed_guest_entry($assessment_id, $revision_id, 'Search' . $index, 10000 - $index, 30, '2026-01-01 10:00:00');
			$this->db->wpdb()->update($this->db->table('attempts'), array('participant_data' => 'deliberately invalid ciphertext'), array('id' => $id));
		}
		$rankings = new RankingService($this->db);
		$queries = $this->db->wpdb()->num_queries;
		$list = $rankings->listing($revision_id, 5, 100, 'Search');
		$this->assertSame(3, $this->db->wpdb()->num_queries - $queries);
		$this->assertSame(500, $list['participant_total']);
		$this->assertCount(100, $list['items']);
		$this->assertSame(401, $list['items'][0]['rank']);
	}

	public function test_public_list_rest_permissions_release_and_input_boundaries(): void {
		list($assessment_id, $revision_id) = $this->seed_guest_exam();
		$this->seed_guest_entry($assessment_id, $revision_id, 'Ahmet', 9000, 30, current_time('mysql', true));
		rest_get_server();
		(new PublicController($this->attempts))->register_routes();
		$route = '/paper-to-quiz/v1/assessments/' . $assessment_id . '/ranking';
		wp_set_current_user(0);
		$request = new \WP_REST_Request('GET', $route);
		$pending = rest_get_server()->dispatch($request);
		$this->assertSame(403, $pending->get_status());
		$this->assertArrayNotHasKey('items', $pending->get_data());
		$this->assertArrayNotHasKey('participant_total', $pending->get_data());
		$this->assertSame('private, no-store, max-age=0', $pending->get_headers()['Cache-Control']);
		$this->finish_exam($revision_id);
		$this->assertSame(200, rest_get_server()->dispatch($request)->get_status());
		foreach (array(array('per_page', 101), array('page', 0), array('search', str_repeat('x', 81))) as $invalid) {
			$bad = new \WP_REST_Request('GET', $route);
			$bad->set_param($invalid[0], $invalid[1]);
			$this->assertSame(400, rest_get_server()->dispatch($bad)->get_status(), $invalid[0] . ' must enforce its schema.');
		}
		$this->db->wpdb()->update($this->db->table('revisions'), array('results_release_at_utc' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS)), array('id' => $revision_id));
		$this->assertSame(403, rest_get_server()->dispatch($request)->get_status());
		$this->db->wpdb()->update($this->db->table('revisions'), array('results_release_at_utc' => null, 'access_mode' => 'login_required'), array('id' => $revision_id));
		$this->assertSame(401, rest_get_server()->dispatch($request)->get_status());
		wp_set_current_user($this->create_member_user('list-access'));
		$this->assertSame(200, rest_get_server()->dispatch($request)->get_status());
		$deny = static fn (): bool => false;
		add_filter('paper_to_quiz_can_access_assessment', $deny);
		try {
			$this->assertSame(403, rest_get_server()->dispatch($request)->get_status());
		} finally {
			remove_filter('paper_to_quiz_can_access_assessment', $deny);
		}
		foreach (array('hidden', 'score_only') as $visibility) {
			$this->db->wpdb()->update($this->db->table('revisions'), array('result_visibility' => $visibility), array('id' => $revision_id));
			$this->assertSame(403, rest_get_server()->dispatch($request)->get_status());
		}
		$this->db->wpdb()->update($this->db->table('revisions'), array('result_visibility' => 'summary', 'ranking_list_enabled' => 0), array('id' => $revision_id));
		$this->assertSame(403, rest_get_server()->dispatch($request)->get_status());
		foreach (array('draft', 'archived', 'trash') as $status) {
			$this->db->wpdb()->update($this->db->table('assessments'), array('status' => $status), array('id' => $assessment_id));
			$this->assertSame(404, rest_get_server()->dispatch($request)->get_status());
		}
	}

	public function test_list_read_limit_is_separate_and_prevents_queries_after_limit(): void {
		list($assessment_id, $revision_id) = $this->seed_guest_exam();
		$this->finish_exam($revision_id);
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		$key = 'paper_to_quiz_ranking_rate_' . substr(hash_hmac('sha256', $assessment_id . '|' . $ip . '|' . (int) floor(time() / MINUTE_IN_SECONDS), wp_salt('nonce')), 0, 40);
		set_transient($key, 119, 120);
		$request = new \WP_REST_Request('GET');
		$request->set_param('id', $assessment_id);
		$request->set_param('page', 1);
		$request->set_param('per_page', 20);
		$request->set_param('search', '');
		$controller = new PublicController($this->attempts);
		try {
			$this->assertSame(200, $controller->ranking($request)->get_status());
			$limited = $controller->ranking($request);
			$this->assertSame(429, $limited->get_status());
			$this->assertSame('60', $limited->get_headers()['Retry-After']);
			$this->assertArrayNotHasKey('items', $limited->get_data());
		} finally {
			delete_transient($key);
		}
	}

	public function test_upgrade_preserves_member_rankings_and_does_not_backfill_names(): void {
		list($assessment_id, $revision_id) = $this->seed_ranked_exam('upgrade');
		$user_id = $this->create_member_user('upgrade');
		$attempt = $this->seed_member_attempt($assessment_id, $revision_id, $user_id, array(), 'upgrade');
		$this->insert_ranking_entry($revision_id, $user_id, $attempt['id'], 5000, 300, current_time('mysql', true));
		$wpdb = $this->db->wpdb();
		$wpdb->query('ALTER TABLE ' . $this->db->table('ranking_entries') . ' MODIFY wp_user_id bigint(20) unsigned NOT NULL, DROP COLUMN public_label, DROP COLUMN public_search');
		$wpdb->query('ALTER TABLE ' . $this->db->table('revisions') . ' DROP COLUMN ranking_list_enabled');
		update_option('paper_to_quiz_db_version', '1.0.0');
		try {
			$this->assertTrue(Installer::repair_schema());
			$this->assertSame(PAPER_TO_QUIZ_DB_VERSION, get_option('paper_to_quiz_db_version'));
			$this->assertTrue(Installer::repair_schema());
			$entry = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE attempt_id = %d', $this->db->table('ranking_entries'), $attempt['id']), ARRAY_A);
			$this->assertSame($user_id, (int) $entry['wp_user_id']);
			$this->assertNull($entry['public_label']);
			$this->assertSame('0', $wpdb->get_var($wpdb->prepare('SELECT ranking_list_enabled FROM %i WHERE id = %d', $this->db->table('revisions'), $revision_id)));
		} finally {
			Installer::repair_schema();
		}
	}

	private function seed_guest_exam(): array {
		$context = $this->seed_ranked_exam_with_subject_question('guest-' . wp_generate_password(5, false, false));
		$this->db->wpdb()->update($this->db->table('revisions'), array(
			'access_mode' => 'guest_allowed', 'ranking_list_enabled' => 1,
			'participant_fields_json' => wp_json_encode(array('first_name' => array('enabled' => true, 'required' => false), 'last_name' => array('enabled' => true, 'required' => false))),
		), array('id' => $context[1]));
		return $context;
	}

	private function finish_exam(int $revision_id): void {
		$this->db->wpdb()->update($this->db->table('revisions'), array('window_end_utc' => gmdate('Y-m-d H:i:s', time() - 60)), array('id' => $revision_id));
	}

	private function seed_guest_entry(int $assessment_id, int $revision_id, string $name, int $score, int $duration, string $submitted): int {
		$attempt = $this->seed_member_attempt($assessment_id, $revision_id, 0, array('score' => $score, 'duration_seconds' => $duration, 'submitted_at' => $submitted), 'guest');
		$this->db->wpdb()->update($this->db->table('attempts'), array('participant_type' => 'guest', 'wp_user_id' => null), array('id' => $attempt['id']));
		$label = RankingService::public_label(array('first_name' => $name, 'last_name' => 'Yılmaz'));
		$this->assertSame(1, $this->db->wpdb()->insert($this->db->table('ranking_entries'), array(
			'revision_id' => $revision_id, 'wp_user_id' => null, 'attempt_id' => $attempt['id'],
			'score' => $score, 'duration_seconds' => $duration, 'submitted_at' => $submitted,
			'public_label' => $label, 'public_search' => RankingService::search_name($label),
		)));
		return $attempt['id'];
	}

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

	/**
	 * Add one selected subject and one answer-keyed question to a ranked exam.
	 *
	 * @return array{0:int,1:int,2:int,3:int} [assessment_id, revision_id, subject_id, question_id]
	 */
	private function seed_ranked_exam_with_subject_question( string $suffix ): array {
		list( $assessment_id, $revision_id ) = $this->seed_ranked_exam( $suffix );
		$wpdb = $this->db->wpdb();
		$now  = current_time( 'mysql', true );

		$this->assertSame(
			1,
			$wpdb->insert(
				$this->db->table( 'terms' ),
				array(
					'type'       => 'subject',
					'name'       => 'PTQ Subject ' . $suffix,
					'slug'       => sanitize_title( 'PTQ Subject ' . $suffix ),
					'status'     => 'active',
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
		$subject_id         = (int) $wpdb->insert_id;
		$this->subject_ids[] = $subject_id;
		$this->assertSame(
			1,
			$wpdb->update(
				$this->db->table( 'revisions' ),
				array( 'subject_ids_json' => wp_json_encode( array( $subject_id ) ) ),
				array( 'id' => $revision_id ),
				array( '%s' ),
				array( '%d' )
			)
		);

		$this->assertSame(
			1,
			$wpdb->insert(
				$this->db->table( 'questions' ),
				array(
					'revision_id' => $revision_id,
					'client_key'  => wp_generate_uuid4(),
					'ordinal'     => 1,
					'source_page' => 1,
					'crop_x'      => '0.1',
					'crop_y'      => '0.1',
					'crop_width'  => '0.8',
					'crop_height' => '0.3',
					'subject_id'     => $subject_id,
					'correct_option' => 'A',
					'points'         => 10000,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			)
		);
		$question_id = (int) $wpdb->insert_id;

		return array( $assessment_id, $revision_id, $subject_id, $question_id );
	}

	private function create_member_user( string $suffix ): int {
		$wpdb     = $this->db->wpdb();
		$login    = 'paper_to_quiz_' . $suffix . '_' . wp_generate_password( 4, false, false );
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
