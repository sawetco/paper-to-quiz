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
	private const MAX_RELEASE_ATTEMPTS = 3;

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

		$wpdb   = $this->db->wpdb();
		$updated = $this->db->write(
			'asset_retain',
			fn (): int|false => $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic reference counters must observe current durable state.
				$wpdb->prepare(
					'UPDATE %i SET ref_count = ref_count + 1 WHERE id = %d AND ref_count > 0',
					$this->db->table('assets'),
					$asset_id
				)
			)
		);
		if (1 === $updated) {
			return;
		}
		if (false === $updated) {
			throw $this->reference_database_exception('retain');
		}

		$wpdb->last_error = '';
		if (! $this->get($asset_id)) {
			if ((string) $wpdb->last_error !== '') {
				throw $this->reference_database_exception('retain lookup');
			}
			throw new StorageException(
				'Asset retain target is missing.',
				__('The file reference could not be found. Please refresh and try again.', 'paper-to-quiz')
			);
		}

		throw new StorageException(
			'Asset retain affected zero live rows.',
			__('The file reference could not be preserved. Please try again.', 'paper-to-quiz')
		);
	}

	public function release(?int $asset_id): void {
		if (! $asset_id) {
			return;
		}

		$wpdb = $this->db->wpdb();
		for ($attempt = 0; $attempt < self::MAX_RELEASE_ATTEMPTS; $attempt++) {
			$decremented = $this->db->write(
				'asset_release_decrement',
				fn (): int|false => $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic reference counters must observe current durable state.
					$wpdb->prepare(
						'UPDATE %i SET ref_count = ref_count - 1 WHERE id = %d AND ref_count > 1',
						$this->db->table('assets'),
						$asset_id
					)
				)
			);
			if (1 === $decremented) {
				return;
			}
			if (false === $decremented) {
				throw $this->reference_database_exception('release decrement');
			}

			/*
			 * The count is deliberately not used to choose a branch here. A
			 * concurrent retain/release may have changed it after the conditional
			 * decrement returned zero. The key is immutable; read it immediately
			 * before the conditional final-row delete and retry if that delete loses
			 * the race.
			 */
			$wpdb->last_error = '';
			$asset = $this->get($asset_id);
			if (! $asset) {
				if ((string) $wpdb->last_error !== '') {
					throw $this->reference_database_exception('release lookup');
				}
				return;
			}
			$storage_key = (string) ($asset['storage_key'] ?? '');
			if ($storage_key === '') {
				throw new StorageException(
					'Asset release target has no storage key.',
					__('The file reference could not be released. Please try again.', 'paper-to-quiz')
				);
			}

			$deleted = $this->db->write(
				'asset_release_delete',
				fn (): int|false => $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional deletion is the final atomic reference release.
					$wpdb->prepare(
						'DELETE FROM %i WHERE id = %d AND ref_count = 1',
						$this->db->table('assets'),
						$asset_id
					)
				)
			);
			if (false === $deleted) {
				throw $this->reference_database_exception('release delete');
			}
			if (1 !== $deleted) {
				continue;
			}

			try {
				if (! $this->storage->delete($storage_key)) {
					throw new \RuntimeException('Private storage deletion returned false.');
				}
			} catch (\Throwable) {
				/* The row is already gone: failed cleanup leaves only recoverable bytes. */
				throw new StorageException(
					'Asset storage deletion failed.',
					__('The file could not be removed. Please try again.', 'paper-to-quiz')
				);
			}
			return;
		}

		$wpdb->last_error = '';
		if (! $this->get($asset_id)) {
			if ((string) $wpdb->last_error !== '') {
				throw $this->reference_database_exception('release final lookup');
			}
			return;
		}
		throw new StorageException(
			'Asset release did not reach a stable reference count.',
			__('The file reference changed while it was being released. Please try again.', 'paper-to-quiz')
		);
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

	private function reference_database_exception(string $operation): StorageException {
		return new StorageException(
			'Asset reference ' . $operation . ' database write failed.',
			__('The file reference could not be updated. Please try again.', 'paper-to-quiz')
		);
	}
}
