<?php
/**
 * Disposable local data regression checks for Paper to Quiz.
 *
 * Run with:
 * wp eval-file wp-content/plugins/paper-to-quiz/tests/data-regression.php
 */

use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\Settings;

if (! defined('WP_CLI') || ! WP_CLI) {
	throw new RuntimeException('This regression script must be run with WP-CLI.');
}

$environment_type = function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
if (! in_array($environment_type, array('local', 'development', 'staging'), true)) {
	throw new RuntimeException('This regression script requires a local, development, or staging WordPress environment.');
}

if (getenv('PAPER_TO_QUIZ_ALLOW_REGRESSION') !== '1') {
	throw new RuntimeException('Set PAPER_TO_QUIZ_ALLOW_REGRESSION=1 to enable destructive regression checks.');
}

/**
 * @throws RuntimeException When a regression assertion fails.
 */
function paper_to_quiz_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

/**
 * @return array<string,int>
 */
function paper_to_quiz_cleanup_counts(wpdb $wpdb, Database $database, string $suffix): array {
	$like = '%' . $wpdb->esc_like($suffix) . '%';

	return array(
		'paper_to_quiz_assessments' => (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $database->table('assessments') . ' a INNER JOIN ' . $database->table('revisions') . ' r ON r.assessment_id = a.id WHERE r.title LIKE %s',
				$like
			)
		),
		'paper_to_quiz_revisions'   => (int) $wpdb->get_var(
			$wpdb->prepare('SELECT COUNT(*) FROM ' . $database->table('revisions') . ' WHERE title LIKE %s', $like)
		),
		'paper_to_quiz_questions'   => (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $database->table('questions') . ' q INNER JOIN ' . $database->table('revisions') . ' r ON r.id = q.revision_id WHERE r.title LIKE %s',
				$like
			)
		),
		'paper_to_quiz_terms'       => (int) $wpdb->get_var(
			$wpdb->prepare('SELECT COUNT(*) FROM ' . $database->table('terms') . ' WHERE name LIKE %s', $like)
		),
	);
}

/**
 * Insert a submitted-attempt fixture for the disposable retention checks.
 *
 * @param int[] $attempt_ids
 */
function paper_to_quiz_insert_retention_attempt(wpdb $wpdb, Database $database, Crypto $crypto, string $status, ?string $submitted_at, bool $anonymized, array &$attempt_ids): int {
	$now = gmdate('Y-m-d H:i:s');
	$inserted = $wpdb->insert(
		$database->table('attempts'),
		array(
			'public_id'        => wp_generate_uuid4(),
			'token_hash'       => hash('sha256', wp_generate_uuid4()),
			'assessment_id'    => 1,
			'revision_id'      => 1,
			'wp_user_id'       => 1,
			'participant_type' => 'member',
			'participant_data' => $crypto->encrypt_array(array('email' => 'retention-regression@example.com')),
			'status'           => $status,
			'submission_id'    => wp_generate_uuid4(),
			'integrity_status' => 'on_time',
			'ranking_eligible' => 1,
			'started_at'       => $now,
			'last_activity_at' => $now,
			'submitted_at'     => $submitted_at,
			'anonymized_at'    => $anonymized ? $now : null,
		)
	);
	paper_to_quiz_assert(1 === $inserted, 'Retention attempt fixture could not be inserted.');
	$attempt_id    = (int) $wpdb->insert_id;
	$attempt_ids[] = $attempt_id;
	return $attempt_id;
}

$database      = new Database();
$wpdb          = $database->wpdb();
$asset_storage = new EncryptedStorage();
$asset_service = new AssetService($database, $asset_storage);
$service       = new AssessmentService($database, $asset_service);
$suffix        = strtolower(wp_generate_password(10, false, false));
$now           = current_time('mysql', true);

$class_id      = 0;
$subject_id    = 0;
$assessment_a  = 0;
$assessment_b  = 0;
$revision_a    = 0;
$revision_b    = 0;
$report        = array();
$cleanup_counts = array();
$retention_attempt_ids = array();
$retention_settings_before = get_option(Settings::OPTION, false);
$asset_ids     = array();
$asset_storage_keys = array();

