<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

use PaperToQuiz\Application\AttemptService;

final class Cleanup {
	public function __construct(
		private readonly Database $db,
		private readonly EncryptedStorage $storage,
		private readonly AttemptService $attempts
	) {
	}

	public function run(): void {
		$this->attempts->anonymize_expired();
		$this->attempts->expire_stale_attempts();
		$sessions = $this->db->wpdb()->get_results(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('upload_sessions') . " WHERE status = 'pending' AND expires_at < %s",
				current_time('mysql', true)
			),
			ARRAY_A
		) ?: array();
		foreach ($sessions as $session) {
			$manifest = json_decode((string) $session['manifest_json'], true) ?: array();
			foreach ($manifest as $chunk) {
				if (! empty($chunk['storage_key'])) {
					$this->storage->delete((string) $chunk['storage_key']);
				}
			}
			$this->db->wpdb()->update(
				$this->db->table('upload_sessions'),
				array('status' => 'expired'),
				array('id' => $session['id'])
			);
		}
	}
}
