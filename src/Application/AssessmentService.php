<?php

declare(strict_types=1);

namespace PaperToQuiz\Application;

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\OperationalErrorReporter;
use PaperToQuiz\Infrastructure\Settings;

final class AssessmentService {
	private TermService $terms;
	private AssessmentPurgeService $purge_service;
	private const REVISION_COLUMNS = array(
		'assessment_id',
		'revision_no',
		'lifecycle',
		'title',
		'description',
		'class_id',
		'subject_ids_json',
		'access_mode',
		'options_json',
		'total_points',
		'duration_seconds',
		'window_start_utc',
		'window_end_utc',
		'results_release_at_utc',
		'allow_repeat',
		'ranking_enabled',
		'feedback_timing',
		'result_visibility',
		'participant_fields_json',
		'retention_days',
		'source_asset_id',
		'created_at',
		'published_at',
	);

	public function __construct(
		private readonly Database $db,
		private readonly AssetService $assets,
		?TermService $terms = null,
		?AssessmentPurgeService $purge_service = null
	) {
		$this->terms         = $terms ?? new TermService($db);
		$this->purge_service = $purge_service ?? new AssessmentPurgeService($db, $assets);
	}

	public function option_sets(): array {
		$sets = array(
			'abc'   => array('A', 'B', 'C'),
			'abcd'  => array('A', 'B', 'C', 'D'),
			'abcde' => array('A', 'B', 'C', 'D', 'E'),
		);
		return apply_filters('ptq_answer_option_sets', $sets); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public API required by the plugin contract.
	}

	public function list(
		string $type = '',
		int $page = 1,
		int $per_page = 20,
		string $status = '',
		string $search = '',
		string $orderby = 'updated',
		string $order = 'desc'
	): array {
		$where = $status === 'trash' ? "a.status = 'trash'" : "a.status <> 'trash'";
		$args  = array();
		if (in_array($type, array('exam', 'test'), true)) {
			$where .= ' AND a.type = %s';
			$args[] = $type;
		}
		if (in_array($status, array('draft', 'published', 'archived'), true)) {
			$where .= ' AND a.status = %s';
			$args[] = $status;
		}
		if ($search !== '') {
			$where .= ' AND r.title LIKE %s';
			$args[] = '%' . $this->db->wpdb()->esc_like($search) . '%';
		}

		$offset = max(0, ($page - 1) * $per_page);
		$order_columns = array(
			'title'         => 'r.title',
			'class'         => 'c.name',
			'subject'       => 'subject_sort',
			'questions'     => 'question_count',
			'status'        => 'a.status',
			'participation' => 'participation_count',
			'created'       => 'a.created_at',
			'updated'       => 'a.updated_at',
		);
		$order_column = $order_columns[$orderby] ?? $order_columns['updated'];
		$order_direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
		$sql    = 'SELECT a.*, r.id revision_id, r.title, r.class_id, r.subject_ids_json, r.access_mode, c.name class_name, c.color class_color,
			(a.published_revision_id IS NOT NULL AND a.current_draft_revision_id IS NOT NULL) has_unpublished_changes,
			(SELECT MIN(s.name) FROM ' . $this->db->table('questions') . ' sq LEFT JOIN ' . $this->db->table('terms') . ' s ON s.id = sq.subject_id WHERE sq.revision_id = COALESCE(a.current_draft_revision_id,a.published_revision_id)) subject_sort,
			(SELECT COUNT(*) FROM ' . $this->db->table('questions') . ' q WHERE q.revision_id = COALESCE(a.current_draft_revision_id,a.published_revision_id)) question_count,
			(SELECT COUNT(*) FROM ' . $this->db->table('attempts') . " t WHERE t.assessment_id = a.id AND t.status IN ('submitted','auto_submitted')) participation_count
			FROM " . $this->db->table('assessments') . ' a
			LEFT JOIN ' . $this->db->table('revisions') . ' r ON r.id = COALESCE(a.current_draft_revision_id,a.published_revision_id)
			LEFT JOIN ' . $this->db->table('terms') . ' c ON c.id = r.class_id
			WHERE ' . $where . ' ORDER BY ' . $order_column . ' ' . $order_direction . ', a.id DESC LIMIT %d OFFSET %d';
		$args[] = $per_page;
		$args[] = $offset;
		$rows   = $this->db->wpdb()->get_results($this->db->wpdb()->prepare($sql, ...$args), ARRAY_A) ?: array();
		foreach ($rows as &$row) {
			$subject_ids = $this->sanitize_subject_ids(json_decode((string) ($row['subject_ids_json'] ?? ''), true));
			if (! $subject_ids && ! empty($row['revision_id'])) {
				$subject_ids = array_map(
					'intval',
					$this->db->wpdb()->get_col(
						$this->db->wpdb()->prepare(
							'SELECT DISTINCT subject_id FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d AND subject_id IS NOT NULL ORDER BY subject_id ASC',
							(int) $row['revision_id']
						)
					) ?: array()
				);
			}
			$row['subject_names'] = $this->subject_names($subject_ids);
			unset($row['subject_ids_json'], $row['subject_sort']);
		}
		unset($row);

		$count_sql  = 'SELECT COUNT(*) FROM ' . $this->db->table('assessments') . ' a
			LEFT JOIN ' . $this->db->table('revisions') . ' r ON r.id = COALESCE(a.current_draft_revision_id,a.published_revision_id)
			WHERE ' . $where;
		$count_args = array_slice($args, 0, -2);
		$total      = $count_args
			? (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare($count_sql, ...$count_args))
			: (int) $this->db->wpdb()->get_var($count_sql);

		$count_where = '1=1';
		$count_type_args = array();
		if (in_array($type, array('exam', 'test'), true)) {
			$count_where .= ' AND type = %s';
			$count_type_args[] = $type;
		}
		$status_sql = 'SELECT status, COUNT(*) count FROM ' . $this->db->table('assessments') .
			' WHERE ' . $count_where . ' GROUP BY status';
		$status_rows = $count_type_args
			? $this->db->wpdb()->get_results($this->db->wpdb()->prepare($status_sql, ...$count_type_args), ARRAY_A)
			: $this->db->wpdb()->get_results($status_sql, ARRAY_A);
		$counts = array('all' => 0, 'draft' => 0, 'published' => 0, 'archived' => 0, 'trash' => 0);
		foreach ($status_rows ?: array() as $status_row) {
			$key = (string) $status_row['status'];
			if (array_key_exists($key, $counts)) {
				$counts[$key] = (int) $status_row['count'];
				if ($key !== 'trash') {
					$counts['all'] += (int) $status_row['count'];
				}
			}
		}

		return array(
			'items' => $rows ?: array(),
			'total' => $total,
			'pages' => (int) ceil($total / max(1, $per_page)),
			'page'  => $page,
			'counts'=> $counts,
		);
	}

	public function get(int $assessment_id, bool $published = false): ?array {
		$assessment = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('assessments') . ' WHERE id = %d',
				$assessment_id
			),
			ARRAY_A
		);
		if (! $assessment) {
			return null;
		}

