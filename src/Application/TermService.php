<?php

declare(strict_types=1);

namespace PaperToQuiz\Application;

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\DatabaseException;

final class TermService {
	public function __construct(
		private readonly Database $db
	) {
	}

	public function classes(
		string $status = 'active',
		string $search = '',
		int $page = 1,
		int $per_page = 20,
		int $focus = 0
	): array|\WP_Error {
		return $this->terms('class', $status, $search, $page, $per_page, $focus);
	}

	public function save_class(string $name, ?int $id = null, ?string $color = null): array|\WP_Error {
		$raw_color = trim((string) $color);
		$color     = $raw_color === '' ? null : sanitize_hex_color($raw_color);
		if ($raw_color !== '' && $color === null) {
			return new \WP_Error(
				'paper_to_quiz_invalid_class_color',
				__('Select a valid class color.', 'paper-to-quiz'),
				array('status' => 400)
			);
		}
		return $this->save_term('class', $name, $id, array('color' => $color));
	}

	public function archive_class(int $id): bool {
		return false !== $this->db->wpdb()->update(
			$this->db->table('terms'),
			array('status' => 'archived', 'updated_at' => current_time('mysql', true)),
			array('id' => $id, 'type' => 'class'),
			null,
			array('%d', '%s')
		);
	}

	public function restore_class(int $id): bool {
		return $this->set_term_status('class', $id, 'active');
	}

	public function trash_class(int $id): bool {
		return $this->set_term_status('class', $id, 'trash');
	}

	public function purge_class(int $id): bool|\WP_Error {
		return $this->purge_term('class', $id);
	}

	public function subjects(string $status = 'active', string $search = '', int $page = 1, int $per_page = 100, int $focus = 0): array|\WP_Error {
		return $this->terms('subject', $status, $search, $page, $per_page, $focus);
	}

	public function save_subject(string $name, ?int $id = null): array|\WP_Error {
		return $this->save_term('subject', $name, $id);
	}

	public function archive_subject(int $id): bool {
		return $this->set_term_status('subject', $id, 'archived');
	}

	public function restore_subject(int $id): bool {
		return $this->set_term_status('subject', $id, 'active');
	}

	public function trash_subject(int $id): bool {
		return $this->set_term_status('subject', $id, 'trash');
	}

	public function purge_subject(int $id): bool|\WP_Error {
		return $this->purge_term('subject', $id);
	}

	private function terms(string $type, string $status, string $search, int $page, int $per_page, int $focus): array|\WP_Error {
		$wpdb = $this->db->wpdb();
		$previous_suppression = $wpdb->suppress_errors(true);
		try {
			return $this->query_terms($type, $status, $search, $page, $per_page, $focus);
		} finally {
			$wpdb->suppress_errors($previous_suppression);
		}
	}

