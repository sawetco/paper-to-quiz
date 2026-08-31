<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

/**
 * Incrementally upgrades participant values and private files from PTQ1 to
 * PTQ2. Every batch is intentionally small so a cron request never needs to
 * load a complete PDF or hold an unbounded migration transaction.
 */
final class EncryptionMigration {
	public const PARTICIPANT_CURSOR_OPTION = 'paper_to_quiz_encryption_participant_cursor';
	public const ASSET_CURSOR_OPTION       = 'paper_to_quiz_encryption_asset_cursor';
	public const STATE_OPTION              = 'paper_to_quiz_encryption_migration';
	public const FAILURES_OPTION           = 'paper_to_quiz_encryption_migration_failures';
	public const PARTICIPANT_BATCH_SIZE    = 25;
	public const ASSET_BATCH_SIZE          = 5;

	// Retry a small, fixed slice of old failures and reserve the rest of every
	// batch for fresh IDs. This keeps a permanently corrupt row from starving
	// healthy records behind it.
	private const PARTICIPANT_RETRY_BUDGET = 5;
	private const ASSET_RETRY_BUDGET       = 1;
	private const MAX_FAILURES             = 200;

	public function __construct(
		private readonly Database $db,
		private readonly Crypto $crypto,
		private readonly EncryptedStorage $storage
	) {
	}

	/**
	 * Initialize the durable migration state. A new install has no legacy rows,
	 * so it can advertise a completed migration immediately. Existing installs
	 * start pending and are drained by the cron worker.
	 */
	public function initialize(bool $fresh_install = false): void {
		$this->ensure_option(self::PARTICIPANT_CURSOR_OPTION, 0);
		$this->ensure_option(self::ASSET_CURSOR_OPTION, 0);
		$this->ensure_option(self::FAILURES_OPTION, array('participant' => array(), 'asset' => array(), 'overflow' => false));

		$current = get_option(self::STATE_OPTION, null);
		if (is_array($current) && isset($current['status'])) {
			// Existing options may have been created by an interrupted deployment;
			// keep the state durable but never let it enter the autoloaded options
			// payload.
			update_option(self::STATE_OPTION, $current, false);
			return;
		}

		$empty = $fresh_install;
		if (! $fresh_install) {
			try {
				$empty = ! $this->has_any_rows();
			} catch (\Throwable) {
				// A read failure must never mark an existing installation complete or
				// prevent the plugin from booting. Leave it pending for the next cron.
				$empty = false;
			}
		}
		$state = array(
			'status'       => ($fresh_install || $empty) ? 'complete' : 'pending',
			'processed'    => 0,
			'failed'       => 0,
			'last_run_at'  => null,
			'completed_at' => ($fresh_install || $empty) ? gmdate('Y-m-d H:i:s') : null,
		);
		update_option(self::STATE_OPTION, $state, false);
	}

	/**
	 * Return only safe operational state for the admin health view.
	 *
	 * @return array{status:string,participant_cursor:int,asset_cursor:int,failures:int}
	 */
	public function status(): array {
		$this->initialize(false);
		$state    = $this->state();
		$failures = $this->failures();
		return array(
			'status'             => $this->status_value((string) ($state['status'] ?? 'pending')),
			'participant_cursor' => (int) get_option(self::PARTICIPANT_CURSOR_OPTION, 0),
			'asset_cursor'       => (int) get_option(self::ASSET_CURSOR_OPTION, 0),
			'failures'           => count($failures['participant']) + count($failures['asset']),
		);
	}

