<?php
/** Real-connection fixtures; only an explicitly marked disposable local site is allowed. */

use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\Installer;

if (! defined('ABSPATH')) {
	$wp_load = getenv('PTQ_TEST_WP_LOAD');
	if (! $wp_load || ! is_file($wp_load)) {
		throw new RuntimeException('An isolated WordPress bootstrap is required.');
	}
	require dirname(__DIR__) . '/native-bootstrap.php';
}
if (wp_get_environment_type() !== 'local' || get_option('paper_to_quiz_disposable_test') !== 'sqlite-1.3') {
	throw new RuntimeException('SQLite regression requires a marked disposable local site.');
}

function paper_to_quiz_sqlite_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

function paper_to_quiz_sqlite_services(?callable $interceptor = null): array {
	$db = new Database(null, $interceptor);
	return array($db, new AttemptService($db, new AssessmentService($db, new AssetService($db, new EncryptedStorage())), new Crypto()));
}

function paper_to_quiz_sqlite_add_attempt(array $state, bool $queue = true): array {
	global $wpdb;
	$db = new Database();
	$token = wp_generate_password(32, false, false);
	$now = gmdate('Y-m-d H:i:s');
	$receipt = array('public_id' => wp_generate_uuid4(), 'token' => $token, 'submission_id' => wp_generate_uuid4());
	$row = array(
		'public_id' => $receipt['public_id'], 'token_hash' => hash_hmac('sha256', $token, wp_salt('auth')),
		'assessment_id' => $state['assessment'], 'revision_id' => $state['revision'], 'participant_type' => 'guest',
		'participant_data' => (new Crypto())->encrypt_array(array('first_name' => 'Synthetic', 'last_name' => 'Private', 'email' => 'ptq-sqlite@example.invalid')),
		'status' => 'in_progress', 'started_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_activity_at' => $now,
	);
	if ($queue) {
		$row += array(
			'submission_id' => $receipt['submission_id'], 'finish_requested_at' => $now, 'submission_retry_at' => $now,
			'pending_submission' => wp_json_encode(array('automatic' => false, 'answers' => array(array('question_id' => $state['question'], 'option' => 'A')))),
		);
	}
	paper_to_quiz_sqlite_assert($wpdb->insert($db->table('attempts'), $row) === 1, 'Attempt fixture insert failed.');
	$receipt['id'] = (int) $wpdb->insert_id;
	return $receipt;
}

[$db, $attempts] = paper_to_quiz_sqlite_services();
$wpdb = $db->wpdb();
$action = getenv('PTQ_SQLITE_ACTION') ?: ($argv[1] ?? 'inspect');
$state = get_option('paper_to_quiz_sqlite_fixture', array());

