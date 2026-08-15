<?php

declare(strict_types=1);

namespace PaperToQuiz\Privacy;

use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;

final class PrivacyManager {
	public function __construct(
		private readonly Database $db,
		private readonly Crypto $crypto,
		private readonly AttemptService $attempts
	) {
	}

	public function register(): void {
		add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter'));
		add_filter('wp_privacy_personal_data_erasers', array($this, 'register_eraser'));
		add_action('delete_user', array($this->attempts, 'anonymize_user'));
		add_action('wp_privacy_personal_data_erased', array($this, 'on_privacy_erased'));
		add_action('admin_init', array($this, 'policy_text'));
	}

	public function register_exporter(array $exporters): array {
		$exporters['paper-to-quiz'] = array(
			'exporter_friendly_name' => __('Paper to Quiz Results', 'paper-to-quiz'),
			'callback'               => array($this, 'export'),
		);
		return $exporters;
	}

	public function register_eraser(array $erasers): array {
		$erasers['paper-to-quiz'] = array(
			'eraser_friendly_name' => __('Paper to Quiz Participant Information', 'paper-to-quiz'),
			'callback'             => array($this, 'erase'),
		);
		return $erasers;
	}

	public function export(string $email, int $page = 1): array {
		$items   = array();
		$rows    = $this->attempt_page($page);
		$user    = get_user_by('email', $email);
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		foreach ($rows as $attempt) {
			$participant = $this->crypto->decrypt_array($attempt['participant_data']);
			$email_matches = ! empty($participant['email']) && strcasecmp((string) $participant['email'], $email) === 0;
			$user_matches  = $user_id > 0 && (int) $attempt['wp_user_id'] === $user_id;
			if (! $email_matches && ! $user_matches) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'paper-to-quiz-results',
				'group_label' => __('Quiz Results', 'paper-to-quiz'),
				'item_id'     => 'paper-to-quiz-attempt-' . $attempt['id'],
				'data'        => array(
					array('name' => __('Assessment', 'paper-to-quiz'), 'value' => $attempt['title']),
					array('name' => __('Started', 'paper-to-quiz'), 'value' => $attempt['started_at']),
					array('name' => __('Score', 'paper-to-quiz'), 'value' => ((int) $attempt['score']) / 100),
					array('name' => __('Participant information', 'paper-to-quiz'), 'value' => wp_json_encode($participant, JSON_UNESCAPED_UNICODE)),
				),
			);
		}
		return array('data' => $items, 'done' => count($rows) < 100);
	}

	public function erase(string $email, int $page = 1): array {
		$removed  = false;
		$retained = false;
		$failed   = false;
		$rows     = $this->attempt_page($page);
		$user     = get_user_by('email', $email);
		$user_id  = $user instanceof \WP_User ? (int) $user->ID : 0;
		foreach ($rows as $attempt) {
			$participant = $this->crypto->decrypt_array($attempt['participant_data']);
			$email_matches = ! empty($participant['email']) && strcasecmp((string) $participant['email'], $email) === 0;
			$user_matches  = $user_id > 0 && (int) $attempt['wp_user_id'] === $user_id;
			if (! $email_matches && ! $user_matches) {
				continue;
			}
			if ($this->attempts->anonymize_attempt((int) $attempt['id'])) {
				$removed  = true;
				$retained = true;
			} else {
				$failed = true;
			}
		}
		$messages = $retained ? array(__('Identity information was erased; the anonymous score and statistics record was retained.', 'paper-to-quiz')) : array();
		if ($failed) {
			$messages[] = __('Some identity information could not be erased. Please try again.', 'paper-to-quiz');
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count($rows) < 100,
		);
	}

	public function on_privacy_erased(int $request_id): void {
		// Core eraser callback handles matching attempts page by page.
	}

	public function policy_text(): void {
		if (! function_exists('wp_add_privacy_policy_content')) {
			return;
		}
		wp_add_privacy_policy_content(
			__('Paper to Quiz', 'paper-to-quiz'),
			wpautop(
				__('This site may store the identity fields enabled by the administrator (such as first name, last name, school, class section, email, and phone number), your answers, duration, and score. These fields are encrypted at rest. For exams that require membership, your WordPress user ID is associated with the result. Data is anonymized after the configured retention period and is not sent to third parties.', 'paper-to-quiz')
			)
		);
	}

	private function attempt_page(int $page): array {
		$offset = max(0, ($page - 1) * 100);
		return $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				'SELECT t.*,r.title FROM ' . $this->db->table('attempts') . ' t
				JOIN ' . $this->db->table('revisions') . ' r ON r.id = t.revision_id
				ORDER BY t.id LIMIT 100 OFFSET %d',
				$offset
			),
			ARRAY_A
		) ?: array();
	}
}
