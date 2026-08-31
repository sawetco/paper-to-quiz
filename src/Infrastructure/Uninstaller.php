<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class Uninstaller {
	private const TABLES = array(
		'result_email_jobs',
		'attempt_subject_scores',
		'answers',
		'ranking_entries',
		'attempts',
		'questions',
		'revisions',
		'assessments',
		'upload_sessions',
		'assets',
		'terms',
	);

	private const OPTIONS = array(
		'paper_to_quiz_settings',
		'paper_to_quiz_db_version',
		'paper_to_quiz_storage_key',
		EncryptionMigration::STATE_OPTION,
		EncryptionMigration::PARTICIPANT_CURSOR_OPTION,
		EncryptionMigration::ASSET_CURSOR_OPTION,
		EncryptionMigration::FAILURES_OPTION,
	);

	private const CAPABILITIES = array(
		'paper_to_quiz_manage_assessments',
		'paper_to_quiz_publish_assessments',
		'paper_to_quiz_view_results',
		'paper_to_quiz_manage_settings',
	);

	public static function purge(): void {
		self::clear_scheduled_tasks();
		self::delete_private_storage();
		self::drop_tables();
		self::delete_options_and_transients();
		self::remove_capabilities();
	}

	private static function clear_scheduled_tasks(): void {
		wp_clear_scheduled_hook('paper_to_quiz_daily_cleanup');
		wp_clear_scheduled_hook('paper_to_quiz_process_result_emails');
		wp_clear_scheduled_hook('paper_to_quiz_process_encryption_migration');
	}

	private static function drop_tables(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . Database::TABLE_PREFIX;
		foreach (self::TABLES as $table) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit uninstall of plugin-owned tables.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare('DROP TABLE IF EXISTS %i', $prefix . $table)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	private static function delete_options_and_transients(): void {
		global $wpdb;

		foreach (self::OPTIONS as $option) {
			delete_option($option);
		}

		$transient_prefix = $wpdb->esc_like('_transient_paper_to_quiz_') . '%';
		$timeout_prefix   = $wpdb->esc_like('_transient_timeout_paper_to_quiz_') . '%';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-prefixed transient options during uninstall.
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$transient_prefix,
				$timeout_prefix
			)
		);
	}

	private static function remove_capabilities(): void {
		foreach (array_keys(wp_roles()->roles) as $role_name) {
			$role = get_role((string) $role_name);
			if (! $role) {
				continue;
			}

			foreach (self::CAPABILITIES as $capability) {
				$role->remove_cap($capability);
			}
		}
	}

	private static function delete_private_storage(): void {
		$uploads = wp_get_upload_dir();
		if (! empty($uploads['error']) || empty($uploads['basedir'])) {
			return;
		}

		$uploads_dir = wp_normalize_path((string) $uploads['basedir']);
		$storage_dir = wp_normalize_path(trailingslashit($uploads_dir) . 'paper-to-quiz-private');
		if (
			$storage_dir === $uploads_dir ||
			dirname($storage_dir) !== untrailingslashit($uploads_dir) ||
			(! is_dir($storage_dir) && ! is_link($storage_dir))
		) {
			return;
		}

		if (is_link($storage_dir)) {
			unlink($storage_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the validated plugin storage link, never its target.
			return;
		}

		self::delete_directory($storage_dir);
	}

	private static function delete_directory(string $directory): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isLink() || $item->isFile()) {
				unlink($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Private plugin storage must be removed during uninstall without requiring filesystem credentials.
			} elseif ($item->isDir()) {
				rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See above.
			}
		}

		rmdir($directory); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See above.
	}
}