		$revision_id = $published
			? (int) $assessment['published_revision_id']
			: (int) ($assessment['current_draft_revision_id'] ?: $assessment['published_revision_id']);

		$revision  = $revision_id ? $this->get_revision($revision_id) : null;
		$questions = $revision_id ? $this->questions($revision_id, true) : array();

		return array(
			'assessment' => $assessment,
			'revision'   => $revision,
			'questions'  => $questions,
		);
	}

	public function get_revision(int $revision_id): ?array {
		$row = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT r.*, c.name class_name, c.color class_color FROM ' . $this->db->table('revisions') . ' r
				LEFT JOIN ' . $this->db->table('terms') . ' c ON c.id = r.class_id
				WHERE r.id = %d',
				$revision_id
			),
			ARRAY_A
		);
		if (! is_array($row)) {
			return null;
		}

		$row['options']            = json_decode((string) $row['options_json'], true) ?: array();
		$row['participant_fields'] = json_decode((string) $row['participant_fields_json'], true) ?: array();
		$row['subject_ids']         = $this->sanitize_subject_ids(
			json_decode((string) ($row['subject_ids_json'] ?? ''), true)
		);
		if (! $row['subject_ids']) {
			$row['subject_ids'] = array_map(
				'intval',
				$this->db->wpdb()->get_col(
					$this->db->wpdb()->prepare(
						'SELECT DISTINCT subject_id FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d AND subject_id IS NOT NULL ORDER BY subject_id ASC',
						$revision_id
					)
				) ?: array()
			);
		}
		$row['subject_names'] = $this->subject_names($row['subject_ids']);
		return $row;
	}

	public function questions(int $revision_id, bool $include_key): array {
		$columns = $include_key
			? 'q.*,s.name subject_name,s.status subject_status'
			: 'q.id,q.revision_id,q.ordinal,q.source_page,q.crop_x,q.crop_y,q.crop_width,q.crop_height,q.source_rotation,q.main_asset_id,q.thumb_asset_id,q.subject_id,q.points,s.name subject_name';
		$rows    = $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				"SELECT {$columns} FROM " . $this->db->table('questions') . ' q
				LEFT JOIN ' . $this->db->table('terms') . " s ON s.id = q.subject_id AND s.type = 'subject'
				WHERE q.revision_id = %d ORDER BY q.ordinal ASC",
				$revision_id
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	public function save(array $payload, ?int $assessment_id, int $user_id): array|\WP_Error {
		$type = sanitize_key((string) ($payload['type'] ?? 'test'));
		if (! in_array($type, array('exam', 'test'), true)) {
			return new \WP_Error('ptq_invalid_type', __('Invalid assessment type.', 'paper-to-quiz'), array('status' => 400));
		}
		if (! $assessment_id) {
			$policy_error = $this->validate_policy_payload($payload, $type);
			if (is_wp_error($policy_error)) {
				return $policy_error;
			}
			$payload = $this->normalize_payload_for_type($payload, $type);
		}

		$title = sanitize_text_field((string) ($payload['title'] ?? ''));
		if ($title === '') {
			return new \WP_Error('ptq_title_required', __('Title is required.', 'paper-to-quiz'), array('status' => 400));
		}
		if (! $assessment_id) {
			$subject_error = $this->validate_subject_selection($payload);
			if (is_wp_error($subject_error)) {
				return $subject_error;
			}
			$payload['subject_ids'] = $this->sanitize_subject_ids($payload['subject_ids'] ?? array());
		}

		$now = current_time('mysql', true);
		if (! $assessment_id) {
			$this->db->wpdb()->insert(
				$this->db->table('assessments'),
				array(
					'type'       => $type,
					'status'     => 'draft',
					'created_by' => $user_id,
					'updated_by' => $user_id,
					'created_at' => $now,
					'updated_at' => $now,
				),
				array('%s', '%s', '%d', '%d', '%s', '%s')
			);
			$assessment_id = (int) $this->db->wpdb()->insert_id;
			$revision_id   = $this->insert_revision($assessment_id, 1, $payload, $now);
			$this->db->wpdb()->update(
				$this->db->table('assessments'),
				array('current_draft_revision_id' => $revision_id),
				array('id' => $assessment_id),
				array('%d'),
				array('%d')
			);
		} else {
			$record = $this->get($assessment_id);
			if (! $record) {
				return new \WP_Error('ptq_not_found', __('Record not found.', 'paper-to-quiz'), array('status' => 404));
			}
			$type = (string) $record['assessment']['type'];
			if (! array_key_exists('subject_ids', $payload)) {
				$payload['subject_ids'] = $record['revision']['subject_ids'] ?? array();
			}
			$subject_error = $this->validate_subject_selection($payload);
			if (is_wp_error($subject_error)) {
				return $subject_error;
			}
			$payload['subject_ids'] = $this->sanitize_subject_ids($payload['subject_ids']);
			$policy_error = $this->validate_policy_payload($payload, $type);
			if (is_wp_error($policy_error)) {
				return $policy_error;
			}
			$payload = $this->normalize_payload_for_type($payload, $type);

			$revision_id = (int) $record['assessment']['current_draft_revision_id'];
			if (! $revision_id) {
				$revision_id = $this->clone_published_to_draft($record);
				if (is_wp_error($revision_id)) {
					return $revision_id;
				}
			}

			$data = $this->revision_payload($payload);
			unset($data['assessment_id'], $data['revision_no'], $data['lifecycle'], $data['created_at']);
			if (false === $this->db->wpdb()->update($this->db->table('revisions'), $data, array('id' => $revision_id))) {
				return new \WP_Error('ptq_revision_save_failed', __('Draft details could not be saved.', 'paper-to-quiz'), array('status' => 500));
			}
			$assessment_status = ! empty($record['assessment']['published_revision_id']) ? 'published' : 'draft';
			if (false === $this->db->wpdb()->update(
				$this->db->table('assessments'),
				array(
					'status'     => $assessment_status,
					'updated_by' => $user_id,
					'updated_at' => $now,
				),
				array('id' => $assessment_id)
			)) {
				return new \WP_Error('ptq_assessment_save_failed', __('Record could not be updated.', 'paper-to-quiz'), array('status' => 500));
			}
		}

		return $this->get($assessment_id) ?: array();
	}

	public function set_source_asset(int $assessment_id, int $asset_id, ?string $question_strategy = null): array|\WP_Error {
		$record = $this->get($assessment_id);
		if (! $record || ! $record['revision']) {
			return new \WP_Error('ptq_not_found', __('Draft not found.', 'paper-to-quiz'), array('status' => 404));
		}
		if ($record['revision']['lifecycle'] !== 'draft') {
			return new \WP_Error('ptq_immutable', __('Published revisions cannot be changed.', 'paper-to-quiz'), array('status' => 409));
		}

		$old       = (int) $record['revision']['source_asset_id'];
		$questions = $record['questions'];
		if ($old && $old !== $asset_id && $questions && ! in_array($question_strategy, array('preserve', 'clear'), true)) {
			return new \WP_Error(
				'ptq_pdf_question_strategy_required',
				__('Specify whether existing questions should be kept or cleared when replacing the PDF.', 'paper-to-quiz'),
				array('status' => 409)
			);
		}

		$assets_to_release = array();
		$this->db->begin();
		try {
			if (false === $this->db->wpdb()->update(
				$this->db->table('revisions'),
				array('source_asset_id' => $asset_id),
				array('id' => (int) $record['revision']['id']),
				array('%d'),
				array('%d')
			)) {
				throw new \RuntimeException(__('The PDF could not be attached to the draft.', 'paper-to-quiz'));
			}
			if ($old && $old !== $asset_id) {
				if ($question_strategy === 'clear') {
					foreach ($questions as $question) {
						$assets_to_release[] = (int) $question['main_asset_id'];
						$assets_to_release[] = (int) $question['thumb_asset_id'];
					}
					if (false === $this->db->wpdb()->delete(
						$this->db->table('questions'),
						array('revision_id' => (int) $record['revision']['id']),
						array('%d')
					)) {
						throw new \RuntimeException(__('Draft questions could not be cleared.', 'paper-to-quiz'));
					}
				} elseif ($question_strategy === 'preserve') {
					foreach ($questions as $question) {
						$assets_to_release[] = (int) $question['main_asset_id'];
						$assets_to_release[] = (int) $question['thumb_asset_id'];
					}
					if (false === $this->db->wpdb()->query(
						$this->db->wpdb()->prepare(
							'UPDATE ' . $this->db->table('questions') . ' SET main_asset_id = NULL, thumb_asset_id = NULL, updated_at = %s WHERE revision_id = %d',
							current_time('mysql', true),
							(int) $record['revision']['id']
						)
					)) {
						throw new \RuntimeException(__('Questions could not be prepared for regeneration.', 'paper-to-quiz'));
					}
				}
				$assets_to_release[] = $old;
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollback();
			return OperationalErrorReporter::report(
				'ptq_pdf_replace_failed',
				$error,
				__('The PDF could not be replaced. Please try again.', 'paper-to-quiz'),
				500
			);
		}
		foreach ($assets_to_release as $released_asset_id) {
			$this->assets->release($released_asset_id);
		}
		return $this->get($assessment_id) ?: array();
	}

	public function save_question(
		int $revision_id,
		array $metadata,
		?int $main_asset_id,
		?int $thumb_asset_id,
		?int $question_id = null
	): array|\WP_Error {
		$revision = $this->get_revision($revision_id);
		if (! $revision || $revision['lifecycle'] !== 'draft') {
			return new \WP_Error('ptq_immutable', __('Only draft questions can be edited.', 'paper-to-quiz'), array('status' => 409));
		}

		$crop = $metadata['crop'] ?? array();
		foreach (array('x', 'y', 'width', 'height') as $key) {
			if (! isset($crop[$key]) || ! is_numeric($crop[$key])) {
				return new \WP_Error('ptq_invalid_crop', __('Crop coordinates are invalid.', 'paper-to-quiz'), array('status' => 400));
			}
		}

		$requested_ordinal = max(1, (int) ($metadata['ordinal'] ?? 1));
		$client_key       = sanitize_text_field((string) ($metadata['client_key'] ?? ''));
		if ($client_key !== '' && ! wp_is_uuid($client_key)) {
			return new \WP_Error('ptq_question_client_key', __('The question record key is invalid.', 'paper-to-quiz'), array('status' => 400));
		}

		if (! $question_id && $client_key !== '') {
			$question_id = (int) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT id FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d AND client_key = %s',
					$revision_id,
					$client_key
				)
			);
		}

		$data = array(
			'revision_id'     => $revision_id,
			'source_page'     => max(1, (int) ($metadata['page'] ?? 1)),
			'crop_x'          => max(0, min(1, (float) $crop['x'])),
			'crop_y'          => max(0, min(1, (float) $crop['y'])),
			'crop_width'      => max(0.0001, min(1, (float) $crop['width'])),
			'crop_height'     => max(0.0001, min(1, (float) $crop['height'])),
			'source_rotation' => (int) ($metadata['rotation'] ?? 0),
			'subject_id'      => ! empty($metadata['subject_id']) ? (int) $metadata['subject_id'] : null,
			'updated_at'      => current_time('mysql', true),
		);
		if ($client_key !== '') {
			$data['client_key'] = $client_key;
		}
		if ($main_asset_id) {
			$data['main_asset_id'] = $main_asset_id;
		}
		if ($thumb_asset_id) {
			$data['thumb_asset_id'] = $thumb_asset_id;
		}

		if ($question_id) {
			$existing = $this->db->wpdb()->get_row(
				$this->db->wpdb()->prepare(
					'SELECT * FROM ' . $this->db->table('questions') . ' WHERE id = %d AND revision_id = %d',
					$question_id,
					$revision_id
				),
				ARRAY_A
			);
			if (! $existing) {
				return new \WP_Error('ptq_question_not_found', __('Question not found.', 'paper-to-quiz'), array('status' => 404));
			}
			$data['ordinal'] = (int) $existing['ordinal'] === $requested_ordinal
				? $requested_ordinal
				: $this->next_temporary_ordinal($revision_id);
			if (false === $this->db->wpdb()->update($this->db->table('questions'), $data, array('id' => $question_id))) {
				return new \WP_Error('ptq_question_save', __('Question could not be saved.', 'paper-to-quiz'), array('status' => 500));
			}
			if ($main_asset_id && (int) $existing['main_asset_id'] !== $main_asset_id) {
				$this->assets->release((int) $existing['main_asset_id']);
			}
			if ($thumb_asset_id && (int) $existing['thumb_asset_id'] !== $thumb_asset_id) {
				$this->assets->release((int) $existing['thumb_asset_id']);
			}
		} else {
			$data['ordinal']    = $this->next_temporary_ordinal($revision_id);
			$data['created_at'] = current_time('mysql', true);
			$data['points']     = 0;
			if (false === $this->db->wpdb()->insert($this->db->table('questions'), $data)) {
				if ($client_key !== '') {
					$duplicate_id = (int) $this->db->wpdb()->get_var(
						$this->db->wpdb()->prepare(
							'SELECT id FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d AND client_key = %s',
							$revision_id,
							$client_key
						)
					);
					if ($duplicate_id) {
						return $this->save_question(
							$revision_id,
							$metadata,
							$main_asset_id,
							$thumb_asset_id,
							$duplicate_id
						);
					}
				}
				return new \WP_Error('ptq_question_save', __('Question could not be saved.', 'paper-to-quiz'), array('status' => 500));
			}
			$question_id = (int) $this->db->wpdb()->insert_id;
		}

		return $this->question($question_id) ?: array();
	}

	public function update_answer_key(int $revision_id, array $items, bool $prune_missing = false): array|\WP_Error {
		$revision = $this->get_revision($revision_id);
		if (! $revision || $revision['lifecycle'] !== 'draft') {
			return new \WP_Error('ptq_immutable', __('Only draft answer keys can be edited.', 'paper-to-quiz'), array('status' => 409));
		}

		$options = $revision['options'];
		$item_ids = array_map(
			static fn (array $item): int => (int) ($item['id'] ?? 0),
			array_values($items)
		);
		$unique_item_ids = array_values(array_unique($item_ids));
		if (count($item_ids) !== count($unique_item_ids)) {
			return new \WP_Error(
				'ptq_answer_key_questions',
				__('The answer key must include every draft question exactly once.', 'paper-to-quiz'),
				array('status' => 400)
			);
		}

		$pruned_assets = array();
		$this->db->begin();
		try {
			$existing_rows = $this->db->wpdb()->get_results(
				$this->db->wpdb()->prepare(
					'SELECT id,main_asset_id,thumb_asset_id FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d',
					$revision_id
				),
				ARRAY_A
			) ?: array();

			if ($prune_missing) {
				foreach ($existing_rows as $row) {
					if (in_array((int) $row['id'], $unique_item_ids, true)) {
						continue;
					}
					if (false === $this->db->wpdb()->delete(
						$this->db->table('questions'),
						array('id' => (int) $row['id'], 'revision_id' => $revision_id),
						array('%d', '%d')
					)) {
						throw new \RuntimeException(__('Previous question records could not be cleared.', 'paper-to-quiz'));
					}
					$pruned_assets[] = array(
						(int) $row['main_asset_id'],
						(int) $row['thumb_asset_id'],
					);
				}
			}

			$existing_ids = array_map(
				'intval',
				$this->db->wpdb()->get_col(
					$this->db->wpdb()->prepare(
						'SELECT id FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d',
						$revision_id
					)
				)
			);
			sort($existing_ids);
			sort($unique_item_ids);
			if ($existing_ids !== $unique_item_ids) {
				throw new \InvalidArgumentException(
					__('The answer key must include every draft question exactly once.', 'paper-to-quiz')
				);
			}

			$temporary_base = $this->next_temporary_ordinal($revision_id) + count($items);
			foreach (array_values($items) as $index => $item) {
				if (false === $this->db->wpdb()->update(
					$this->db->table('questions'),
					array('ordinal' => $temporary_base + $index),
					array(
						'id'          => (int) $item['id'],
						'revision_id' => $revision_id,
					)
				)) {
					throw new \RuntimeException(__('Question order could not be prepared.', 'paper-to-quiz'));
				}
			}

			foreach (array_values($items) as $index => $item) {
				$question_id = (int) ($item['id'] ?? 0);
				$correct     = sanitize_key((string) ($item['correct_option'] ?? ''));
				$points      = max(0, (int) ($item['points'] ?? 0));
				if ($correct !== '' && ! in_array(strtoupper($correct), $options, true)) {
					throw new \InvalidArgumentException(__('The correct answer is not one of the available options.', 'paper-to-quiz'));
				}
				if (false === $this->db->wpdb()->update(
					$this->db->table('questions'),
					array(
						'ordinal'       => $index + 1,
						'correct_option' => strtoupper($correct),
						'points'        => $points,
						'updated_at'    => current_time('mysql', true),
					),
					array(
						'id'          => $question_id,
						'revision_id' => $revision_id,
					)
				)) {
					throw new \RuntimeException(__('The answer key could not be saved.', 'paper-to-quiz'));
				}
			}
			$this->db->commit();
		} catch (\InvalidArgumentException $error) {
			$this->db->rollback();
			$validation_message = $error->getMessage();
			return new \WP_Error('ptq_answer_key_failed', $validation_message, array('status' => 400));
		} catch (\Throwable $error) {
			$this->db->rollback();
			return OperationalErrorReporter::report(
				'ptq_answer_key_failed',
				$error,
				__('The answer key could not be saved. Please try again.', 'paper-to-quiz'),
				500
			);
		}

		foreach ($pruned_assets as $asset_pair) {
			$this->assets->release($asset_pair[0]);
			$this->assets->release($asset_pair[1]);
		}

		return $this->questions($revision_id, true);
	}

	public function delete_question(int $question_id): bool|\WP_Error {
		$question = $this->question($question_id);
		if (! $question) {
			return new \WP_Error('ptq_not_found', __('Question not found.', 'paper-to-quiz'), array('status' => 404));
		}
		$revision = $this->get_revision((int) $question['revision_id']);
		if (! $revision || $revision['lifecycle'] !== 'draft') {
			return new \WP_Error('ptq_immutable', __('Published questions cannot be deleted.', 'paper-to-quiz'), array('status' => 409));
		}

		$this->db->wpdb()->delete($this->db->table('questions'), array('id' => $question_id), array('%d'));
		$this->assets->release((int) $question['main_asset_id']);
		$this->assets->release((int) $question['thumb_asset_id']);

		$remaining = $this->questions((int) $question['revision_id'], true);
		foreach ($remaining as $index => $item) {
			$this->db->wpdb()->update(
				$this->db->table('questions'),
				array('ordinal' => $index + 1),
				array('id' => (int) $item['id']),
				array('%d'),
				array('%d')
			);
		}
		return true;
	}

	public function publish(int $assessment_id): array|\WP_Error {
		$record = $this->get($assessment_id);
		if (! $record || ! $record['revision']) {
			return new \WP_Error('ptq_not_found', __('Record not found.', 'paper-to-quiz'), array('status' => 404));
		}

		$errors = $this->validate_publish($record);
		if ($errors) {
			return new \WP_Error(
				'ptq_publish_validation',
				__('Publishing checks are incomplete.', 'paper-to-quiz'),
				array('status' => 422, 'errors' => $errors)
			);
		}

		$revision_id = (int) $record['revision']['id'];
		$now         = current_time('mysql', true);
		$this->db->begin();
		try {
			if (1 !== $this->db->wpdb()->update(
				$this->db->table('revisions'),
				array('lifecycle' => 'published', 'published_at' => $now),
				array('id' => $revision_id),
				array('%s', '%s'),
				array('%d')
			)) {
				throw new \RuntimeException('Revision publish update failed.');
			}
			if (1 !== $this->db->wpdb()->update(
				$this->db->table('assessments'),
				array(
					'status'                    => 'published',
					'published_revision_id'     => $revision_id,
					'current_draft_revision_id' => null,
					'updated_at'                => $now,
				),
				array('id' => $assessment_id)
			)) {
				throw new \RuntimeException('Assessment publish pointer update failed.');
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollback();
			return OperationalErrorReporter::report(
				'ptq_publish_failed',
				$error,
				__('Publishing could not be completed.', 'paper-to-quiz'),
				500
			);
		}

		do_action('ptq_assessment_published', $assessment_id, $revision_id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public API required by the plugin contract.
		return $this->get($assessment_id, true) ?: array();
	}

	public function duplicate(int $assessment_id, int $user_id): array|\WP_Error {
		$source = $this->get($assessment_id);
		if (! $source || ! $source['revision']) {
			return new \WP_Error('ptq_not_found', __('The record to duplicate could not be found.', 'paper-to-quiz'), array('status' => 404));
		}

		$payload = array(
			'type'                 => (string) $source['assessment']['type'],
			'title'                => (string) $source['revision']['title'],
			'description'          => (string) $source['revision']['description'],
			'class_id'             => $source['revision']['class_id'],
			'subject_ids'          => $source['revision']['subject_ids'] ?? array(),
			'access_mode'          => $source['revision']['access_mode'],
			'options'              => $source['revision']['options'],
			'total_points'         => (int) $source['revision']['total_points'],
			'duration_seconds'     => $source['revision']['duration_seconds'],
			'window_start_utc'     => $source['revision']['window_start_utc'],
			'window_end_utc'       => $source['revision']['window_end_utc'],
			'results_release_at_utc' => $source['revision']['results_release_at_utc'],
			'allow_repeat'         => (bool) $source['revision']['allow_repeat'],
			'ranking_enabled'      => (bool) $source['revision']['ranking_enabled'],
			'feedback_timing'      => $source['revision']['feedback_timing'],
			'result_visibility'    => $source['revision']['result_visibility'],
			'participant_fields'   => $source['revision']['participant_fields'],
		);
		/* translators: %s: Original assessment title. */
		$payload['title'] = sprintf(__('%s (Copy)', 'paper-to-quiz'), $payload['title']);
		$created          = $this->save($payload, null, $user_id);
		if (is_wp_error($created)) {
			return $created;
		}

		return $this->get((int) $created['assessment']['id']) ?: array();
	}

	public function trash(int $assessment_id): bool {
		return false !== $this->db->wpdb()->update(
			$this->db->table('assessments'),
			array('status' => 'trash', 'deleted_at' => current_time('mysql', true)),
			array('id' => $assessment_id)
		);
	}

	public function restore(int $assessment_id): bool {
		$published_revision_id = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT published_revision_id FROM ' . $this->db->table('assessments') . ' WHERE id = %d AND status = %s',
				$assessment_id,
				'trash'
			)
		);
		return false !== $this->db->wpdb()->update(
			$this->db->table('assessments'),
			array(
				'status'     => $published_revision_id ? 'published' : 'draft',
				'deleted_at' => null,
				'updated_at' => current_time('mysql', true),
			),
			array('id' => $assessment_id, 'status' => 'trash')
		);
	}

	public function purge(int $assessment_id): array|\WP_Error {
		return $this->purge_service->purge($assessment_id);
	}

	// Term management has been extracted to TermService. The four delegating
	// methods below remain so legacy callers (e.g. the data-regression harness)
	// that construct AssessmentService directly keep working without behavior
	// change. Production routes call TermService via AdminController.

	public function save_class(string $name, ?int $id = null, ?string $color = null): array|\WP_Error {
		return $this->terms->save_class($name, $id, $color);
	}

	public function trash_class(int $id): bool {
		return $this->terms->trash_class($id);
	}

	public function save_subject(string $name, ?int $id = null): array|\WP_Error {
		return $this->terms->save_subject($name, $id);
	}

	public function trash_subject(int $id): bool {
		return $this->terms->trash_subject($id);
	}

	private function insert_revision(int $assessment_id, int $revision_no, array $payload, string $now): int {
		$data                  = $this->revision_payload($payload);
		$data['assessment_id'] = $assessment_id;
		$data['revision_no']   = $revision_no;
		$data['lifecycle']     = 'draft';
		$data['created_at']    = $now;
		$this->db->wpdb()->insert($this->db->table('revisions'), $data);
		return (int) $this->db->wpdb()->insert_id;
	}

	private function revision_payload(array $payload): array {
		$access  = sanitize_key((string) ($payload['access_mode'] ?? 'guest_allowed'));
		$ranking = ! empty($payload['ranking_enabled']);
		$subject_ids = $this->sanitize_subject_ids($payload['subject_ids'] ?? array());
		$options = $payload['options'] ?? json_decode((string) ($payload['options_json'] ?? '[]'), true);
		if (! is_array($options) || count($options) < 2) {
			$options = array('A', 'B', 'C', 'D');
		}
		$options = array_values(array_unique(array_map(static fn ($value): string => strtoupper(sanitize_key((string) $value)), $options)));

		return array(
			'title'                    => sanitize_text_field((string) ($payload['title'] ?? '')),
			'description'              => wp_kses_post((string) ($payload['description'] ?? '')),
			'class_id'                 => ! empty($payload['class_id']) ? (int) $payload['class_id'] : null,
			'subject_ids_json'         => wp_json_encode($subject_ids),
			'access_mode'              => in_array($access, array('guest_allowed', 'login_required'), true) ? $access : 'guest_allowed',
			'options_json'              => wp_json_encode($options),
			'total_points'             => max(1, (int) ($payload['total_points'] ?? 10000)),
			'duration_seconds'          => ! empty($payload['duration_seconds']) ? max(60, (int) $payload['duration_seconds']) : null,
			'window_start_utc'          => $this->date_or_null($payload['window_start_utc'] ?? null),
			'window_end_utc'            => $this->date_or_null($payload['window_end_utc'] ?? null),
			'results_release_at_utc'    => $this->date_or_null($payload['results_release_at_utc'] ?? null),
			'allow_repeat'              => ! empty($payload['allow_repeat']) ? 1 : 0,
			'ranking_enabled'           => $ranking ? 1 : 0,
			'feedback_timing'           => $this->enum($payload['feedback_timing'] ?? 'after_submit', array('never', 'immediate', 'after_submit', 'scheduled'), 'after_submit'),
			'result_visibility'         => $this->enum($payload['result_visibility'] ?? 'summary', array('hidden', 'score_only', 'summary', 'detailed'), 'summary'),
			'participant_fields_json'   => wp_json_encode($this->sanitize_participant_fields($payload['participant_fields'] ?? array())),
			'retention_days'            => max(1, min(3650, (int) (Settings::get()['retention_days'] ?? 365))),
		);
	}

	private function clone_published_to_draft(array $record): int|\WP_Error {
		$source = $record['revision'];
		if (! $source || $source['lifecycle'] !== 'published') {
			return new \WP_Error('ptq_published_revision_missing', __('The published revision to duplicate could not be found.', 'paper-to-quiz'), array('status' => 409));
		}

		$assessment_id = (int) $record['assessment']['id'];
		$this->db->begin();
		try {
			$locked = $this->db->wpdb()->get_row(
				$this->db->wpdb()->prepare(
					'SELECT current_draft_revision_id,published_revision_id FROM ' . $this->db->table('assessments') . ' WHERE id = %d FOR UPDATE',
					$assessment_id
				),
				ARRAY_A
			);
			if (! $locked) {
				throw new \RuntimeException(__('The record could not be locked.', 'paper-to-quiz'));
			}
			if (! empty($locked['current_draft_revision_id'])) {
				$this->db->commit();
				return (int) $locked['current_draft_revision_id'];
			}
			if ((int) $locked['published_revision_id'] !== (int) $source['id']) {
				throw new \RuntimeException(__('The published revision changed. Refresh the page and try again.', 'paper-to-quiz'));
			}

			$data = array();
			foreach (self::REVISION_COLUMNS as $column) {
				if (array_key_exists($column, $source)) {
					$data[$column] = $source[$column];
				}
			}
			$data['revision_no']  = (int) $source['revision_no'] + 1;
			$data['lifecycle']    = 'draft';
			$data['created_at']   = current_time('mysql', true);
			$data['published_at'] = null;
			unset($data['id'], $data['options'], $data['participant_fields']);
			if (false === $this->db->wpdb()->insert($this->db->table('revisions'), $data)) {
				throw new \RuntimeException(__('The draft revision could not be created.', 'paper-to-quiz'));
			}
			$new_revision_id = (int) $this->db->wpdb()->insert_id;
			$this->retain_asset_or_throw((int) $source['source_asset_id']);

			$question_columns = array(
				'client_key',
				'ordinal',
				'source_page',
				'crop_x',
				'crop_y',
				'crop_width',
				'crop_height',
				'source_rotation',
				'main_asset_id',
				'thumb_asset_id',
				'subject_id',
				'correct_option',
				'points',
				'created_at',
				'updated_at',
			);
			foreach ($record['questions'] as $question) {
				$copy = array('revision_id' => $new_revision_id);
				foreach ($question_columns as $column) {
					if (array_key_exists($column, $question)) {
						$copy[$column] = $question[$column];
					}
				}
				if (empty($copy['client_key'])) {
					$copy['client_key'] = wp_generate_uuid4();
				}
				if (false === $this->db->wpdb()->insert($this->db->table('questions'), $copy)) {
					throw new \RuntimeException(__('Published questions could not be copied to the draft.', 'paper-to-quiz'));
				}
				$this->retain_asset_or_throw((int) $question['main_asset_id']);
				$this->retain_asset_or_throw((int) $question['thumb_asset_id']);
			}

			if (1 !== $this->db->wpdb()->update(
				$this->db->table('assessments'),
				array('current_draft_revision_id' => $new_revision_id, 'status' => 'published'),
				array('id' => $assessment_id, 'current_draft_revision_id' => null),
				array('%d', '%s'),
				array('%d', '%d')
			)) {
				throw new \RuntimeException(__('The draft revision could not be attached to the record.', 'paper-to-quiz'));
			}
			$this->db->commit();
			return $new_revision_id;
		} catch (\Throwable $error) {
			$this->db->rollback();
			return OperationalErrorReporter::report(
				'ptq_draft_clone_failed',
				$error,
				__('The draft revision could not be created. Please try again.', 'paper-to-quiz'),
				500
			);
		}
	}

	private function retain_asset_or_throw(int $asset_id): void {
		if (! $asset_id) {
			return;
		}
		$updated = $this->db->wpdb()->query(
			$this->db->wpdb()->prepare(
				'UPDATE ' . $this->db->table('assets') . ' SET ref_count = ref_count + 1 WHERE id = %d',
				$asset_id
			)
		);
		if ($updated !== 1) {
			throw new \RuntimeException(esc_html__('The question image reference could not be preserved.', 'paper-to-quiz'));
		}
	}

	private function validate_publish(array $record): array {
		$revision  = $record['revision'];
		$questions = $record['questions'];
		$errors    = array();

		if (trim((string) $revision['title']) === '') {
			$errors[] = __('Title is required.', 'paper-to-quiz');
		}
		if (empty($revision['class_id'])) {
			$errors[] = __('A class must be selected.', 'paper-to-quiz');
		}
		$selected_subject_ids = $this->sanitize_subject_ids($revision['subject_ids'] ?? array());
		if (! $selected_subject_ids) {
			$errors[] = __('Select at least one subject.', 'paper-to-quiz');
		}
		if (empty($revision['source_asset_id'])) {
			$errors[] = __('A PDF must be uploaded.', 'paper-to-quiz');
		}
		if (! $questions) {
			$errors[] = __('Select at least one question.', 'paper-to-quiz');
		}
		if (
			! empty($revision['ranking_enabled']) &&
			($revision['access_mode'] !== 'login_required' || ! empty($revision['allow_repeat']))
		) {
			$errors[] = __('Ranking can be used only for membership-required, non-repeatable exams.', 'paper-to-quiz');
		}

		$total = 0;
		foreach ($questions as $index => $question) {
			$number = $index + 1;
			if ((int) $question['ordinal'] !== $number) {
				/* translators: %d: Expected question position. */
				$errors[] = sprintf(__('Question order has a gap at position %d.', 'paper-to-quiz'), $number);
			}
			if (empty($question['main_asset_id'])) {
				/* translators: %d: Question number. */
				$errors[] = sprintf(__('Question %d has no image.', 'paper-to-quiz'), $number);
			}
			if (empty($question['thumb_asset_id'])) {
				/* translators: %d: Question number. */
				$errors[] = sprintf(__('Question %d has no thumbnail image.', 'paper-to-quiz'), $number);
			}
			if (empty($question['subject_id'])) {
				/* translators: %d: Question number. */
				$errors[] = sprintf(__('Select a subject for question %d.', 'paper-to-quiz'), $number);
			} else {
				$valid_subject = (int) $this->db->wpdb()->get_var(
					$this->db->wpdb()->prepare(
						"SELECT COUNT(*) FROM " . $this->db->table('terms') . " WHERE id = %d AND type = 'subject' AND status IN ('active','archived')",
						(int) $question['subject_id']
					)
				);
				if (! $valid_subject) {
					/* translators: %d: Question number. */
					$errors[] = sprintf(__('The subject record for question %d is invalid.', 'paper-to-quiz'), $number);
				} elseif (! in_array((int) $question['subject_id'], $selected_subject_ids, true)) {
					/* translators: %d: Question number. */
					$errors[] = sprintf(__('Question %d uses a subject that is not selected in Basic Information.', 'paper-to-quiz'), $number);
				}
			}
			if (! in_array((string) $question['correct_option'], $revision['options'], true)) {
				/* translators: %d: Question number. */
				$errors[] = sprintf(__('The correct answer for question %d is invalid.', 'paper-to-quiz'), $number);
			}
			$total += (int) $question['points'];
		}
		if ($total !== (int) $revision['total_points']) {
			$errors[] = sprintf(
				/* translators: 1: Current question point total, 2: Required assessment point total. */
				__('Question points total is %1$s; required total is %2$s.', 'paper-to-quiz'),
				number_format_i18n($total / 100, 2),
				number_format_i18n(((int) $revision['total_points']) / 100, 2)
			);
		}
		if ($record['assessment']['type'] === 'exam') {
			if (! empty($revision['allow_repeat'])) {
				if (
					$revision['window_start_utc'] ||
					$revision['window_end_utc'] ||
					$revision['results_release_at_utc']
				) {
					$errors[] = __('Repeatable exams cannot use a schedule or result release date.', 'paper-to-quiz');
				}
				if (! empty($revision['ranking_enabled'])) {
					$errors[] = __('Repeatable exams cannot use ranking.', 'paper-to-quiz');
				}
			} else {
				if (empty($revision['window_start_utc']) || empty($revision['window_end_utc'])) {
					$errors[] = __('Exam start and end dates are required.', 'paper-to-quiz');
				} elseif ($revision['window_start_utc'] >= $revision['window_end_utc']) {
					$errors[] = __('The exam end date must be after the start date.', 'paper-to-quiz');
				} elseif (strtotime((string) $revision['window_end_utc'] . ' UTC') <= time()) {
					$errors[] = __('An exam with a past end date cannot be published. Select a new date.', 'paper-to-quiz');
				}
				if ($revision['results_release_at_utc'] && $revision['window_end_utc'] && $revision['results_release_at_utc'] < $revision['window_end_utc']) {
					$errors[] = __('The result release date cannot be before the exam end date.', 'paper-to-quiz');
				}
			}
			if ($revision['results_release_at_utc'] && $revision['feedback_timing'] !== 'scheduled') {
				$errors[] = __('When a result release date is set, correct answers can only be shown on that date.', 'paper-to-quiz');
			}
		}
		if ($revision['feedback_timing'] === 'scheduled' && empty($revision['results_release_at_utc'])) {
			$errors[] = __('A result release date is required for scheduled feedback.', 'paper-to-quiz');
		}

		return $errors;
	}

	private function normalize_payload_for_type(array $payload, string $type): array {
		if ($type === 'exam') {
			$repeat = ! empty($payload['allow_repeat']);
			if ($repeat) {
				$payload['window_start_utc']       = null;
				$payload['window_end_utc']         = null;
				$payload['results_release_at_utc'] = null;
				$payload['ranking_enabled']        = false;
				if (($payload['feedback_timing'] ?? '') === 'scheduled') {
					$payload['feedback_timing'] = 'after_submit';
				}
			} elseif (! empty($payload['results_release_at_utc'])) {
				$payload['feedback_timing'] = 'scheduled';
			} elseif (($payload['feedback_timing'] ?? '') === 'scheduled') {
				$payload['feedback_timing'] = 'after_submit';
			}
			return $payload;
		}
		$payload['access_mode']           = 'guest_allowed';
		$payload['duration_seconds']      = null;
		$payload['window_start_utc']      = null;
		$payload['window_end_utc']        = null;
		$payload['results_release_at_utc']= null;
		$payload['allow_repeat']          = true;
		$payload['ranking_enabled']       = false;
		$payload['feedback_timing']       = in_array(($payload['feedback_timing'] ?? ''), array('never', 'immediate', 'after_submit'), true)
			? $payload['feedback_timing']
			: 'after_submit';
		return $payload;
	}

	private function validate_policy_payload(array $payload, string $type): bool|\WP_Error {
		if ($type !== 'exam' || empty($payload['ranking_enabled'])) {
			return true;
		}
		if (! empty($payload['allow_repeat'])) {
			return new \WP_Error(
				'ptq_repeat_ranking_conflict',
				__('Ranking cannot be enabled for repeatable exams.', 'paper-to-quiz'),
				array('status' => 400)
			);
		}
		if (($payload['access_mode'] ?? 'guest_allowed') !== 'login_required') {
			return new \WP_Error(
				'ptq_guest_ranking_conflict',
				__('Ranking can only be enabled for exams that require WordPress membership.', 'paper-to-quiz'),
				array('status' => 400)
			);
		}
		return true;
	}

	private function validate_subject_selection(array $payload): bool|\WP_Error {
		$subject_ids = $this->sanitize_subject_ids($payload['subject_ids'] ?? array());
		if (! $subject_ids) {
			$message = __('Select at least one subject.', 'paper-to-quiz');
			return new \WP_Error(
				'ptq_subject_required',
				$message,
				array('status' => 400, 'params' => array('subject_ids' => $message))
			);
		}

		$placeholders = implode(',', array_fill(0, count($subject_ids), '%d'));
		$valid_count = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				"SELECT COUNT(*) FROM " . $this->db->table('terms') . " WHERE type = 'subject' AND status IN ('active','archived') AND id IN ({$placeholders})",
				...$subject_ids
			)
		);
		if ($valid_count !== count($subject_ids)) {
			$message = __('One or more selected subjects are no longer available. Review the subject selection and try again.', 'paper-to-quiz');
			return new \WP_Error(
				'ptq_subject_invalid',
				$message,
				array('status' => 400, 'params' => array('subject_ids' => $message))
			);
		}

		return true;
	}

	private function sanitize_subject_ids(mixed $value): array {
		if (! is_array($value)) {
			return array();
		}
		$ids = array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	private function subject_names(array $subject_ids): array {
		if (! $subject_ids) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($subject_ids), '%d'));
		$rows = $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				"SELECT id,name FROM " . $this->db->table('terms') . " WHERE type = 'subject' AND id IN ({$placeholders})",
				...$subject_ids
			),
			ARRAY_A
		) ?: array();
		$names = array_column($rows, 'name', 'id');
		return array_values(array_filter(array_map(static fn (int $id): string => (string) ($names[$id] ?? ''), $subject_ids)));
	}

	private function question(int $question_id): ?array {
		$row = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('questions') . ' WHERE id = %d',
				$question_id
			),
			ARRAY_A
		);
		return is_array($row) ? $row : null;
	}

	private function next_temporary_ordinal(int $revision_id): int {
		$maximum = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT COALESCE(MAX(ordinal), 0) FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d',
				$revision_id
			)
		);
		return $maximum + 1;
	}

	private function enum(mixed $value, array $allowed, string $default): string {
		$value = sanitize_key((string) $value);
		return in_array($value, $allowed, true) ? $value : $default;
	}

	private function date_or_null(mixed $value): ?string {
		if (! is_string($value) || trim($value) === '') {
			return null;
		}
		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
			return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		} catch (\Throwable) {
			return null;
		}
	}

	private function sanitize_participant_fields(mixed $fields): array {
		$allowed = array('first_name', 'last_name', 'school', 'class_section', 'email', 'phone');
		$result  = array();
		if (! is_array($fields)) {
			return $result;
		}
		foreach ($fields as $key => $config) {
			if (! in_array($key, $allowed, true) || ! is_array($config)) {
				continue;
			}
			$result[$key] = array(
				'enabled'  => ! empty($config['enabled']),
				'required' => ! empty($config['required']),
			);
		}
		return $result;
	}

}
