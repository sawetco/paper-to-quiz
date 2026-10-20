<?php
/** Synthetic ranking fixtures and a guarded release step for browser tests. */

use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Infrastructure\Crypto;

if ('local' !== wp_get_environment_type() || '1' !== getenv('PAPER_TO_QUIZ_ALLOW_E2E')) {
	throw new RuntimeException('Ranking fixtures require a disposable local environment.');
}

if ('release' === getenv('PTQ_RANKING_ACTION')) {
	global $wpdb;
	$changed = $wpdb->query($wpdb->prepare(
		'UPDATE ' . $wpdb->prefix . 'paper_to_quiz_revisions SET window_end_utc = %s,results_release_at_utc = %s WHERE title LIKE %s',
		gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() - 30), 'PTQ E2E Ranking %'
	));
	if (false === $changed) {
		throw new RuntimeException('The synthetic ranking release failed.');
	}
	return;
}

// Included only after the parent fixture creates its synthetic terms and PDF.
$member = wp_insert_user(array(
	'user_login' => 'ptq_e2e_ranking_member', 'user_pass' => 'paper-to-quiz-integration',
	'user_email' => 'ptq-e2e-ranking@example.invalid', 'first_name' => 'Deniz', 'last_name' => 'Yılmaz', 'role' => 'subscriber',
));
if (is_wp_error($member)) {
	throw new RuntimeException('The synthetic ranking member could not be created.');
}
$attempt_service = new AttemptService($db, $service, new Crypto());
foreach (array('public' => 'guest_allowed', 'member' => 'login_required') as $variant => $access) {
	$ranked = $service->save(array(
		'type' => 'exam', 'title' => 'PTQ E2E Ranking ' . $variant,
		'class_id' => (int) $class['id'], 'subject_ids' => array((int) $subject['id']),
		'access_mode' => $access, 'options' => array('A', 'B', 'C', 'D'), 'total_points' => 10000,
		'allow_repeat' => false, 'ranking_enabled' => true, 'ranking_list_enabled' => true,
		'window_start_utc' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
		'window_end_utc' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
		'results_release_at_utc' => gmdate('Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS),
		'result_visibility' => 'summary', 'feedback_timing' => 'scheduled',
		'participant_fields' => array('first_name' => array('enabled' => true, 'required' => true), 'last_name' => array('enabled' => true, 'required' => true)),
	), null, 1);
	if (is_wp_error($ranked)) {
		throw new RuntimeException('The synthetic ranked exam could not be saved.');
	}
	$ranked_id = (int) $ranked['assessment']['id'];
	$revision_id = (int) $ranked['revision']['id'];
	$attached = $service->set_source_asset($ranked_id, $assets->create_from_string($pdf, 'source_pdf', 'application/pdf'));
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==');
	$question = $service->save_question($revision_id, array(
		'client_key' => wp_generate_uuid4(), 'page' => 1, 'ordinal' => 1,
		'crop' => array('x' => 0, 'y' => 0, 'width' => 1, 'height' => 1), 'subject_id' => (int) $subject['id'],
	), $assets->create_from_string($png, 'question_image', 'image/png'), $assets->create_from_string($png, 'question_thumb', 'image/png'));
	if (is_wp_error($attached) || is_wp_error($question)) {
		throw new RuntimeException('The synthetic ranked question could not be created.');
	}
	$question_id = (int) $question['id'];
	$key = $service->update_answer_key($revision_id, array(array('id' => $question_id, 'correct_option' => 'A', 'points' => 10000)));
	$published = $service->publish($ranked_id);
	if (is_wp_error($key) || is_wp_error($published)) {
		throw new RuntimeException('The synthetic ranked exam could not be published.');
	}
	if ($variant === 'public') {
		wp_set_current_user(0);
		for ($index = 0; $index < 21; ++$index) {
			$started = $attempt_service->start($ranked_id, array('first_name' => $index === 0 ? 'İpek Mehmet' : 'Katılımcı' . $index, 'last_name' => 'Yılmaz'));
			if (is_wp_error($started)) {
				throw new RuntimeException('The synthetic ranked attempt could not start.');
			}
			$submitted = $attempt_service->submit($started['public_id'], $started['token'], false, wp_generate_uuid4(), array(array('question_id' => $question_id, 'option' => 'A')));
			if (is_wp_error($submitted)) {
				throw new RuntimeException('The synthetic ranked attempt could not finish.');
			}
		}
	}
	wp_set_current_user(1);
	$post = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'PTQ E2E Ranking ' . $variant,
		'post_name' => 'ptq-e2e-ranking-' . $variant, 'post_content' => '[paper_to_quiz id="' . $ranked_id . '"]'));
	if (! $post || is_wp_error($post)) {
		throw new RuntimeException('The synthetic ranking page could not be created.');
	}
	echo 'PTQ_E2E_RANKING_' . strtoupper($variant) . '=' . $ranked_id . "\n";
}
