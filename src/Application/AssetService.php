<?php

declare(strict_types=1);

namespace PaperToQuiz\Application;

// Exception messages are escaped by their eventual presentation layer.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\Installer;
use PaperToQuiz\Infrastructure\StorageException;

final class AssetService {
	public function __construct(
		private readonly Database $db,
		private readonly EncryptedStorage $storage
	) {
	}

	public function create_from_file(
		string $path,
		string $type,
		string $mime,
		?int $width = null,
		?int $height = null
	): int {
		$stored = $this->storage->put_file($path, $type);
		return $this->insert($stored, $type, $mime, $width, $height);
	}

	public function create_from_string(
		string $contents,
		string $type,
		string $mime,
		?int $width = null,
		?int $height = null
	): int {
		$stored = $this->storage->put_string($contents, $type);
		return $this->insert($stored, $type, $mime, $width, $height);
	}

	public function create_from_stored(array $stored, string $type, string $mime): int {
		return $this->insert($stored, $type, $mime, null, null);
	}

	public function get(int $asset_id): ?array {
		$row = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('assets') . ' WHERE id = %d',
				$asset_id
			),
			ARRAY_A
		);
		return is_array($row) ? $row : null;
	}

	public function retain(?int $asset_id): void {
		if (! $asset_id) {
			return;
		}
		$this->db->wpdb()->query(
			$this->db->wpdb()->prepare(
				'UPDATE ' . $this->db->table('assets') . ' SET ref_count = ref_count + 1 WHERE id = %d',
				$asset_id
			)
		);
	}

	public function release(?int $asset_id): void {
		if (! $asset_id) {
			return;
		}

		$asset = $this->get($asset_id);
		if (! $asset) {
			return;
		}

		if ((int) $asset['ref_count'] > 1) {
			$this->db->wpdb()->update(
				$this->db->table('assets'),
				array('ref_count' => (int) $asset['ref_count'] - 1),
				array('id' => $asset_id),
				array('%d'),
				array('%d')
			);
			return;
		}

		$this->storage->delete((string) $asset['storage_key']);
		$this->db->wpdb()->delete($this->db->table('assets'), array('id' => $asset_id), array('%d'));
	}

	public function delete_files(array $assets): void {
		foreach ($assets as $asset) {
			if (is_array($asset) && ! empty($asset['storage_key'])) {
				$this->storage->delete((string) $asset['storage_key']);
			}
		}
	}

	private function insert(
		array $stored,
		string $type,
		string $mime,
		?int $width,
		?int $height
	): int {
		$data = array(
			'type'        => $type,
			'storage_key' => $stored['storage_key'],
			'mime'        => $mime,
			'byte_size'   => $stored['byte_size'],
			'sha256'      => $stored['sha256'],
			'width'       => $width,
			'height'      => $height,
			'ref_count'   => 1,
			'created_at'  => current_time('mysql', true),
		);
		$formats = array('%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s');

		$ok = $this->db->wpdb()->insert($this->db->table('assets'), $data, $formats);

		if (! $ok) {
			$first_error = (string) $this->db->wpdb()->last_error;
			Installer::repair_schema();
			$ok = $this->db->wpdb()->insert($this->db->table('assets'), $data, $formats);
		}

		if (! $ok) {
			$last_error = (string) $this->db->wpdb()->last_error;
			$this->storage->delete((string) $stored['storage_key']);
			throw new StorageException(
				'Asset database insert failed after schema repair. Initial error: ' . $first_error . '; retry error: ' . $last_error,
				self::database_error_message($last_error)
			);
		}

		return (int) $this->db->wpdb()->insert_id;
	}

	private static function database_error_message(string $error): string {
		if (preg_match('/doesn.t exist|unknown column|doesn.t have a default value|no default value/i', $error)) {
			return __('The asset table could not be prepared. Try again after updating the plugin.', 'paper-to-quiz');
		}
		if (preg_match('/denied|permission|command denied/i', $error)) {
			return __('The asset table could not be updated. Check the hosting database permissions.', 'paper-to-quiz');
		}
		if (preg_match('/table.*full|disk full|quota/i', $error)) {
			return __('The server database appears to be full. Check the hosting disk quota.', 'paper-to-quiz');
		}
		return __('The asset record could not be created. Please try again.', 'paper-to-quiz');
	}
}