try {
	$administrator = get_role('administrator');
	paper_to_quiz_assert($administrator instanceof WP_Role, 'Administrator role could not be loaded.');
	foreach (array('paper_to_quiz_manage_assessments', 'paper_to_quiz_publish_assessments', 'paper_to_quiz_view_results', 'paper_to_quiz_manage_settings') as $capability) {
		paper_to_quiz_assert($administrator->has_cap($capability), 'Administrator role is missing ' . $capability . '.');
	}

	/*
	 * A live reference must keep its encrypted file, while the final release
	 * must remove both records. Releasing the already-removed ID is idempotent.
	 */
	$atomic_asset_id = $asset_service->create_from_string(
		'Atomic asset reference regression ' . $suffix,
		'question_image',
		'image/png'
	);
	$asset_ids[] = $atomic_asset_id;
	$atomic_asset = $asset_service->get($atomic_asset_id);
	paper_to_quiz_assert(is_array($atomic_asset) && ! empty($atomic_asset['storage_key']), 'Atomic asset fixture was not persisted.');
	$atomic_storage_key = (string) $atomic_asset['storage_key'];
	$asset_storage_keys[] = $atomic_storage_key;
	paper_to_quiz_assert($asset_storage->exists($atomic_storage_key), 'Atomic asset fixture file was not written.');
	$asset_service->retain($atomic_asset_id);
	paper_to_quiz_assert(
		2 === (int) $wpdb->get_var($wpdb->prepare('SELECT ref_count FROM ' . $database->table('assets') . ' WHERE id=%d', $atomic_asset_id)),
		'Retaining a live asset did not increment its reference count.'
	);
	$asset_service->release($atomic_asset_id);
	paper_to_quiz_assert(
		1 === (int) $wpdb->get_var($wpdb->prepare('SELECT ref_count FROM ' . $database->table('assets') . ' WHERE id=%d', $atomic_asset_id)),
		'Releasing one live asset reference changed the final count.'
	);
	paper_to_quiz_assert($asset_storage->exists($atomic_storage_key), 'Releasing one live asset reference deleted its file.');
	$asset_service->release($atomic_asset_id);
	paper_to_quiz_assert(
		0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $database->table('assets') . ' WHERE id=%d', $atomic_asset_id)),
		'Releasing the final asset reference left a database row.'
	);
	paper_to_quiz_assert(! $asset_storage->exists($atomic_storage_key), 'Releasing the final asset reference left a storage file.');
	$asset_service->release($atomic_asset_id);
	$report['asset_reference_atomicity'] = 'passed';

	$created_class = $service->save_class('Regression Class ' . $suffix, null, '#2f6fed');
	paper_to_quiz_assert(! is_wp_error($created_class), 'Class could not be created.');
	$class_id = (int) $created_class['id'];
	paper_to_quiz_assert($created_class['color'] === '#2f6fed', 'Class color was not persisted.');
	paper_to_quiz_assert($service->trash_class($class_id), 'Class could not be moved to trash.');

	$restored_class = $service->save_class('Regression Class ' . $suffix, null, '#d63638');
	paper_to_quiz_assert(! is_wp_error($restored_class), 'Trashed class could not be recreated.');
	paper_to_quiz_assert((int) $restored_class['id'] === $class_id, 'Recreating a trashed class did not reuse its identity.');
	paper_to_quiz_assert($restored_class['status'] === 'active', 'Recreated class was not restored.');
	paper_to_quiz_assert($restored_class['color'] === '#d63638', 'Restored class color was not updated.');

	$class_conflict = $service->save_class('Regression Class ' . $suffix, null, '#1769aa');
	paper_to_quiz_assert(is_wp_error($class_conflict), 'An active duplicate class was accepted.');
	paper_to_quiz_assert($class_conflict->get_error_code() === 'paper_to_quiz_term_exists', 'Active duplicate class did not return a conflict.');
	paper_to_quiz_assert((int) $class_conflict->get_error_data()['status'] === 409, 'Active duplicate class did not return HTTP 409.');

	$created_subject = $service->save_subject('Regression Subject ' . $suffix);
	paper_to_quiz_assert(! is_wp_error($created_subject), 'Subject could not be created.');
	$subject_id = (int) $created_subject['id'];
	paper_to_quiz_assert($service->trash_subject($subject_id), 'Subject could not be moved to trash.');

	$restored_subject = $service->save_subject('Regression Subject ' . $suffix);
	paper_to_quiz_assert(! is_wp_error($restored_subject), 'Trashed subject could not be recreated.');
	paper_to_quiz_assert((int) $restored_subject['id'] === $subject_id, 'Recreating a trashed subject did not reuse its identity.');
	paper_to_quiz_assert($restored_subject['status'] === 'active', 'Recreated subject was not restored.');

	foreach (array('exam', 'test') as $index => $type) {
		$inserted = $wpdb->insert(
			$database->table('assessments'),
			array(
				'type'       => $type,
				'status'     => 'draft',
				'created_by' => 1,
				'updated_by' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		paper_to_quiz_assert($inserted === 1, 'Regression assessment could not be inserted.');
		$assessment_id = (int) $wpdb->insert_id;

		$inserted = $wpdb->insert(
			$database->table('revisions'),
			array(
				'assessment_id'          => $assessment_id,
				'revision_no'            => 1,
				'lifecycle'              => 'draft',
				'title'                  => 'Regression ' . $type . ' ' . $suffix,
				'description'            => '',
				'class_id'               => $class_id,
				'access_mode'            => 'guest_allowed',
				'options_json'            => wp_json_encode(array('A', 'B', 'C', 'D')),
				'total_points'           => 10000,
				'duration_seconds'       => null,
				'window_start_utc'       => null,
				'window_end_utc'         => null,
				'results_release_at_utc' => null,
				'allow_repeat'           => 1,
				'ranking_enabled'        => 0,
				'feedback_timing'        => 'after_submit',
				'result_visibility'      => 'summary',
				'participant_fields_json' => '{}',
				'retention_days'         => 365,
				'source_asset_id'        => null,
				'created_at'             => $now,
				'published_at'           => null,
			)
		);
		paper_to_quiz_assert($inserted === 1, 'Regression revision could not be inserted.');
		$revision_id = (int) $wpdb->insert_id;

		paper_to_quiz_assert(
			false !== $wpdb->update(
				$database->table('assessments'),
				array('current_draft_revision_id' => $revision_id),
				array('id' => $assessment_id)
			),
			'Regression assessment could not be linked to its revision.'
		);
		paper_to_quiz_assert(
			1 === $wpdb->insert(
				$database->table('questions'),
				array(
					'revision_id'    => $revision_id,
					'client_key'     => wp_generate_uuid4(),
					'ordinal'        => 1,
					'source_page'    => 1,
					'crop_x'         => '0.10000000',
					'crop_y'         => '0.10000000',
					'crop_width'     => '0.80000000',
					'crop_height'    => '0.30000000',
					'source_rotation'=> 0,
					'main_asset_id'  => null,
					'thumb_asset_id' => null,
					'subject_id'     => $subject_id,
					'correct_option' => 'A',
					'points'         => 10000,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			),
			'Regression question could not be inserted.'
		);

		if ($index === 0) {
			$assessment_a = $assessment_id;
			$revision_a   = $revision_id;
		} else {
			$assessment_b = $assessment_id;
			$revision_b   = $revision_id;
		}
	}

	paper_to_quiz_assert(
		false !== $wpdb->update(
			$database->table('revisions'),
			array('lifecycle' => 'published', 'published_at' => $now),
			array('id' => $revision_b)
		),
		'Regression test revision could not be published.'
	);
	paper_to_quiz_assert(
		false !== $wpdb->update(
			$database->table('assessments'),
			array(
				'status'                    => 'published',
				'current_draft_revision_id' => null,
				'published_revision_id'     => $revision_b,
			),
			array('id' => $assessment_b)
		),
		'Regression test could not be published.'
	);
	$bootstrap = (new AttemptService($database, $service, new Crypto()))->bootstrap($assessment_b);
	paper_to_quiz_assert(! is_wp_error($bootstrap), 'Published regression test could not be bootstrapped.');
	paper_to_quiz_assert($bootstrap['class_color'] === '#d63638', 'Student bootstrap did not receive the selected class color.');

	paper_to_quiz_assert($service->trash($assessment_a), 'Target exam could not be moved to trash.');
	$purged = $service->purge($assessment_a);
	paper_to_quiz_assert(! is_wp_error($purged), 'Target exam could not be permanently deleted.');
	paper_to_quiz_assert(
		$wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $database->table('assessments') . ' WHERE id = %d', $assessment_b)) === 'published',
		'Deleting the exam changed the unrelated test status.'
	);
	paper_to_quiz_assert(
		$wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $database->table('terms') . ' WHERE id = %d', $class_id)) === 'active',
		'Deleting the exam changed the shared class status.'
	);
	paper_to_quiz_assert(
		$wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $database->table('terms') . ' WHERE id = %d', $subject_id)) === 'active',
		'Deleting the exam changed the shared subject status.'
	);
	paper_to_quiz_assert(
		(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $database->table('questions') . ' WHERE revision_id = %d', $revision_b)) === 1,
		'Deleting the exam removed the unrelated test question.'
	);

	$repeated_purge = $service->purge($assessment_a);
	paper_to_quiz_assert(! is_wp_error($repeated_purge), 'Repeating a completed permanent deletion returned an error.');
	paper_to_quiz_assert(! empty($repeated_purge['already_deleted']), 'Repeated permanent deletion was not reported as idempotent.');

	$retention_settings = Settings::get();
	$retention_settings['retention_days'] = 1;
	update_option(Settings::OPTION, $retention_settings);
	$retention_crypto = new Crypto();
	$retention_service = new AttemptService($database, $service, $retention_crypto);
	$retention_now = time();
	$retention_old_submitted = paper_to_quiz_insert_retention_attempt(
		$wpdb,
		$database,
		$retention_crypto,
		'submitted',
		gmdate('Y-m-d H:i:s', $retention_now - DAY_IN_SECONDS - 5),
		false,
		$retention_attempt_ids
	);
	$retention_old_auto = paper_to_quiz_insert_retention_attempt(
		$wpdb,
		$database,
		$retention_crypto,
		'auto_submitted',
		gmdate('Y-m-d H:i:s', $retention_now - DAY_IN_SECONDS - 4),
		false,
		$retention_attempt_ids
	);
	$retention_new = paper_to_quiz_insert_retention_attempt(
		$wpdb,
		$database,
		$retention_crypto,
		'submitted',
		gmdate('Y-m-d H:i:s', $retention_now - DAY_IN_SECONDS + 5),
		false,
		$retention_attempt_ids
	);
	$retention_in_progress = paper_to_quiz_insert_retention_attempt(
		$wpdb,
		$database,
		$retention_crypto,
		'in_progress',
		null,
		false,
		$retention_attempt_ids
	);
	$retention_already_anonymized = paper_to_quiz_insert_retention_attempt(
		$wpdb,
		$database,
		$retention_crypto,
		'submitted',
		gmdate('Y-m-d H:i:s', $retention_now - DAY_IN_SECONDS - 3),
		true,
		$retention_attempt_ids
	);
	paper_to_quiz_assert(
		1 === $wpdb->insert(
			$database->table('ranking_entries'),
			array(
				'revision_id'      => 1,
				'wp_user_id'       => 1,
				'attempt_id'       => $retention_old_submitted,
				'score'            => 100,
				'duration_seconds' => 60,
				'submitted_at'     => gmdate('Y-m-d H:i:s', $retention_now - 2 * DAY_IN_SECONDS),
			)
		),
		'Retention ranking fixture could not be inserted.'
	);
	paper_to_quiz_assert(2 === $retention_service->anonymize_expired(), 'Retention cleanup did not process all due submitted attempts.');
	$old_row = $wpdb->get_row(
		$wpdb->prepare('SELECT wp_user_id,participant_data,anonymized_at FROM ' . $database->table('attempts') . ' WHERE id = %d', $retention_old_submitted),
		ARRAY_A
	);
	paper_to_quiz_assert(null === $old_row['wp_user_id'] && null === $old_row['participant_data'] && null !== $old_row['anonymized_at'], 'Retention cleanup did not remove the expired attempt identity.');
	paper_to_quiz_assert(
		0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $database->table('ranking_entries') . ' WHERE attempt_id = %d', $retention_old_submitted)),
		'Retention cleanup left a ranking identity behind.'
	);
	paper_to_quiz_assert(
		null !== $wpdb->get_var($wpdb->prepare('SELECT participant_data FROM ' . $database->table('attempts') . ' WHERE id = %d', $retention_new)),
		'Retention cleanup anonymized a newer submission.'
	);
	paper_to_quiz_assert(
		null === $wpdb->get_var($wpdb->prepare('SELECT anonymized_at FROM ' . $database->table('attempts') . ' WHERE id = %d', $retention_in_progress)),
		'Retention cleanup anonymized an in-progress attempt.'
	);
	paper_to_quiz_assert(
		null !== $wpdb->get_var($wpdb->prepare('SELECT anonymized_at FROM ' . $database->table('attempts') . ' WHERE id = %d', $retention_already_anonymized)),
		'Retention cleanup changed an already anonymized attempt.'
	);
	paper_to_quiz_assert(0 === $retention_service->anonymize_expired(), 'Retention cleanup was not idempotent after due rows drained.');

	$report = array(
		'admin_capabilities'  => 'passed',
		'class_restore'       => 'passed',
		'subject_restore'     => 'passed',
		'class_color'         => 'passed',
		'student_class_color' => 'passed',
		'delete_isolation'    => 'passed',
		'idempotent_delete'   => 'passed',
		'retention_cleanup'   => 'passed',
		'asset_reference_atomicity' => 'passed',
	);
} finally {
	if (false === $retention_settings_before) {
		delete_option(Settings::OPTION);
	} else {
		update_option(Settings::OPTION, $retention_settings_before);
	}
	if ($retention_attempt_ids) {
		$ids = implode(',', array_map('intval', $retention_attempt_ids));
		// phpcs:disable WordPress.DB -- Direct cleanup of disposable retention fixtures by collected IDs.
		$wpdb->query('DELETE FROM ' . $database->table('ranking_entries') . " WHERE attempt_id IN ({$ids})");
		$wpdb->query('DELETE FROM ' . $database->table('answers') . " WHERE attempt_id IN ({$ids})");
		$wpdb->query('DELETE FROM ' . $database->table('attempt_subject_scores') . " WHERE attempt_id IN ({$ids})");
		$wpdb->query('DELETE FROM ' . $database->table('result_email_jobs') . " WHERE attempt_id IN ({$ids})");
		$wpdb->query('DELETE FROM ' . $database->table('attempts') . " WHERE id IN ({$ids})");
		// phpcs:enable WordPress.DB
	}
	foreach (array_values(array_unique($asset_ids)) as $asset_id) {
		$wpdb->delete($database->table('assets'), array('id' => $asset_id), array('%d'));
	}
	foreach (array_values(array_unique($asset_storage_keys)) as $storage_key) {
		try {
			$asset_storage->delete($storage_key);
		} catch (Throwable $error) {
			error_log('[Paper to Quiz regression cleanup] asset storage: ' . $error->getMessage());
		}
	}
	foreach (array_filter(array($revision_a, $revision_b)) as $revision_id) {
		paper_to_quiz_assert(false !== $wpdb->delete($database->table('questions'), array('revision_id' => $revision_id), array('%d')), 'Regression questions could not be cleaned up.');
	}
	foreach (array_filter(array($assessment_a, $assessment_b)) as $assessment_id) {
		paper_to_quiz_assert(false !== $wpdb->delete($database->table('revisions'), array('assessment_id' => $assessment_id), array('%d')), 'Regression revisions could not be cleaned up.');
		paper_to_quiz_assert(false !== $wpdb->delete($database->table('assessments'), array('id' => $assessment_id), array('%d')), 'Regression assessments could not be cleaned up.');
	}
	if ($subject_id) {
		paper_to_quiz_assert(false !== $wpdb->delete($database->table('terms'), array('id' => $subject_id, 'type' => 'subject'), array('%d', '%s')), 'Regression subject could not be cleaned up.');
	}
	if ($class_id) {
		paper_to_quiz_assert(false !== $wpdb->delete($database->table('terms'), array('id' => $class_id, 'type' => 'class'), array('%d', '%s')), 'Regression class could not be cleaned up.');
	}
	$cleanup_counts = paper_to_quiz_cleanup_counts($wpdb, $database, $suffix);
	paper_to_quiz_assert($cleanup_counts === array('paper_to_quiz_assessments' => 0, 'paper_to_quiz_revisions' => 0, 'paper_to_quiz_questions' => 0, 'paper_to_quiz_terms' => 0), 'Regression cleanup left owned records behind.');
}

$report['cleanup'] = $cleanup_counts;
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
