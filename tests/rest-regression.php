<?php
/** WP-CLI REST regression gate for Paper to Quiz. */

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;

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

/**
 * Dispatch a raw request through the in-process REST server.
 *
 * @param array<string,string> $headers
 */
function paper_to_quiz_rest_binary_request(string $method, string $route, string $body, array $headers = array(), ?int $user_id = null): WP_REST_Response|WP_Error {
	$previous = get_current_user_id();
	wp_set_current_user(null === $user_id ? 0 : $user_id);
	try {
		$request = new WP_REST_Request($method, $route);
		$request->set_body($body);
		$request->set_header('Content-Type', 'application/octet-stream');
		$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
		foreach ($headers as $name => $value) {
			$request->set_header($name, $value);
		}
		return rest_do_request($request);
	} finally {
		wp_set_current_user($previous);
	}
}

/**
 * Build the internal HTTP URL used for a true multipart upload.
 */
function paper_to_quiz_rest_http_url(string $route): string {
	$parsed = wp_parse_url(rest_url());
	$host = (string) ($parsed['host'] ?? 'wordpress');
	$port = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';
	$override = getenv('PAPER_TO_QUIZ_REST_HOST');
	if (is_string($override) && $override !== '') {
		$host = $override;
		$port = '';
	} elseif (in_array($host, array('localhost', '127.0.0.1', '::1'), true)) {
		/* wp-env's CLI container reaches the web container as `wordpress`. */
		$host = 'wordpress';
		$port = '';
	}
	$scheme = (string) ($parsed['scheme'] ?? 'http');
	return $scheme . '://' . $host . $port . '/?rest_route=' . rawurlencode($route);
}

/**
 * Dispatch a multipart question-image request through the web container.
 *
 * @param array<string,string>                $fields
 * @param array<string,array{path:string,name:string,mime:string}> $files
 */
function paper_to_quiz_rest_multipart_request(string $route, array $fields, array $files, int $user_id): WP_REST_Response|WP_Error {
	$boundary = '--------------------------' . strtolower(wp_generate_password(24, false, false));
	$body     = '';
	foreach ($fields as $name => $value) {
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
		$body .= $value . "\r\n";
	}
	foreach ($files as $field => $file) {
		$contents = file_get_contents($file['path']);
		if (! is_string($contents)) {
			return new WP_Error('paper_to_quiz_test_file_read', 'The regression fixture could not be read.', array('status' => 500));
		}
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . sanitize_file_name($file['name']) . "\"\r\n";
		$body .= 'Content-Type: ' . $file['mime'] . "\r\n\r\n";
		$body .= $contents . "\r\n";
	}
	$body .= '--' . $boundary . "--\r\n";

	$previous = get_current_user_id();
	wp_set_current_user($user_id);
	try {
		$cookie = wp_generate_auth_cookie($user_id, time() + HOUR_IN_SECONDS, 'logged_in');
		$cookie_was_set = array_key_exists(LOGGED_IN_COOKIE, $_COOKIE);
		$previous_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
		$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
		try {
			$nonce = wp_create_nonce('wp_rest');
		} finally {
			if ($cookie_was_set) {
				$_COOKIE[LOGGED_IN_COOKIE] = $previous_cookie;
			} else {
				unset($_COOKIE[LOGGED_IN_COOKIE]);
			}
		}
	} finally {
		wp_set_current_user($previous);
	}
	if (! is_string($cookie) || $cookie === '') {
		return new WP_Error('paper_to_quiz_test_auth_cookie', 'The regression manager cookie could not be created.', array('status' => 500));
	}

	$response = wp_remote_request(
		paper_to_quiz_rest_http_url($route),
		array(
			'method'     => 'POST',
			'headers'    => array(
				'Content-Type'   => 'multipart/form-data; boundary=' . $boundary,
				'Content-Length' => (string) strlen($body),
				'X-WP-Nonce'    => $nonce,
				'Cookie'        => LOGGED_IN_COOKIE . '=' . $cookie,
			),
			'body'       => $body,
			'timeout'    => 30,
			'redirection' => 0,
		)
	);
	if (is_wp_error($response)) {
		return $response;
	}
	$status = (int) wp_remote_retrieve_response_code($response);
	$data   = json_decode((string) wp_remote_retrieve_body($response), true);
	if (! is_array($data)) {
		return new WP_Error('paper_to_quiz_test_http_response', 'The multipart regression response was not JSON.', array('status' => $status ?: 500));
	}
	return new WP_REST_Response($data, $status);
}