if ($action === 'seed') {
	paper_to_quiz_sqlite_assert(! $state, 'Do not overwrite an existing upgrade fixture.');
	$now = gmdate('Y-m-d H:i:s');
	paper_to_quiz_sqlite_assert($wpdb->insert($db->table('assessments'), array('type' => 'exam', 'status' => 'published', 'created_by' => 1, 'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now)) === 1, 'Assessment insert failed.');
	$state = array('assessment' => (int) $wpdb->insert_id);
	paper_to_quiz_sqlite_assert($wpdb->insert($db->table('revisions'), array(
		'assessment_id' => $state['assessment'], 'revision_no' => 1, 'lifecycle' => 'published', 'title' => 'PTQ SQLite synthetic history',
		'access_mode' => 'guest_allowed', 'options_json' => '["A","B"]', 'total_points' => 10000,
		'allow_repeat' => 0, 'ranking_enabled' => 1, 'ranking_list_enabled' => 1,
		'window_start_utc' => gmdate('Y-m-d H:i:s', time() - 3600), 'window_end_utc' => gmdate('Y-m-d H:i:s', time() + 3600),
		'feedback_timing' => 'after_submit', 'result_visibility' => 'summary', 'participant_fields_json' => '{}', 'created_at' => $now,
	)) === 1, 'Revision insert failed.');
	$state['revision'] = (int) $wpdb->insert_id;
	paper_to_quiz_sqlite_assert($wpdb->update($db->table('assessments'), array('published_revision_id' => $state['revision']), array('id' => $state['assessment'])) === 1, 'Published pointer failed.');
	paper_to_quiz_sqlite_assert($wpdb->insert($db->table('questions'), array(
		'revision_id' => $state['revision'], 'client_key' => wp_generate_uuid4(), 'ordinal' => 1, 'source_page' => 1,
		'crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1, 'crop_height' => 1, 'correct_option' => 'A', 'points' => 10000,
		'created_at' => $now, 'updated_at' => $now,
	)) === 1, 'Question insert failed.');
	$state['question'] = (int) $wpdb->insert_id;
	$historical = paper_to_quiz_sqlite_add_attempt($state, false);
	$result = $attempts->submit($historical['public_id'], $historical['token'], false, $historical['submission_id'], array(array('question_id' => $state['question'], 'option' => 'A')));
	paper_to_quiz_sqlite_assert(! is_wp_error($result), 'Historical attempt could not finish.');
	$state['historical'] = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $db->table('attempts'), $historical['id']), ARRAY_A);
	$storage = new EncryptedStorage();
	$state['private_file'] = $storage->put_string("%PDF-1.4\nPTQ SQLite private source fixture\n%%EOF", 'source_pdf');
	$state['private_cipher_hash'] = hash_file('sha256', $storage->base_directory() . '/' . $state['private_file']['storage_key']);
	$state['receipts'] = array();
	for ($i = 0; $i < 40; ++$i) {
		$state['receipts'][] = paper_to_quiz_sqlite_add_attempt($state);
	}
	// Move publication to a second revision without touching old attempts.
	$revision = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $db->table('revisions'), $state['revision']), ARRAY_A);
	unset($revision['id']);
	$revision['revision_no'] = 2;
	$revision['title'] = 'PTQ SQLite synthetic current revision';
	paper_to_quiz_sqlite_assert($wpdb->insert($db->table('revisions'), $revision) === 1, 'Second revision insert failed.');
	$state['current_revision'] = (int) $wpdb->insert_id;
	$wpdb->update($db->table('assessments'), array('published_revision_id' => $state['current_revision']), array('id' => $state['assessment']));
	$state['baseline_version'] = PAPER_TO_QUIZ_VERSION;
	add_option('paper_to_quiz_sqlite_fixture', $state, '', false);
	echo wp_json_encode(array('baseline' => PAPER_TO_QUIZ_VERSION, 'queued' => count($state['receipts']))) . "\n";
	return;
}

if ($action === 'inspect') {
	echo wp_json_encode(array('plugin' => PAPER_TO_QUIZ_VERSION, 'core' => get_bloginfo('version'), 'php' => PHP_VERSION, 'driver' => defined('SQLITE_DRIVER_VERSION') ? SQLITE_DRIVER_VERSION : null, 'engine' => $db->engine())) . "\n";
	return;
}

if ($action === 'unit-clean') {
	paper_to_quiz_sqlite_assert((bool) getenv('PTQ_TEST_SQLITE_PATH') && DB_PATH === getenv('PTQ_TEST_SQLITE_PATH'), 'Only a disposable unit copy can be cleared.');
	foreach (array('result_email_jobs', 'attempt_subject_scores', 'ranking_entries', 'answers', 'attempts', 'questions', 'revisions', 'assessments', 'upload_sessions', 'assets', 'terms') as $table) {
		paper_to_quiz_sqlite_assert($wpdb->query($wpdb->prepare('DELETE FROM %i', $db->table($table))) !== false, 'Unit-copy cleanup failed.');
	}
	echo "disposable-unit-copy-ready\n";
	return;
}

if ($action === 'ranking-release') {
	putenv('PAPER_TO_QUIZ_ALLOW_E2E=1');
	putenv('PTQ_RANKING_ACTION=release');
	require dirname(__DIR__) . '/ranking-e2e-fixture.php';
	return;
}

