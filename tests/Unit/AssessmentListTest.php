<?php
/**
 * Characterization tests for assessment-list subject enrichment.
 *
 * The list response must preserve stored subject order, recover subjects from
 * question rows when the revision JSON is empty, omit missing terms, and keep
 * subject-resolution queries bounded at the page level.
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

final class AssessmentListTest extends TestCase {

	private Database $db;

	/** @var int[] */
	private array $assessment_ids = array();

	/** @var int[] */
	private array $revision_ids = array();

	/** @var int[] */
	private array $question_ids = array();

	/** @var int[] */
	private array $subject_ids = array();

	private string $title_prefix;

	public function setUp(): void {
		parent::setUp();

		$this->db           = new Database();
		$this->title_prefix = 'PTQ list ' . wp_generate_password(8, false, false);
	}

	public function tearDown(): void {
		$wpdb = $this->db->wpdb();

		foreach ($this->question_ids as $question_id) {
			$wpdb->delete($this->db->table('questions'), array('id' => $question_id), array('%d'));
		}
		foreach ($this->revision_ids as $revision_id) {
			$wpdb->delete($this->db->table('revisions'), array('id' => $revision_id), array('%d'));
		}
		foreach ($this->assessment_ids as $assessment_id) {
			$wpdb->delete($this->db->table('assessments'), array('id' => $assessment_id), array('%d'));
		}
		foreach ($this->subject_ids as $subject_id) {
			$wpdb->delete($this->db->table('terms'), array('id' => $subject_id), array('%d'));
		}

		parent::tearDown();
	}

	public function test_list_batches_subject_resolution_and_preserves_response_semantics(): void {
		$subjects = $this->seed_subjects();
		$deleted_subject_id = $subjects['deleted'];
		$ordered_revision  = $this->seed_assessment(
			'ordered',
			wp_json_encode(array($subjects['second'], $subjects['first'], $deleted_subject_id, 0, 'invalid', $subjects['second']))
		);
		$fallback_revision = $this->seed_assessment('fallback', '[]');
		$this->seed_question($fallback_revision, $subjects['second'], 1);
		$this->seed_question($fallback_revision, $subjects['first'], 2);
		$this->seed_question($fallback_revision, $deleted_subject_id, 3);
		$this->seed_question($fallback_revision, 0, 4);
		$empty_revision = $this->seed_assessment('empty', '[]');

		$this->assertSame(
			1,
			$this->db->wpdb()->delete(
				$this->db->table('terms'),
				array('id' => $deleted_subject_id),
				array('%d')
			)
		);

		$wpdb       = $this->db->wpdb();
		$query_start = (int) $wpdb->num_queries;
		$result      = (new AssessmentService($this->db, new AssetService($this->db, new EncryptedStorage())))->list(
			'',
			1,
			10,
			'',
			$this->title_prefix,
			'title',
			'asc'
		);
		$query_count = (int) $wpdb->num_queries - $query_start;

		$this->assertLessThanOrEqual(
			5,
			$query_count,
			'List query count should include only the page, count, status, and at most two subject-resolution queries.'
		);
		$this->assertSame(3, $result['total']);
		$this->assertCount(3, $result['items']);

		$items = array_column($result['items'], null, 'title');
		$this->assertSame(
			array('List subject second', 'List subject first'),
			$items[$this->title_prefix . ' ordered']['subject_names']
		);
		$this->assertSame(
			array('List subject first', 'List subject second'),
			$items[$this->title_prefix . ' fallback']['subject_names']
		);
		$this->assertSame(array(), $items[$this->title_prefix . ' empty']['subject_names']);
		$this->assertArrayNotHasKey('subject_ids_json', $items[$this->title_prefix . ' ordered']);
		$this->assertArrayNotHasKey('subject_sort', $items[$this->title_prefix . ' ordered']);

		// Keep the fixture revision referenced so the intent is explicit even
		// though cleanup tracks every created row independently.
		$this->assertContains($ordered_revision, $this->revision_ids);
		$this->assertContains($fallback_revision, $this->revision_ids);
		$this->assertContains($empty_revision, $this->revision_ids);
	}

	/** @return array{first:int,second:int,deleted:int} */
	private function seed_subjects(): array {
		$ids = array();
		foreach (array('first' => 'first', 'second' => 'second', 'deleted' => 'deleted') as $key => $name) {
			$this->assertSame(
				1,
				$this->db->wpdb()->insert(
					$this->db->table('terms'),
					array(
						'type'       => 'subject',
						'name'       => 'List subject ' . $name,
						'slug'       => $this->title_prefix . '-' . $name,
						'status'     => 'active',
						'created_at' => current_time('mysql', true),
						'updated_at' => current_time('mysql', true),
					)
				)
			);
			$ids[$key]        = (int) $this->db->wpdb()->insert_id;
			$this->subject_ids[] = $ids[$key];
		}
		return $ids;
	}

	private function seed_assessment(string $suffix, string $subject_ids_json): int {
		$wpdb = $this->db->wpdb();
		$now  = current_time('mysql', true);
		$this->assertSame(
			1,
			$wpdb->insert(
				$this->db->table('assessments'),
				array(
					'type'       => 'test',
					'status'     => 'draft',
					'created_by' => 1,
					'updated_by' => 1,
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
		$assessment_id = (int) $wpdb->insert_id;
		$this->assessment_ids[] = $assessment_id;

		$this->assertSame(
			1,
			$wpdb->insert(
				$this->db->table('revisions'),
				array(
					'assessment_id'           => $assessment_id,
					'revision_no'             => 1,
					'lifecycle'               => 'draft',
					'title'                   => $this->title_prefix . ' ' . $suffix,
					'description'             => '',
					'class_id'                => null,
					'subject_ids_json'        => $subject_ids_json,
					'access_mode'             => 'guest_allowed',
					'options_json'            => wp_json_encode(array('A', 'B', 'C', 'D')),
					'total_points'            => 10000,
					'duration_seconds'        => null,
					'window_start_utc'        => null,
					'window_end_utc'          => null,
					'results_release_at_utc'  => null,
					'allow_repeat'            => 1,
					'ranking_enabled'         => 0,
					'feedback_timing'         => 'after_submit',
					'result_visibility'       => 'summary',
					'participant_fields_json' => '{}',
					'retention_days'          => 365,
					'source_asset_id'         => null,
					'created_at'              => $now,
					'published_at'            => null,
				)
			)
		);
		$revision_id = (int) $wpdb->insert_id;
		$this->revision_ids[] = $revision_id;
		$this->assertSame(
			1,
			$wpdb->update(
				$this->db->table('assessments'),
				array('current_draft_revision_id' => $revision_id),
				array('id' => $assessment_id),
				array('%d'),
				array('%d')
			)
		);

		return $revision_id;
	}

	private function seed_question(int $revision_id, int $subject_id, int $ordinal): void {
		$now = current_time('mysql', true);
		$this->assertSame(
			1,
			$this->db->wpdb()->insert(
				$this->db->table('questions'),
				array(
					'revision_id'     => $revision_id,
					'client_key'      => wp_generate_uuid4(),
					'ordinal'         => $ordinal,
					'source_page'     => 1,
					'crop_x'          => '0.00000000',
					'crop_y'          => '0.00000000',
					'crop_width'      => '1.00000000',
					'crop_height'     => '1.00000000',
					'source_rotation' => 0,
					'main_asset_id'   => null,
					'thumb_asset_id'  => null,
					'subject_id'      => $subject_id,
					'correct_option'  => 'A',
					'points'          => 2500,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			)
		);
		$this->question_ids[] = (int) $this->db->wpdb()->insert_id;
	}
}