	/**
	 * Process at most 25 participant rows and 5 asset rows.
	 *
	 * @return array{status:string,participant_cursor:int,asset_cursor:int,failures:int}
	 */
	public function run(): array {
		$this->initialize(false);
		$state = $this->state();
		if ($this->status_value((string) ($state['status'] ?? 'pending')) === 'complete') {
			return $this->status();
		}

		$state['status']      = 'running';
		$state['last_run_at'] = gmdate('Y-m-d H:i:s');
		$this->save_state($state);

		try {
			$participant_result = $this->process_participants();
			$asset_result       = $this->process_assets();
		} catch (\Throwable) {
			$state['status'] = 'pending';
			$this->save_state($state);
			return $this->status();
		}
		$state              = $this->state();
		$state['processed'] = (int) ($state['processed'] ?? 0) + $participant_result['processed'] + $asset_result['processed'];
		$state['failed']    = (int) ($state['failed'] ?? 0) + $participant_result['failed'] + $asset_result['failed'];

		try {
			$has_pending_rows = $this->has_pending_rows();
		} catch (\Throwable) {
			$state['status'] = 'pending';
			$this->save_state($state);
			return $this->status();
		}
		$failures = $this->failures();
		if (! $has_pending_rows && $failures['overflow']) {
			// The persisted retry queue is intentionally bounded. If older failures
			// were evicted, rewind both scans once they reach the end so no legacy
			// record behind a cursor can be mistaken for a completed migration.
			update_option(self::PARTICIPANT_CURSOR_OPTION, 0, false);
			update_option(self::ASSET_CURSOR_OPTION, 0, false);
			$failures['overflow'] = false;
			update_option(self::FAILURES_OPTION, $failures, false);
			$has_pending_rows = true;
		}

		if ($has_pending_rows || $this->failure_count() > 0) {
			$state['status']       = 'pending';
			$state['completed_at'] = null;
		} else {
			$state['status']       = 'complete';
			$state['completed_at'] = gmdate('Y-m-d H:i:s');
		}
		$this->save_state($state);

		return $this->status();
	}