if ($action === 'park-email') {
	$wpdb->query($wpdb->prepare('UPDATE %i SET next_run_at=%s', $db->table('result_email_jobs'), gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)));
	echo "synthetic-email-jobs-parked\n";
	return;
}

if ($action === 'email-ready') {
	$receipt = end($state['receipts']);
	$wpdb->query($wpdb->prepare('UPDATE %i SET next_run_at=%s', $db->table('result_email_jobs'), gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)));
	$wpdb->update($db->table('result_email_jobs'), array('status' => 'pending', 'next_run_at' => gmdate('Y-m-d H:i:s')), array('attempt_id' => $receipt['id']));
	echo "one-email-job-ready\n";
	return;
}

if ($action === 'email-worker') {
	$log = getenv('PTQ_EMAIL_TEST_LOG');
	paper_to_quiz_sqlite_assert((bool) $log, 'A synthetic email observation log is required.');
	add_filter('pre_wp_mail', static function ($result, array $attributes) use ($log) {
		paper_to_quiz_sqlite_assert($attributes['to'] === 'ptq-sqlite@example.invalid', 'Do not send non-fixture email.');
		file_put_contents($log, "claimed-send\n", FILE_APPEND | LOCK_EX);
		usleep(150000);
		return true;
	}, 10, 2);
	(new \PaperToQuiz\Application\ResultEmailService($db, $attempts))->process();
	echo "email-worker-finished\n";
	return;
}

if ($action === 'lock' || $action === 'hold-lock') {
	$lock = $db->acquire_lock('ptq-submit-' . md5(DB_NAME . ':' . $db->table('attempts')), 'submissions');
	echo (null === $lock ? 'contended' : 'acquired') . "\n";
	flush();
	if ($action === 'hold-lock' && null !== $lock) {
		sleep(30);
	}
	if ($lock) {
		$lock->release();
		$lock->release(); // A stale handle must never release a later owner's lock.
	}
	return;
}

if ($action === 'hold-write') {
	$db->begin();
	echo "write-held\n";
	flush();
	sleep(30);
	$db->rollback();
	return;
}

if ($action === 'schema') {
	echo Installer::maybe_upgrade() ? "schema-ready\n" : "schema-contended\n";
	return;
}

if ($action === 'damage-schema') {
	$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX submission_queue', $db->table('attempts')));
	delete_transient('paper_to_quiz_schema_verified_' . PAPER_TO_QUIZ_DB_VERSION);
	delete_transient('paper_to_quiz_schema_failed_' . PAPER_TO_QUIZ_DB_VERSION);
	echo "schema-index-removed\n";
	return;
}

paper_to_quiz_sqlite_assert((bool) $state, 'Seed fixture first.');

if ($action === 'add') {
	$receipt = paper_to_quiz_sqlite_add_attempt($state, getenv('PTQ_UNQUEUED') !== '1');
	$state['receipts'][] = $receipt;
	update_option('paper_to_quiz_sqlite_fixture', $state, false);
	echo wp_json_encode(array('index' => count($state['receipts']) - 1)) . "\n";
	return;
}

if ($action === 'submit' || $action === 'busy-submit') {
	$index = (int) getenv('PTQ_RECEIPT_INDEX');
	$receipt = $state['receipts'][$index];
	$result = $attempts->submit($receipt['public_id'], $receipt['token'], false, $receipt['submission_id'], array(array('question_id' => $state['question'], 'option' => 'A')), getenv('PTQ_DEFERRED') !== '0');
	echo wp_json_encode(is_wp_error($result) ? array('status' => $result->get_error_data()['status'], 'code' => $result->get_error_code()) : array('status' => $result['status'])) . "\n";
	return;
}

