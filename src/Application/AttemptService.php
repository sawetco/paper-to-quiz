<?php

declare(strict_types=1);

namespace PaperToQuiz\Application;

use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\Settings;

final class AttemptService {
	/**
	 * Request-scoped memo for AssessmentService::get_revision() results.
	 *
	 * The service is reconstructed per request in Plugin::boot(), so this cache
	 * only lives for one PHP request. It dedups the 2-3 revision fetches a
	 * single submit/result cycle used to issue (see plan 016).
	 *
	 * @var array<int, array|null>
	 */
	private array $revision_cache = array();

	/**
	 * Request-scoped memo for AssessmentService::questions() results, keyed by
	 * "<revision_id>:<include_key>" because the column set differs between the
	 * public (no answer key) and grading (full row) variants.
	 *
	 * @var array<string, array>
	 */
	private array $questions_cache = array();

	public function __construct(
		private readonly Database $db,
		private readonly AssessmentService $assessments,
		private readonly Crypto $crypto
	) {
	}

	/**
	 * Return the revision row for the given ID, memoized per request.
	 *
	 * Wraps AssessmentService::get_revision() so a single submit/result cycle
	 * does not issue the same SELECT 2-3 times. Uses array_key_exists() so a
	 * null result (revision deleted mid-request) is also cached.
	 */
	private function revision(int $id): ?array {
		if (! array_key_exists($id, $this->revision_cache)) {
			$this->revision_cache[$id] = $this->assessments->get_revision($id);
		}
		return $this->revision_cache[$id];
	}

	/**
	 * Return the question rows for the given revision, memoized per request.
	 *
	 * Wraps AssessmentService::questions(). The cache key includes the
	 * $include_key flag because the public (no correct_option) and grading
	 * (full row) variants select different columns; both variants may be
	 * requested within one request without overwriting each other.
	 */
	private function questions_for(int $revision_id, bool $include_key = true): array {
		$key = $revision_id . ':' . ($include_key ? '1' : '0');
		if (! isset($this->questions_cache[$key])) {
			$this->questions_cache[$key] = $this->assessments->questions($revision_id, $include_key);
		}
		return $this->questions_cache[$key];
	}

	private function public_access(?array $record, int $assessment_id, string $not_available_message): bool|\WP_Error {
		if (! $record || $record['assessment']['status'] !== 'published' || ! $record['revision']) {
			return new \WP_Error('paper_to_quiz_not_available', $not_available_message, array('status' => 404));
		}

		$revision = $record['revision'];
		if ($revision['access_mode'] === 'login_required' && (! is_user_logged_in() || ! current_user_can('read'))) {
			return new \WP_Error(
				'paper_to_quiz_login_required',
				__('You must log in to your account to participate.', 'paper-to-quiz'),
				array(
					'status'       => 401,
					'login_url'    => wp_login_url($this->current_url()),
					'register_url' => get_option('users_can_register') ? wp_registration_url() : null,
				)
			);
		}

		$allowed = apply_filters('paper_to_quiz_can_access_assessment', true, $assessment_id, get_current_user_id(), $record); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public API required by the plugin contract.
		if (! $allowed) {
			return new \WP_Error('paper_to_quiz_access_denied', __('You do not have permission to participate in this item.', 'paper-to-quiz'), array('status' => 403));
		}

		return true;
	}

