<?php

use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;

if (wp_get_environment_type() !== 'local' || getenv('PAPER_TO_QUIZ_ALLOW_REGRESSION') !== '1') {
	throw new RuntimeException('Use a disposable local WordPress installation.');
}

$db = new Database();
$wpdb = $db->wpdb();
$mode = $args[0] ?? '';
$fixture_name = '[PTQ synthetic concurrent deadline]';
$ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT assessment_id FROM %i WHERE title=%s', $db->table('revisions'), $fixture_name)));
$put = static function (string $table, array $row) use ($db, $wpdb): int {
	if ($wpdb->insert($db->table($table), $row) !== 1) {
		throw new RuntimeException('Synthetic fixture insert failed: ' . $wpdb->last_error);
	}
	return (int) $wpdb->insert_id;
};

if ($mode === 'cleanup') {
	foreach ($ids as $id) {
		foreach (array('answers', 'attempt_subject_scores', 'ranking_entries', 'result_email_jobs') as $table) {
			$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE attempt_id IN (SELECT id FROM %i WHERE assessment_id=%d)', $db->table($table), $db->table('attempts'), $id));
		}
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE revision_id IN (SELECT id FROM %i WHERE assessment_id=%d)', $db->table('questions'), $db->table('revisions'), $id));
		$wpdb->delete($db->table('attempts'), array('assessment_id' => $id));
		$wpdb->delete($db->table('revisions'), array('assessment_id' => $id));
		$wpdb->delete($db->table('assessments'), array('id' => $id));
	}
	return;
}

if ($mode === 'seed') {
	if ($ids) {
		throw new RuntimeException('Clean up the previous synthetic batch first.');
	}
	$fixture = array('exams' => array());
	$deadline = time() + 15;
	$end = gmdate('Y-m-d H:i:s', $deadline);
	$start = gmdate('Y-m-d H:i:s', $deadline - 600);
	$identity = (new Crypto())->encrypt_array(array('first_name' => 'Fixture', 'last_name' => 'Synthetic'));
	foreach (array(15 => 40, 12 => 50, 35 => 50) as $participants => $question_count) {
		$exam = $put('assessments', array('type' => 'exam', 'status' => 'published', 'created_by' => 1, 'updated_by' => 1, 'created_at' => $start, 'updated_at' => $start));
		$revision = $put('revisions', array('assessment_id' => $exam, 'lifecycle' => 'published', 'title' => $fixture_name, 'options_json' => '["A","B"]', 'total_points' => $question_count * 200, 'allow_repeat' => 0, 'ranking_enabled' => 1, 'ranking_list_enabled' => 1, 'result_visibility' => 'detailed', 'window_start_utc' => $start, 'window_end_utc' => $end, 'results_release_at_utc' => $end, 'created_at' => $start));
		$wpdb->update($db->table('assessments'), array('published_revision_id' => $revision), array('id' => $exam));
		$questions = array();
		for ($ordinal = 1; $ordinal <= $question_count; ++$ordinal) {
			$questions[] = $put('questions', array('revision_id' => $revision, 'ordinal' => $ordinal, 'source_page' => 1, 'crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1, 'crop_height' => 1, 'correct_option' => 'A', 'subject_id' => 1, 'points' => 200, 'created_at' => $start, 'updated_at' => $start));
		}
		$attempts = array();
		for ($participant = 0; $participant < $participants; ++$participant) {
			$public_id = wp_generate_uuid4();
			$token = wp_generate_uuid4();
			$put('attempts', array('assessment_id' => $exam, 'revision_id' => $revision, 'public_id' => $public_id, 'token_hash' => hash_hmac('sha256', $token, wp_salt('auth')), 'participant_type' => 'guest', 'participant_data' => $identity, 'status' => 'in_progress', 'started_at' => $start, 'deadline_at' => $end, 'last_activity_at' => $start));
			$attempts[] = array('public_id' => $public_id, 'token' => $token);
		}
		$fixture['exams'][] = array('id' => $exam, 'questions' => $questions, 'attempts' => $attempts);
	}
	$fixture['deadline'] = $deadline;
	echo wp_json_encode($fixture) . "\n";
	return;
}

if (! $ids || ! in_array($mode, array('state', 'drain', 'fill-rankings'), true)) {
	throw new RuntimeException('Unexpected synthetic test operation.');
}

if ($mode === 'fill-rankings') {
	$revisions = $wpdb->get_results($wpdb->prepare('SELECT id,assessment_id FROM %i WHERE title=%s', $db->table('revisions'), $fixture_name), ARRAY_A);
	$stamp = gmdate('Y-m-d H:i:s', time() - 60);
	$db->begin();
	try {
		foreach ($revisions as $revision) {
			for ($i = 0; $i < 1000; ++$i) {
				$score = 5000 + ($i % 20) * 50;
				$duration = 300 + $i % 7;
				$attempt = $put('attempts', array(
					'assessment_id' => $revision['assessment_id'], 'revision_id' => $revision['id'],
					'public_id' => wp_generate_uuid4(), 'token_hash' => hash('sha256', wp_generate_uuid4()),
					'status' => 'submitted', 'participant_type' => 'guest', 'participant_data' => '',
					'integrity_status' => 'on_time', 'ranking_eligible' => 1, 'score' => $score,
					'duration_seconds' => $duration, 'started_at' => $stamp, 'last_activity_at' => $stamp, 'submitted_at' => $stamp,
				));
				$put('ranking_entries', array(
					'revision_id' => $revision['id'], 'attempt_id' => $attempt, 'score' => $score,
					'duration_seconds' => $duration, 'submitted_at' => $stamp,
					'public_label' => 'Synthetic F.', 'public_search' => 'synthetic f',
				));
			}
		}
		$db->commit();
	} catch (Throwable $error) {
		$db->rollback();
		throw $error;
	}
}

if ($mode === 'drain') {
	for ($batch = 0; $batch < 5; ++$batch) {
		do_action('paper_to_quiz_process_submissions');
	}
}

$scope = implode(',', $ids);
$rows = $wpdb->get_results($wpdb->prepare(
	"SELECT t.assessment_id,COUNT(*) total,SUM(t.pending_submission IS NOT NULL) pending,
	SUM(t.status='auto_submitted') completed,SUM(t.integrity_status='on_time') on_time,
	SUM(t.score) score,COUNT(e.id) ranked,SUM(t.submitted_at=t.finish_requested_at) receipt_times
	FROM %i t LEFT JOIN %i e ON e.attempt_id=t.id WHERE t.assessment_id IN ({$scope}) GROUP BY t.assessment_id ORDER BY t.assessment_id",
	$db->table('attempts'), $db->table('ranking_entries')
), ARRAY_A);
$answers = (int) $wpdb->get_var($wpdb->prepare(
	"SELECT COUNT(*) FROM %i a JOIN %i t ON a.attempt_id=t.id WHERE t.assessment_id IN ({$scope})",
	$db->table('answers'), $db->table('attempts')
));
echo wp_json_encode(array('exams' => $rows, 'answers' => $answers)) . "\n";
