<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class Installer {
	private const SCHEMA_FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	public static function activate(): void {
		add_filter('cron_schedules', array(self::class, 'cron_schedules'));
		$existing_install = get_option('paper_to_quiz_db_version', null) !== null;
		if (! self::install_schema()) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress displays activation exceptions in its own escaped error screen.
			throw new \RuntimeException(__('Paper to Quiz could not create its database tables. Check the database permissions before activation.', 'paper-to-quiz'));
		}
		self::add_capabilities();

		$storage = new EncryptedStorage();
		$storage->ensure_base_directory();
		self::initialize_encryption_migration(! $existing_install, $storage);
	}

	public static function ensure_schedules(): void {
		if (! wp_next_scheduled('paper_to_quiz_daily_cleanup')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'paper_to_quiz_daily_cleanup');
		}
		$args = array('queue');
		if (! wp_next_scheduled('paper_to_quiz_process_result_emails', $args)) {
			wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'paper_to_quiz_five_minutes', 'paper_to_quiz_process_result_emails', $args);
		}
		if (! wp_next_scheduled('paper_to_quiz_process_encryption_migration')) {
			wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'paper_to_quiz_five_minutes', 'paper_to_quiz_process_encryption_migration');
		}
	}

	/**
	 * @param array<string,array<string,int|string>> $schedules Cron schedules.
	 * @return array<string,array<string,int|string>>
	 */
	public static function cron_schedules(array $schedules): array {
		$schedules['paper_to_quiz_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __('Every 5 minutes', 'paper-to-quiz'),
		);
		return $schedules;
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook('paper_to_quiz_daily_cleanup');
		wp_clear_scheduled_hook('paper_to_quiz_process_result_emails');
		wp_clear_scheduled_hook('paper_to_quiz_process_encryption_migration');
	}

	public static function maybe_upgrade(): bool {
		$existing_install = get_option('paper_to_quiz_db_version', null) !== null;
		// Keep role capabilities current for sites that were already active before
		// a capability was introduced or renamed. This is idempotent and must run
		// even when the schema verification cache allows an early return below.
		self::add_capabilities();
		// Activation hooks do not run again when an already-active plugin is
		// replaced. Reconcile private storage for those installations without
		// turning a permissions problem into a frontend fatal error.
		try {
			(new EncryptedStorage())->ensure_base_directory();
		} catch (\Throwable) {
			// The settings health check reports the actionable storage status.
		}

		$verified = 'paper_to_quiz_schema_verified_' . PAPER_TO_QUIZ_DB_VERSION;
		$failed   = 'paper_to_quiz_schema_failed_' . PAPER_TO_QUIZ_DB_VERSION;

		if (
			get_option('paper_to_quiz_db_version') === PAPER_TO_QUIZ_DB_VERSION &&
			get_transient($verified) === '1'
		) {
			self::initialize_encryption_migration(! $existing_install);
			return true;
		}

		if (get_transient($failed) === '1') {
			return false;
		}

		if (self::install_schema()) {
			self::initialize_encryption_migration(! $existing_install);
			delete_transient($failed);
			set_transient($verified, '1', 12 * HOUR_IN_SECONDS);
			return true;
		}

		set_transient($failed, '1', self::SCHEMA_FAILURE_TTL);
		return false;
	}

	public static function repair_schema(): bool {
		delete_transient('paper_to_quiz_schema_failed_' . PAPER_TO_QUIZ_DB_VERSION);
		return self::install_schema();
	}

	private static function schema_is_complete(): bool {
		global $wpdb;

		$prefix = $wpdb->prefix . 'paper_to_quiz_';
		$found  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded schema health check cached for twelve hours.
			$wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')
		);
		$required = array(
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

		$tables_complete = count(
			array_intersect(
				array_map('strtolower', array_map('strval', $found)),
				array_map(
					static fn (string $table): string => strtolower($prefix . $table),
					$required
				)
			)
		) === count($required);

		if (! $tables_complete) {
			return false;
		}

		$required_columns = array(
			'assets'            => array('id', 'type', 'storage_key', 'mime', 'byte_size', 'sha256', 'width', 'height', 'ref_count', 'created_at'),
			'questions'         => array('id', 'revision_id', 'client_key', 'ordinal', 'subject_id'),
			'revisions'         => array('id', 'assessment_id', 'subject_ids_json', 'source_asset_id'),
			'terms'             => array('id', 'type', 'name', 'slug', 'color', 'status'),
			'upload_sessions'   => array('id', 'owner_user_id', 'expected_size', 'received_size', 'chunk_count', 'manifest_json', 'status'),
			'attempts'          => array('id', 'submission_id', 'integrity_status', 'ranking_eligible'),
			'result_email_jobs' => array('id', 'attempt_id', 'status', 'next_run_at', 'claimed_at', 'sent_at'),
		);

		foreach ($required_columns as $table => $columns) {
			$found_columns = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded schema health check cached for twelve hours.
				$wpdb->prepare('SHOW COLUMNS FROM %i', $prefix . $table)
			);
			if (
				count(
					array_intersect(
						array_map('strtolower', array_map('strval', $found_columns)),
						$columns
					)
				) !== count($columns)
			) {
				return false;
			}
		}

		return true;
	}

	private static function add_capabilities(): void {
		$administrator = get_role('administrator');
		if (! $administrator) {
			return;
		}

		foreach (
			array(
				'paper_to_quiz_manage_assessments',
				'paper_to_quiz_publish_assessments',
				'paper_to_quiz_view_results',
				'paper_to_quiz_manage_settings',
			) as $capability
		) {
			if (! $administrator->has_cap($capability)) {
				$administrator->add_cap($capability);
			}
		}
	}

	private static function initialize_encryption_migration(bool $fresh_install, ?EncryptedStorage $storage = null): void {
		$storage   = $storage ?? new EncryptedStorage();
		$database  = new Database();
		$migration = new EncryptionMigration($database, new Crypto(), $storage);
		$migration->initialize($fresh_install);
	}

	private static function install_schema(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'paper_to_quiz_';

		$sql = array();
		$sql[] = "CREATE TABLE {$prefix}assessments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(16) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			current_draft_revision_id bigint(20) unsigned DEFAULT NULL,
			published_revision_id bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			updated_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY type_status (type,status),
			KEY published_revision (published_revision_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}revisions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			assessment_id bigint(20) unsigned NOT NULL,
			revision_no int(10) unsigned NOT NULL DEFAULT 1,
			lifecycle varchar(20) NOT NULL DEFAULT 'draft',
			title varchar(255) NOT NULL,
			description longtext DEFAULT NULL,
			class_id bigint(20) unsigned DEFAULT NULL,
			subject_ids_json text DEFAULT NULL,
			access_mode varchar(24) NOT NULL DEFAULT 'guest_allowed',
			options_json text NOT NULL,
			total_points int(10) unsigned NOT NULL DEFAULT 10000,
			duration_seconds int(10) unsigned DEFAULT NULL,
			window_start_utc datetime DEFAULT NULL,
			window_end_utc datetime DEFAULT NULL,
			results_release_at_utc datetime DEFAULT NULL,
			allow_repeat tinyint(1) NOT NULL DEFAULT 1,
			ranking_enabled tinyint(1) NOT NULL DEFAULT 0,
			feedback_timing varchar(24) NOT NULL DEFAULT 'after_submit',
			result_visibility varchar(24) NOT NULL DEFAULT 'summary',
			participant_fields_json longtext DEFAULT NULL,
			retention_days int(10) unsigned NOT NULL DEFAULT 365,
			source_asset_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			published_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY assessment_revision (assessment_id,revision_no),
			KEY lifecycle (lifecycle),
			KEY source_asset (source_asset_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}questions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			revision_id bigint(20) unsigned NOT NULL,
			client_key char(36) DEFAULT NULL,
			ordinal int(10) unsigned NOT NULL,
			source_page int(10) unsigned NOT NULL,
			crop_x decimal(12,8) NOT NULL,
			crop_y decimal(12,8) NOT NULL,
			crop_width decimal(12,8) NOT NULL,
			crop_height decimal(12,8) NOT NULL,
			source_rotation smallint(6) NOT NULL DEFAULT 0,
			main_asset_id bigint(20) unsigned DEFAULT NULL,
			thumb_asset_id bigint(20) unsigned DEFAULT NULL,
			subject_id bigint(20) unsigned DEFAULT NULL,
			correct_option varchar(24) DEFAULT NULL,
			points int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY revision_ordinal (revision_id,ordinal),
			UNIQUE KEY revision_client (revision_id,client_key),
			KEY revision (revision_id),
			KEY main_asset (main_asset_id),
			KEY subject (subject_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}terms (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(16) NOT NULL,
			name varchar(190) NOT NULL,
			slug varchar(190) NOT NULL,
			color varchar(7) DEFAULT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_slug (type,slug),
			KEY type_status (type,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}assets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(32) NOT NULL,
			storage_key varchar(255) NOT NULL,
			mime varchar(100) NOT NULL,
			byte_size bigint(20) unsigned NOT NULL,
			sha256 char(64) NOT NULL,
			width int(10) unsigned DEFAULT NULL,
			height int(10) unsigned DEFAULT NULL,
			ref_count int(10) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY storage_key (storage_key(190)),
			KEY type_sha (type,sha256)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}upload_sessions (
			id char(36) NOT NULL,
			owner_user_id bigint(20) unsigned NOT NULL,
			original_name varchar(255) NOT NULL,
			expected_size bigint(20) unsigned NOT NULL,
			received_size bigint(20) unsigned NOT NULL DEFAULT 0,
			chunk_count int(10) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			manifest_json longtext DEFAULT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY owner_status (owner_user_id,status),
			KEY expires (expires_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}attempts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id char(36) NOT NULL,
			token_hash char(64) NOT NULL,
			assessment_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			participant_type varchar(16) NOT NULL,
			participant_data longtext DEFAULT NULL,
			status varchar(24) NOT NULL DEFAULT 'in_progress',
			submission_id char(36) DEFAULT NULL,
			integrity_status varchar(24) NOT NULL DEFAULT 'pending',
			ranking_eligible tinyint(1) NOT NULL DEFAULT 0,
			finish_requested_at datetime DEFAULT NULL,
			started_at datetime NOT NULL,
			deadline_at datetime DEFAULT NULL,
			last_activity_at datetime NOT NULL,
			submitted_at datetime DEFAULT NULL,
			duration_seconds int(10) unsigned DEFAULT NULL,
			correct_count int(10) unsigned NOT NULL DEFAULT 0,
			wrong_count int(10) unsigned NOT NULL DEFAULT 0,
			blank_count int(10) unsigned NOT NULL DEFAULT 0,
			score int(10) unsigned NOT NULL DEFAULT 0,
			percentage decimal(7,2) NOT NULL DEFAULT 0,
			anonymized_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY token_hash (token_hash),
			UNIQUE KEY submission_id (submission_id),
			KEY revision_status (revision_id,status),
			KEY assessment_status (assessment_id,status),
			KEY user_revision (wp_user_id,revision_id),
			KEY submitted (submitted_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}answers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attempt_id bigint(20) unsigned NOT NULL,
			question_id bigint(20) unsigned NOT NULL,
			selected_option varchar(24) DEFAULT NULL,
			is_flagged tinyint(1) NOT NULL DEFAULT 0,
			is_correct tinyint(1) DEFAULT NULL,
			awarded_points int(10) unsigned NOT NULL DEFAULT 0,
			mutation_id char(36) DEFAULT NULL,
			answered_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_question (attempt_id,question_id),
			KEY attempt (attempt_id),
			KEY mutation (mutation_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}ranking_entries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			revision_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			attempt_id bigint(20) unsigned NOT NULL,
			score int(10) unsigned NOT NULL,
			duration_seconds int(10) unsigned NOT NULL,
			submitted_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY revision_user (revision_id,wp_user_id),
			UNIQUE KEY attempt (attempt_id),
			KEY ranking (revision_id,score,duration_seconds,submitted_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}attempt_subject_scores (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attempt_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
			correct_count int(10) unsigned NOT NULL DEFAULT 0,
			wrong_count int(10) unsigned NOT NULL DEFAULT 0,
			blank_count int(10) unsigned NOT NULL DEFAULT 0,
			score int(10) unsigned NOT NULL DEFAULT 0,
			max_score int(10) unsigned NOT NULL DEFAULT 0,
			percentage decimal(7,2) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_subject (attempt_id,subject_id),
			KEY revision_subject (revision_id,subject_id),
			KEY subject_score (revision_id,subject_id,score)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}result_email_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attempt_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_run_at datetime NOT NULL,
			claimed_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attempt (attempt_id),
			KEY due_jobs (status,next_run_at),
			KEY claim_window (status,claimed_at)
		) {$charset};";

		foreach ($sql as $statement) {
			dbDelta($statement);
		}

		add_option(
			Settings::OPTION,
			Settings::defaults(),
			'',
			false
		);

		if (! self::schema_is_complete()) {
			return false;
		}

		update_option('paper_to_quiz_db_version', PAPER_TO_QUIZ_DB_VERSION, false);
		return true;
	}

}
