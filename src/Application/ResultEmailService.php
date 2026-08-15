<?php

declare(strict_types=1);

namespace PaperToQuiz\Application;

use PaperToQuiz\Infrastructure\Database;

final class ResultEmailService {
	public function __construct(
		private readonly Database $db,
		private readonly AttemptService $attempts
	) {
	}

	public function enqueue(int $attempt_id): void {
		$context = $this->attempts->email_context($attempt_id);
		if (! $context) {
			return;
		}
		$now = current_time('mysql', true);
		$this->db->wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue writes must be durable and immediately visible to concurrent workers.
			$this->db->wpdb()->prepare(
				'INSERT IGNORE INTO %i
				(attempt_id,status,attempt_count,next_run_at,created_at,updated_at)
				VALUES (%d,%s,0,%s,%s,%s)',
				$this->db->table('result_email_jobs'),
				$attempt_id,
				'pending',
				$now,
				$now,
				$now
			)
		);
		wp_schedule_single_event(time() + 10, 'paper_to_quiz_process_result_emails', array($attempt_id), true);
	}

	public function process(): void {
		$this->release_stale_claims();

		$wpdb = $this->db->wpdb();
		$now  = current_time('mysql', true);
		$table = $this->db->table('result_email_jobs');

		/*
		 * Two-phase atomic claim:
		 * 1. Read up to 20 due job ids.
		 * 2. Atomically flip each row from pending/retry -> processing (and stamp
		 *    claimed_at). The status guard makes the claim safe against another
		 *    worker that selected the same ids; only an update affecting exactly
		 *    one row belongs to this worker.
		 * 3. Re-select only the ids this worker actually claimed
		 *    and process them. wp_mail is gated behind a successful claim, which
		 *    closes the duplicate-send vector when the recurring 5-minute tick
		 *    and a per-attempt single event overlap.
		 */
		$candidates = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Due queue rows must be read from current database state.
			$wpdb->prepare(
				"SELECT id FROM %i
				WHERE status IN ('pending','retry') AND next_run_at <= %s
				ORDER BY next_run_at,id LIMIT 20",
				$table,
				$now
			),
			ARRAY_A
		) ?: array();

		if (! $candidates) {
			return;
		}

		$ids = array_filter(array_map('intval', array_column($candidates, 'id')));
		if (! $ids) {
			return;
		}

		$claimed_ids = array();
		foreach ($ids as $id) {
			$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update is the queue's atomic claim operation.
				$wpdb->prepare(
					"UPDATE %i SET status = 'processing', claimed_at = %s
					WHERE id = %d AND status IN ('pending','retry')",
					$table,
					$now,
					$id
				)
			);
			if (1 === $affected) {
				$claimed_ids[] = $id;
			}
		}

		if (! $claimed_ids) {
			return;
		}

		$id_placeholders = implode(',', array_fill(0, count($claimed_ids), '%d'));
		$claimed         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Claimed queue rows must be read from current database state.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id IN ({$id_placeholders}) AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The interpolated value contains only generated %d placeholders.
				...array_merge(array($table), $claimed_ids)
			),
			ARRAY_A
		) ?: array();

		foreach ($claimed as $job) {
			$this->process_job($job);
		}
	}

	/**
	 * Release claims held past their TTL. A worker that died mid-send leaves the
	 * row stranded in 'processing'; this revives it to 'pending' so the next
	 * process() tick reclaims it. The 15-minute TTL comfortably exceeds the
	 * worst-case wp_mail duration.
	 */
	public function release_stale_claims(): void {
		$wpdb   = $this->db->wpdb();
		$cutoff = gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale queue claims must be released atomically.
			$wpdb->prepare(
				"UPDATE %i
				SET status = 'pending', claimed_at = NULL
				WHERE status = 'processing' AND claimed_at < %s",
				$this->db->table('result_email_jobs'),
				$cutoff
			)
		);
	}

	private function process_job(array $job): void {
		$context = $this->attempts->email_context((int) $job['attempt_id']);
		if (! $context) {
			$this->fail_permanently((int) $job['id'], __('The email address could not be found.', 'paper-to-quiz'));
			return;
		}
		$result = $context['result'];
		if (($result['visibility'] ?? 'hidden') === 'hidden') {
			$release = ! empty($result['release_at']) ? strtotime((string) $result['release_at']) : false;
			if ($release && $release > time()) {
				$next = $release + 30;
				$this->db->wpdb()->update(
					$this->db->table('result_email_jobs'),
					array('status' => 'pending', 'next_run_at' => gmdate('Y-m-d H:i:s', $next), 'claimed_at' => null, 'updated_at' => current_time('mysql', true)),
					array('id' => (int) $job['id'])
				);
				/*
				 * Schedule a dedicated single event at the release moment so the
				 * deferred email is delivered on time instead of relying solely on
				 * the recurring 5-minute queue tick. Mirrors the immediate-send
				 * path used by enqueue().
				 */
				wp_schedule_single_event($next, 'paper_to_quiz_process_result_emails', array((int) $job['attempt_id']), true);
				return;
			}
			$this->db->wpdb()->update(
				$this->db->table('result_email_jobs'),
				array(
					'status'      => 'pending',
					'next_run_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
					'claimed_at'  => null,
					'last_error'  => __('The result policy currently prevents email delivery.', 'paper-to-quiz'),
					'updated_at'  => current_time('mysql', true),
				),
				array('id' => (int) $job['id'])
			);
			return;
		}

		$subject = sprintf(
			/* translators: %s: Assessment title. */
			__('%s result document', 'paper-to-quiz'),
			(string) $context['title']
		);
		$sent = wp_mail(
			(string) $context['email'],
			$subject,
			$this->render($context),
			array('Content-Type: text/html; charset=UTF-8')
		);
		$now = current_time('mysql', true);
		if ($sent) {
			$this->db->wpdb()->update(
				$this->db->table('result_email_jobs'),
				array('status' => 'sent', 'sent_at' => $now, 'claimed_at' => null, 'updated_at' => $now, 'last_error' => null),
				array('id' => (int) $job['id'])
			);
			return;
		}
		$count = (int) $job['attempt_count'] + 1;
		if ($count >= 8) {
			$this->fail_permanently((int) $job['id'], __('wp_mail delivery failed.', 'paper-to-quiz'), $count);
			return;
		}
		$delay = min(DAY_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (2 ** max(0, $count - 1)));
		$this->db->wpdb()->update(
			$this->db->table('result_email_jobs'),
			array(
				'status'        => 'retry',
				'attempt_count' => $count,
				'next_run_at'   => gmdate('Y-m-d H:i:s', time() + $delay),
				'claimed_at'    => null,
				'last_error'    => __('wp_mail delivery failed.', 'paper-to-quiz'),
				'updated_at'    => $now,
			),
			array('id' => (int) $job['id'])
		);
	}

	private function render(array $context): string {
		$result          = $context['result'];
		$participant     = $context['participant'];
		$document        = (array) ($result['document'] ?? array());
		$precision       = (int) ($result['score_precision'] ?? 0);
		$include_summary = in_array($result['visibility'], array('summary', 'detailed'), true);
		$name = (string) ($document['participant_name'] ?? trim(
			(string) ($participant['first_name'] ?? '') . ' ' .
			(string) ($participant['last_name'] ?? '')
		));
		$border = 'border:1px solid #d7e0e8;';
		$head   = $border . 'padding:9px 10px;background:#eef5fb;color:#34495e;font-size:11px;text-align:left;text-transform:uppercase;';
		$cell   = $border . 'padding:10px;color:#17202a;font-size:13px;';
		$document_heading = ($document['assessment_type'] ?? 'exam') === 'test'
			? __('Test result document', 'paper-to-quiz')
			: __('Exam result document', 'paper-to-quiz');

		$info_rows = '<tr><th style="' . $head . '">' . esc_html__('Participant', 'paper-to-quiz') . '</th><td style="' . $cell . '">' .
			esc_html($name ?: __('Participant', 'paper-to-quiz')) .
			'</td><th style="' . $head . '">' . esc_html__('Class', 'paper-to-quiz') . '</th><td style="' . $cell . '">' .
			esc_html((string) ($document['class_section'] ?? $document['class_name'] ?? $context['class_name'] ?? '—')) .
			'</td></tr><tr><th style="' . $head . '">' . esc_html__('School', 'paper-to-quiz') . '</th><td style="' . $cell . '">' .
			esc_html((string) ($document['school'] ?? $participant['school'] ?? '—')) .
			'</td><th style="' . $head . '">' . esc_html__('Submission date', 'paper-to-quiz') . '</th><td style="' . $cell . '">' .
			esc_html((string) ($document['submitted_at'] ?? $context['submitted_at'])) . '</td></tr>';
		if (isset($document['duration_seconds']) && $document['duration_seconds'] !== null) {
			$info_rows .= '<tr><th style="' . $head . '">' . esc_html__('Duration', 'paper-to-quiz') . '</th><td colspan="3" style="' . $cell . '">' .
				esc_html($this->duration((int) $document['duration_seconds'])) . '</td></tr>';
		}

		$summary_headers = '<th style="' . $head . 'text-align:center">' . esc_html__('Points', 'paper-to-quiz') . '</th>';
		$summary_values  = '<td style="' . $cell . 'text-align:center;font-size:22px;font-weight:700;color:#0b5fa5">' .
			esc_html($this->score((int) ($result['score'] ?? 0), $precision)) . '</td>';
		if ($include_summary) {
			$summary_headers .= '<th style="' . $head . 'text-align:center">' . esc_html__('Success', 'paper-to-quiz') . '</th><th style="' . $head .
				'text-align:center">' . esc_html__('Correct', 'paper-to-quiz') . '</th><th style="' . $head . 'text-align:center">' . esc_html__('Wrong', 'paper-to-quiz') . '</th><th style="' .
				$head . 'text-align:center">' . esc_html__('Blank', 'paper-to-quiz') . '</th>';
			$summary_values .= '<td style="' . $cell . 'text-align:center">%' . esc_html((string) $result['percentage']) .
				'</td><td style="' . $cell . 'text-align:center">' . esc_html((string) $result['correct']) .
				'</td><td style="' . $cell . 'text-align:center">' . esc_html((string) $result['wrong']) .
				'</td><td style="' . $cell . 'text-align:center">' . esc_html((string) $result['blank']) . '</td>';
		}
		$summary_table = '<table role="presentation" style="width:100%;border-collapse:collapse"><thead><tr>' .
			$summary_headers . '</tr></thead><tbody><tr>' . $summary_values . '</tr></tbody></table>';

		$subject_table = '';
		if ($include_summary && ! empty($result['subjects'])) {
			$has_subject_ranking = false;
			foreach ((array) $result['subjects'] as $subject) {
				$has_subject_ranking = $has_subject_ranking || ! empty($subject['ranking']);
			}
			$subject_rows = '';
			foreach ((array) $result['subjects'] as $subject) {
				$subject_rows .= '<tr><td style="' . $cell . 'font-weight:700">' . esc_html((string) $subject['name']) .
					'</td><td style="' . $cell . 'text-align:center">' . esc_html((string) $subject['correct']) .
					'</td><td style="' . $cell . 'text-align:center">' . esc_html((string) $subject['wrong']) .
					'</td><td style="' . $cell . 'text-align:center">' . esc_html((string) $subject['blank']) .
					'</td><td style="' . $cell . 'text-align:center">' .
					esc_html($this->score((int) $subject['score'], $precision)) . ' / ' .
					esc_html($this->score((int) $subject['max_score'], $precision)) .
					'</td><td style="' . $cell . 'text-align:center">%' . esc_html((string) $subject['percentage']) . '</td>';
				if ($has_subject_ranking) {
					$subject_rows .= '<td style="' . $cell . 'text-align:center">' .
						(! empty($subject['ranking'])
							? esc_html((string) $subject['ranking']['rank'] . ' / ' . (string) $subject['ranking']['total'])
							: '—') . '</td>';
				}
				$subject_rows .= '</tr>';
			}
			$subject_table = '<h2 style="margin:24px 0 10px;font-size:16px">' . esc_html__('Subject results', 'paper-to-quiz') . '</h2>' .
				'<table role="presentation" style="width:100%;border-collapse:collapse"><thead><tr>' .
				'<th style="' . $head . '">' . esc_html__('Subject', 'paper-to-quiz') . '</th><th style="' . $head . 'text-align:center">' . esc_html__('Correct', 'paper-to-quiz') . '</th>' .
				'<th style="' . $head . 'text-align:center">' . esc_html__('Wrong', 'paper-to-quiz') . '</th><th style="' . $head . 'text-align:center">' . esc_html__('Blank', 'paper-to-quiz') . '</th>' .
				'<th style="' . $head . 'text-align:center">' . esc_html__('Points', 'paper-to-quiz') . '</th><th style="' . $head . 'text-align:center">' . esc_html__('Success', 'paper-to-quiz') . '</th>' .
				($has_subject_ranking ? '<th style="' . $head . 'text-align:center">' . esc_html__('Rank', 'paper-to-quiz') . '</th>' : '') .
				'</tr></thead><tbody>' . $subject_rows . '</tbody></table>';
		}

		$ranking = '';
		if ($include_summary && ! empty($result['ranking'])) {
			$ranking = '<h2 style="margin:24px 0 10px;font-size:16px">' . esc_html__('Overall ranking', 'paper-to-quiz') . '</h2>' .
				'<table role="presentation" style="width:100%;border-collapse:collapse"><thead><tr>' .
				'<th style="' . $head . 'text-align:center">' . esc_html__('Rank', 'paper-to-quiz') . '</th><th style="' . $head .
				'text-align:center">' . esc_html__('Participant', 'paper-to-quiz') . '</th><th style="' . $head . 'text-align:center">' . esc_html__('Percentile', 'paper-to-quiz') . '</th></tr></thead>' .
				'<tbody><tr><td style="' . $cell . 'text-align:center;font-weight:700">' .
				esc_html((string) $result['ranking']['rank']) . '</td><td style="' . $cell . 'text-align:center">' .
				esc_html((string) $result['ranking']['total']) . '</td><td style="' . $cell . 'text-align:center">%' .
				esc_html((string) $result['ranking']['percentile']) . '</td></tr></tbody></table>';
		}

		$details = '';
		if ($result['visibility'] === 'detailed' && ! empty($result['answers'])) {
			$answer_rows = '';
			foreach ((array) $result['answers'] as $answer) {
				$answer_rows .= '<tr><td style="' . $cell . '">' . esc_html((string) $answer['ordinal']) .
					'</td><td style="' . $cell . '">' . esc_html((string) ($answer['subject_name'] ?? '—')) .
					'</td><td style="' . $cell . '">' . esc_html((string) ($answer['selected_option'] ?: __('Blank', 'paper-to-quiz'))) . '</td>' .
					(! empty($result['answer_key_visible'])
						? '<td style="' . $cell . '">' . esc_html((string) ($answer['correct_option'] ?? '—')) . '</td>'
						: '') .
					'</tr>';
			}
			$details = '<h2 style="margin:24px 0 10px;font-size:16px">' . esc_html__('Answers', 'paper-to-quiz') . '</h2>' .
				'<table role="presentation" style="width:100%;border-collapse:collapse"><thead><tr>' .
				'<th style="' . $head . '">' . esc_html__('Question', 'paper-to-quiz') . '</th><th style="' . $head . '">' . esc_html__('Subject', 'paper-to-quiz') . '</th>' .
				'<th style="' . $head . '">' . esc_html__('Answer', 'paper-to-quiz') . '</th>' .
				(! empty($result['answer_key_visible']) ? '<th style="' . $head . '">' . esc_html__('Correct', 'paper-to-quiz') . '</th>' : '') .
				'</tr></thead><tbody>' . $answer_rows . '</tbody></table>';
		}

		return '<!doctype html><html><body style="margin:0;padding:20px;background:#f2f5f8;font-family:Arial,sans-serif;color:#17202a">' .
			'<div style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #d7e0e8;border-radius:12px;overflow:hidden">' .
			'<div style="padding:24px 28px;background:#0b5fa5;color:#fff">' .
			'<h1 style="margin:4px 0 2px;font-size:24px;color:#fff">' . esc_html($document_heading) . '</h1><div style="opacity:.9">' .
			esc_html((string) ($document['assessment_title'] ?? $context['title'])) . '</div></div>' .
			'<div style="padding:26px"><h2 style="margin:0 0 10px;font-size:16px">' . esc_html__('Participant and exam information', 'paper-to-quiz') . '</h2>' .
			'<table role="presentation" style="width:100%;border-collapse:collapse"><tbody>' . $info_rows . '</tbody></table>' .
			'<h2 style="margin:24px 0 10px;font-size:16px">' . esc_html__('Result summary', 'paper-to-quiz') . '</h2>' . $summary_table .
			$ranking . $subject_table . $details . '</div></div></body></html>';
	}

	private function score(int $value, int $precision): string {
		return number_format_i18n($value / 100, $precision);
	}

	private function duration(int $seconds): string {
		$seconds = max(0, $seconds);
		$hours   = intdiv($seconds, HOUR_IN_SECONDS);
		$minutes = intdiv($seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS);
		$rest    = $seconds % MINUTE_IN_SECONDS;
		$parts   = array();
		if ($hours) {
			/* translators: %d: Number of hours. */
			$parts[] = sprintf(_n('%d hour', '%d hours', $hours, 'paper-to-quiz'), $hours);
		}
		if ($minutes) {
			/* translators: %d: Number of minutes. */
			$parts[] = sprintf(_n('%d minute', '%d minutes', $minutes, 'paper-to-quiz'), $minutes);
		}
		if ($rest || ! $parts) {
			/* translators: %d: Number of seconds. */
			$parts[] = sprintf(_n('%d second', '%d seconds', $rest, 'paper-to-quiz'), $rest);
		}
		return implode(' ', $parts);
	}

	private function fail_permanently(int $job_id, string $error, ?int $count = null): void {
		$data = array('status' => 'failed', 'claimed_at' => null, 'last_error' => $error, 'updated_at' => current_time('mysql', true));
		if ($count !== null) {
			$data['attempt_count'] = $count;
		}
		$this->db->wpdb()->update($this->db->table('result_email_jobs'), $data, array('id' => $job_id));
	}
}
