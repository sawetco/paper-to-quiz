<?php
/** WP-CLI REST regression gate for Paper to Quiz. */

use PaperToQuiz\Infrastructure\Database;

if (! defined('WP_CLI') || ! WP_CLI) {
	throw new RuntimeException('This regression script must be run with WP-CLI.');
}
$environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
if (! in_array($environment, array('local', 'development', 'staging'), true)) {
	throw new RuntimeException('This regression script requires a local, development, or staging environment.');
}
if (getenv('PAPER_TO_QUIZ_ALLOW_REGRESSION') !== '1') {
	throw new RuntimeException('Set PAPER_TO_QUIZ_ALLOW_REGRESSION=1 to enable regression checks.');
}

function paper_to_quiz_rest_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

function paper_to_quiz_rest_status(WP_REST_Response|WP_Error $response): int {
	return is_wp_error($response) ? (int) ($response->get_error_data()['status'] ?? 500) : (int) $response->get_status();
}

function paper_to_quiz_rest_data(WP_REST_Response|WP_Error $response): array {
	$data = is_wp_error($response) ? rest_convert_error_to_response($response)->get_data() : $response->get_data();
	return is_array($data) ? $data : array();
}

function paper_to_quiz_rest_request(string $method, string $route, array $params = array(), ?int $user_id = null): WP_REST_Response|WP_Error {
	$previous = get_current_user_id();
	wp_set_current_user(null === $user_id ? 0 : $user_id);
	$request = new WP_REST_Request($method, $route);
	if ($params) {
		$request->set_body(wp_json_encode($params));
		$request->set_header('Content-Type', 'application/json');
	}
	$response = rest_do_request($request);
	wp_set_current_user($previous);
	return $response;
}

function paper_to_quiz_rest_cleanup_counts(wpdb $wpdb, Database $db, string $suffix): array {
	$like = '%' . $wpdb->esc_like($suffix) . '%';
	return array(
		'assessments' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('assessments') . ' a INNER JOIN ' . $db->table('revisions') . ' r ON r.assessment_id=a.id WHERE r.title LIKE %s', $like)),
		'revisions'   => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('revisions') . ' WHERE title LIKE %s', $like)),
		'questions'   => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('questions') . ' q INNER JOIN ' . $db->table('revisions') . ' r ON r.id=q.revision_id WHERE r.title LIKE %s', $like)),
		'terms'       => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('terms') . ' WHERE name LIKE %s', $like)),
	);
}

$db = new Database();
$wpdb = $db->wpdb();
$suffix = 'REST Regression ' . strtolower(wp_generate_password(10, false, false));
$now = current_time('mysql', true);
$manager = 0;
$class = 0;
$subject = 0;
$assessment_draft = 0;
$assessment_public = 0;
$assessment_other = 0;
$revision_draft = 0;
$question = 0;
$attempt = 0;
$report = array();

