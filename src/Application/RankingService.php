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
		$value = remove_accents(strtr($value, array('İ' => 'i', 'I' => 'i', 'ı' => 'i')));
		// mbstring is optional in WordPress; Turkish/Latin letters are ASCII now.
		$value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
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
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT COUNT(*) total,SUM(CASE WHEN ' . $this->ahead_of('e', 'oe') . ' THEN 1 ELSE 0 END) ahead
			FROM ' . $this->source() . ' JOIN (' . $this->source('oe', 'ot') . ') ON oe.revision_id = e.revision_id
			WHERE e.revision_id = %d AND oe.attempt_id = %d AND ' . $this->eligible() . ' AND ' . $this->eligible('ot'),
			$revision_id, $attempt_id
		), ARRAY_A);
		return empty($row['total']) ? null : $this->position((int) $row['ahead'], (int) $row['total']);
	}

	private function ahead_of(string $entry, string $other): string {
		return "({$entry}.score > {$other}.score OR ({$entry}.score = {$other}.score AND {$entry}.duration_seconds < {$other}.duration_seconds)
			OR ({$entry}.score = {$other}.score AND {$entry}.duration_seconds = {$other}.duration_seconds AND {$entry}.submitted_at < {$other}.submitted_at))";
	}

	/** Read all of an attempt's subject positions in one query. */
	public function subject_ranks(array $attempt): array {
		$wpdb = $this->db->wpdb();
		$table = $this->db->table('attempt_subject_scores');
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT ss.subject_id,COUNT(*) total,SUM(CASE WHEN ss.score > own.score
			OR (ss.score = own.score AND e.duration_seconds < %d)
			OR (ss.score = own.score AND e.duration_seconds = %d AND e.submitted_at < %s)
			THEN 1 ELSE 0 END) ahead
			FROM ' . $this->source() . ' JOIN ' . $table . ' ss ON ss.attempt_id = e.attempt_id AND ss.revision_id = e.revision_id
			JOIN ' . $table . ' own ON own.revision_id = ss.revision_id AND own.subject_id = ss.subject_id AND own.attempt_id = %d
			WHERE e.revision_id = %d AND ' . $this->eligible() . '
			AND EXISTS (SELECT 1 FROM ' . $this->source('oe', 'ot') . ' WHERE oe.attempt_id = own.attempt_id
			AND oe.revision_id = own.revision_id AND ' . $this->eligible('ot') . ')
			GROUP BY ss.subject_id',
			(int) $attempt['duration_seconds'], (int) $attempt['duration_seconds'], (string) $attempt['submitted_at'],
			(int) $attempt['id'], (int) $attempt['revision_id']
		), ARRAY_A) ?: array();
		$ranks = array();
		foreach ($rows as $row) {
			$ranks[(int) $row['subject_id']] = $this->position((int) $row['ahead'], (int) $row['total']);
		}
		return $ranks;
	}

	private function position(int $ahead, int $total): array {
		$rank = $ahead + 1;
		return array('rank' => $rank, 'total' => $total, 'percentile' => $total ? round((($total - $rank + 1) / $total) * 100, 2) : 100);
	}

	/** Unfiltered pages need at most one look-up for a tie across the page boundary. */
	public function listing(int $revision_id, int $page, int $per_page, string $search): array {
		$page = max(1, min(1000000, $page));
		$per_page = max(1, min(100, $per_page));
		$wpdb = $this->db->wpdb();
		$where = ' WHERE e.revision_id = %d AND ' . $this->eligible();
		$args = array($revision_id);
		$total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $this->source() . $where, $args));
		$search = self::search_name($search);
		$offset = ($page - 1) * $per_page;
		$matched = $total;
		$position = '';
		if ($search !== '') {
			$where .= ' AND e.public_search LIKE %s';
			$args[] = '%' . $wpdb->esc_like($search) . '%';
			$matched = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $this->source() . $where, $args));
			$position = ',(SELECT COUNT(*) + 1 FROM ' . $this->source('e2', 't2') . ' WHERE e2.revision_id = e.revision_id AND '
				. $this->eligible('t2') . ' AND ' . $this->ahead_of('e2', 'e') . ') ranking_position';
		}
		$rows = $offset >= $matched ? array() : ($wpdb->get_results($wpdb->prepare(
			'SELECT e.public_label,e.score,e.duration_seconds,e.submitted_at' . $position . '
			FROM ' . $this->source() . $where . '
			ORDER BY e.score DESC,e.duration_seconds ASC,e.submitted_at ASC,e.id ASC LIMIT %d OFFSET %d',
			array_merge($args, array($per_page, $offset))
		), ARRAY_A) ?: array());
		if ($rows && $search === '') {
			$rank = 1;
			if ($offset > 0) {
				$rank = 1 + (int) $wpdb->get_var($wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $this->source() . ' JOIN (SELECT %d score,%d duration_seconds,%s submitted_at) boundary
					WHERE e.revision_id = %d AND ' . $this->eligible() . ' AND ' . $this->ahead_of('e', 'boundary'),
					(int) $rows[0]['score'], (int) $rows[0]['duration_seconds'], $rows[0]['submitted_at'], $revision_id
				));
			}
			$previous = null;
			foreach ($rows as $index => &$row) {
				$order = array($row['score'], $row['duration_seconds'], $row['submitted_at']);
				if ($index > 0 && $order !== $previous) {
					$rank = $offset + $index + 1;
				}
				$row['ranking_position'] = $rank;
				$previous = $order;
			}
			unset($row);
		}
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