	/**
	 * @return array{processed:int,failed:int}
	 */
	private function process_participants(): array {
		$budget  = self::PARTICIPANT_BATCH_SIZE;
		$handled = 0;
		$failed  = 0;
		$failures = $this->failures();
		$retry_ids = array_map('intval', array_keys($failures['participant']));
		$retry_ids = array_slice($retry_ids, 0, min(self::PARTICIPANT_RETRY_BUDGET, $budget));
		$rows = $retry_ids ? $this->rows_by_ids('attempts', $retry_ids, array('id', 'participant_data')) : array();
		$seen = array();

		foreach ($rows as $row) {
			if ($handled >= $budget) {
				break;
			}
			$id = (int) ($row['id'] ?? 0);
			if ($id < 1) {
				continue;
			}
			$seen[$id] = true;
			++$handled;
			if ($this->migrate_participant_row($row)) {
				$this->clear_failure('participant', $id);
				$this->advance_cursor(self::PARTICIPANT_CURSOR_OPTION, $id);
			} else {
				++$failed;
			}
		}
		foreach ($retry_ids as $retry_id) {
			if (! isset($seen[$retry_id])) {
				// A deleted row no longer needs retrying and must not consume the
				// reserved retry slice forever.
				$this->clear_failure('participant', $retry_id);
			}
		}

		$remaining = $budget - $handled;
		if ($remaining > 0) {
			$cursor = (int) get_option(self::PARTICIPANT_CURSOR_OPTION, 0);
			$rows = $this->fresh_rows(
				'attempts',
				array('id', 'participant_data'),
				$cursor,
				$remaining,
				array_keys($this->failures()['participant']),
				true
			);
			foreach ($rows as $row) {
				$id = (int) ($row['id'] ?? 0);
				if ($id < 1 || isset($seen[$id])) {
					continue;
				}
				++$handled;
				if ($this->migrate_participant_row($row)) {
					$this->clear_failure('participant', $id);
					$this->advance_cursor(self::PARTICIPANT_CURSOR_OPTION, $id);
				} else {
					++$failed;
				}
			}
		}

		return array('processed' => $handled - $failed, 'failed' => $failed);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function migrate_participant_row(array $row): bool {
		$id   = (int) ($row['id'] ?? 0);
		$old  = isset($row['participant_data']) ? (string) $row['participant_data'] : '';
		if ($id < 1 || $old === '') {
			return true;
		}

		try {
			if ($this->crypto->is_v2($old)) {
				$this->crypto->decrypt_array_strict($old);
				return true;
			}
			$plain = $this->crypto->decrypt_legacy_array($old);
			$new   = $this->crypto->encrypt_array($plain);
			$this->db->begin();
			try {
				$updated = $this->db->write(
					'encryption_participant_update',
					fn (): int|false => $this->db->wpdb()->query(
						$this->db->wpdb()->prepare(
							'UPDATE ' . $this->db->table('attempts') . ' SET participant_data = %s WHERE id = %d AND participant_data = %s',
							$new,
							$id,
							$old
						)
					)
				);
				if (false === $updated) {
					throw new \RuntimeException('Participant migration database update failed.');
				}
				if (1 !== (int) $updated) {
					$this->db->rollback();
					$current = $this->participant_value($id);
					if ($current === null || $this->crypto->is_v2($current)) {
						if ($current !== null) {
							$this->crypto->decrypt_array_strict($current);
						}
						return true;
					}
					throw new \RuntimeException('Participant migration lost its compare-and-swap race.');
				}
				$this->db->commit();
			} catch (\Throwable $error) {
				$this->db->rollback();
				throw $error;
			}
			return true;
		} catch (\Throwable $error) {
			$this->record_failure($id, 'participant', $this->safe_error_class($error));
			return false;
		}
	}

	/**
	 * @return array{processed:int,failed:int}
	 */
	private function process_assets(): array {
		$budget  = self::ASSET_BATCH_SIZE;
		$handled = 0;
		$failed  = 0;
		$failures = $this->failures();
		$retry_ids = array_map('intval', array_keys($failures['asset']));
		$retry_ids = array_slice($retry_ids, 0, min(self::ASSET_RETRY_BUDGET, $budget));
		$rows = $retry_ids ? $this->rows_by_ids('assets', $retry_ids, array('id', 'type', 'storage_key', 'byte_size', 'sha256')) : array();
		$seen = array();

		foreach ($rows as $row) {
			if ($handled >= $budget) {
				break;
			}
			$id = (int) ($row['id'] ?? 0);
			if ($id < 1) {
				continue;
			}
			$seen[$id] = true;
			++$handled;
			if ($this->migrate_asset_row($row)) {
				$this->clear_failure('asset', $id);
				$this->advance_cursor(self::ASSET_CURSOR_OPTION, $id);
			} else {
				++$failed;
			}
		}
		foreach ($retry_ids as $retry_id) {
			if (! isset($seen[$retry_id])) {
				$this->clear_failure('asset', $retry_id);
			}
		}

		$remaining = $budget - $handled;
		if ($remaining > 0) {
			$cursor = (int) get_option(self::ASSET_CURSOR_OPTION, 0);
			$rows = $this->fresh_rows(
				'assets',
				array('id', 'type', 'storage_key', 'byte_size', 'sha256'),
				$cursor,
				$remaining,
				array_keys($this->failures()['asset'])
			);
			foreach ($rows as $row) {
				$id = (int) ($row['id'] ?? 0);
				if ($id < 1 || isset($seen[$id])) {
					continue;
				}
				++$handled;
				if ($this->migrate_asset_row($row)) {
					$this->clear_failure('asset', $id);
					$this->advance_cursor(self::ASSET_CURSOR_OPTION, $id);
				} else {
					++$failed;
				}
			}
		}

		return array('processed' => $handled - $failed, 'failed' => $failed);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function migrate_asset_row(array $row): bool {
		$id          = (int) ($row['id'] ?? 0);
		$type        = (string) ($row['type'] ?? '');
		$storage_key = (string) ($row['storage_key'] ?? '');
		$byte_size   = (int) ($row['byte_size'] ?? -1);
		$sha256      = strtolower((string) ($row['sha256'] ?? ''));
		if ($id < 1 || $type === '' || $storage_key === '') {
			$this->record_failure($id, $type ?: 'asset', 'metadata');
			return false;
		}

		try {
			if ($this->storage->is_v2($storage_key)) {
				if (! $this->storage->verify($storage_key, $type, $byte_size, $sha256)) {
					throw new \RuntimeException('PTQ2 asset verification failed.');
				}
				return true;
			}

			$new = $this->storage->migrate_legacy($storage_key, $type, $byte_size, $sha256);
			$this->db->begin();
			try {
				$updated = $this->db->write(
					'encryption_asset_update',
					fn (): int|false => $this->db->wpdb()->query(
						$this->db->wpdb()->prepare(
							'UPDATE ' . $this->db->table('assets') . ' SET storage_key = %s, byte_size = %d, sha256 = %s WHERE id = %d AND storage_key = %s',
							(string) $new['storage_key'],
							(int) $new['byte_size'],
							(string) $new['sha256'],
							$id,
							$storage_key
						)
					)
				);
				if (false === $updated) {
					throw new \RuntimeException('Asset migration database update failed.');
				}
				if (1 !== (int) $updated) {
					$this->db->rollback();
					$current = $this->asset_value($id);
					if ($current === null || $this->storage->is_v2($current)) {
						$this->storage->delete((string) $new['storage_key']);
						return true;
					}
					throw new \RuntimeException('Asset migration lost its compare-and-swap race.');
				}
				$this->db->commit();
			} catch (\Throwable $error) {
				$this->db->rollback();
				$this->storage->delete((string) $new['storage_key']);
				throw $error;
			}
			$this->storage->delete($storage_key);
			return true;
		} catch (\Throwable $error) {
			$this->record_failure($id, $type, $this->safe_error_class($error));
			return false;
		}
	}

	private function participant_value(int $id): ?string {
		$wpdb = $this->db->wpdb();
		$wpdb->last_error = '';
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration compare-and-swap reads must observe current durable state.
			$wpdb->prepare('SELECT participant_data FROM %i WHERE id = %d', $this->db->table('attempts'), $id)
		);
		if ($wpdb->last_error !== '') {
			throw new \RuntimeException('Participant migration database read failed.');
		}
		return is_string($value) ? $value : null;
	}

	private function asset_value(int $id): ?string {
		$wpdb = $this->db->wpdb();
		$wpdb->last_error = '';
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration compare-and-swap reads must observe current durable state.
			$wpdb->prepare('SELECT storage_key FROM %i WHERE id = %d', $this->db->table('assets'), $id)
		);
		if ($wpdb->last_error !== '') {
			throw new \RuntimeException('Asset migration database read failed.');
		}
		return is_string($value) ? $value : null;
	}

