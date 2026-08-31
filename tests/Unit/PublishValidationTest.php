<?php
/**
 * Publish subject validation characterization and query-scaling tests.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PublishValidationTest extends TestCase {

	private Database $db;

	/** @var int[] */
	private array $term_ids = array();

	private string $title_prefix;

	public function setUp(): void {
		parent::setUp();

		$this->db           = new Database();
		$this->title_prefix = 'PTQ publish validation ' . wp_generate_password(8, false, false);
	}

	public function tearDown(): void {
		$wpdb = $this->db->wpdb();
		foreach ($this->term_ids as $term_id) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test teardown removes isolated fixtures.
				$this->db->table('terms'),
				array('id' => $term_id),
				array('%d')
			);
		}

		parent::tearDown();
	}

	public function test_no_subject_ids_skips_subject_lookup_and_keeps_missing_subject_messages(): void {
		$service = $this->service();
		$record  = $this->record(
			array(null, 0),
			array()
		);

		$query_start = (int) $this->db->wpdb()->num_queries;
		$errors      = $this->validate($service, $record);
		$query_count = (int) $this->db->wpdb()->num_queries - $query_start;

		$this->assertSame(0, $query_count, 'Questions without subject IDs must not issue a terms query.');
		$this->assertSame(
			array(
				__('Select at least one subject.', 'paper-to-quiz'),
				__('Select a subject for question 1.', 'paper-to-quiz'),
				__('Select a subject for question 2.', 'paper-to-quiz'),
			),
			$errors
		);
	}

	public function test_unique_subject_lookup_preserves_validation_messages_and_order(): void {
		$active   = $this->seed_term('subject', 'active', 'active');
		$archived = $this->seed_term('subject', 'archived', 'archived');
		$trashed  = $this->seed_term('subject', 'trashed', 'trash');
		$class    = $this->seed_term('class', 'class', 'active');
		$unselected = $this->seed_term('subject', 'unselected', 'active');

		$record = $this->record(
			array($active, $active, $archived, $trashed, $class, 999999, $unselected),
			array($active, $archived)
		);
		$record['revision']['class_id'] = $class;

		$query_start = (int) $this->db->wpdb()->num_queries;
		$errors      = $this->validate($this->service(), $record);
		$query_count = (int) $this->db->wpdb()->num_queries - $query_start;

		$this->assertSame(1, $query_count, 'All unique question subjects must be validated with one terms query.');
		$this->assertSame(
			array(
				/* translators: %d: Question number. */
				sprintf(__('The subject record for question %d is invalid.', 'paper-to-quiz'), 4),
				/* translators: %d: Question number. */
				sprintf(__('The subject record for question %d is invalid.', 'paper-to-quiz'), 5),
				/* translators: %d: Question number. */
				sprintf(__('The subject record for question %d is invalid.', 'paper-to-quiz'), 6),
				/* translators: %d: Question number. */
				sprintf(__('Question %d uses a subject that is not selected in Basic Information.', 'paper-to-quiz'), 7),
			),
			$errors
		);
	}

	public function test_repeated_subjects_keep_terms_queries_constant_for_one_hundred_questions(): void {
		$subject = $this->seed_term('subject', 'repeated', 'active');
		$record  = $this->record(array_fill(0, 100, $subject), array($subject));

		$query_start = (int) $this->db->wpdb()->num_queries;
		$errors      = $this->validate($this->service(), $record);
		$query_count = (int) $this->db->wpdb()->num_queries - $query_start;

		$this->assertSame(1, $query_count, 'Publish validation should not scale terms queries with question count.');
		$this->assertSame(array(), $errors);
	}

	private function service(): AssessmentService {
		return new AssessmentService($this->db, new AssetService($this->db, new EncryptedStorage()));
	}

	/** @param array<int|null> $question_subjects */
	private function record(array $question_subjects, array $selected_subject_ids): array {
		$questions = array();
		foreach ($question_subjects as $index => $subject_id) {
			$questions[] = array(
				'ordinal'         => $index + 1,
				'main_asset_id'   => 1,
				'thumb_asset_id'  => 1,
				'subject_id'      => $subject_id,
				'correct_option'  => 'A',
				'points'          => 10000,
			);
		}

		return array(
			'assessment' => array('type' => 'test'),
			'revision'   => array(
				'title'                   => $this->title_prefix,
				'class_id'                => 1,
				'subject_ids'             => $selected_subject_ids,
				'source_asset_id'         => 1,
				'ranking_enabled'         => 0,
				'access_mode'             => 'guest_allowed',
				'allow_repeat'            => 1,
				'options'                 => array('A', 'B', 'C', 'D'),
				'total_points'            => count($questions) * 10000,
				'window_start_utc'        => null,
				'window_end_utc'          => null,
				'results_release_at_utc'  => null,
				'feedback_timing'         => 'after_submit',
			),
			'questions'  => $questions,
		);
	}

	private function seed_term(string $type, string $slug, string $status): int {
		$now = current_time('mysql', true);
		$this->assertSame(
			1,
			$this->db->wpdb()->insert(
				$this->db->table('terms'),
				array(
					'type'       => $type,
					'name'       => $this->title_prefix . ' ' . $slug,
					'slug'       => $this->title_prefix . '-' . $slug,
					'status'     => $status,
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
		$id = (int) $this->db->wpdb()->insert_id;
		$this->term_ids[] = $id;
		return $id;
	}

	/** @return string[] */
	private function validate(AssessmentService $service, array $record): array {
		$method = new \ReflectionMethod($service, 'validate_publish');
		$method->setAccessible(true);
		$result = $method->invoke($service, $record);
		$this->assertIsArray($result);
		return $result;
	}
}
