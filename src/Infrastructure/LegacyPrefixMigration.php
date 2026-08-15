<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

/**
 * Moves identifiers shipped before the first WordPress.org review to the
 * directory-compliant prefix without discarding existing plugin data.
 */
final class LegacyPrefixMigration {
	private const LEGACY_PREFIX = 'ptq_';

	private const TABLES = array(
		'assessments',
		'revisions',
		'questions',
		'terms',
		'assets',
		'upload_sessions',
		'attempts',
		'answers',
		'ranking_entries',
		'attempt_subject_scores',
		'result_email_jobs',
	);

	private const OPTION_SUFFIXES = array(
		'settings',
		'db_version',
		'storage_key',
	);

	private const CAPABILITY_SUFFIXES = array(
		'manage_assessments',
		'publish_assessments',
		'view_results',
		'manage_settings',
	);

	public static function run(): bool {
		self::migrate_options();
		self::migrate_capabilities();
		self::clear_scheduled_tasks();
		self::delete_transients();
		return true;
	}

	public static function has_legacy_tables(): bool {
		global $wpdb;

		foreach (self::TABLES as $table) {
			if (self::table_exists($wpdb->prefix . self::LEGACY_PREFIX . $table)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Copy legacy rows only after dbDelta has prepared every canonical table.
	 * Each table is verified before the old copy is dropped, so an interrupted
	 * migration can resume on the next request.
	 */
	public static function migrate_tables_after_schema(): bool {
		global $wpdb;

		foreach (self::TABLES as $table) {
			$legacy    = $wpdb->prefix . self::LEGACY_PREFIX . $table;
			$canonical = $wpdb->prefix . Database::TABLE_PREFIX . $table;
			if (! self::table_exists($legacy)) {
				continue;
			}
			if (! self::table_exists($canonical)) {
				return false;
			}

			$legacy_count    = self::row_count($legacy);
			$canonical_count = self::row_count($canonical);
			if ($legacy_count > 0 && $canonical_count > 0) {
				if ($legacy_count !== $canonical_count || ! self::canonical_contains_all_ids($legacy, $canonical)) {
					return false;
				}
			} elseif ($legacy_count > 0) {
				$legacy_columns    = self::columns($legacy);
				$canonical_columns = self::columns($canonical);
				$columns           = array_values(array_intersect($legacy_columns, $canonical_columns));
				if (! $columns || ! in_array('id', $columns, true)) {
					return false;
				}

				$column_placeholders = implode(', ', array_fill(0, count($columns), '%i'));
				$args = array_merge(array($canonical), $columns, $columns, array($legacy));
				$copied = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time copy into an empty, plugin-owned canonical table.
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The spread contains both table names and every generated column placeholder.
					$wpdb->prepare(
						"INSERT IGNORE INTO %i ({$column_placeholders}) SELECT {$column_placeholders} FROM %i", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolated text contains only generated %i placeholders.
						...$args
					)
				);
				if (false === $copied || self::row_count($canonical) !== $legacy_count) {
					return false;
				}
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- The verified legacy copy is obsolete after the canonical row count and IDs match.
			$dropped = $wpdb->query(
				$wpdb->prepare('DROP TABLE %i', $legacy)
			);
			// phpcs:enable
			if (false === $dropped) {
				return false;
			}
		}

		return true;
	}

	/** @return string[] */
	private static function columns(string $table): array {
		global $wpdb;

		return array_map(
			'strval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration discovery.
				$wpdb->prepare('SHOW COLUMNS FROM %i', $table)
			) ?: array()
		);
	}

	private static function row_count(string $table): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration verification.
			$wpdb->prepare('SELECT COUNT(*) FROM %i', $table)
		);
	}

	private static function canonical_contains_all_ids(string $legacy, string $canonical): bool {
		global $wpdb;

		$missing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Detects whether a previously copied table can be safely finalized.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i legacy LEFT JOIN %i canonical ON canonical.id = legacy.id WHERE canonical.id IS NULL',
				$legacy,
				$canonical
			)
		);
		return 0 === (int) $missing;
	}

	private static function table_exists(string $table): bool {
		global $wpdb;

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration discovery.
			$wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
		);
		return is_string($found) && strtolower($found) === strtolower($table);
	}

	private static function migrate_options(): void {
		$missing = new \stdClass();
		foreach (self::OPTION_SUFFIXES as $suffix) {
			$legacy    = self::LEGACY_PREFIX . $suffix;
			$canonical = Database::TABLE_PREFIX . $suffix;
			$value     = get_option($legacy, $missing);

			if ($value !== $missing && get_option($canonical, $missing) === $missing) {
				add_option($canonical, $value, '', false);
			}
			if ($value !== $missing) {
				delete_option($legacy);
			}
		}
	}

	private static function migrate_capabilities(): void {
		foreach (array_keys(wp_roles()->roles) as $role_name) {
			$role = get_role((string) $role_name);
			if (! $role) {
				continue;
			}

			foreach (self::CAPABILITY_SUFFIXES as $suffix) {
				$legacy    = self::LEGACY_PREFIX . $suffix;
				$canonical = Database::TABLE_PREFIX . $suffix;
				if ($role->has_cap($legacy) && ! $role->has_cap($canonical)) {
					$role->add_cap($canonical);
				}
				if ($role->has_cap($legacy)) {
					$role->remove_cap($legacy);
				}
			}
		}
	}

	private static function clear_scheduled_tasks(): void {
		wp_clear_scheduled_hook(self::LEGACY_PREFIX . 'daily_cleanup');
		wp_clear_scheduled_hook(self::LEGACY_PREFIX . 'process_result_emails');
	}

	private static function delete_transients(): void {
		global $wpdb;

		$transient_prefix = $wpdb->esc_like('_transient_' . self::LEGACY_PREFIX) . '%';
		$timeout_prefix   = $wpdb->esc_like('_transient_timeout_' . self::LEGACY_PREFIX) . '%';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes obsolete, short-lived legacy migration and rate-limit keys.
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$transient_prefix,
				$timeout_prefix
			)
		);
	}
}