try {
	$anonymous = paper_to_quiz_rest_request('GET', '/paper-to-quiz/v1/admin/classes');
	paper_to_quiz_rest_assert(in_array(paper_to_quiz_rest_status($anonymous), array(401, 403), true), 'Unauthenticated admin access was not denied.');
	$anon_post = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/admin/classes', array('name' => $suffix . ' Anon', 'color' => '#000000'), 0);
	paper_to_quiz_rest_assert(in_array(paper_to_quiz_rest_status($anon_post), array(401, 403), true), 'Anonymous mutating admin route was not denied.');

	$server = rest_get_server();
	$public_methods = array_flip(array('GET'));
	foreach ($server->get_routes('paper-to-quiz/v1') as $route => $handlers) {
		foreach ($handlers as $handler) {
			$methods = is_array($handler['methods']) ? $handler['methods'] : array($handler['methods'] => true);
			$mutating = false;
			foreach (array_keys($methods) as $m) {
				if (! in_array(strtoupper((string) $m), array('GET'), true)) { $mutating = true; break; }
			}
			if (! $mutating) { continue; }
			$cb = $handler['permission_callback'] ?? null;
			$is_admin_route = str_starts_with($route, '/paper-to-quiz/v1/admin');
			if ($is_admin_route) {
				paper_to_quiz_rest_assert($cb !== null && $cb !== '__return_true', 'Mutating admin route lacks a capability permission_callback: ' . $route);
			}
		}
	}

	$admin_classes = paper_to_quiz_rest_request('GET', '/paper-to-quiz/v1/admin/classes', array(), 1);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($admin_classes), 'Admin class list route was not available.');
	$settings_response = paper_to_quiz_rest_request('GET', '/paper-to-quiz/v1/admin/settings', array(), 1);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($settings_response), 'Admin settings route was not available.');
	$settings_data = paper_to_quiz_rest_data($settings_response);
	paper_to_quiz_rest_assert(array_key_exists('purge_on_uninstall', $settings_data), 'Settings did not expose the uninstall cleanup option.');
	paper_to_quiz_rest_assert(true === ($settings_data['storage_writable'] ?? false), 'Private file storage was not prepared for the active plugin.');
	$manager = wp_insert_user(array('user_login' => 'paper_to_quiz_reg_' . strtolower(wp_generate_password(8, false, false)), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber'));
	paper_to_quiz_rest_assert(! is_wp_error($manager), 'Synthetic manager could not be created.');
	$manager = (int) $manager;
	$user = new WP_User($manager);
	$user->add_cap('paper_to_quiz_manage_assessments');
	$class_response = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/admin/classes', array('name' => $suffix . ' Class', 'color' => '#123456'), $manager);
	paper_to_quiz_rest_assert(201 === paper_to_quiz_rest_status($class_response) || 200 === paper_to_quiz_rest_status($class_response), 'Class REST creation failed.');
	$class_data = $class_response->get_data();
	$class = (int) ($class_data['id'] ?? 0);
	paper_to_quiz_rest_assert($class > 0 && '#123456' === ($class_data['color'] ?? ''), 'Class color was not persisted.');
	$duplicate = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/admin/classes', array('name' => $suffix . ' Class', 'color' => '#123456'), $manager);
	paper_to_quiz_rest_assert(409 === paper_to_quiz_rest_status($duplicate), 'Duplicate class did not return 409.');

	paper_to_quiz_rest_assert(1 === $wpdb->insert($db->table('terms'), array('type' => 'subject', 'name' => $suffix . ' Subject', 'slug' => sanitize_title($suffix . ' Subject'), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now)), 'Subject insert failed.');
	$subject = (int) $wpdb->insert_id;
	$insert_assessment = static function (string $type) use ($wpdb, $db, $now, $class, $subject, $suffix): array {
		paper_to_quiz_rest_assert(1 === $wpdb->insert($db->table('assessments'), array('type' => $type, 'status' => 'draft', 'created_by' => 1, 'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now)), 'Assessment insert failed.');
		$id = (int) $wpdb->insert_id;
		paper_to_quiz_rest_assert(1 === $wpdb->insert($db->table('revisions'), array('assessment_id' => $id, 'revision_no' => 1, 'lifecycle' => 'draft', 'title' => $suffix . ' ' . $type, 'description' => '', 'class_id' => $class, 'access_mode' => 'guest_allowed', 'options_json' => wp_json_encode(array('A', 'B', 'C', 'D')), 'total_points' => 10000, 'allow_repeat' => 1, 'feedback_timing' => 'after_submit', 'result_visibility' => 'summary', 'participant_fields_json' => '{}', 'retention_days' => 365, 'created_at' => $now)), 'Revision insert failed.');
		$revision = (int) $wpdb->insert_id;
		paper_to_quiz_rest_assert(false !== $wpdb->update($db->table('assessments'), array('current_draft_revision_id' => $revision), array('id' => $id)), 'Assessment pointer update failed.');
		paper_to_quiz_rest_assert(1 === $wpdb->insert($db->table('questions'), array('revision_id' => $revision, 'client_key' => wp_generate_uuid4(), 'ordinal' => 1, 'source_page' => 1, 'crop_x' => '0.1', 'crop_y' => '0.1', 'crop_width' => '0.8', 'crop_height' => '0.3', 'subject_id' => $subject, 'correct_option' => 'A', 'points' => 10000, 'created_at' => $now, 'updated_at' => $now)), 'Question insert failed.');
		return array($id, $revision, (int) $wpdb->insert_id);
	};
	[$assessment_draft, $revision_draft, $question] = $insert_assessment('exam');
	[$assessment_public, $published_revision, $public_question] = $insert_assessment('test');
	paper_to_quiz_rest_assert(false !== $wpdb->update($db->table('revisions'), array('lifecycle' => 'published', 'published_at' => $now), array('id' => $published_revision)), 'Publish revision update failed.');
	paper_to_quiz_rest_assert(false !== $wpdb->update($db->table('assessments'), array('status' => 'published', 'published_revision_id' => $published_revision, 'current_draft_revision_id' => null), array('id' => $assessment_public)), 'Publish assessment update failed.');

	$bootstrap = paper_to_quiz_rest_request('GET', '/paper-to-quiz/v1/assessments/' . $assessment_public . '/bootstrap');
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($bootstrap), 'Published assessment bootstrap failed.');
	$deny_assessment_access = static function (bool $allowed, int $assessment_id, int $user_id, array $record) use ($assessment_public): bool {
		return $assessment_id === $assessment_public ? false : $allowed;
	};
	try {
		add_filter('paper_to_quiz_can_access_assessment', $deny_assessment_access, 10, 4);
		$denied_bootstrap = paper_to_quiz_rest_request('GET', '/paper-to-quiz/v1/assessments/' . $assessment_public . '/bootstrap');
		paper_to_quiz_rest_assert(403 === paper_to_quiz_rest_status($denied_bootstrap), 'Access filter did not deny public bootstrap.');
		$denied_start = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/assessments/' . $assessment_public . '/attempts', array('participant' => array(), 'client' => array()));
		paper_to_quiz_rest_assert(403 === paper_to_quiz_rest_status($denied_start), 'Access filter did not deny public attempt start.');
		paper_to_quiz_rest_assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('attempts') . ' WHERE assessment_id=%d', $assessment_public)), 'Access-filter-denied assessment created an attempt.');
	} finally {
		remove_filter('paper_to_quiz_can_access_assessment', $deny_assessment_access, 10);
	}

	$payload = array('title' => $suffix . ' edited', 'type' => 'exam', 'class_id' => $class, 'subject_ids' => array($subject), 'access_mode' => 'guest_allowed', 'options' => array('A', 'B', 'C', 'D'), 'total_points' => 10000, 'window_start_utc' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS), 'window_end_utc' => gmdate('Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS), 'results_release_at_utc' => gmdate('Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS), 'allow_repeat' => true, 'feedback_timing' => 'scheduled', 'result_visibility' => 'summary');
	$edit_one = paper_to_quiz_rest_request('PUT', '/paper-to-quiz/v1/admin/assessments/' . $assessment_public, $payload, $manager);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($edit_one), 'First assessment PUT failed.');
	$edit_one_data = $edit_one->get_data();
	paper_to_quiz_rest_assert(array($subject) === array_map('intval', $edit_one_data['revision']['subject_ids'] ?? array()), 'Selected subjects were not persisted in the revision response.');
	paper_to_quiz_rest_assert(null === ($edit_one_data['revision']['results_release_at_utc'] ?? null), 'A test retained a result release date.');
	paper_to_quiz_rest_assert('after_submit' === ($edit_one_data['revision']['feedback_timing'] ?? ''), 'A test retained scheduled feedback.');
	$draft_after_one = (int) $wpdb->get_var($wpdb->prepare('SELECT current_draft_revision_id FROM ' . $db->table('assessments') . ' WHERE id=%d', $assessment_public));
	paper_to_quiz_rest_assert($draft_after_one > 0 && $draft_after_one !== $published_revision, 'First PUT did not create a draft from the published revision.');
	$edit_two = paper_to_quiz_rest_request('PUT', '/paper-to-quiz/v1/admin/assessments/' . $assessment_public, $payload, $manager);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($edit_two), 'Second assessment PUT failed.');
	$draft_after_two = (int) $wpdb->get_var($wpdb->prepare('SELECT current_draft_revision_id FROM ' . $db->table('assessments') . ' WHERE id=%d', $assessment_public));
	paper_to_quiz_rest_assert($draft_after_one > 0 && $draft_after_one === $draft_after_two, 'Repeated PUT did not retain one draft revision.');

	$start = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/assessments/' . $assessment_public . '/attempts', array('participant' => array(), 'client' => array()));
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($start) || 201 === paper_to_quiz_rest_status($start), 'Public attempt start failed.');
	$start_data = $start->get_data();
	$public_id = (string) ($start_data['public_id'] ?? '');
	paper_to_quiz_rest_assert($public_id !== '', 'Attempt public ID was not returned.');
	$token = wp_generate_password(64, false, false);
	$attempt = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('attempts') . ' WHERE public_id=%s', $public_id));
	paper_to_quiz_rest_assert($attempt > 0, 'REST-created attempt was not persisted.');
	paper_to_quiz_rest_assert(false !== $wpdb->update($db->table('attempts'), array('token_hash' => hash_hmac('sha256', $token, wp_salt('auth'))), array('id' => $attempt)), 'Synthetic attempt token could not be prepared.');
	$submission = wp_generate_uuid4();
	$submit_params = array('token' => $token, 'submission_id' => $submission, 'answers' => array(array('question_id' => $public_question, 'option' => 'A')));
	$submit_one = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/attempts/' . $public_id . '/submit', $submit_params);
	$submit_two = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/attempts/' . $public_id . '/submit', $submit_params);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($submit_one) && 200 === paper_to_quiz_rest_status($submit_two), 'Idempotent submit failed.');
	paper_to_quiz_rest_assert(1 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('attempts') . ' WHERE submission_id=%s', $submission)), 'Submission created more than one attempt.');

	/*
	 * Different-id concurrent submit characterization (plan 012).
	 *
	 * A second submit with a NEW submission_id for the same attempt must return
	 * 200 (the already-finalized row) and must NOT double-enqueue the result
	 * email job. The bare regression fixture omits participant data so the
	 * paper_to_quiz_attempt_completed hook's enqueue() short-circuits before inserting a
	 * result_email_jobs row; seed one row here to represent what the first
	 * finalize would have produced for an attempt with an email on file, then
	 * verify the different-id submit leaves the count at exactly one.
	 */
	paper_to_quiz_rest_assert(1 === $wpdb->insert(
		$db->table('result_email_jobs'),
		array(
			'attempt_id'    => $attempt,
			'status'        => 'pending',
			'attempt_count' => 0,
			'next_run_at'   => $now,
			'created_at'    => $now,
			'updated_at'    => $now,
		)
	), 'Synthetic result email job could not be seeded for single-flight characterization.');
	$different_submission = wp_generate_uuid4();
	$submit_three = paper_to_quiz_rest_request(
		'POST',
		'/paper-to-quiz/v1/attempts/' . $public_id . '/submit',
		array(
			'token'         => $token,
			'submission_id' => $different_submission,
			'answers'       => array(array('question_id' => $public_question, 'option' => 'A')),
		)
	);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($submit_three), 'Different-id concurrent submit did not return 200.');
	$email_job_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('result_email_jobs') . ' WHERE attempt_id=%d', $attempt));
	paper_to_quiz_rest_assert(1 === $email_job_count, 'Different-id concurrent submit double-enqueued the result email job.');

	$invalid_answer_key = paper_to_quiz_rest_request(
		'PUT',
		'/paper-to-quiz/v1/admin/revisions/' . $revision_draft . '/answer-key',
		array(
			'questions'     => array(array('id' => $question, 'correct_option' => 'E', 'points' => 10000)),
			'prune_missing' => false,
		),
		$manager
	);
	paper_to_quiz_rest_assert(400 === paper_to_quiz_rest_status($invalid_answer_key), 'Invalid answer-key input did not return 400.');
	$invalid_answer_key_data = paper_to_quiz_rest_data($invalid_answer_key);
	paper_to_quiz_rest_assert('paper_to_quiz_answer_key_failed' === ($invalid_answer_key_data['code'] ?? ''), 'Invalid answer-key input changed its error code.');
	$expected_invalid_option_message = __('The correct answer is not one of the available options.', 'paper-to-quiz');
	paper_to_quiz_rest_assert(str_contains((string) ($invalid_answer_key_data['message'] ?? ''), $expected_invalid_option_message), 'Invalid answer-key input lost its localized validation message.');

	$synthetic_message = 'PAPER_TO_QUIZ_SYNTHETIC_INTERNAL_PATH_OR_TABLE';
	$synthetic_triggered = false;
	$synthetic_log_path = tempnam(sys_get_temp_dir(), 'ptq-operational-');
	paper_to_quiz_rest_assert(false !== $synthetic_log_path, 'Synthetic operational log could not be created.');
	$previous_error_log = ini_get('error_log');
	$synthetic_query_filter = static function (string $query) use (&$synthetic_triggered, $synthetic_message, $revision_draft, $db): string {
		$target = 'SELECT id,main_asset_id,thumb_asset_id FROM ' . $db->table('questions');
		if (! $synthetic_triggered && str_contains($query, $target) && str_contains($query, 'WHERE revision_id = ' . $revision_draft)) {
			$synthetic_triggered = true;
			throw new RuntimeException($synthetic_message);
		}
		return $query;
	};
	try {
		paper_to_quiz_rest_assert(false !== ini_set('error_log', $synthetic_log_path), 'Synthetic operational log could not be configured.');
		add_filter('query', $synthetic_query_filter, 9999);
		$synthetic_response = paper_to_quiz_rest_request(
			'PUT',
			'/paper-to-quiz/v1/admin/revisions/' . $revision_draft . '/answer-key',
			array(
				'questions'     => array(array('id' => $question, 'correct_option' => 'A', 'points' => 10000)),
				'prune_missing' => false,
			),
			$manager
		);
		paper_to_quiz_rest_assert($synthetic_triggered, 'Synthetic database exception was not injected.');
		paper_to_quiz_rest_assert(500 === paper_to_quiz_rest_status($synthetic_response), 'Synthetic operational failure did not return 500.');
		$synthetic_data = paper_to_quiz_rest_data($synthetic_response);
		paper_to_quiz_rest_assert('paper_to_quiz_answer_key_failed' === ($synthetic_data['code'] ?? ''), 'Synthetic operational failure changed its error code.');
		$reference = (string) ($synthetic_data['data']['reference'] ?? '');
		paper_to_quiz_rest_assert(1 === preg_match('/^[A-Z0-9]{8}$/', $reference), 'Synthetic operational failure did not return a short support reference.');
		$synthetic_json = wp_json_encode($synthetic_data, JSON_UNESCAPED_SLASHES);
		paper_to_quiz_rest_assert(is_string($synthetic_json) && ! str_contains($synthetic_json, $synthetic_message), 'Synthetic technical exception message reached REST JSON.');
		paper_to_quiz_rest_assert(is_string($synthetic_json) && str_contains($synthetic_json, $reference), 'REST JSON did not contain the support reference.');
		$synthetic_log = file_get_contents($synthetic_log_path);
		paper_to_quiz_rest_assert(is_string($synthetic_log) && str_contains($synthetic_log, $reference), 'Support reference was not correlated with the server log.');
		paper_to_quiz_rest_assert(is_string($synthetic_log) && str_contains($synthetic_log, 'RuntimeException'), 'Server log omitted the exception class.');
		paper_to_quiz_rest_assert(is_string($synthetic_log) && 1 === preg_match("/\\bin\\s+EvalFile_Command\\.php\\(\\d+\\)\\s+:\\s+eval\\(\\)'d code:[1-9]\\d*\\b/", $synthetic_log), 'Server log omitted the exception location.');
		paper_to_quiz_rest_assert(is_string($synthetic_log) && ! str_contains($synthetic_log, $synthetic_message), 'Server log included the technical exception message.');
	} finally {
		remove_filter('query', $synthetic_query_filter, 9999);
		if (false !== $previous_error_log) {
			ini_set('error_log', (string) $previous_error_log);
		}
		if (is_file($synthetic_log_path)) {
			unlink($synthetic_log_path);
		}
	}

	[$assessment_other, $other_revision, $other_question] = $insert_assessment('exam');
	$attempt_count_before_trash = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('attempts') . ' WHERE assessment_id=%d', $assessment_public));
	$trash = paper_to_quiz_rest_request('DELETE', '/paper-to-quiz/v1/admin/assessments/' . $assessment_public, array(), $manager);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($trash), 'Assessment trash failed.');
	$trashed_bootstrap = paper_to_quiz_rest_request('GET', '/paper-to-quiz/v1/assessments/' . $assessment_public . '/bootstrap');
	paper_to_quiz_rest_assert(404 === paper_to_quiz_rest_status($trashed_bootstrap), 'Trashed assessment bootstrap was not denied.');
	$trashed_start = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/assessments/' . $assessment_public . '/attempts', array('participant' => array(), 'client' => array()));
	paper_to_quiz_rest_assert(404 === paper_to_quiz_rest_status($trashed_start), 'Trashed assessment attempt start was not denied.');
	paper_to_quiz_rest_assert($attempt_count_before_trash === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('attempts') . ' WHERE assessment_id=%d', $assessment_public)), 'Trashed assessment created an attempt.');
	$purge = paper_to_quiz_rest_request('DELETE', '/paper-to-quiz/v1/admin/assessments/' . $assessment_public, array('force' => true), $manager);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($purge), 'Assessment force delete failed.');
	paper_to_quiz_rest_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('assessments') . ' WHERE id=%d', $assessment_other)) === 1, 'Unrelated assessment was deleted.');
	paper_to_quiz_rest_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('terms') . ' WHERE id IN (%d,%d)', $class, $subject)) === 2, 'Shared terms were deleted.');
	$report = array('admin_auth' => 'passed', 'admin_route_and_settings_health' => 'passed', 'class_color_and_duplicate' => 'passed', 'subject_selection_and_test_policy' => 'passed', 'public_attempt_access_gate' => 'passed', 'draft_revision_idempotency' => 'passed', 'attempt_submit_idempotency' => 'passed', 'finalize_single_flight' => 'passed', 'answer_key_validation' => 'passed', 'operational_error_sanitization' => 'passed', 'delete_isolation' => 'passed', 'route_permission_gates' => 'passed');
} finally {
	if ($attempt) {
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('result_email_jobs'), array('attempt_id' => $attempt), array('%d')), 'Result email jobs cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('answers'), array('attempt_id' => $attempt), array('%d')), 'Attempt answers cleanup failed.');
	}
	foreach (array_filter(array($assessment_draft, $assessment_public, $assessment_other)) as $id) {
		$wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('answers') . ' WHERE attempt_id IN (SELECT id FROM ' . $db->table('attempts') . ' WHERE assessment_id=%d)', $id));
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('attempts'), array('assessment_id' => $id), array('%d')), 'Attempts cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->query($wpdb->prepare('DELETE q FROM ' . $db->table('questions') . ' q INNER JOIN ' . $db->table('revisions') . ' r ON r.id=q.revision_id WHERE r.assessment_id=%d', $id)), 'Questions cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('revisions'), array('assessment_id' => $id), array('%d')), 'Revisions cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('assessments'), array('id' => $id), array('%d')), 'Assessments cleanup failed.');
	}
	if ($class) { paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('terms'), array('id' => $class), array('%d')), 'Class cleanup failed.'); }
	if ($subject) { paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('terms'), array('id' => $subject), array('%d')), 'Subject cleanup failed.'); }
	if ($manager) { require_once ABSPATH . 'wp-admin/includes/user.php'; paper_to_quiz_rest_assert(false !== wp_delete_user($manager), 'Synthetic manager cleanup failed.'); }
	paper_to_quiz_rest_assert(paper_to_quiz_rest_cleanup_counts($wpdb, $db, $suffix) === array('assessments' => 0, 'revisions' => 0, 'questions' => 0, 'terms' => 0), 'Generated rows remain after cleanup.');
}
$report['cleanup'] = 'passed';
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