if ($action === 'worker' || $action === 'fault' || $action === 'crash-before' || $action === 'crash-after') {
	$barrier = getenv('PTQ_BARRIER');
	if ($barrier) {
		file_put_contents($barrier . '.' . getmypid(), 'ready');
		$deadline = microtime(true) + 10;
		while (! is_file($barrier) && microtime(true) < $deadline) {
			usleep(10000);
		}
		paper_to_quiz_sqlite_assert(is_file($barrier), 'Worker barrier timed out.');
	}
	if ($action === 'fault' || $action === 'crash-before') {
		$operation = getenv('PTQ_FAIL_OPERATION') ?: 'transaction_commit';
		[$db, $attempts] = paper_to_quiz_sqlite_services(static function (string $name, callable $write) use ($operation, $action) {
			if ($name === $operation) {
				if ($action === 'crash-before') {
					echo "crash-ready\n";
					flush();
					sleep(30);
				}
				return false;
			}
			return $write();
		});
	}
	if ($action === 'crash-after') {
		add_action('paper_to_quiz_attempt_completed', static function (): void {
			echo "crash-ready\n";
			flush();
			sleep(30);
		}, 1);
	}
	$attempts->process_submissions();
	echo "worker-finished\n";
	return;
}

if ($action === 'retry') {
	$wpdb->query($wpdb->prepare("UPDATE %i SET submission_retry_at=%s WHERE assessment_id=%d AND pending_submission IS NOT NULL", $db->table('attempts'), gmdate('Y-m-d H:i:s'), $state['assessment']));
	echo "retry-ready\n";
	return;
}

if ($action === 'verify' || $action === 'verify-pending') {
	$pending_count = 0;
	foreach ($state['receipts'] as $receipt) {
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $db->table('attempts'), $receipt['id']), ARRAY_A);
		if ($row['status'] === 'in_progress') {
			++$pending_count;
			paper_to_quiz_sqlite_assert((bool) $row['pending_submission'], 'Failed worker lost its durable snapshot.');
			foreach (array('answers', 'ranking_entries', 'result_email_jobs', 'attempt_subject_scores') as $table) {
				paper_to_quiz_sqlite_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE attempt_id=%d', $db->table($table), $receipt['id'])) === 0, 'Partial transaction escaped rollback.');
			}
			continue;
		}
		paper_to_quiz_sqlite_assert(in_array($row['status'], array('submitted', 'auto_submitted'), true) && (int) $row['score'] === 10000 && null === $row['pending_submission'], 'Final score or durable state mismatch.');
		paper_to_quiz_sqlite_assert((int) $row['revision_id'] === $state['revision'], 'An old attempt moved to a new revision.');
		foreach (array('answers', 'ranking_entries', 'result_email_jobs') as $table) {
			paper_to_quiz_sqlite_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE attempt_id=%d', $db->table($table), $receipt['id'])) === 1, 'Final answer/ranking/email job is not unique.');
		}
	}
	paper_to_quiz_sqlite_assert($action === 'verify-pending' ? $pending_count === 1 : $pending_count === 0, 'Unexpected pending work remains.');
	$historical = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $db->table('attempts'), $state['historical']['id']), ARRAY_A);
	$expected_historical = $state['historical'];
	// Both engines preserve DECIMAL(7,2); their string padding may differ.
	$historical['percentage'] = number_format((float) $historical['percentage'], 2, '.', '');
	$expected_historical['percentage'] = number_format((float) $expected_historical['percentage'], 2, '.', '');
	paper_to_quiz_sqlite_assert($historical === $expected_historical, 'Historical result changed during upgrade.');
	$storage = new EncryptedStorage();
	paper_to_quiz_sqlite_assert(hash_file('sha256', $storage->base_directory() . '/' . $state['private_file']['storage_key']) === $state['private_cipher_hash'], 'Private ciphertext changed.');
	paper_to_quiz_sqlite_assert(hash('sha256', $storage->get_contents($state['private_file']['storage_key'], 'source_pdf')) === $state['private_file']['sha256'], 'Private source no longer decrypts.');
	echo wp_json_encode(array('verified' => count($state['receipts']), 'pending' => $pending_count, 'history' => 'unchanged', 'private_file' => 'unchanged')) . "\n";
	return;
}
throw new RuntimeException('Unknown SQLite test action.');