/**
 * Return storage keys referenced by an upload session manifest.
 *
 * @return string[]
 */
function paper_to_quiz_rest_session_storage_keys(wpdb $wpdb, Database $db, string $session_id): array {
	$manifest = $wpdb->get_var($wpdb->prepare('SELECT manifest_json FROM ' . $db->table('upload_sessions') . ' WHERE id=%s', $session_id));
	$decoded  = json_decode((string) $manifest, true);
	if (! is_array($decoded)) {
		return array();
	}
	$keys = array();
	foreach ($decoded as $chunk) {
		if (is_array($chunk) && ! empty($chunk['storage_key'])) {
			$keys[] = (string) $chunk['storage_key'];
		}
	}
	return array_values(array_unique($keys));
}

/**
 * Return relative private-storage files, excluding directory guards.
 *
 * @return string[]
 */
function paper_to_quiz_rest_storage_files(EncryptedStorage $storage): array {
	$base = wp_normalize_path($storage->base_directory());
	if (! is_dir($base)) {
		return array();
	}
	$files = array();
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if (! $file->isFile() || in_array($file->getFilename(), array('index.php', '.htaccess', 'web.config'), true)) {
			continue;
		}
		$path = wp_normalize_path($file->getPathname());
		$files[] = ltrim(substr($path, strlen($base)), '/');
	}
	sort($files);
	return $files;
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
$storage = new EncryptedStorage();
$suffix = 'REST Regression ' . strtolower(wp_generate_password(10, false, false));
$now = current_time('mysql', true);
$manager = 0;
$manager_password = '';
$class = 0;
$subject = 0;
$assessment_draft = 0;
$assessment_public = 0;
$assessment_other = 0;
$revision_draft = 0;
$question = 0;
$attempt = 0;
$workflow_assessment = 0;
$workflow_revision = 0;
$workflow_question = 0;
$workflow_attempt = 0;
$workflow_upload_sessions = array();
$workflow_asset_ids = array();
$workflow_storage_keys = array();
$workflow_temp_files = array();
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
	$manager_password = wp_generate_password(32);
	$manager = wp_insert_user(array('user_login' => 'paper_to_quiz_reg_' . strtolower(wp_generate_password(8, false, false)), 'user_pass' => $manager_password, 'role' => 'subscriber'));
	paper_to_quiz_rest_assert(! is_wp_error($manager), 'Synthetic manager could not be created.');
	$manager = (int) $manager;
	$user = new WP_User($manager);
	$user->add_cap('paper_to_quiz_manage_assessments');
	$user->add_cap('paper_to_quiz_publish_assessments');
	$class_response = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/admin/classes', array('name' => $suffix . ' Class', 'color' => '#123456'), $manager);
	paper_to_quiz_rest_assert(201 === paper_to_quiz_rest_status($class_response) || 200 === paper_to_quiz_rest_status($class_response), 'Class REST creation failed.');
	$class_data = $class_response->get_data();
	$class = (int) ($class_data['id'] ?? 0);
	paper_to_quiz_rest_assert($class > 0 && '#123456' === ($class_data['color'] ?? ''), 'Class color was not persisted.');
	$duplicate = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/admin/classes', array('name' => $suffix . ' Class', 'color' => '#123456'), $manager);
	paper_to_quiz_rest_assert(409 === paper_to_quiz_rest_status($duplicate), 'Duplicate class did not return 409.');

	paper_to_quiz_rest_assert(1 === $wpdb->insert($db->table('terms'), array('type' => 'subject', 'name' => $suffix . ' Subject', 'slug' => sanitize_title($suffix . ' Subject'), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now)), 'Subject insert failed.');
	$subject = (int) $wpdb->insert_id;

	/*
	 * Exercise the complete authoring workflow through its REST contracts. The
	 * upload branches deliberately run before the happy path so both failure
	 * modes can prove that no source asset was attached to the draft.
	 */
	$workflow_create = paper_to_quiz_rest_request(
		'POST',
		'/paper-to-quiz/v1/admin/assessments',
		array(
			'type'             => 'test',
			'title'            => $suffix . ' upload workflow',
			'description'      => 'REST upload-to-publish workflow fixture.',
			'class_id'         => $class,
			'subject_ids'      => array($subject),
			'access_mode'      => 'guest_allowed',
			'options'          => array('A', 'B', 'C', 'D'),
			'total_points'     => 10000,
			'allow_repeat'     => true,
			'feedback_timing'   => 'after_submit',
			'result_visibility' => 'summary',
		),
		$manager
	);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($workflow_create) || 201 === paper_to_quiz_rest_status($workflow_create), 'Workflow assessment REST creation failed.');
	$workflow_create_data = paper_to_quiz_rest_data($workflow_create);
	$workflow_assessment = (int) ($workflow_create_data['assessment']['id'] ?? 0);
	$workflow_revision    = (int) ($workflow_create_data['revision']['id'] ?? 0);
	paper_to_quiz_rest_assert($workflow_assessment > 0 && $workflow_revision > 0, 'Workflow assessment IDs were not returned.');
	paper_to_quiz_rest_assert('draft' === ($workflow_create_data['assessment']['status'] ?? '') && 'draft' === ($workflow_create_data['revision']['lifecycle'] ?? ''), 'Workflow assessment did not start as a draft.');

	$chunk_size = 2 * 1024 * 1024;
	$pdf_prefix = "%PDF-1.4\n";
	$source_pdf = $pdf_prefix . str_repeat('0', $chunk_size - strlen($pdf_prefix) + 1) . "%%EOF\n";
	$source_size = strlen($source_pdf);
	$source_sha  = hash('sha256', $source_pdf);
	$chunks      = array();
	for ($offset = 0; $offset < $source_size; $offset += $chunk_size) {
		$chunks[] = substr($source_pdf, $offset, $chunk_size);
	}
	$chunk_count = (int) ceil($source_size / $chunk_size);
	paper_to_quiz_rest_assert(2 === $chunk_count && strlen($chunks[0]) === $chunk_size, 'The generated PDF fixture did not produce the expected exact chunks.');

	$begin_upload = static function (string $name) use ($manager, $source_size, $chunk_count, &$workflow_upload_sessions): string {
		$response = paper_to_quiz_rest_request(
			'POST',
			'/paper-to-quiz/v1/admin/uploads',
			array('name' => $name, 'size' => $source_size, 'chunk_count' => $chunk_count),
			$manager
		);
		paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($response) || 201 === paper_to_quiz_rest_status($response), 'PDF upload session could not be started.');
		$data = paper_to_quiz_rest_data($response);
		paper_to_quiz_rest_assert((int) ($data['chunk_size'] ?? 0) === 2 * 1024 * 1024, 'Upload session returned an unexpected chunk size.');
		$id = (string) ($data['id'] ?? '');
		paper_to_quiz_rest_assert(wp_is_uuid($id), 'Upload session did not return a UUID.');
		$workflow_upload_sessions[] = $id;
		return $id;
	};
	$send_chunk = static function (string $session_id, int $index, string $chunk) use ($manager, $wpdb, $db, &$workflow_storage_keys): void {
		$response = paper_to_quiz_rest_binary_request(
			'PUT',
			'/paper-to-quiz/v1/admin/uploads/' . $session_id . '/chunks/' . $index,
			$chunk,
			array('X-Paper-To-Quiz-Chunk-SHA256' => hash('sha256', $chunk)),
			$manager
		);
		paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($response), 'PDF upload chunk could not be saved.');
		$data = paper_to_quiz_rest_data($response);
		paper_to_quiz_rest_assert(true === ($data['received'] ?? false) && $index === (int) ($data['index'] ?? -1), 'PDF upload chunk response was invalid.');
		$workflow_storage_keys = array_values(array_unique(array_merge($workflow_storage_keys, paper_to_quiz_rest_session_storage_keys($wpdb, $db, $session_id))));
	};

	$incomplete_session = $begin_upload($suffix . '-incomplete.pdf');
	$send_chunk($incomplete_session, 0, $chunks[0]);
	$incomplete_files_before = paper_to_quiz_rest_storage_files($storage);
	$incomplete = paper_to_quiz_rest_request(
		'POST',
		'/paper-to-quiz/v1/admin/uploads/' . $incomplete_session . '/complete',
		array('assessment_id' => $workflow_assessment, 'sha256' => $source_sha),
		$manager
	);
	paper_to_quiz_rest_assert(409 === paper_to_quiz_rest_status($incomplete), 'Incomplete PDF completion did not return 409.');
	paper_to_quiz_rest_assert('paper_to_quiz_upload_incomplete' === (paper_to_quiz_rest_data($incomplete)['code'] ?? ''), 'Incomplete PDF completion changed its error code.');
	paper_to_quiz_rest_assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT source_asset_id FROM ' . $db->table('revisions') . ' WHERE id=%d', $workflow_revision)), 'Incomplete PDF completion attached a source asset.');
	paper_to_quiz_rest_assert('pending' === (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('upload_sessions') . ' WHERE id=%s', $incomplete_session)), 'Incomplete upload session was not retained as pending.');
	paper_to_quiz_rest_assert($incomplete_files_before === paper_to_quiz_rest_storage_files($storage), 'Incomplete PDF completion left an unexpected combined file.');

	$hash_session = $begin_upload($suffix . '-hash-mismatch.pdf');
	foreach ($chunks as $index => $chunk) {
		$send_chunk($hash_session, $index, $chunk);
	}
	$hash_files_before = paper_to_quiz_rest_storage_files($storage);
	$hash_mismatch = paper_to_quiz_rest_request(
		'POST',
		'/paper-to-quiz/v1/admin/uploads/' . $hash_session . '/complete',
		array('assessment_id' => $workflow_assessment, 'sha256' => str_repeat('0', 64)),
		$manager
	);
	paper_to_quiz_rest_assert(400 === paper_to_quiz_rest_status($hash_mismatch), 'Whole-file hash mismatch did not return 400.');
	paper_to_quiz_rest_assert('paper_to_quiz_pdf_hash' === (paper_to_quiz_rest_data($hash_mismatch)['code'] ?? ''), 'Whole-file hash mismatch changed its error code.');
	paper_to_quiz_rest_assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT source_asset_id FROM ' . $db->table('revisions') . ' WHERE id=%d', $workflow_revision)), 'Whole-file hash mismatch attached a source asset.');
	paper_to_quiz_rest_assert($hash_files_before === paper_to_quiz_rest_storage_files($storage), 'Whole-file hash mismatch left a combined file behind.');

	$complete_session = $begin_upload($suffix . '-source.pdf');
	foreach ($chunks as $index => $chunk) {
		$send_chunk($complete_session, $index, $chunk);
	}
	$complete = paper_to_quiz_rest_request(
		'POST',
		'/paper-to-quiz/v1/admin/uploads/' . $complete_session . '/complete',
		array('assessment_id' => $workflow_assessment, 'sha256' => $source_sha),
		$manager
	);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($complete), 'Complete PDF upload failed.');
	$complete_data = paper_to_quiz_rest_data($complete);
	$source_asset_id = (int) ($complete_data['asset_id'] ?? 0);
	paper_to_quiz_rest_assert($source_asset_id > 0, 'Complete PDF upload did not return an asset ID.');
	$workflow_asset_ids[] = $source_asset_id;
	paper_to_quiz_rest_assert('completed' === (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('upload_sessions') . ' WHERE id=%s', $complete_session)), 'Completed PDF upload session did not reach completed status.');
	$source_asset = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('assets') . ' WHERE id=%d', $source_asset_id), ARRAY_A);
	paper_to_quiz_rest_assert(is_array($source_asset), 'Stored source asset row was not found.');
	paper_to_quiz_rest_assert((int) $source_asset['byte_size'] === $source_size && hash_equals($source_sha, (string) $source_asset['sha256']), 'Stored source asset metadata did not preserve the original PDF hash and size.');
	$workflow_storage_keys[] = (string) $source_asset['storage_key'];
	paper_to_quiz_rest_assert($storage->exists((string) $source_asset['storage_key']), 'Stored source asset file does not exist.');
	/* The complete response nests the decorated assessment record. */
	$workflow_record = $complete_data['assessment'] ?? array();
	paper_to_quiz_rest_assert((int) ($workflow_record['revision']['source_asset_id'] ?? 0) === $source_asset_id, 'Complete PDF upload did not attach the source asset to the draft.');

	$image_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAwAB/6GxX2cAAAAASUVORK5CYII=';
	$image_bytes = base64_decode($image_base64, true);
	paper_to_quiz_rest_assert(is_string($image_bytes) && $image_bytes !== '', 'Question image fixture could not be decoded.');
	$image_files = array();
	foreach (array('main', 'thumb') as $image_field) {
		$image_path = tempnam(sys_get_temp_dir(), 'ptq-question-');
		paper_to_quiz_rest_assert(is_string($image_path), 'Question image temporary file could not be created.');
		paper_to_quiz_rest_assert(false !== file_put_contents($image_path, $image_bytes), 'Question image fixture could not be written.');
		$workflow_temp_files[] = $image_path;
		$image_files[$image_field] = array('path' => $image_path, 'name' => $image_field . '.png', 'mime' => 'image/png');
	}
	$question_metadata = array(
		'page'       => 1,
		'ordinal'    => 1,
		'rotation'   => 0,
		'client_key' => wp_generate_uuid4(),
		'subject_id' => $subject,
		'crop'       => array('x' => 0, 'y' => 0, 'width' => 1, 'height' => 1),
	);
	$question_response = paper_to_quiz_rest_multipart_request(
		'/paper-to-quiz/v1/admin/revisions/' . $workflow_revision . '/questions',
		array('metadata' => wp_json_encode($question_metadata)),
		$image_files,
		$manager
	);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($question_response), 'Question image REST upload failed.');
	$question_data = paper_to_quiz_rest_data($question_response);
	$workflow_question = (int) ($question_data['id'] ?? 0);
	$main_asset_id = (int) ($question_data['main_asset_id'] ?? 0);
	$thumb_asset_id = (int) ($question_data['thumb_asset_id'] ?? 0);
	paper_to_quiz_rest_assert($workflow_question > 0 && $main_asset_id > 0 && $thumb_asset_id > 0, 'Question image REST upload did not return question assets.');
	$workflow_asset_ids[] = $main_asset_id;
	$workflow_asset_ids[] = $thumb_asset_id;
	paper_to_quiz_rest_assert((int) ($question_data['subject_id'] ?? 0) === $subject && 1 === (int) ($question_data['ordinal'] ?? 0), 'Question metadata was not persisted through the REST upload.');
	foreach (array($main_asset_id, $thumb_asset_id) as $question_asset_id) {
		$question_asset = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('assets') . ' WHERE id=%d', $question_asset_id), ARRAY_A);
		paper_to_quiz_rest_assert(is_array($question_asset) && $storage->exists((string) $question_asset['storage_key']), 'Question image asset storage was not created.');
		$workflow_storage_keys[] = (string) $question_asset['storage_key'];
	}

	$answer_key = paper_to_quiz_rest_request(
		'PUT',
		'/paper-to-quiz/v1/admin/revisions/' . $workflow_revision . '/answer-key',
		array('questions' => array(array('id' => $workflow_question, 'correct_option' => 'A', 'points' => 10000)), 'prune_missing' => false),
		$manager
	);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($answer_key), 'Workflow answer-key REST update failed.');
	$workflow_question_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('questions') . ' WHERE id=%d', $workflow_question), ARRAY_A);
	paper_to_quiz_rest_assert(is_array($workflow_question_row) && 'A' === $workflow_question_row['correct_option'] && 10000 === (int) $workflow_question_row['points'] && 1 === (int) $workflow_question_row['ordinal'], 'Workflow answer key was not persisted.');

	$published = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/admin/assessments/' . $workflow_assessment . '/publish', array(), $manager);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($published), 'Workflow assessment publish REST request failed.');
	$published_data = paper_to_quiz_rest_data($published);
	$published_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('assessments') . ' WHERE id=%d', $workflow_assessment), ARRAY_A);
	$published_revision_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('revisions') . ' WHERE id=%d', $workflow_revision), ARRAY_A);
	paper_to_quiz_rest_assert(is_array($published_row) && 'published' === $published_row['status'] && (int) $published_row['published_revision_id'] === $workflow_revision && empty($published_row['current_draft_revision_id']), 'Workflow publish did not update assessment lifecycle pointers.');
	paper_to_quiz_rest_assert(is_array($published_revision_row) && 'published' === $published_revision_row['lifecycle'] && ! empty($published_revision_row['published_at']), 'Workflow publish did not publish the revision lifecycle.');
	paper_to_quiz_rest_assert((int) ($published_data['revision']['id'] ?? 0) === $workflow_revision && 'published' === ($published_data['revision']['lifecycle'] ?? ''), 'Workflow publish response omitted the published revision.');

	$workflow_bootstrap = paper_to_quiz_rest_request('GET', '/paper-to-quiz/v1/assessments/' . $workflow_assessment . '/bootstrap');
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($workflow_bootstrap), 'Published workflow bootstrap failed.');
	$workflow_bootstrap_data = paper_to_quiz_rest_data($workflow_bootstrap);
	paper_to_quiz_rest_assert((int) ($workflow_bootstrap_data['id'] ?? 0) === $workflow_assessment && 1 === (int) ($workflow_bootstrap_data['question_count'] ?? 0), 'Published workflow bootstrap did not expose the published question.');
	$workflow_start = paper_to_quiz_rest_request('POST', '/paper-to-quiz/v1/assessments/' . $workflow_assessment . '/attempts', array('participant' => array(), 'client' => array()));
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($workflow_start) || 201 === paper_to_quiz_rest_status($workflow_start), 'Published workflow attempt start failed.');
	$workflow_start_data = paper_to_quiz_rest_data($workflow_start);
	$workflow_public_id = (string) ($workflow_start_data['public_id'] ?? '');
	paper_to_quiz_rest_assert(wp_is_uuid($workflow_public_id), 'Published workflow attempt did not return a public ID.');
	$workflow_attempt = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('attempts') . ' WHERE public_id=%s', $workflow_public_id));
	paper_to_quiz_rest_assert($workflow_attempt > 0, 'Published workflow attempt was not persisted.');
	paper_to_quiz_rest_assert((int) $wpdb->get_var($wpdb->prepare('SELECT revision_id FROM ' . $db->table('attempts') . ' WHERE id=%d', $workflow_attempt)) === $workflow_revision, 'Published workflow attempt was not tied to the published revision.');
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
	$trashed_start_data = paper_to_quiz_rest_data($trashed_start);
	paper_to_quiz_rest_assert(__('This item could not be found.', 'paper-to-quiz') === ($trashed_start_data['message'] ?? ''), 'Attempt start changed its not-found message.');
	paper_to_quiz_rest_assert($attempt_count_before_trash === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('attempts') . ' WHERE assessment_id=%d', $assessment_public)), 'Trashed assessment created an attempt.');
	$purge = paper_to_quiz_rest_request('DELETE', '/paper-to-quiz/v1/admin/assessments/' . $assessment_public, array('force' => true), $manager);
	paper_to_quiz_rest_assert(200 === paper_to_quiz_rest_status($purge), 'Assessment force delete failed.');
	paper_to_quiz_rest_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('assessments') . ' WHERE id=%d', $assessment_other)) === 1, 'Unrelated assessment was deleted.');
	paper_to_quiz_rest_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('terms') . ' WHERE id IN (%d,%d)', $class, $subject)) === 2, 'Shared terms were deleted.');
	$report = array('admin_auth' => 'passed', 'admin_route_and_settings_health' => 'passed', 'class_color_and_duplicate' => 'passed', 'upload_to_publish_workflow' => 'passed', 'subject_selection_and_test_policy' => 'passed', 'public_attempt_access_gate' => 'passed', 'draft_revision_idempotency' => 'passed', 'attempt_submit_idempotency' => 'passed', 'finalize_single_flight' => 'passed', 'answer_key_validation' => 'passed', 'operational_error_sanitization' => 'passed', 'delete_isolation' => 'passed', 'route_permission_gates' => 'passed');
} finally {
	foreach ($workflow_upload_sessions as $session_id) {
		$workflow_storage_keys = array_values(array_unique(array_merge($workflow_storage_keys, paper_to_quiz_rest_session_storage_keys($wpdb, $db, $session_id))));
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('upload_sessions'), array('id' => $session_id), array('%s')), 'Workflow upload session cleanup failed.');
	}

	if ($workflow_assessment) {
		$referenced_assets = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT source_asset_id FROM ' . $db->table('revisions') . ' WHERE assessment_id=%d AND source_asset_id IS NOT NULL
				UNION SELECT main_asset_id FROM ' . $db->table('questions') . ' q INNER JOIN ' . $db->table('revisions') . ' r ON r.id=q.revision_id WHERE r.assessment_id=%d AND main_asset_id IS NOT NULL
				UNION SELECT thumb_asset_id FROM ' . $db->table('questions') . ' q INNER JOIN ' . $db->table('revisions') . ' r ON r.id=q.revision_id WHERE r.assessment_id=%d AND thumb_asset_id IS NOT NULL',
				$workflow_assessment,
				$workflow_assessment,
				$workflow_assessment
			)
		) ?: array();
		$workflow_asset_ids = array_values(array_unique(array_filter(array_map('intval', array_merge($workflow_asset_ids, $referenced_assets)))));
		foreach ($workflow_asset_ids as $asset_id) {
			$asset = $wpdb->get_row($wpdb->prepare('SELECT storage_key FROM ' . $db->table('assets') . ' WHERE id=%d', $asset_id), ARRAY_A);
			if (is_array($asset) && ! empty($asset['storage_key'])) {
				$workflow_storage_keys[] = (string) $asset['storage_key'];
			}
		}
		paper_to_quiz_rest_assert(false !== $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('answers') . ' WHERE attempt_id IN (SELECT id FROM ' . $db->table('attempts') . ' WHERE assessment_id=%d)', $workflow_assessment)), 'Workflow answers cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('result_email_jobs') . ' WHERE attempt_id IN (SELECT id FROM ' . $db->table('attempts') . ' WHERE assessment_id=%d)', $workflow_assessment)), 'Workflow result email jobs cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('attempts'), array('assessment_id' => $workflow_assessment), array('%d')), 'Workflow attempts cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('questions') . ' WHERE revision_id IN (SELECT id FROM ' . $db->table('revisions') . ' WHERE assessment_id=%d)', $workflow_assessment)), 'Workflow questions cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('revisions'), array('assessment_id' => $workflow_assessment), array('%d')), 'Workflow revisions cleanup failed.');
		paper_to_quiz_rest_assert(false !== $wpdb->delete($db->table('assessments'), array('id' => $workflow_assessment), array('%d')), 'Workflow assessment cleanup failed.');
	}
	$workflow_assets = new PaperToQuiz\Application\AssetService($db, $storage);
	foreach ($workflow_asset_ids as $asset_id) {
		$workflow_assets->release((int) $asset_id);
	}
	foreach (array_values(array_unique($workflow_storage_keys)) as $storage_key) {
		$storage->delete($storage_key);
	}
	foreach ($workflow_temp_files as $temp_file) {
		if (is_file($temp_file)) {
			wp_delete_file($temp_file);
		}
	}
	$workflow_counts = array(
		'assessments' => $workflow_assessment ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('assessments') . ' WHERE id=%d', $workflow_assessment)) : 0,
		'revisions'   => $workflow_revision ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('revisions') . ' WHERE id=%d', $workflow_revision)) : 0,
		'questions'   => $workflow_question ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('questions') . ' WHERE id=%d', $workflow_question)) : 0,
		'attempts'    => $workflow_attempt ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('attempts') . ' WHERE id=%d', $workflow_attempt)) : 0,
		'uploads'     => 0,
		'assets'      => 0,
	);
	foreach ($workflow_upload_sessions as $session_id) {
		$workflow_counts['uploads'] += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('upload_sessions') . ' WHERE id=%s', $session_id));
	}
	foreach (array_values(array_unique($workflow_asset_ids)) as $asset_id) {
		$workflow_counts['assets'] += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('assets') . ' WHERE id=%d', $asset_id));
	}
	paper_to_quiz_rest_assert($workflow_counts === array('assessments' => 0, 'revisions' => 0, 'questions' => 0, 'attempts' => 0, 'uploads' => 0, 'assets' => 0), 'Workflow database rows remain after cleanup.');
	foreach (array_values(array_unique($workflow_storage_keys)) as $storage_key) {
		paper_to_quiz_rest_assert(! $storage->exists($storage_key), 'Workflow storage file remains after cleanup: ' . $storage_key);
	}
	foreach ($workflow_temp_files as $temp_file) {
		paper_to_quiz_rest_assert(! is_file($temp_file), 'Workflow temporary file remains after cleanup.');
	}
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