	public function bootstrap(int $assessment_id): array|\WP_Error {
		$record = $this->assessments->get($assessment_id, true);
		$access = $this->public_access($record, $assessment_id, __('This item is currently unavailable.', 'paper-to-quiz'));
		if (is_wp_error($access)) {
			return $access;
		}

		$revision = $record['revision'];
		$latest_attempt_public_id = null;
		if (is_user_logged_in()) {
			$latest_attempt_public_id = $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT public_id FROM ' . $this->db->table('attempts') . " WHERE assessment_id = %d AND wp_user_id = %d AND status IN ('submitted','auto_submitted') ORDER BY id DESC LIMIT 1",
					$assessment_id,
					get_current_user_id()
				)
			);
		} else {
			$token_hashes = array();
			foreach ($_COOKIE as $name => $value) {
				if (! str_starts_with((string) $name, 'paper_to_quiz_attempt_') || ! is_string($value)) {
					continue;
				}
				$token = sanitize_text_field(wp_unslash($value));
				if ($token !== '') {
					$token_hashes[] = $this->token_hash($token);
				}
			}
			if ($token_hashes) {
				$placeholders = implode(',', array_fill(0, count($token_hashes), '%s'));
				$query = 'SELECT public_id FROM ' . $this->db->table('attempts')
					. " WHERE assessment_id = %d AND participant_type = 'guest'"
					. " AND status IN ('submitted','auto_submitted')"
					. " AND token_hash IN ({$placeholders}) ORDER BY id DESC LIMIT 1";
				$latest_attempt_public_id = $this->db->wpdb()->get_var(
					$this->db->wpdb()->prepare($query, $assessment_id, ...$token_hashes)
				);
			}
		}

		return array(
			'id'                 => $assessment_id,
			'type'               => $record['assessment']['type'],
			'title'              => $revision['title'],
			'description'        => wpautop((string) $revision['description']),
			'class_name'         => (string) ($revision['class_name'] ?? ''),
			'class_color'        => (string) ($revision['class_color'] ?? ''),
			'access_mode'        => $revision['access_mode'],
			'question_count'     => count($record['questions']),
			'duration_seconds'   => $revision['duration_seconds'] ? (int) $revision['duration_seconds'] : null,
			'allow_repeat'       => (bool) $revision['allow_repeat'],
			'ranking_enabled'    => (bool) $revision['ranking_enabled'],
			'participant_fields' => $this->participant_schema($revision),
			'current_user'       => $this->current_user_data($revision),
			'latest_attempt_public_id' => $latest_attempt_public_id ?: null,
			'schedule'           => $this->schedule_payload($record),
		);
	}

	public function start(int $assessment_id, array $participant): array|\WP_Error {
		$record = $this->assessments->get($assessment_id, true);
		$access = $this->public_access($record, $assessment_id, __('This item could not be found.', 'paper-to-quiz'));
		if (is_wp_error($access)) {
			return $access;
		}
		$availability = $this->availability($record);
		if (is_wp_error($availability)) {
			return $availability;
		}

		$revision = $record['revision'];
		$is_member = $revision['access_mode'] === 'login_required';
		$user_id   = get_current_user_id();

		$participant_data = $this->validate_participant($revision, $participant, $is_member);
		if (is_wp_error($participant_data)) {
			return $participant_data;
		}
		if ($is_member && ! $revision['allow_repeat']) {
			$existing = $this->db->wpdb()->get_row(
				$this->db->wpdb()->prepare(
					'SELECT * FROM ' . $this->db->table('attempts') . " WHERE assessment_id = %d AND wp_user_id = %d ORDER BY id DESC LIMIT 1",
					$assessment_id,
					$user_id
				),
				ARRAY_A
			);
			if ($existing) {
				if ($existing['status'] !== 'in_progress') {
					return new \WP_Error('paper_to_quiz_repeat_not_allowed', __('This item can only be completed once.', 'paper-to-quiz'), array('status' => 409));
				}
				return $this->rotate_and_state($existing);
			}
		}

		$token      = $this->new_token();
		$public_id  = wp_generate_uuid4();
		$now        = time();
		$deadline   = null;
		$is_test = (string) $record['assessment']['type'] === 'test';
		if (! $is_test && $revision['duration_seconds']) {
			$deadline = $now + (int) $revision['duration_seconds'];
		}
		if (! $is_test && $revision['window_end_utc']) {
			$window_end = strtotime((string) $revision['window_end_utc'] . ' UTC');
			$deadline   = $deadline ? min($deadline, $window_end) : $window_end;
		}

		$inserted = $this->db->wpdb()->insert(
			$this->db->table('attempts'),
			array(
				'public_id'        => $public_id,
				'token_hash'       => $this->token_hash($token),
				'assessment_id'    => $assessment_id,
				'revision_id'      => (int) $revision['id'],
				'wp_user_id'       => $is_member ? $user_id : null,
				'participant_type' => $is_member ? 'member' : 'guest',
				'participant_data' => $this->crypto->encrypt_array($participant_data),
				'status'           => 'in_progress',
				'started_at'       => gmdate('Y-m-d H:i:s', $now),
				'deadline_at'      => $deadline ? gmdate('Y-m-d H:i:s', $deadline) : null,
				'last_activity_at' => gmdate('Y-m-d H:i:s', $now),
			)
		);
		if (! $inserted) {
			return new \WP_Error('paper_to_quiz_attempt_failed', __('Could not start. Please try again.', 'paper-to-quiz'), array('status' => 500));
		}

		$attempt_id = (int) $this->db->wpdb()->insert_id;
		do_action('paper_to_quiz_attempt_started', $attempt_id, $assessment_id, $user_id ?: null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public API required by the plugin contract.

		$attempt = $this->attempt_by_id($attempt_id);
		return $this->state_payload($attempt, $token);
	}

	public function state(string $public_id, string $token): array|\WP_Error {
		$attempt = $this->authenticate($public_id, $token);
		if (is_wp_error($attempt)) {
			return $attempt;
		}
		return $this->state_payload($attempt, null);
	}

	public function answer(string $public_id, string $token, int $question_id, ?string $option, bool $flagged, string $mutation_id): array|\WP_Error {
		$attempt = $this->authenticate($public_id, $token);
		if (is_wp_error($attempt)) {
			return $attempt;
		}
		$open = $this->validate_open_attempt($attempt);
		if (is_wp_error($open)) {
			return $open;
		}
		$result = $this->save_answer($attempt, $question_id, $option, $flagged, $mutation_id);
		if (! is_wp_error($result)) {
			$this->touch_attempt((int) $attempt['id']);
		}
		return $result;
	}

	public function answer_many(string $public_id, string $token, array $items): array|\WP_Error {
		$attempt = $this->authenticate($public_id, $token);
		if (is_wp_error($attempt)) {
			return $attempt;
		}
		$open = $this->validate_open_attempt($attempt);
		if (is_wp_error($open)) {
			return $open;
		}

		$latest = array();
		foreach ($items as $item) {
			if (! is_array($item) || empty($item['question_id'])) {
				continue;
			}
			$latest[(int) $item['question_id']] = $item;
		}
		if (! $latest) {
			return new \WP_Error('paper_to_quiz_answers_required', __('No answers to save were found.', 'paper-to-quiz'), array('status' => 400));
		}

		$responses = array();
		$this->db->begin();
		try {
			foreach ($latest as $question_id => $item) {
				$result = $this->save_answer(
					$attempt,
					$question_id,
					isset($item['option']) && $item['option'] !== '' ? (string) $item['option'] : null,
					! empty($item['flagged']),
					sanitize_text_field((string) ($item['mutation_id'] ?? ''))
				);
				if (is_wp_error($result)) {
					$this->db->rollback();
					return $result;
				}
				$responses[(string) $question_id] = $result;
			}
			$this->touch_attempt((int) $attempt['id']);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollback();
			return new \WP_Error('paper_to_quiz_answers_failed', __('Answers could not be saved. Please try again.', 'paper-to-quiz'), array('status' => 500));
		}

		return array('saved' => true, 'answers' => $responses);
	}

	private function validate_open_attempt(array $attempt): bool|\WP_Error {
		if ($attempt['status'] !== 'in_progress') {
			return new \WP_Error('paper_to_quiz_attempt_closed', __('This item has already been completed.', 'paper-to-quiz'), array('status' => 409));
		}
		if ($this->is_past_grace($attempt)) {
			$this->finalize($attempt, true);
			return new \WP_Error('paper_to_quiz_time_expired', __('The answer could not be saved because time expired.', 'paper-to-quiz'), array('status' => 409));
		}
		return true;
	}

	private function save_answer(array $attempt, int $question_id, ?string $option, bool $flagged, string $mutation_id): array|\WP_Error {
		$question = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT q.*, r.options_json, r.feedback_timing FROM ' . $this->db->table('questions') . ' q
				JOIN ' . $this->db->table('revisions') . ' r ON r.id = q.revision_id
				WHERE q.id = %d AND q.revision_id = %d',
				$question_id,
				(int) $attempt['revision_id']
			),
			ARRAY_A
		);
		if (! $question) {
			return new \WP_Error('paper_to_quiz_question_not_found', __('Question not found.', 'paper-to-quiz'), array('status' => 404));
		}

		$options = json_decode((string) $question['options_json'], true) ?: array();
		$option  = $option !== null && $option !== '' ? strtoupper(sanitize_key($option)) : null;
		if ($option !== null && ! in_array($option, $options, true)) {
			return new \WP_Error('paper_to_quiz_invalid_option', __('Invalid answer option.', 'paper-to-quiz'), array('status' => 400));
		}

		if ($mutation_id !== '') {
			$duplicate = (int) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table('answers') . ' WHERE attempt_id = %d AND mutation_id = %s',
					(int) $attempt['id'],
					$mutation_id
				)
			);
			if ($duplicate) {
				return array('saved' => true, 'duplicate' => true);
			}
		}

		$now      = current_time('mysql', true);
		$existing = $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT id FROM ' . $this->db->table('answers') . ' WHERE attempt_id = %d AND question_id = %d',
				(int) $attempt['id'],
				$question_id
			)
		);
		$data = array(
			'selected_option' => $option,
			'is_flagged'      => $flagged ? 1 : 0,
			'mutation_id'     => $mutation_id ?: null,
			'answered_at'     => $option ? $now : null,
		);
		if ($existing) {
			$this->db->wpdb()->update($this->db->table('answers'), $data, array('id' => (int) $existing));
		} else {
			$data['attempt_id']  = (int) $attempt['id'];
			$data['question_id'] = $question_id;
			$this->db->wpdb()->insert($this->db->table('answers'), $data);
		}
		$response = array('saved' => true);
		if ($question['feedback_timing'] === 'immediate' && $option !== null) {
			$response['feedback'] = array(
				'is_correct'     => hash_equals((string) $question['correct_option'], $option),
				'correct_option' => $question['correct_option'],
			);
		}
		return $response;
	}

	private function touch_attempt(int $attempt_id): void {
		$this->db->wpdb()->update(
			$this->db->table('attempts'),
			array('last_activity_at' => current_time('mysql', true)),
			array('id' => $attempt_id),
			array('%s'),
			array('%d')
		);
	}

	public function submit(string $public_id, string $token, bool $automatic = false, string $submission_id = '', array $answers = array()): array|\WP_Error {
		$attempt = $this->authenticate($public_id, $token);
		if (is_wp_error($attempt)) {
			return $attempt;
		}
		if ($attempt['status'] !== 'in_progress') {
			return $this->result_payload($attempt);
		}
		if (! wp_is_uuid($submission_id)) {
			return new \WP_Error('paper_to_quiz_submission_id', __('The submission ID is invalid.', 'paper-to-quiz'), array('status' => 400));
		}
		$duplicate = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('attempts') . ' WHERE submission_id = %s',
				$submission_id
			),
			ARRAY_A
		);
		if ($duplicate) {
			if ((int) $duplicate['id'] !== (int) $attempt['id']) {
				return new \WP_Error('paper_to_quiz_submission_conflict', __('The submission ID has been used in another attempt.', 'paper-to-quiz'), array('status' => 409));
			}
			return $this->result_payload($duplicate);
		}
		$valid = $this->validate_submission_answers($attempt, $answers);
		if (is_wp_error($valid)) {
			return $valid;
		}
		$attempt = $this->finalize($attempt, $automatic, $submission_id, $answers);
		return $this->result_payload($attempt);
	}

	public function result(string $public_id, string $token): array|\WP_Error {
		$attempt = $this->authenticate($public_id, $token);
		if (is_wp_error($attempt)) {
			return $attempt;
		}
		if ($attempt['status'] === 'in_progress') {
			return new \WP_Error('paper_to_quiz_not_submitted', __('This item has not been completed yet.', 'paper-to-quiz'), array('status' => 409));
		}
		return $this->result_payload($attempt);
	}

	public function email_context(int $attempt_id): ?array {
		$attempt = $this->attempt_by_id($attempt_id);
		if (! $attempt || $attempt['status'] === 'in_progress') {
			return null;
		}
		$participant = $this->crypto->decrypt_array($attempt['participant_data']);
		$email = sanitize_email((string) ($participant['email'] ?? ''));
		if (! is_email($email)) {
			return null;
		}
		$revision = $this->revision((int) $attempt['revision_id']);
		$result = $this->result_payload($attempt);
		return array(
			'email'       => $email,
			'participant' => $participant,
			'title'       => (string) ($revision['title'] ?? ''),
			'class_name'  => (string) ($revision['class_name'] ?? ''),
			'submitted_at'=> $this->format_admin_date($attempt['submitted_at']),
			'result'      => $result,
		);
	}

	public function question_asset(string $public_id, string $token, int $question_id): array|\WP_Error {
		$attempt = $this->authenticate($public_id, $token);
		if (is_wp_error($attempt)) {
			return $attempt;
		}
		$question = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT q.main_asset_id,a.storage_key,a.mime,a.byte_size FROM ' . $this->db->table('questions') . ' q
				JOIN ' . $this->db->table('assets') . ' a ON a.id = q.main_asset_id
				WHERE q.id = %d AND q.revision_id = %d',
				$question_id,
				(int) $attempt['revision_id']
			),
			ARRAY_A
		);
		if (! $question) {
			return new \WP_Error('paper_to_quiz_image_not_found', __('Question image not found.', 'paper-to-quiz'), array('status' => 404));
		}
		return $question;
	}

	public function admin_results(array $filters): array {
		$base_where = '1=1';
		$base_args  = array();
		if (! empty($filters['assessment_id'])) {
			$base_where .= ' AND t.assessment_id = %d';
			$base_args[] = (int) $filters['assessment_id'];
		}
		if (! empty($filters['participant_type']) && in_array($filters['participant_type'], array('member', 'guest'), true)) {
			$base_where .= ' AND t.participant_type = %s';
			$base_args[] = $filters['participant_type'];
		}
		$search = sanitize_text_field((string) ($filters['search'] ?? ''));
		if ($search !== '') {
			$like = '%' . $this->db->wpdb()->esc_like($search) . '%';
			$base_where .= ' AND (
				r.title LIKE %s
				OR EXISTS (
					SELECT 1 FROM ' . $this->db->wpdb()->usermeta . " um
					WHERE um.user_id = t.wp_user_id
					AND um.meta_key IN ('first_name','last_name')
					AND um.meta_value LIKE %s
				)
			)";
			$base_args[] = $like;
			$base_args[] = $like;
		}
		$where = $base_where;
		$args  = $base_args;
		if (! empty($filters['status'])) {
			$where .= ' AND t.status = %s';
			$args[] = sanitize_key((string) $filters['status']);
		}
		$page     = max(1, (int) ($filters['page'] ?? 1));
		$per_page = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
		$query_args = array_merge($args, array($per_page, ($page - 1) * $per_page));
		$order_columns = array(
			'title'      => 'r.title',
			'started'    => 't.started_at',
			'finished'   => 't.submitted_at',
			'duration'   => 't.duration_seconds',
			'correct'    => 't.correct_count',
			'wrong'      => 't.wrong_count',
			'blank'      => 't.blank_count',
			'score'      => 't.score',
			'status'     => 't.status',
		);
		$orderby = sanitize_key((string) ($filters['orderby'] ?? 'started'));
		$order_column = $order_columns[$orderby] ?? $order_columns['started'];
		$order_direction = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

		$sql = 'SELECT t.*, r.title, a.type,
			EXISTS(SELECT 1 FROM ' . $this->db->table('questions') . ' qp WHERE qp.revision_id = t.revision_id AND (qp.points %% 100) <> 0) score_has_fraction
			FROM ' . $this->db->table('attempts') . ' t
			JOIN ' . $this->db->table('revisions') . ' r ON r.id = t.revision_id
			JOIN ' . $this->db->table('assessments') . ' a ON a.id = t.assessment_id
			WHERE ' . $where . ' ORDER BY ' . $order_column . ' ' . $order_direction . ', t.id DESC LIMIT %d OFFSET %d';
		$rows = $this->db->wpdb()->get_results($this->db->wpdb()->prepare($sql, ...$query_args), ARRAY_A) ?: array();
		foreach ($rows as &$row) {
			$participant = $this->crypto->decrypt_array($row['participant_data']);
			$row['participant_label'] = $this->participant_label($row, $participant);
			$row['started_at_display'] = $this->format_admin_date($row['started_at']);
			$row['submitted_at_display'] = $this->format_admin_date($row['submitted_at']);
			unset($row['participant_data'], $row['token_hash']);
		}

		$count_sql = 'SELECT COUNT(*) FROM ' . $this->db->table('attempts') . ' t
			JOIN ' . $this->db->table('revisions') . ' r ON r.id = t.revision_id
			WHERE ' . $where;
		$total = $args
			? (int) $this->db->wpdb()->get_var($this->db->wpdb()->prepare($count_sql, ...$args))
			: (int) $this->db->wpdb()->get_var($count_sql);
		$status_sql = 'SELECT t.status, COUNT(*) count FROM ' . $this->db->table('attempts') . ' t
			JOIN ' . $this->db->table('revisions') . ' r ON r.id = t.revision_id
			WHERE ' . $base_where . ' GROUP BY t.status';
		$status_rows = $base_args
			? $this->db->wpdb()->get_results($this->db->wpdb()->prepare($status_sql, ...$base_args), ARRAY_A)
			: $this->db->wpdb()->get_results($status_sql, ARRAY_A);
		$counts = array(
			'all'            => 0,
			'in_progress'    => 0,
			'submitted'      => 0,
			'auto_submitted' => 0,
			'expired'        => 0,
		);
		foreach ($status_rows ?: array() as $status_row) {
			if (isset($counts[$status_row['status']])) {
				$counts[$status_row['status']] = (int) $status_row['count'];
				$counts['all'] += (int) $status_row['count'];
			}
		}

		$response = array(
			'items' => $rows,
			'page'  => $page,
			'total' => $total,
			'pages' => (int) ceil($total / max(1, $per_page)),
			'counts'=> $counts,
		);
		if (! empty($filters['assessment_id'])) {
			$response['subject_analytics'] = $this->subject_analytics((int) $filters['assessment_id']);
		}
		return $response;
	}

	public function admin_result(int $attempt_id): ?array {
		$attempt = $this->attempt_by_id($attempt_id);
		if (! $attempt) {
			return null;
		}
		$revision = $this->revision((int) $attempt['revision_id']);
		$record   = $this->assessments->get((int) $attempt['assessment_id']);
		$answers = $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				'SELECT an.id,an.selected_option,an.is_flagged,an.is_correct,an.awarded_points,
					q.ordinal,q.correct_option,q.thumb_asset_id,q.subject_id,q.source_page,q.points question_points,
					COALESCE(s.name,%s) subject_name
				FROM ' . $this->db->table('questions') . ' q
				LEFT JOIN ' . $this->db->table('answers') . ' an ON an.question_id = q.id AND an.attempt_id = %d
				LEFT JOIN ' . $this->db->table('terms') . " s ON s.id = q.subject_id AND s.type = 'subject'
				WHERE q.revision_id = %d ORDER BY q.ordinal",
				__('Subject not specified', 'paper-to-quiz'),
				$attempt_id,
				(int) $attempt['revision_id']
			),
			ARRAY_A
		) ?: array();
		foreach ($answers as &$answer) {
			$answer['thumbnail_url'] = ! empty($answer['thumb_asset_id'])
				? rest_url('paper-to-quiz/v1/admin/assets/' . (int) $answer['thumb_asset_id'])
				: null;
		}
		unset($attempt['token_hash']);
		$participant = $this->crypto->decrypt_array($attempt['participant_data']);
		unset($participant['_diagnostics']);
		if (! empty($participant['phone'])) {
			$participant['phone'] = $this->display_phone((string) $participant['phone']);
		}
		$attempt['participant'] = $participant;
		$attempt['title'] = (string) ($revision['title'] ?? '');
		$attempt['type'] = (string) ($record['assessment']['type'] ?? '');
		$attempt['participant_label'] = $this->participant_label($attempt, $participant);
		$attempt['started_at_display'] = $this->format_admin_date($attempt['started_at']);
		$attempt['deadline_at_display'] = $this->format_admin_date($attempt['deadline_at']);
		$attempt['last_activity_at_display'] = $this->format_admin_date($attempt['last_activity_at']);
		$attempt['submitted_at_display'] = $this->format_admin_date($attempt['submitted_at']);
		unset($attempt['participant_data']);
		$attempt['answers'] = $answers;
		$attempt['subjects'] = $this->subject_scores($attempt_id);
		$attempt['score_precision'] = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT EXISTS(SELECT 1 FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d AND (points %% 100) <> 0)',
				(int) $attempt['revision_id']
			)
		) ? 2 : 0;
		return $attempt;
	}

	private function subject_analytics(int $assessment_id): array {
		$record = $this->assessments->get($assessment_id, true);
		$ranked = ! empty($record['revision']['ranking_enabled']);
		$where  = "t.assessment_id = %d AND t.status IN ('submitted','auto_submitted')";
		if ($ranked) {
			$where .= " AND t.participant_type = 'member' AND t.ranking_eligible = 1";
		}
		return $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				'SELECT ss.subject_id,COALESCE(s.name,%s) subject_name,
					COUNT(DISTINCT ss.attempt_id) participant_count,
					ROUND(AVG(ss.score),2) average_score,
					ROUND(AVG(ss.percentage),2) average_percentage,
					SUM(ss.correct_count) correct_count,SUM(ss.wrong_count) wrong_count,SUM(ss.blank_count) blank_count
				FROM ' . $this->db->table('attempt_subject_scores') . ' ss
				JOIN ' . $this->db->table('attempts') . ' t ON t.id = ss.attempt_id
				LEFT JOIN ' . $this->db->table('terms') . " s ON s.id = ss.subject_id AND s.type = 'subject'
				WHERE {$where}
				GROUP BY ss.subject_id,s.name,s.sort_order ORDER BY s.sort_order,s.name",
				__('Subject not specified', 'paper-to-quiz'),
				$assessment_id
			),
			ARRAY_A
		) ?: array();
	}

	public function anonymize_expired(): int {
		$rows = $this->db->wpdb()->get_results(
			'SELECT t.id, t.submitted_at FROM ' . $this->db->table('attempts') . " t
			WHERE t.anonymized_at IS NULL AND t.submitted_at IS NOT NULL",
			ARRAY_A
		) ?: array();
		$settings       = Settings::get();
		$retention_days = max(1, min(3650, (int) ($settings['retention_days'] ?? 365)));
		$count = 0;
		foreach ($rows as $row) {
			$expires = strtotime((string) $row['submitted_at'] . ' UTC') + ($retention_days * DAY_IN_SECONDS);
			if ($expires > time()) {
				continue;
			}
			if ($this->anonymize_attempt((int) $row['id'])) {
				++$count;
			}
		}
		return $count;
	}

	public function expire_stale_attempts(): int {
		return (int) $this->db->wpdb()->query(
			$this->db->wpdb()->prepare(
				'UPDATE ' . $this->db->table('attempts') . "
				SET status = 'expired', integrity_status = 'expired', ranking_eligible = 0
				WHERE status = 'in_progress' AND deadline_at IS NOT NULL AND deadline_at < %s",
				gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)
			)
		);
	}

	public function anonymize_user(int $user_id): int {
		$attempt_ids = $this->db->wpdb()->get_col(
			$this->db->wpdb()->prepare(
				'SELECT id FROM %i WHERE wp_user_id = %d',
				$this->db->table('attempts'),
				$user_id
			)
		);

		$count = 0;
		foreach ($attempt_ids as $attempt_id) {
			if ($this->anonymize_attempt((int) $attempt_id)) {
				++$count;
			}
		}
		return $count;
	}

	public function anonymize_attempt(int $attempt_id): bool {
		$this->db->begin();
		try {
			$deleted = $this->db->wpdb()->delete(
				$this->db->table('ranking_entries'),
				array('attempt_id' => $attempt_id),
				array('%d')
			);
			$updated = $this->db->wpdb()->update(
				$this->db->table('attempts'),
				array(
					'wp_user_id'       => null,
					'participant_data' => null,
					'anonymized_at'    => current_time('mysql', true),
				),
				array('id' => $attempt_id),
				array('%d', '%s', '%s'),
				array('%d')
			);
			if ($deleted === false || $updated === false) {
				throw new \RuntimeException('Attempt anonymization failed.');
			}
			$this->db->commit();
			return true;
		} catch (\Throwable) {
			$this->db->rollback();
			return false;
		}
	}

	private function format_admin_date(mixed $value): string {
		if (! is_string($value) || $value === '') {
			return '';
		}
		$timestamp = strtotime($value . ' UTC');
		if (! $timestamp) {
			return '';
		}
		return wp_date(
			get_option('date_format') . ' ' . get_option('time_format'),
			$timestamp,
			wp_timezone()
		);
	}

	private function participant_label(array $attempt, array $participant): string {
		$first_name = trim((string) ($participant['first_name'] ?? ''));
		$last_name  = trim((string) ($participant['last_name'] ?? ''));
		if (($first_name === '' || $last_name === '') && ! empty($attempt['wp_user_id'])) {
			$user = get_userdata((int) $attempt['wp_user_id']);
			if ($user) {
				$first_name = $first_name ?: trim((string) $user->first_name);
				$last_name  = $last_name ?: trim((string) $user->last_name);
			}
		}
		if (($attempt['participant_type'] ?? '') === 'guest') {
			return __('Guest', 'paper-to-quiz');
		}
		if ($first_name !== '' || $last_name !== '') {
			return trim($first_name . ' ' . $last_name);
		}
		if (! empty($attempt['wp_user_id'])) {
			/* translators: %d: WordPress user ID. */
			return sprintf(__('Member #%d', 'paper-to-quiz'), (int) $attempt['wp_user_id']);
		}
		return __('Anonymous member', 'paper-to-quiz');
	}

	private function availability(array $record): bool|\WP_Error {
		$revision = $record['revision'];
		$now      = time();
		if ($record['assessment']['type'] === 'exam') {
			if ($revision['window_start_utc'] && $now < strtotime((string) $revision['window_start_utc'] . ' UTC')) {
				return new \WP_Error('paper_to_quiz_not_started', __('The exam has not started yet.', 'paper-to-quiz'), array('status' => 403));
			}
			if ($revision['window_end_utc'] && $now >= strtotime((string) $revision['window_end_utc'] . ' UTC')) {
				return new \WP_Error('paper_to_quiz_ended', __('The exam has ended.', 'paper-to-quiz'), array('status' => 403));
			}
		}
		return true;
	}

	private function schedule_payload(array $record): array {
		$revision = $record['revision'];
		$now      = time();
		$start    = ! empty($revision['window_start_utc'])
			? strtotime((string) $revision['window_start_utc'] . ' UTC')
			: null;
		$end      = ! empty($revision['window_end_utc'])
			? strtotime((string) $revision['window_end_utc'] . ' UTC')
			: null;
		$release  = ! empty($revision['results_release_at_utc'])
			? strtotime((string) $revision['results_release_at_utc'] . ' UTC')
			: null;
		$state    = 'open';
		if ($record['assessment']['type'] === 'exam') {
			if ($start && $now < $start) {
				$state = 'scheduled';
			} elseif ($end && $now >= $end) {
				$state = 'ended';
			}
		}

		return array(
			'state'                   => $state,
			'server_time'             => gmdate(DATE_ATOM, $now),
			'starts_at'               => $start ? gmdate(DATE_ATOM, $start) : null,
			'ends_at'                 => $end ? gmdate(DATE_ATOM, $end) : null,
			'results_release_at'      => $release ? gmdate(DATE_ATOM, $release) : null,
			'starts_at_display'       => $start ? $this->format_admin_date((string) $revision['window_start_utc']) : '',
			'ends_at_display'         => $end ? $this->format_admin_date((string) $revision['window_end_utc']) : '',
			'results_release_display' => $release ? $this->format_admin_date((string) $revision['results_release_at_utc']) : '',
		);
	}

	private function validate_participant(array $revision, array $input, bool $is_member): array|\WP_Error {
		$schema = $revision['participant_fields'];
		$user   = $is_member ? wp_get_current_user() : null;
		$result = array();

		foreach ($schema as $key => $config) {
			if (empty($config['enabled'])) {
				continue;
			}
			$value = '';
			if ($user instanceof \WP_User) {
				if ($key === 'first_name') {
					$value = (string) $user->first_name;
				} elseif ($key === 'last_name') {
					$value = (string) $user->last_name;
				} elseif ($key === 'email') {
					$value = (string) $user->user_email;
				}
			}
			if ($value === '') {
				$value = (string) ($input[$key] ?? '');
			}
			$value = $key === 'email' ? sanitize_email($value) : sanitize_text_field($value);
			if (! empty($config['required']) && $value === '') {
				return new \WP_Error(
					'paper_to_quiz_participant_required',
					/* translators: %s: Participant field label. */
					sprintf(__('%s is required.', 'paper-to-quiz'), $this->field_label($key)),
					array('status' => 400, 'field' => $key)
				);
			}
			if ($key === 'email' && $value !== '' && ! is_email($value)) {
				return new \WP_Error('paper_to_quiz_invalid_email', __('Enter a valid email address.', 'paper-to-quiz'), array('status' => 400, 'field' => $key));
			}
			if ($key === 'phone' && $value !== '') {
				$digits = preg_replace('/\D+/', '', $value);
				if (str_starts_with($digits, '90') && strlen($digits) === 12) {
					$digits = substr($digits, 2);
				} elseif (str_starts_with($digits, '05') && strlen($digits) === 11) {
					$digits = substr($digits, 1);
				}
				if (! preg_match('/^5\d{9}$/', $digits)) {
					return new \WP_Error('paper_to_quiz_invalid_phone', __('Enter a valid Turkish mobile phone number.', 'paper-to-quiz'), array('status' => 400, 'field' => $key));
				}
				$value = $digits;
			}
			$result[$key] = mb_substr($value, 0, 190);
		}
		return $result;
	}

	private function display_phone(string $value): string {
		$digits = preg_replace('/\D+/', '', $value);
		if (str_starts_with($digits, '90') && strlen($digits) === 12) {
			$digits = substr($digits, 2);
		} elseif (str_starts_with($digits, '05') && strlen($digits) === 11) {
			$digits = substr($digits, 1);
		}
		return preg_match('/^5\d{9}$/', $digits)
			? substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2)
			: $value;
	}

	private function participant_schema(array $revision): array {
		$labels = array(
			'first_name'    => __('First name', 'paper-to-quiz'),
			'last_name'     => __('Last name', 'paper-to-quiz'),
			'school'        => __('School name', 'paper-to-quiz'),
			'class_section' => __('Class and section', 'paper-to-quiz'),
			'email'         => __('Email', 'paper-to-quiz'),
			'phone'         => __('Phone', 'paper-to-quiz'),
		);
		$result = array();
		foreach ($revision['participant_fields'] as $key => $config) {
			if (empty($config['enabled']) || ! isset($labels[$key])) {
				continue;
			}
			$result[] = array(
				'key'      => $key,
				'label'    => $labels[$key],
				'required' => ! empty($config['required']),
				'type'     => $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text'),
			);
		}
		return apply_filters('paper_to_quiz_participant_fields', $result, $revision); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public API required by the plugin contract.
	}

	private function current_user_data(array $revision): ?array {
		if (! is_user_logged_in()) {
			return null;
		}
		$user   = wp_get_current_user();
		$result = array('id' => $user->ID);
		foreach (array_keys($revision['participant_fields']) as $field) {
			if ($field === 'first_name') {
				$result[$field] = $user->first_name;
			} elseif ($field === 'last_name') {
				$result[$field] = $user->last_name;
			} elseif ($field === 'email') {
				$result[$field] = $user->user_email;
			}
		}
		return $result;
	}

	private function authenticate(string $public_id, string $token): array|\WP_Error {
		$attempt = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('attempts') . ' WHERE public_id = %s',
				$public_id
			),
			ARRAY_A
		);
		if (! $attempt || ! hash_equals((string) $attempt['token_hash'], $this->token_hash($token))) {
			return new \WP_Error('paper_to_quiz_invalid_attempt', __('Could not open the item. Please restart.', 'paper-to-quiz'), array('status' => 401));
		}
		if ($attempt['participant_type'] === 'member') {
			if (! is_user_logged_in()) {
				return new \WP_Error('paper_to_quiz_session_expired', __('Your login session has expired. Please log in again.', 'paper-to-quiz'), array('status' => 401));
			}
			if ((int) $attempt['wp_user_id'] !== get_current_user_id()) {
				return new \WP_Error('paper_to_quiz_attempt_owner', __('This item belongs to another user.', 'paper-to-quiz'), array('status' => 403));
			}
		}
		return $attempt;
	}

	private function validate_submission_answers(array $attempt, array $answers): bool|\WP_Error {
		$questions = $this->questions_for((int) $attempt['revision_id'], false);
		$ids = array_map(static fn (array $question): int => (int) $question['id'], $questions);
		$revision = $this->revision((int) $attempt['revision_id']);
		$options = (array) ($revision['options'] ?? array());
		$seen = array();
		foreach ($answers as $item) {
			if (! is_array($item) || empty($item['question_id'])) {
				return new \WP_Error('paper_to_quiz_invalid_answer', __('The submission contains an invalid answer.', 'paper-to-quiz'), array('status' => 400));
			}
			$question_id = (int) $item['question_id'];
			if (! in_array($question_id, $ids, true) || isset($seen[$question_id])) {
				return new \WP_Error('paper_to_quiz_invalid_answer', __('The submission contains an invalid or duplicate question.', 'paper-to-quiz'), array('status' => 400));
			}
			$seen[$question_id] = true;
			$option = isset($item['option']) && $item['option'] !== '' ? strtoupper(sanitize_key((string) $item['option'])) : null;
			if ($option !== null && ! in_array($option, $options, true)) {
				return new \WP_Error('paper_to_quiz_invalid_option', __('The submission contains an invalid answer option.', 'paper-to-quiz'), array('status' => 400));
			}
		}
		return true;
	}

	public static function cookie_name(string $public_id): string {
		return 'paper_to_quiz_attempt_' . substr(hash('sha256', strtolower($public_id)), 0, 20);
	}

	private function rotate_and_state(array $attempt): array {
		$token = $this->new_token();
		$this->db->wpdb()->update(
			$this->db->table('attempts'),
			array('token_hash' => $this->token_hash($token)),
			array('id' => (int) $attempt['id'])
		);
		$attempt['token_hash'] = $this->token_hash($token);
		return $this->state_payload($attempt, $token);
	}

	private function state_payload(array $attempt, ?string $new_token): array {
		$revision  = $this->revision((int) $attempt['revision_id']);
		$assessment_type = (string) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT type FROM ' . $this->db->table('assessments') . ' WHERE id = %d',
				(int) $attempt['assessment_id']
			)
		);
		$immediate_feedback = $assessment_type === 'test' && $revision['feedback_timing'] === 'immediate';
		$questions = $this->questions_for((int) $attempt['revision_id'], $immediate_feedback);
		$answers   = $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				'SELECT question_id,selected_option,is_flagged,answered_at FROM ' . $this->db->table('answers') . ' WHERE attempt_id = %d',
				(int) $attempt['id']
			),
			ARRAY_A
		) ?: array();

		$question_payload = array();
		foreach ($questions as $question) {
			$item = array(
				'id'       => (int) $question['id'],
				'ordinal'  => (int) $question['ordinal'],
				'imageUrl' => rest_url('paper-to-quiz/v1/attempts/' . $attempt['public_id'] . '/questions/' . $question['id'] . '/image'),
			);
			if ($immediate_feedback) {
				$item['correctOption'] = (string) $question['correct_option'];
			}
			$question_payload[] = $item;
		}

		$payload = array(
			'public_id'       => $attempt['public_id'],
			'revision_id'     => (int) $attempt['revision_id'],
			'status'          => $attempt['status'],
			'started_at'      => $this->iso((string) $attempt['started_at']),
			'deadline_at'     => $attempt['deadline_at'] ? $this->iso((string) $attempt['deadline_at']) : null,
			'server_time'     => gmdate(DATE_ATOM),
			'title'           => $revision['title'],
			'class_name'      => (string) ($revision['class_name'] ?? ''),
			'class_color'     => (string) ($revision['class_color'] ?? ''),
			'options'         => $revision['options'],
			'feedback_timing' => $revision['feedback_timing'],
			'questions'       => $question_payload,
			'answers'         => $answers,
			'participant_type'=> $attempt['participant_type'],
		);
		if ($new_token) {
			$payload['token'] = $new_token;
		}
		return $payload;
	}

	private function finalize(array $attempt, bool $automatic, ?string $submission_id = null, ?array $snapshot = null): array {
		$questions = $this->questions_for((int) $attempt['revision_id'], true);
		$question_map = array();
		foreach ($questions as $question) {
			$question_map[(int) $question['id']] = $question;
		}

		$this->db->begin();
		try {
			if (is_array($snapshot)) {
				$latest = array();
				foreach ($snapshot as $item) {
					if (is_array($item) && ! empty($item['question_id'])) {
						$latest[(int) $item['question_id']] = $item;
					}
				}
				foreach ($latest as $question_id => $item) {
					if (! isset($question_map[$question_id])) {
						throw new \InvalidArgumentException('Question does not belong to this revision.');
					}
					$option = isset($item['option']) && $item['option'] !== ''
						? strtoupper(sanitize_key((string) $item['option']))
						: null;
					$revision_options = $this->revision((int) $attempt['revision_id'])['options'] ?? array();
					if ($option !== null && ! in_array($option, $revision_options, true)) {
						throw new \InvalidArgumentException('Invalid answer option.');
					}
				}
				$this->db->wpdb()->delete($this->db->table('answers'), array('attempt_id' => (int) $attempt['id']), array('%d'));
				foreach ($question_map as $question_id => $question) {
					$item    = $latest[$question_id] ?? array();
					$option  = isset($item['option']) && $item['option'] !== '' ? strtoupper(sanitize_key((string) $item['option'])) : null;
					$flagged = ! empty($item['flagged']);
					if ($option === null && ! $flagged) {
						continue;
					}
					$this->db->wpdb()->insert(
						$this->db->table('answers'),
						array(
							'attempt_id'      => (int) $attempt['id'],
							'question_id'     => $question_id,
							'selected_option' => $option,
							'is_flagged'      => $flagged ? 1 : 0,
							'answered_at'     => $option ? current_time('mysql', true) : null,
						)
					);
				}
			}

			$answer_rows = $this->db->wpdb()->get_results(
				$this->db->wpdb()->prepare(
					'SELECT * FROM ' . $this->db->table('answers') . ' WHERE attempt_id = %d',
					(int) $attempt['id']
				),
				OBJECT_K
			) ?: array();
			$answers_by_question = array();
			foreach ($answer_rows as $answer) {
				$answers_by_question[(int) $answer->question_id] = $answer;
			}

			$correct = 0;
			$wrong   = 0;
			$blank   = 0;
			$score   = 0;
			$subjects = array();
			foreach ($questions as $question) {
				$subject_id = (int) ($question['subject_id'] ?? 0);
				if (! isset($subjects[$subject_id])) {
					$subjects[$subject_id] = array('correct' => 0, 'wrong' => 0, 'blank' => 0, 'score' => 0, 'max' => 0);
				}
				$subjects[$subject_id]['max'] += (int) $question['points'];
				$answer = $answers_by_question[(int) $question['id']] ?? null;
				if (! $answer || ! $answer->selected_option) {
					++$blank;
					++$subjects[$subject_id]['blank'];
					continue;
				}
				$is_correct = hash_equals((string) $question['correct_option'], (string) $answer->selected_option);
				$points     = $is_correct ? (int) $question['points'] : 0;
				if ($is_correct) {
					++$correct;
					++$subjects[$subject_id]['correct'];
				} else {
					++$wrong;
					++$subjects[$subject_id]['wrong'];
				}
				$score += $points;
				$subjects[$subject_id]['score'] += $points;
				$this->db->wpdb()->update(
					$this->db->table('answers'),
					array('is_correct' => $is_correct ? 1 : 0, 'awarded_points' => $points),
					array('id' => (int) $answer->id)
				);
			}

			$now       = time();
			$started   = strtotime((string) $attempt['started_at'] . ' UTC');
			$duration  = max(0, $now - $started);
			$revision  = $this->revision((int) $attempt['revision_id']);
			$total     = max(1, (int) $revision['total_points']);
			$late      = $this->is_past_grace($attempt);
			$eligible  = ! $late && $attempt['participant_type'] === 'member' && ! empty($revision['ranking_enabled']) && ! empty($attempt['wp_user_id']);
			$submitted = gmdate('Y-m-d H:i:s', $now);
			$affected = $this->db->wpdb()->update(
				$this->db->table('attempts'),
				array(
					'status'              => $automatic ? 'auto_submitted' : 'submitted',
					'submission_id'       => $submission_id,
					'integrity_status'    => $late ? 'late_recovered' : 'on_time',
					'ranking_eligible'    => $eligible ? 1 : 0,
					'finish_requested_at' => $submitted,
					'submitted_at'        => $submitted,
					'last_activity_at'    => $submitted,
					'duration_seconds'    => $duration,
					'correct_count'       => $correct,
					'wrong_count'         => $wrong,
					'blank_count'         => $blank,
					'score'               => $score,
					'percentage'          => round(($score / $total) * 100, 2),
				),
				array('id' => (int) $attempt['id'], 'status' => 'in_progress')
			);
			if ($affected === 0) {
				// A concurrent finalize already closed this attempt. Roll back the
				// answer rewrite and return the existing row WITHOUT re-firing the
				// paper_to_quiz_attempt_completed hook or re-running subject/ranking writes.
				$this->db->rollback();
				return $this->attempt_by_id((int) $attempt['id']);
			}

			$this->db->wpdb()->delete($this->db->table('attempt_subject_scores'), array('attempt_id' => (int) $attempt['id']), array('%d'));
			foreach ($subjects as $subject_id => $subject) {
				$this->db->wpdb()->insert(
					$this->db->table('attempt_subject_scores'),
					array(
						'attempt_id'    => (int) $attempt['id'],
						'revision_id'   => (int) $attempt['revision_id'],
						'subject_id'    => $subject_id,
						'correct_count' => $subject['correct'],
						'wrong_count'   => $subject['wrong'],
						'blank_count'   => $subject['blank'],
						'score'         => $subject['score'],
						'max_score'     => $subject['max'],
						'percentage'    => $subject['max'] ? round(($subject['score'] / $subject['max']) * 100, 2) : 0,
						'created_at'    => $submitted,
					)
				);
			}

			if ($eligible) {
				$this->db->wpdb()->query(
					$this->db->wpdb()->prepare(
						'INSERT IGNORE INTO ' . $this->db->table('ranking_entries') . ' (revision_id,wp_user_id,attempt_id,score,duration_seconds,submitted_at) VALUES (%d,%d,%d,%d,%d,%s)',
						(int) $attempt['revision_id'],
						(int) $attempt['wp_user_id'],
						(int) $attempt['id'],
						$score,
						$duration,
						$submitted
					)
				);
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollback();
			throw $error;
		}

		$final = $this->attempt_by_id((int) $attempt['id']);
		do_action('paper_to_quiz_attempt_completed', (int) $attempt['id'], $final); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public API required by the plugin contract.
		return $final;
	}

	private function result_payload(array $attempt): array {
		$content_revision = $this->revision((int) $attempt['revision_id']);
		$revision = $this->current_policy($attempt) ?: $content_revision;
		$visible  = $revision['result_visibility'];
		$release  = $revision['results_release_at_utc'] ? strtotime((string) $revision['results_release_at_utc'] . ' UTC') : null;
		$release_pending = $release && time() < $release;
		if ($release_pending) {
			$visible = 'hidden';
		}
		$can_retry = ! empty($revision['allow_repeat']);
		if ($attempt['participant_type'] === 'member' && ! empty($attempt['wp_user_id']) && ! $can_retry) {
			$can_retry = false;
		}
		$fractional = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table('questions') . ' WHERE revision_id = %d AND (points %% 100) <> 0',
				(int) $attempt['revision_id']
			)
		) > 0;

		$result = array(
			'status'           => $attempt['status'],
			'submitted'        => true,
			'visibility'       => $visible,
			'release_at'       => $revision['results_release_at_utc'] ? $this->iso((string) $revision['results_release_at_utc']) : null,
			'release_pending'  => $release_pending,
			'server_time'      => gmdate(DATE_ATOM),
			'can_retry'        => $can_retry,
			'integrity_status' => (string) ($attempt['integrity_status'] ?? 'on_time'),
			'ranking_eligible' => ! empty($attempt['ranking_eligible']),
			'score_precision'  => $fractional ? 2 : 0,
			'answer_key_visible' => $revision['feedback_timing'] !== 'never',
		);
		if ($visible === 'hidden') {
			return $result;
		}

		$result['document']   = $this->result_document($attempt, $content_revision);
		$result['score']      = (int) $attempt['score'];
		$result['percentage'] = (float) $attempt['percentage'];
		if (in_array($visible, array('summary', 'detailed'), true)) {
			$result['correct'] = (int) $attempt['correct_count'];
			$result['wrong']   = (int) $attempt['wrong_count'];
			$result['blank']   = (int) $attempt['blank_count'];
		}
		if ($visible === 'detailed') {
			$key_columns = $result['answer_key_visible']
				? 'q.correct_option,an.is_correct'
				: 'NULL correct_option,NULL is_correct';
			$result['answers'] = $this->db->wpdb()->get_results(
				$this->db->wpdb()->prepare(
					'SELECT q.id question_id,q.ordinal,' . $key_columns . ',q.points,q.subject_id,
						COALESCE(s.name,%s) subject_name,an.selected_option,an.awarded_points
					FROM ' . $this->db->table('questions') . ' q
					LEFT JOIN ' . $this->db->table('answers') . ' an ON an.question_id = q.id AND an.attempt_id = %d
					LEFT JOIN ' . $this->db->table('terms') . " s ON s.id = q.subject_id AND s.type = 'subject'
					WHERE q.revision_id = %d ORDER BY q.ordinal",
					__('Subject not specified', 'paper-to-quiz'),
					(int) $attempt['id'],
					(int) $attempt['revision_id']
				),
				ARRAY_A
			) ?: array();
		}
		if (in_array($visible, array('summary', 'detailed'), true)) {
			$result['subjects'] = $this->subject_scores((int) $attempt['id']);
			if ($attempt['participant_type'] === 'member' && ! empty($revision['ranking_enabled']) && ! empty($attempt['ranking_eligible'])) {
				$result['ranking'] = $this->ranking((int) $attempt['revision_id'], (int) $attempt['wp_user_id']);
			}
		}
		return $result;
	}

	private function result_document(array $attempt, array $revision): array {
		$participant = $this->crypto->decrypt_array($attempt['participant_data']);
		$name = trim(
			(string) ($participant['first_name'] ?? '') . ' ' .
			(string) ($participant['last_name'] ?? '')
		);
		if ($name === '' && $attempt['participant_type'] === 'member' && ! empty($attempt['wp_user_id'])) {
			$user = get_userdata((int) $attempt['wp_user_id']);
			if ($user) {
				$name = trim((string) $user->first_name . ' ' . (string) $user->last_name);
				if ($name === '') {
					$name = (string) $user->display_name;
				}
			}
		}

		return array(
			'assessment_type'  => (string) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT type FROM ' . $this->db->table('assessments') . ' WHERE id = %d',
					(int) $attempt['assessment_id']
				)
			),
			'assessment_title' => (string) ($revision['title'] ?? ''),
			'class_name'       => (string) ($revision['class_name'] ?? ''),
			'participant_name' => $name ?: __('Participant', 'paper-to-quiz'),
			'school'           => sanitize_text_field((string) ($participant['school'] ?? '')),
			'class_section'    => sanitize_text_field((string) ($participant['class_section'] ?? '')),
			'submitted_at'     => $this->format_admin_date($attempt['submitted_at']),
			'duration_seconds' => ! empty($attempt['deadline_at']) && isset($attempt['duration_seconds']) ? (int) $attempt['duration_seconds'] : null,
		);
	}

	private function current_policy(array $attempt): ?array {
		$record = $this->assessments->get((int) $attempt['assessment_id'], true);
		return $record && is_array($record['revision']) ? $record['revision'] : null;
	}

	private function subject_scores(int $attempt_id): array {
		$attempt = $this->attempt_by_id($attempt_id);
		$rows = $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				'SELECT ss.*,COALESCE(s.name,%s) subject_name
				FROM ' . $this->db->table('attempt_subject_scores') . ' ss
				LEFT JOIN ' . $this->db->table('terms') . " s ON s.id = ss.subject_id AND s.type = 'subject'
				WHERE ss.attempt_id = %d ORDER BY s.sort_order,s.name",
				__('Subject not specified', 'paper-to-quiz'),
				$attempt_id
			),
			ARRAY_A
		) ?: array();
		return array_map(
			function (array $row) use ($attempt): array {
				$result = array(
				'subject_id' => (int) $row['subject_id'],
				'name'       => (string) $row['subject_name'],
				'correct'    => (int) $row['correct_count'],
				'wrong'      => (int) $row['wrong_count'],
				'blank'      => (int) $row['blank_count'],
				'score'      => (int) $row['score'],
				'max_score'  => (int) $row['max_score'],
				'percentage' => (float) $row['percentage'],
				);
				if ($attempt && ! empty($attempt['ranking_eligible'])) {
					$total = (int) $this->db->wpdb()->get_var(
						$this->db->wpdb()->prepare(
							'SELECT COUNT(*) FROM ' . $this->db->table('attempt_subject_scores') . ' ss
							JOIN ' . $this->db->table('attempts') . ' t ON t.id = ss.attempt_id
							WHERE ss.revision_id = %d AND ss.subject_id = %d AND t.ranking_eligible = 1',
							(int) $attempt['revision_id'],
							(int) $row['subject_id']
						)
					);
					$ahead = (int) $this->db->wpdb()->get_var(
						$this->db->wpdb()->prepare(
							'SELECT COUNT(*) FROM ' . $this->db->table('attempt_subject_scores') . ' ss
							JOIN ' . $this->db->table('attempts') . ' t ON t.id = ss.attempt_id
							WHERE ss.revision_id = %d AND ss.subject_id = %d AND t.ranking_eligible = 1 AND (
								ss.score > %d OR
								(ss.score = %d AND t.duration_seconds < %d) OR
								(ss.score = %d AND t.duration_seconds = %d AND t.submitted_at < %s)
							)',
							(int) $attempt['revision_id'],
							(int) $row['subject_id'],
							(int) $row['score'],
							(int) $row['score'],
							(int) $attempt['duration_seconds'],
							(int) $row['score'],
							(int) $attempt['duration_seconds'],
							(string) $attempt['submitted_at']
						)
					);
					$rank = $ahead + 1;
					$result['ranking'] = array(
						'rank'       => $rank,
						'total'      => $total,
						'percentile' => $total ? round((($total - $rank + 1) / $total) * 100, 2) : 100,
					);
				}
				return $result;
			},
			$rows
		);
	}

	private function ranking(int $revision_id, int $user_id): ?array {
		$entry = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('ranking_entries') . ' WHERE revision_id = %d AND wp_user_id = %d',
				$revision_id,
				$user_id
			),
			ARRAY_A
		);
		if (! $entry) {
			return null;
		}
		$total = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table('ranking_entries') . ' WHERE revision_id = %d',
				$revision_id
			)
		);
		$ahead = (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table('ranking_entries') . '
				WHERE revision_id = %d AND (
					score > %d OR
					(score = %d AND duration_seconds < %d) OR
					(score = %d AND duration_seconds = %d AND submitted_at < %s)
				)',
				$revision_id,
				(int) $entry['score'],
				(int) $entry['score'],
				(int) $entry['duration_seconds'],
				(int) $entry['score'],
				(int) $entry['duration_seconds'],
				$entry['submitted_at']
			)
		);
		$rank = $ahead + 1;
		return array(
			'rank'       => $rank,
			'total'      => $total,
			'percentile' => $total ? round((($total - $rank + 1) / $total) * 100, 2) : 100,
		);
	}

	private function is_past_grace(array $attempt): bool {
		if (empty($attempt['deadline_at'])) {
			return false;
		}
		$settings = Settings::get();
		$grace    = max(0, (int) ($settings['network_grace'] ?? 30));
		return time() > strtotime((string) $attempt['deadline_at'] . ' UTC') + $grace;
	}

	private function attempt_by_id(int $attempt_id): ?array {
		$row = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('attempts') . ' WHERE id = %d',
				$attempt_id
			),
			ARRAY_A
		);
		return is_array($row) ? $row : null;
	}

	private function new_token(): string {
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	private function token_hash(string $token): string {
		return hash_hmac('sha256', $token, wp_salt('auth'));
	}

	private function iso(string $mysql_utc): string {
		return gmdate(DATE_ATOM, strtotime($mysql_utc . ' UTC'));
	}

	private function current_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : wp_parse_url(home_url(), PHP_URL_HOST);
		$uri    = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
		return $scheme . '://' . $host . $uri;
	}

	private function field_label(string $key): string {
		$labels = array(
			'first_name'    => __('First name', 'paper-to-quiz'),
			'last_name'     => __('Last name', 'paper-to-quiz'),
			'school'        => __('School name', 'paper-to-quiz'),
			'class_section' => __('Class and section', 'paper-to-quiz'),
			'email'         => __('Email', 'paper-to-quiz'),
			'phone'         => __('Phone', 'paper-to-quiz'),
		);
		return $labels[$key] ?? $key;
	}
}
