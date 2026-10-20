<?php

declare(strict_types=1);

namespace PaperToQuiz\Application;

use PaperToQuiz\Infrastructure\Database;

// PHPCS cannot resolve source()/eligible(): they contain only internal aliases,
// allowlisted table names, and fixed predicates. Every request value is prepared.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// Plugin Check likewise cannot follow those fixed SQL fragments or the combined
// placeholder/argument arrays. Each array supplies all placeholders exactly once;
// search uses esc_like() followed by %s, and pagination/revision IDs use %d.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// Rankings must reflect submissions and privacy erasure immediately, without a stale identity cache.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

/** Ranking projections contain only deliberately published, masked names. */
final class RankingService {
	public function __construct(private readonly Database $db) {
	}

	public static function enabled(array $revision, string $type): bool {
		return $type === 'exam' && ! empty($revision['ranking_enabled']) && empty($revision['allow_repeat']);
	}

	public static function public_label(array $participant): string {
		$first = preg_split('/\s+/u', trim(sanitize_text_field((string) ($participant['first_name'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: array();
		$last  = preg_split('/\s+/u', trim(sanitize_text_field((string) ($participant['last_name'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: array();
		if (! $first) {
			return __('Participant', 'paper-to-quiz');
		}
		$label = array_shift($first);
		foreach (array_merge($first, $last) as $word) {
			$label .= ' ' . mb_substr($word, 0, 1) . '.';
		}
		return mb_substr($label, 0, 190);
	}

	public static function search_name(string $value): string {
		$value = mb_strtolower(strtr($value, array('İ' => 'i', 'I' => 'i', 'ı' => 'i')));
		$value = remove_accents($value);
		return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '');
	}

	/** All ranking surfaces use the same persisted eligibility and privacy checks. */
	private function source(string $entry = 'e', string $attempt = 't'): string {
		// Aliases are internal constants; table names come from Database's allowlist.
		return $this->db->table('ranking_entries') . " {$entry} JOIN " . $this->db->table('attempts') . " {$attempt}
			ON {$attempt}.id = {$entry}.attempt_id AND {$attempt}.revision_id = {$entry}.revision_id";
	}

	private function eligible(string $attempt = 't'): string {
		return "{$attempt}.ranking_eligible = 1 AND {$attempt}.anonymized_at IS NULL
			AND {$attempt}.integrity_status = 'on_time' AND {$attempt}.status IN ('submitted','auto_submitted')";
	}

	public function rank(int $revision_id, int $attempt_id): ?array {
		$wpdb = $this->db->wpdb();
		$entry = $wpdb->get_row($wpdb->prepare(
			'SELECT e.score,e.duration_seconds,e.submitted_at FROM ' . $this->source() . '
			WHERE e.revision_id = %d AND e.attempt_id = %d AND ' . $this->eligible(),
			$revision_id, $attempt_id
		), ARRAY_A);
		if (! $entry) {
			return null;
		}
		$total = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $this->source() . ' WHERE e.revision_id = %d AND ' . $this->eligible(), $revision_id
		));
		$ahead = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $this->source() . ' WHERE e.revision_id = %d AND ' . $this->eligible() . '
			AND (e.score > %d OR (e.score = %d AND e.duration_seconds < %d)
			OR (e.score = %d AND e.duration_seconds = %d AND e.submitted_at < %s))',
			$revision_id, (int) $entry['score'], (int) $entry['score'], (int) $entry['duration_seconds'],
			(int) $entry['score'], (int) $entry['duration_seconds'], $entry['submitted_at']
		));
		return $this->position($ahead, $total);
	}

	public function subject_rank(array $attempt, array $subject): ?array {
		$wpdb = $this->db->wpdb();
		$source = $this->source() . ' JOIN ' . $this->db->table('attempt_subject_scores') . ' ss ON ss.attempt_id = e.attempt_id AND ss.revision_id = e.revision_id';
		$where = ' WHERE e.revision_id = %d AND ss.subject_id = %d AND ' . $this->eligible();
		$args = array((int) $attempt['revision_id'], (int) $subject['subject_id']);
		$total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $source . $where, $args));
		if (! $total || ! $this->rank_entry_exists((int) $attempt['id'])) {
			return null;
		}
		$ahead = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $source . $where . '
			AND (ss.score > %d OR (ss.score = %d AND e.duration_seconds < %d)
			OR (ss.score = %d AND e.duration_seconds = %d AND e.submitted_at < %s))',
			array_merge($args, array((int) $subject['score'], (int) $subject['score'], (int) $attempt['duration_seconds'],
				(int) $subject['score'], (int) $attempt['duration_seconds'], $attempt['submitted_at']))
		));
		return $this->position($ahead, $total);
	}

	private function rank_entry_exists(int $attempt_id): bool {
		return (bool) $this->db->wpdb()->get_var($this->db->wpdb()->prepare(
			'SELECT 1 FROM ' . $this->source() . ' WHERE e.attempt_id = %d AND ' . $this->eligible(), $attempt_id
		));
	}

	private function position(int $ahead, int $total): array {
		$rank = $ahead + 1;
		return array('rank' => $rank, 'total' => $total, 'percentile' => $total ? round((($total - $rank + 1) / $total) * 100, 2) : 100);
	}

	/** Three bounded queries, no participant decryption or per-row database calls. */
	public function listing(int $revision_id, int $page, int $per_page, string $search): array {
		$page = max(1, min(1000000, $page));
		$per_page = max(1, min(100, $per_page));
		$wpdb = $this->db->wpdb();
		$where = ' WHERE e.revision_id = %d AND ' . $this->eligible();
		$args = array($revision_id);
		$total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $this->source() . $where, $args));
		$search = self::search_name($search);
		if ($search !== '') {
			$where .= ' AND e.public_search LIKE %s';
			$args[] = '%' . $wpdb->esc_like($search) . '%';
		}
		$matched = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $this->source() . $where, $args));
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT e.public_label,e.score,e.duration_seconds,
			(SELECT COUNT(*) + 1 FROM ' . $this->source('e2', 't2') . ' WHERE e2.revision_id = e.revision_id AND ' . $this->eligible('t2') . '
			AND (e2.score > e.score OR (e2.score = e.score AND e2.duration_seconds < e.duration_seconds)
			OR (e2.score = e.score AND e2.duration_seconds = e.duration_seconds AND e2.submitted_at < e.submitted_at))) ranking_position
			FROM ' . $this->source() . $where . '
			ORDER BY e.score DESC,e.duration_seconds ASC,e.submitted_at ASC,e.id ASC LIMIT %d OFFSET %d',
			array_merge($args, array($per_page, ($page - 1) * $per_page))
		), ARRAY_A) ?: array();
		return array(
			'revision_id' => $revision_id, 'page' => $page, 'per_page' => $per_page,
			'total' => $matched, 'total_pages' => (int) ceil($matched / $per_page), 'participant_total' => $total,
			'items' => array_map(static fn (array $row): array => array(
				'rank' => (int) $row['ranking_position'], 'display_name' => $row['public_label'] ?: __('Participant', 'paper-to-quiz'),
				'score' => (int) $row['score'], 'duration_seconds' => (int) $row['duration_seconds'],
			), $rows),
		);
	}
}
