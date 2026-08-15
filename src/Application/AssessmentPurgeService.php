<?php

declare(strict_types=1);

namespace PaperToQuiz\Application;

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\OperationalErrorReporter;

final class AssessmentPurgeService {
	public function __construct(
		private readonly Database $db,
		private readonly AssetService $assets
	) {
	}

	public function purge_impact(int $assessment_id): array|\WP_Error {
		$assessment = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT a.*,COALESCE(d.title,p.title,%s) title
				FROM ' . $this->db->table('assessments') . ' a
				LEFT JOIN ' . $this->db->table('revisions') . ' d ON d.id = a.current_draft_revision_id
				LEFT JOIN ' . $this->db->table('revisions') . ' p ON p.id = a.published_revision_id
				WHERE a.id = %d',
				__('Untitled content', 'paper-to-quiz'),
				$assessment_id
			),
			ARRAY_A
		);
		if (! $assessment) {
			return new \WP_Error('paper_to_quiz_not_found', __('Record not found.', 'paper-to-quiz'), array('status' => 404));
		}

		return array(
			'id'        => $assessment_id,
			'title'     => (string) $assessment['title'],
			'status'    => (string) $assessment['status'],
			'revisions' => $this->count_assessment_rows('revisions', 'assessment_id', $assessment_id),
			'questions' => (int) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table('questions') . ' q
					JOIN ' . $this->db->table('revisions') . ' r ON r.id = q.revision_id
					WHERE r.assessment_id = %d',
					$assessment_id
				)
			),
			'attempts'  => $this->count_assessment_rows('attempts', 'assessment_id', $assessment_id),
			'answers'   => (int) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table('answers') . ' an
					JOIN ' . $this->db->table('attempts') . ' t ON t.id = an.attempt_id
					WHERE t.assessment_id = %d',
					$assessment_id
				)
			),
		);
	}

	public function purge(int $assessment_id): array|\WP_Error {
		$impact = $this->purge_impact($assessment_id);
		if (is_wp_error($impact)) {
			if ($impact->get_error_code() === 'paper_to_quiz_not_found') {
				return array(
					'id'              => $assessment_id,
					'title'           => '',
					'status'          => 'deleted',
					'revisions'       => 0,
					'questions'       => 0,
					'attempts'        => 0,
					'answers'         => 0,
					'already_deleted' => true,
				);
			}
			return $impact;
		}
		if ($impact['status'] !== 'trash') {
			return new \WP_Error(
				'paper_to_quiz_not_in_trash',
				__('Content must be moved to the trash before it can be permanently deleted.', 'paper-to-quiz'),
				array('status' => 409)
			);
		}

		$orphan_assets = array();
		$attempt_ids   = array();
		$this->db->begin();
		try {
			$status = $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT status FROM ' . $this->db->table('assessments') . ' WHERE id = %d FOR UPDATE',
					$assessment_id
				)
			);
			if ($status !== 'trash') {
				throw new \RuntimeException(__('The content is no longer in the trash.', 'paper-to-quiz'));
			}

			$revision_ids = array_map(
				'intval',
				$this->db->wpdb()->get_col(
					$this->db->wpdb()->prepare(
						'SELECT id FROM ' . $this->db->table('revisions') . ' WHERE assessment_id = %d',
						$assessment_id
					)
				)
			);
			$attempt_ids = array_map(
				'intval',
				$this->db->wpdb()->get_col(
					$this->db->wpdb()->prepare(
						'SELECT id FROM ' . $this->db->table('attempts') . ' WHERE assessment_id = %d',
						$assessment_id
					)
				)
			);

			$asset_counts = array();
			if ($revision_ids) {
				$revision_placeholders = implode(',', array_fill(0, count($revision_ids), '%d'));
				$source_assets = $this->db->wpdb()->get_col(
					$this->db->wpdb()->prepare(
						'SELECT source_asset_id FROM ' . $this->db->table('revisions') .
						" WHERE id IN ({$revision_placeholders}) AND source_asset_id IS NOT NULL",
						...$revision_ids
					)
				);
				$question_assets = $this->db->wpdb()->get_results(
					$this->db->wpdb()->prepare(
						'SELECT main_asset_id,thumb_asset_id FROM ' . $this->db->table('questions') .
						" WHERE revision_id IN ({$revision_placeholders})",
						...$revision_ids
					),
					ARRAY_A
				) ?: array();
				foreach ($source_assets as $asset_id) {
					$asset_counts[(int) $asset_id] = ($asset_counts[(int) $asset_id] ?? 0) + 1;
				}
				foreach ($question_assets as $question_asset) {
					foreach (array('main_asset_id', 'thumb_asset_id') as $column) {
						$asset_id = (int) $question_asset[$column];
						if ($asset_id) {
							$asset_counts[$asset_id] = ($asset_counts[$asset_id] ?? 0) + 1;
						}
					}
				}
			}

			$this->delete_rows_by_ids('result_email_jobs', 'attempt_id', $attempt_ids);
			$this->delete_rows_by_ids('ranking_entries', 'attempt_id', $attempt_ids);
			$this->delete_rows_by_ids('attempt_subject_scores', 'attempt_id', $attempt_ids);
			$this->delete_rows_by_ids('answers', 'attempt_id', $attempt_ids);
			if (false === $this->db->wpdb()->delete(
				$this->db->table('attempts'),
				array('assessment_id' => $assessment_id),
				array('%d')
			)) {
				throw new \RuntimeException(__('Participation records could not be deleted.', 'paper-to-quiz'));
			}
			$this->delete_rows_by_ids('questions', 'revision_id', $revision_ids);
			if (false === $this->db->wpdb()->delete(
				$this->db->table('revisions'),
				array('assessment_id' => $assessment_id),
				array('%d')
			)) {
				throw new \RuntimeException(__('Assessment revisions could not be deleted.', 'paper-to-quiz'));
			}

			foreach ($asset_counts as $asset_id => $count) {
				$asset = $this->assets->get((int) $asset_id);
				if (! $asset) {
					continue;
				}
				$remaining = max(0, (int) $asset['ref_count'] - $count);
				if ($remaining > 0) {
					if (false === $this->db->wpdb()->update(
						$this->db->table('assets'),
						array('ref_count' => $remaining),
						array('id' => (int) $asset_id),
						array('%d'),
						array('%d')
					)) {
						throw new \RuntimeException(__('File references could not be updated.', 'paper-to-quiz'));
					}
				} else {
					$orphan_assets[] = $asset;
					if (false === $this->db->wpdb()->delete(
						$this->db->table('assets'),
						array('id' => (int) $asset_id),
						array('%d')
					)) {
						throw new \RuntimeException(__('Unused file records could not be deleted.', 'paper-to-quiz'));
					}
				}
			}

			if (1 !== $this->db->wpdb()->delete(
				$this->db->table('assessments'),
				array('id' => $assessment_id, 'status' => 'trash'),
				array('%d', '%s')
			)) {
				throw new \RuntimeException(__('Content could not be permanently deleted.', 'paper-to-quiz'));
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollback();
			return OperationalErrorReporter::report(
				'paper_to_quiz_purge_failed',
				$error,
				__('Content could not be permanently deleted. Please try again.', 'paper-to-quiz'),
				500
			);
		}

		foreach ($attempt_ids as $attempt_id) {
			try {
				wp_clear_scheduled_hook('paper_to_quiz_process_result_emails', array($attempt_id));
			} catch (\Throwable $error) {
				$this->log_cleanup_warning('unschedule_result_email', $attempt_id, $error);
			}
		}
		foreach ($orphan_assets as $asset) {
			try {
				$this->assets->delete_files(array($asset));
			} catch (\Throwable $error) {
				$this->log_cleanup_warning('delete_asset_file', (int) ($asset['id'] ?? 0), $error);
			}
		}
		return $impact;
	}

	private function count_assessment_rows(string $table, string $column, int $assessment_id): int {
		$allowed = array(
			'revisions' => 'assessment_id',
			'attempts'  => 'assessment_id',
		);
		if (! isset($allowed[$table]) || $allowed[$table] !== $column) {
			throw new \InvalidArgumentException('Unsupported assessment count.');
		}
		return (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table($table) . " WHERE {$column} = %d",
				$assessment_id
			)
		);
	}

	private function delete_rows_by_ids(string $table, string $column, array $ids): void {
		$allowed = array(
			'result_email_jobs'     => 'attempt_id',
			'ranking_entries'       => 'attempt_id',
			'attempt_subject_scores'=> 'attempt_id',
			'answers'               => 'attempt_id',
			'questions'             => 'revision_id',
		);
		if (! isset($allowed[$table]) || $allowed[$table] !== $column) {
			throw new \InvalidArgumentException('Unsupported cascading delete.');
		}
		$ids = array_values(array_filter(array_unique(array_map('absint', $ids))));
		if (! $ids) {
			return;
		}
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$deleted = $this->db->wpdb()->query(
			$this->db->wpdb()->prepare(
				'DELETE FROM ' . $this->db->table($table) . " WHERE {$column} IN ({$placeholders})",
				...$ids
			)
		);
		if ($deleted === false) {
			throw new \RuntimeException(esc_html__('Related records could not be deleted.', 'paper-to-quiz'));
		}
	}

	private function log_cleanup_warning(string $operation, int $record_id, \Throwable $error): void {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A committed deletion must not be reported as failed when only best-effort cleanup fails.
			sprintf(
				'[Paper to Quiz cleanup] %s #%d: %s',
				$operation,
				$record_id,
				$error->getMessage()
			)
		);
	}
}
