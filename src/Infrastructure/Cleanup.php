<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

use PaperToQuiz\Application\AttemptService;

final class Cleanup {
	public const CONTINUATION_HOOK = 'paper_to_quiz_continue_cleanup';
	private const UPLOAD_BATCH_SIZE = 50;

	public function __construct(
		private readonly Database $db,
		private readonly EncryptedStorage $storage,
		private readonly AttemptService $attempts,
		private readonly ?\Closure $delete_file = null
	) {
	}

	/**
	 * Process one bounded cleanup slice.
	 *
	 * @param array<string,mixed> $cursor Non-sensitive keyset state supplied by
	 *                                    the continuation cron event.
	 */
	public function run(array $cursor = array()): void {
		$attempt_cursor = $this->attempt_cursor($cursor['attempt_cursor'] ?? null);
		$attempt_cutoff = $this->date_value($cursor['attempt_cutoff'] ?? null);
		$attempt_batch  = $this->attempts->anonymize_expired_batch($attempt_cursor, $attempt_cutoff);

		if (! $cursor) {
			$this->attempts->expire_stale_attempts();
		}

		$upload_cursor = $this->upload_cursor($cursor['upload_cursor'] ?? null);
		$upload_cutoff = $this->date_value($cursor['upload_cutoff'] ?? null) ?: current_time('mysql', true);
		$upload_batch  = $this->expire_upload_batch($upload_cursor, $upload_cutoff);

		if (
			$attempt_batch['selected'] < 100 &&
			$upload_batch['selected'] < self::UPLOAD_BATCH_SIZE
		) {
			return;
		}

		$next = array(
			'attempt_cursor' => $attempt_batch['cursor'] ?? $attempt_cursor,
			'attempt_cutoff' => $attempt_batch['cutoff'],
			'upload_cursor'  => $upload_batch['cursor'] ?? $upload_cursor,
			'upload_cutoff'  => $upload_cutoff,
		);
		$args = array($next);
		if (! wp_next_scheduled(self::CONTINUATION_HOOK, $args)) {
			wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CONTINUATION_HOOK, $args);
		}
	}

	/**
	 * @param array{expires_at:string,id:string}|null $cursor Upload keyset cursor.
	 * @return array{selected:int,succeeded:int,failed:int,cursor:array{expires_at:string,id:string}|null}
	 */
	private function expire_upload_batch(?array $cursor, string $cutoff): array {
		$where = "status = 'pending' AND expires_at < %s";
		$args  = array($cutoff);
		if ($cursor) {
			$where .= ' AND (expires_at > %s OR (expires_at = %s AND id > %s))';
			$args[] = $cursor['expires_at'];
			$args[] = $cursor['expires_at'];
			$args[] = $cursor['id'];
		}
		$args[] = self::UPLOAD_BATCH_SIZE;
		$sessions = $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				'SELECT id,manifest_json,expires_at FROM ' . $this->db->table('upload_sessions') . " WHERE {$where} ORDER BY expires_at ASC,id ASC LIMIT %d",
				...$args
			),
			ARRAY_A
		) ?: array();

		$succeeded = 0;
		$failed    = 0;
		$delete    = $this->delete_file ?? fn (string $storage_key): bool => $this->storage->delete($storage_key);
		foreach ($sessions as $session) {
			$manifest = json_decode((string) $session['manifest_json'], true);
			$manifest = is_array($manifest) ? $manifest : array();
			$deleted  = true;
			try {
				foreach ($manifest as $chunk) {
					if (! empty($chunk['storage_key']) && ! $delete((string) $chunk['storage_key'])) {
						$deleted = false;
						break;
					}
				}
			} catch (\Throwable) {
				$deleted = false;
			}

			if (! $deleted) {
				++$failed;
				$this->report_failure(new \RuntimeException('Private upload chunk deletion failed.'));
				continue;
			}

			$updated = $this->db->write(
				'upload_session_expire',
				fn (): int|false => $this->db->wpdb()->update(
					$this->db->table('upload_sessions'),
					array('status' => 'expired'),
					array('id' => (string) $session['id'], 'status' => 'pending'),
					array('%s'),
					array('%s', '%s')
				)
			);
			if (false === $updated) {
				++$failed;
				$this->report_failure(new \RuntimeException('Upload session status update failed.'));
				continue;
			}
			++$succeeded;
		}

		$last = $sessions ? $sessions[array_key_last($sessions)] : null;
		return array(
			'selected'  => count($sessions),
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'cursor'    => is_array($last)
				? array('expires_at' => (string) $last['expires_at'], 'id' => (string) $last['id'])
				: null,
		);
	}

	private function report_failure(\Throwable $error): void {
		OperationalErrorReporter::report(
			'paper_to_quiz_upload_cleanup_failed',
			$error,
			__('A private upload could not be cleaned up and will be retried.', 'paper-to-quiz'),
			500
		);
	}

	/** @return array{submitted_at:string,id:int}|null */
	private function attempt_cursor(mixed $value): ?array {
		if (! is_array($value) || empty($value['submitted_at']) || (int) ($value['id'] ?? 0) < 1) {
			return null;
		}
		$date = $this->date_value($value['submitted_at']);
		return $date ? array('submitted_at' => $date, 'id' => (int) $value['id']) : null;
	}

	/** @return array{expires_at:string,id:string}|null */
	private function upload_cursor(mixed $value): ?array {
		if (! is_array($value) || empty($value['expires_at']) || ! is_string($value['id'] ?? null)) {
			return null;
		}
		$date = $this->date_value($value['expires_at']);
		$id   = sanitize_text_field((string) $value['id']);
		return $date && $id !== '' ? array('expires_at' => $date, 'id' => $id) : null;
	}

	private function date_value(mixed $value): ?string {
		if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
			return null;
		}
		return $value;
	}
}
