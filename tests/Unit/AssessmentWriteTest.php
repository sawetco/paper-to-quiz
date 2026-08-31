<?php
/**
 * Failure-path tests for AssessmentService's initial write transaction.
 *
 * These tests use Database's narrow named-write interceptor so a single
 * database operation can fail without adding a production flag or changing
 * the global wpdb object.
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

final class AssessmentWriteTest extends TestCase {

	private Database $base_db;
	private int $subject_id = 0;
	private string $title_prefix;

	public function setUp(): void {
		parent::setUp();

		$this->base_db     = new Database();
		$this->title_prefix = 'PTQ write failure ' . wp_generate_password(8, false, false);
		$now               = current_time('mysql', true);
		$inserted          = $this->base_db->wpdb()->insert(
			$this->base_db->table('terms'),
			array(
				'type'       => 'subject',
				'name'       => $this->title_prefix . ' subject',
				'slug'       => sanitize_title($this->title_prefix . ' subject'),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$this->assertSame(1, $inserted, 'The assessment write fixture subject could not be created.');
		$this->subject_id = (int) $this->base_db->wpdb()->insert_id;
	}

	public function tearDown(): void {
		$wpdb = $this->base_db->wpdb();
		$like = '%' . $wpdb->esc_like($this->title_prefix) . '%';
		$assessment_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT assessment_id FROM ' . $this->base_db->table('revisions') . ' WHERE title LIKE %s',
				$like
			)
		);
		$wpdb->query(
			$wpdb->prepare('DELETE FROM ' . $this->base_db->table('revisions') . ' WHERE title LIKE %s', $like)
		);
		if ($assessment_ids) {
			$placeholders = implode(',', array_fill(0, count($assessment_ids), '%d'));
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . $this->base_db->table('assessments') . " WHERE id IN ({$placeholders})",
					...array_map('intval', $assessment_ids)
				)
			);
		}
		if ($this->subject_id) {
			$wpdb->delete($this->base_db->table('terms'), array('id' => $this->subject_id), array('%d'));
		}

		parent::tearDown();
	}

	public function test_revision_insert_failure_rolls_back_assessment_and_revision(): void {
		$this->assert_initial_write_failure_rolls_back('revision_insert');
	}

	public function test_draft_pointer_failure_rolls_back_assessment_and_revision(): void {
		$this->assert_initial_write_failure_rolls_back('assessment_draft_pointer_update');
	}

	private function assert_initial_write_failure_rolls_back(string $failed_operation): void {
		$wpdb          = $this->base_db->wpdb();
		$title         = $this->title_prefix . ' ' . $failed_operation;
		$created_id    = 0;
		$revision_id   = 0;
		$failure_seen  = false;
		$db            = new Database(
			$wpdb,
			static function (string $operation, callable $write) use (&$created_id, &$revision_id, &$failure_seen, $failed_operation, $wpdb): mixed {
				if ($operation === $failed_operation) {
					$failure_seen = true;
					return false;
				}

				$result = $write();
				if ('assessment_insert' === $operation) {
					$created_id = (int) $wpdb->insert_id;
				} elseif ('revision_insert' === $operation) {
					$revision_id = (int) $wpdb->insert_id;
				}
				return $result;
			}
		);
		$assets      = new AssetService($db, new EncryptedStorage());
		$assessments = new AssessmentService($db, $assets);

		$result = $assessments->save(
			array(
				'type'             => 'test',
				'title'            => $title,
				'description'      => 'Failure-path fixture.',
				'class_id'         => null,
				'subject_ids'      => array($this->subject_id),
				'access_mode'      => 'guest_allowed',
				'options'          => array('A', 'B', 'C', 'D'),
				'total_points'     => 10000,
				'allow_repeat'     => true,
				'feedback_timing'  => 'after_submit',
				'result_visibility' => 'summary',
			),
			null,
			1
		);

		$this->assertTrue($failure_seen, "The {$failed_operation} failure was not injected.");
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('paper_to_quiz_assessment_create_failed', $result->get_error_code());
		$this->assertSame(500, (int) ($result->get_error_data()['status'] ?? 0));
		$this->assertStringContainsString('Support code:', $result->get_error_message());
		$this->assertSame(0, $created_id ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('assessments') . ' WHERE id=%d', $created_id)) : 0);
		$this->assertSame(0, $revision_id ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('revisions') . ' WHERE id=%d', $revision_id)) : 0);
		$this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('revisions') . ' WHERE title=%s', $title)));
	}
}