	private function query_terms(string $type, string $status, string $search, int $page, int $per_page, int $focus): array|\WP_Error {
		// This custom table is authoritative for catalog changes, so reads must bypass persistent caching.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb = $this->db->wpdb();
		$table = $this->db->table('terms');
		$status = in_array($status, array('active', 'archived', 'trash'), true) ? $status : 'active';
		$where = 't.type = %s AND t.status = %s';
		$args = array($table, $type, $status);
		if ($search !== '') {
			$where .= ' AND t.name LIKE %s';
			$args[] = '%' . $wpdb->esc_like($search) . '%';
		}
		$total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i t WHERE {$where}", ...$args)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE contains only fixed clauses with placeholders.
		if ($total === null || $wpdb->last_error !== '') {
			return (new DatabaseException(DatabaseException::is_busy($wpdb->last_error)))->rest_error();
		}
		$total = (int) $total;
		$per_page = max(1, $per_page);
		$pages = (int) ceil($total / $per_page);
		if ($focus > 0) {
			$position = $this->term_position($where, $args, $focus);
			if (is_wp_error($position)) {
				return $position;
			}
			if ($position !== null) {
				$page = intdiv($position, $per_page) + 1;
			}
		}
		$page = max(1, min($page, $pages));
		$usage_table = $this->db->table($type === 'subject' ? 'questions' : 'revisions');
		$usage_sql = $type === 'subject'
			? '(SELECT COUNT(*) FROM %i q WHERE q.subject_id = t.id)'
			: '(SELECT COUNT(DISTINCT r.assessment_id) FROM %i r WHERE r.class_id = t.id)';
		$items = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Usage and WHERE are fixed SQL fragments; all tables and values use placeholders.
				"SELECT t.*, {$usage_sql} usage_count FROM %i t WHERE {$where} ORDER BY t.sort_order, t.name, t.id LIMIT %d OFFSET %d",
				...array_merge(array($usage_table), $args, array($per_page, ($page - 1) * $per_page))
			),
			ARRAY_A
		);
		if ($wpdb->last_error !== '') {
			return (new DatabaseException(DatabaseException::is_busy($wpdb->last_error)))->rest_error();
		}
		$count_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) count FROM %i WHERE type = %s GROUP BY status',
				$table,
				$type
			),
			ARRAY_A
		);
		if ($wpdb->last_error !== '') {
			return (new DatabaseException(DatabaseException::is_busy($wpdb->last_error)))->rest_error();
		}
		$counts = array('active' => 0, 'archived' => 0, 'trash' => 0);
		foreach ($count_rows as $row) {
			if (isset($counts[$row['status']])) {
				$counts[$row['status']] = (int) $row['count'];
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return array('items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page, 'counts' => $counts);
	}

	/** Count earlier rows using the same filters and collation as the list. */
	private function term_position(string $where, array $args, int $focus): int|\WP_Error|null {
		// Resolve the page from current data using the fixed WHERE clauses built by query_terms().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb = $this->db->wpdb();
		$term = $wpdb->get_row(
			$wpdb->prepare("SELECT t.id, t.name, t.sort_order FROM %i t WHERE {$where} AND t.id = %d", ...array_merge($args, array($focus))),
			ARRAY_A
		);
		if ($wpdb->last_error !== '') {
			return (new DatabaseException(DatabaseException::is_busy($wpdb->last_error)))->rest_error();
		}
		if ($term === null) {
			return null;
		}
		$position = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i t WHERE {$where}
					AND (t.sort_order < %d OR (t.sort_order = %d AND (t.name < %s OR (t.name = %s AND t.id < %d))))",
				...array_merge($args, array($term['sort_order'], $term['sort_order'], $term['name'], $term['name'], $focus))
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $position === null || $wpdb->last_error !== ''
			? (new DatabaseException(DatabaseException::is_busy($wpdb->last_error)))->rest_error()
			: (int) $position;
	}

	private function save_term(string $type, string $name, ?int $id, array $extra = array()): array|\WP_Error {
		if (trim($name) === '') {
			return new \WP_Error('paper_to_quiz_invalid_term', __('Invalid term.', 'paper-to-quiz'), array('status' => 400));
		}
		$name = sanitize_text_field($name);
		$slug = sanitize_title($name);
		if ($slug === '') {
			return new \WP_Error('paper_to_quiz_invalid_term', __('Invalid term.', 'paper-to-quiz'), array('status' => 400));
		}
		$data = array(
			'type'       => $type,
			'name'       => $name,
			'slug'       => $slug,
			'status'     => 'active',
			'updated_at' => current_time('mysql', true),
		);
		if ($type === 'class' && array_key_exists('color', $extra)) {
			$data['color'] = $extra['color'];
		}
		if ($id) {
			$conflict = $this->find_term_by_slug($type, $slug, $id);
			if ($conflict) {
				return $this->term_exists_error($type, $conflict);
			}
			if (false === $this->db->wpdb()->update($this->db->table('terms'), $data, array('id' => $id, 'type' => $type))) {
				return new \WP_Error('paper_to_quiz_term_save_failed', __('The term could not be saved.', 'paper-to-quiz'), array('status' => 500));
			}
		} else {
			$existing = $this->find_term_by_slug($type, $slug);
			if ($existing) {
				if ($existing['status'] !== 'trash') {
					return $this->term_exists_error($type, $existing);
				}
				$id = (int) $existing['id'];
				if (false === $this->db->wpdb()->update($this->db->table('terms'), $data, array('id' => $id, 'type' => $type, 'status' => 'trash'))) {
					return new \WP_Error('paper_to_quiz_term_save_failed', __('The term could not be restored.', 'paper-to-quiz'), array('status' => 500));
				}
			} else {
				$data['created_at'] = current_time('mysql', true);
				$inserted = $this->db->wpdb()->insert($this->db->table('terms'), $data);
				if ($inserted === false) {
					$existing = $this->find_term_by_slug($type, $slug);
					if ($existing) {
						return $this->term_exists_error($type, $existing);
					}
					return new \WP_Error('paper_to_quiz_term_save_failed', __('The term could not be saved.', 'paper-to-quiz'), array('status' => 500));
				}
				$id = (int) $this->db->wpdb()->insert_id;
			}
		}
		$row = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare('SELECT * FROM ' . $this->db->table('terms') . ' WHERE id = %d AND type = %s', $id, $type),
			ARRAY_A
		);
		return $row ?: new \WP_Error('paper_to_quiz_term_not_found', __('Term not found.', 'paper-to-quiz'), array('status' => 404));
	}

	private function find_term_by_slug(string $type, string $slug, ?int $except_id = null): ?array {
		$query = 'SELECT * FROM ' . $this->db->table('terms') . ' WHERE type = %s AND slug = %s';
		$args  = array($type, $slug);
		if ($except_id) {
			$query .= ' AND id <> %d';
			$args[] = $except_id;
		}
		$row = $this->db->wpdb()->get_row($this->db->wpdb()->prepare($query, ...$args), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	private function term_exists_error(string $type, array $term): \WP_Error {
		$label = $type === 'class' ? __('class', 'paper-to-quiz') : __('subject', 'paper-to-quiz');
		return new \WP_Error(
			'paper_to_quiz_term_exists',
			sprintf(
				/* translators: %s: Class or subject label. */
				__('A %s with this name already exists.', 'paper-to-quiz'),
				$label
			),
			array(
				'status'        => 409,
				'term_id'       => (int) $term['id'],
				'term_status'   => (string) $term['status'],
			)
		);
	}

	private function set_term_status(string $type, int $id, string $status, ?string $required_status = null): bool {
		$where = array('id' => $id, 'type' => $type);
		if ($required_status) {
			$where['status'] = $required_status;
		}
		return false !== $this->db->wpdb()->update(
			$this->db->table('terms'),
			array('status' => $status, 'updated_at' => current_time('mysql', true)),
			$where
		);
	}

	private function purge_term(string $type, int $id): bool|\WP_Error {
		$term = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('terms') . ' WHERE id = %d AND type = %s',
				$id,
				$type
			),
			ARRAY_A
		);
		if (! $term) {
			return new \WP_Error('paper_to_quiz_term_not_found', __('Record not found.', 'paper-to-quiz'), array('status' => 404));
		}
		if ($term['status'] !== 'trash') {
			return new \WP_Error(
				'paper_to_quiz_term_not_in_trash',
				__('The record must be moved to the trash before it can be permanently deleted.', 'paper-to-quiz'),
				array('status' => 409)
			);
		}
		$usage = $type === 'subject'
			? (int) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table('questions') . ' WHERE subject_id = %d',
					$id
				)
			)
			: (int) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare(
					'SELECT COUNT(*) FROM ' . $this->db->table('revisions') . ' WHERE class_id = %d',
					$id
				)
			);
		if ($usage > 0) {
			return new \WP_Error(
				'paper_to_quiz_term_in_use',
				sprintf(
					/* translators: %d: Number of references to the class or subject. */
					__('This record cannot be permanently deleted because it is used in %d historical revisions or questions.', 'paper-to-quiz'),
					$usage
				),
				array('status' => 409, 'usage_count' => $usage)
			);
		}
		if (1 !== $this->db->wpdb()->delete(
			$this->db->table('terms'),
			array('id' => $id, 'type' => $type, 'status' => 'trash'),
			array('%d', '%s', '%s')
		)) {
			return new \WP_Error('paper_to_quiz_term_purge_failed', __('The record could not be permanently deleted.', 'paper-to-quiz'), array('status' => 500));
		}
		return true;
	}
}
