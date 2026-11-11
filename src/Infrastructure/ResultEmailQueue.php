<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

/** The email outbox is committed in the same transaction as the final result. */
final class ResultEmailQueue {
	public function __construct(private readonly Database $db) {
	}

	public function ensure(int $attempt_id, string $email): void {
		if (! is_email(sanitize_email($email))) {
			return;
		}
		$wpdb = $this->db->wpdb();
		$now = current_time('mysql', true);
		$result = $this->db->write('result_email_enqueue', fn (): int|false => $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The unique attempt index makes outbox insertion idempotent.
			'INSERT IGNORE INTO %i (attempt_id,status,attempt_count,next_run_at,created_at,updated_at) VALUES (%d,%s,0,%s,%s,%s)',
			$this->db->table('result_email_jobs'), $attempt_id, 'pending', $now, $now, $now
		)));
		if (false === $result) {
			throw new DatabaseException();
		}
	}
}