	/**
	 * @param string[] $columns
	 * @param int[]    $ids
	 * @return array<int,array<string,mixed>>
	 */
	private function rows_by_ids(string $table, array $ids, array $columns): array {
		$this->assert_query_shape($table, $columns);
		$ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
		if (! $ids) {
			return array();
		}
		$column_placeholders = implode(',', array_fill(0, count($columns), '%i'));
		$id_placeholders     = implode(',', array_fill(0, count($ids), '%d'));
		$wpdb                = $this->db->wpdb();
		$wpdb->last_error = '';
		$query = $wpdb->prepare(
			"SELECT {$column_placeholders} FROM %i WHERE id IN ({$id_placeholders}) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolated fragments contain only generated identifier/value placeholders.
			...array_merge($columns, array($this->db->table($table)), $ids)
		);
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The bounded migration query is prepared immediately above.
			$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above after query-shape validation.
			ARRAY_A
		);
		if ($wpdb->last_error !== '') {
			throw new \RuntimeException('Encryption migration database query failed.');
		}
		return is_array($rows) ? $rows : array();
	}

	/**
	 * Select fresh IDs while excluding recorded failures so they cannot starve
	 * the bounded fresh-work slice behind a permanently bad row.
	 *
	 * @param string[] $columns
	 * @param array<int|string> $excluded_ids
	 * @return array<int,array<string,mixed>>
	 */
	private function fresh_rows(string $table, array $columns, int $cursor, int $limit, array $excluded_ids, bool $require_participant_data = false): array {
		$this->assert_query_shape($table, $columns);
		$excluded_ids = array_values(array_filter(array_map('intval', $excluded_ids), static fn (int $id): bool => $id > 0));
		$wpdb = $this->db->wpdb();
		if ($require_participant_data) {
			if ($excluded_ids) {
				$placeholders = implode(',', array_fill(0, count($excluded_ids), '%d'));
				$prepared = $wpdb->prepare(
					"SELECT %i,%i FROM %i WHERE id > %d AND participant_data IS NOT NULL AND id NOT IN ({$placeholders}) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolation contains only generated %d placeholders.
					'id',
					'participant_data',
					$this->db->table('attempts'),
					$cursor,
					...array_merge($excluded_ids, array($limit))
				);
			} else {
				$prepared = $wpdb->prepare(
					'SELECT %i,%i FROM %i WHERE id > %d AND participant_data IS NOT NULL ORDER BY id ASC LIMIT %d',
					'id',
					'participant_data',
					$this->db->table('attempts'),
					$cursor,
					$limit
				);
			}
		} elseif ($excluded_ids) {
			$placeholders = implode(',', array_fill(0, count($excluded_ids), '%d'));
			$prepared = $wpdb->prepare(
				"SELECT %i,%i,%i,%i,%i FROM %i WHERE id > %d AND id NOT IN ({$placeholders}) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolation contains only generated %d placeholders.
				'id',
				'type',
				'storage_key',
				'byte_size',
				'sha256',
				$this->db->table('assets'),
				$cursor,
				...array_merge($excluded_ids, array($limit))
			);
		} else {
			$prepared = $wpdb->prepare(
				'SELECT %i,%i,%i,%i,%i FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				'id',
				'type',
				'storage_key',
				'byte_size',
				'sha256',
				$this->db->table('assets'),
				$cursor,
				$limit
			);
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The bounded migration query is prepared immediately above.
			$prepared, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above after query-shape validation.
			ARRAY_A
		);
		if ($wpdb->last_error !== '') {
			throw new \RuntimeException('Encryption migration database query failed.');
		}
		return is_array($rows) ? $rows : array();
	}

	private function has_pending_rows(): bool {
		$participant_cursor = (int) get_option(self::PARTICIPANT_CURSOR_OPTION, 0);
		$asset_cursor       = (int) get_option(self::ASSET_CURSOR_OPTION, 0);
		$wpdb               = $this->db->wpdb();
		$wpdb->last_error   = '';
		$participant = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration completion must observe current durable state.
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d AND participant_data IS NOT NULL LIMIT 1',
				$this->db->table('attempts'),
				$participant_cursor
			)
		);
		$this->throw_on_database_read_error();
		if ($participant !== null) {
			return true;
		}
		$wpdb->last_error = '';
		$asset = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration completion must observe current durable state.
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d LIMIT 1',
				$this->db->table('assets'),
				$asset_cursor
			)
		);
		$this->throw_on_database_read_error();
		return $asset !== null;
	}

	private function has_any_rows(): bool {
		$wpdb             = $this->db->wpdb();
		$wpdb->last_error = '';
		$participant = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One bounded read determines safe initial migration state.
			$wpdb->prepare(
				'SELECT id FROM %i WHERE participant_data IS NOT NULL LIMIT 1',
				$this->db->table('attempts')
			)
		);
		$this->throw_on_database_read_error();
		if ($participant !== null) {
			return true;
		}
		$wpdb->last_error = '';
		$asset = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One bounded read determines safe initial migration state.
			$wpdb->prepare('SELECT id FROM %i LIMIT 1', $this->db->table('assets'))
		);
		$this->throw_on_database_read_error();
		return $asset !== null;
	}

	private function throw_on_database_read_error(): void {
		$wpdb = $this->db->wpdb();
		if ($wpdb->last_error !== '') {
			throw new \RuntimeException('Encryption migration database query failed.');
		}
	}

	/**
	 * @param string[] $columns
	 */
	private function assert_query_shape(string $table, array $columns): void {
		$allowed = array(
			'attempts' => array('id', 'participant_data'),
			'assets'   => array('id', 'type', 'storage_key', 'byte_size', 'sha256'),
		);
		if (! isset($allowed[$table]) || ! $columns || array_diff($columns, $allowed[$table])) {
			throw new \InvalidArgumentException('Unsupported encryption migration query shape.');
		}
	}

	private function advance_cursor(string $option, int $id): void {
		$current = (int) get_option($option, 0);
		if ($id > $current) {
			update_option($option, $id, false);
		}
	}

	private function ensure_option(string $name, mixed $default): void {
		$current = get_option($name, false);
		if (false === $current) {
			add_option($name, $default, '', false);
			return;
		}
		update_option($name, $current, false);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$value = get_option(self::STATE_OPTION, array());
		return is_array($value) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function save_state(array $state): void {
		update_option(self::STATE_OPTION, $state, false);
	}

	/**
	 * @return array{participant:array<string,array<string,int|string>>,asset:array<string,array<string,int|string>>,overflow:bool}
	 */
	private function failures(): array {
		$value = get_option(self::FAILURES_OPTION, array());
		return array(
			'participant' => is_array($value) && isset($value['participant']) && is_array($value['participant']) ? $value['participant'] : array(),
			'asset'      => is_array($value) && isset($value['asset']) && is_array($value['asset']) ? $value['asset'] : array(),
			'overflow'   => is_array($value) && ! empty($value['overflow']),
		);
	}

	private function failure_count(): int {
		$value = $this->failures();
		return count($value['participant']) + count($value['asset']);
	}

	private function record_failure(int $id, string $type, string $error_class): void {
		if ($id < 1) {
			return;
		}
		$failures = $this->failures();
		$bucket   = $type === 'participant' ? 'participant' : 'asset';
		$key      = (string) $id;
		// Move a retried failure to the end of the bounded queue. Otherwise the
		// first permanent failures would consume every retry slice forever and a
		// later repaired record would never be revisited.
		unset($failures[$bucket][$key]);
		$failures[$bucket][$key] = array(
			'id'          => $id,
			'type'        => $type,
			'error_class' => $error_class,
		);
		if (count($failures[$bucket]) > self::MAX_FAILURES) {
			$failures[$bucket] = array_slice($failures[$bucket], -self::MAX_FAILURES, null, true);
			$failures['overflow'] = true;
		}
		update_option(self::FAILURES_OPTION, $failures, false);
	}

	private function clear_failure(string $type, int $id): void {
		$failures = $this->failures();
		$bucket   = $type === 'participant' ? 'participant' : 'asset';
		unset($failures[$bucket][(string) $id]);
		update_option(self::FAILURES_OPTION, $failures, false);
	}

	private function safe_error_class(\Throwable $error): string {
		$message = strtolower($error->getMessage());
		if (str_contains($message, 'authentication') || str_contains($message, 'auth')) {
			return 'authentication';
		}
		if (str_contains($message, 'marker') || str_contains($message, 'corrupt') || str_contains($message, 'invalid')) {
			return 'corrupt';
		}
		if (str_contains($message, 'database') || str_contains($message, 'query') || str_contains($message, 'race')) {
			return 'database';
		}
		if (str_contains($message, 'file') || str_contains($message, 'storage') || str_contains($message, 'read')) {
			return 'storage';
		}
		return 'migration';
	}

	private function status_value(string $status): string {
		return in_array($status, array('pending', 'running', 'complete'), true) ? $status : 'pending';
	}
}
