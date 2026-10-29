<?php
/** Installable ZIP upgrade regression. Run only in a disposable, unmapped wp-env. */

use PaperToQuiz\Application\AssessmentPurgeService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\Installer;

if (! defined('WP_CLI') || ! WP_CLI || wp_get_environment_type() !== 'local' || getenv('PAPER_TO_QUIZ_ALLOW_REGRESSION') !== '1') {
	throw new RuntimeException('Release upgrade checks require a disposable local WP-CLI environment.');
}
function paper_to_quiz_release_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}
$db = new Database();
$wpdb = $db->wpdb();
$now = current_time('mysql', true);
$phase = getenv('PTQ_UPGRADE_PHASE');
$state_option = 'paper_to_quiz_release_test_fixture';
$assessment_row = array('type' => 'exam', 'status' => 'published', 'created_by' => 1, 'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now);
$revision_row = array('revision_no' => 1, 'lifecycle' => 'published', 'title' => 'PTQ ZIP upgrade synthetic fixture',
	'options_json' => '["A","B"]', 'total_points' => 10000, 'allow_repeat' => 0, 'ranking_enabled' => 1,
	'feedback_timing' => 'after_submit', 'result_visibility' => 'summary', 'participant_fields_json' => '{}', 'created_at' => $now);

if ($phase === 'seed') {
	paper_to_quiz_release_assert(PAPER_TO_QUIZ_VERSION === '1.1.6' && PAPER_TO_QUIZ_DB_VERSION === '1.0.0', 'The baseline ZIP was not installed.');
	$member = wp_insert_user(array('user_login' => 'ptq_zip_upgrade_' . wp_generate_password(8, false, false), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber'));
	paper_to_quiz_release_assert(! is_wp_error($member), 'The baseline member fixture failed.');
	paper_to_quiz_release_assert(1 === $wpdb->insert($db->table('assessments'), $assessment_row), 'The baseline assessment failed.');
	$assessment = (int) $wpdb->insert_id;
	paper_to_quiz_release_assert(1 === $wpdb->insert($db->table('revisions'), array_merge($revision_row, array('assessment_id' => $assessment, 'access_mode' => 'login_required'))), 'The baseline revision failed.');
	$revision = (int) $wpdb->insert_id;
	paper_to_quiz_release_assert(false !== $wpdb->update($db->table('assessments'), array('published_revision_id' => $revision), array('id' => $assessment)), 'The baseline publication pointer failed.');
	paper_to_quiz_release_assert(1 === $wpdb->insert($db->table('attempts'), array(
		'public_id' => wp_generate_uuid4(), 'token_hash' => hash('sha256', wp_generate_password(32)), 'assessment_id' => $assessment,
		'revision_id' => $revision, 'wp_user_id' => (int) $member, 'participant_type' => 'member',
		'participant_data' => (new Crypto())->encrypt_array(array('first_name' => 'Baseline', 'last_name' => 'Member')),
		'status' => 'submitted', 'submission_id' => wp_generate_uuid4(), 'integrity_status' => 'on_time', 'ranking_eligible' => 1,
		'started_at' => $now, 'submitted_at' => $now, 'last_activity_at' => $now, 'score' => 9000, 'duration_seconds' => 60,
	)), 'The baseline attempt failed.');
	$attempt = (int) $wpdb->insert_id;
	paper_to_quiz_release_assert(1 === $wpdb->insert($db->table('ranking_entries'), array('revision_id' => $revision, 'attempt_id' => $attempt, 'wp_user_id' => (int) $member, 'score' => 9000, 'duration_seconds' => 60, 'submitted_at' => $now)), 'The baseline ranking failed.');
	paper_to_quiz_release_assert(add_option($state_option, array('assessment' => $assessment, 'revision' => $revision, 'attempt' => $attempt, 'member' => (int) $member), '', false), 'The upgrade fixture state failed.');
	echo "PTQ_RELEASE_BASELINE=ready\n";
	return;
}

paper_to_quiz_release_assert(PAPER_TO_QUIZ_VERSION === (getenv('PTQ_EXPECTED_VERSION') ?: '1.2.0') && get_option('paper_to_quiz_db_version') === '1.1.0', 'The 1.2.0 ZIP did not upgrade its schema.');
$column = $wpdb->get_row($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $db->table('ranking_entries'), 'wp_user_id'), ARRAY_A);
paper_to_quiz_release_assert($column['Null'] === 'YES', 'Guest ranking IDs are not nullable.');
paper_to_quiz_release_assert(Installer::repair_schema(), 'Reapplying the new schema was not idempotent.');
if ($phase === 'clean') {
	paper_to_quiz_release_assert(0 === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('attempts')), 'The clean ZIP install contains unexpected attempts.');
	$flag = $wpdb->get_row($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $db->table('revisions'), 'ranking_list_enabled'), ARRAY_A);
	paper_to_quiz_release_assert((string) $flag['Default'] === '0', 'List publication does not default to off.');
	echo "PTQ_RELEASE_CLEAN_INSTALL=passed\n";
	return;
}
paper_to_quiz_release_assert($phase === 'verify', 'Unknown release verification phase.');
$state = get_option($state_option);
paper_to_quiz_release_assert(is_array($state), 'Baseline fixture state is missing.');
$old = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE attempt_id = %d', $db->table('ranking_entries'), $state['attempt']), ARRAY_A);
paper_to_quiz_release_assert((int) $old['wp_user_id'] === $state['member'] && (int) $old['score'] === 9000 && null === $old['public_label'], 'The member ranking changed or its identity was automatically published.');
paper_to_quiz_release_assert('0' === (string) $wpdb->get_var($wpdb->prepare('SELECT ranking_list_enabled FROM %i WHERE id = %d', $db->table('revisions'), $state['revision'])), 'The upgrade enabled an existing participant list.');

$storage = new EncryptedStorage();
$assets = new AssetService($db, $storage);
$assessments = new AssessmentService($db, $assets);
$attempts = new AttemptService($db, $assessments, new Crypto());
paper_to_quiz_release_assert(1 === $wpdb->insert($db->table('assessments'), $assessment_row), 'The new public assessment failed.');
$assessment = (int) $wpdb->insert_id;
$revision_row['participant_fields_json'] = '{"first_name":{"enabled":true},"last_name":{"enabled":true}}';
paper_to_quiz_release_assert(1 === $wpdb->insert($db->table('revisions'), array_merge($revision_row, array('assessment_id' => $assessment, 'access_mode' => 'guest_allowed', 'ranking_list_enabled' => 1))), 'The new public revision failed.');
$revision = (int) $wpdb->insert_id;
paper_to_quiz_release_assert(false !== $wpdb->update($db->table('assessments'), array('published_revision_id' => $revision), array('id' => $assessment)), 'The new publication pointer failed.');
paper_to_quiz_release_assert(1 === $wpdb->insert($db->table('questions'), array('revision_id' => $revision, 'client_key' => wp_generate_uuid4(), 'ordinal' => 1, 'source_page' => 1, 'crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1, 'crop_height' => 1, 'correct_option' => 'A', 'points' => 10000, 'created_at' => $now, 'updated_at' => $now)), 'The new synthetic question failed.');
$question = (int) $wpdb->insert_id;
wp_set_current_user(0);
$started = $attempts->start($assessment, array('first_name' => 'Release', 'last_name' => 'Guest'));
paper_to_quiz_release_assert(is_array($started), 'The installed ZIP could not start a guest attempt.');
$result = $attempts->submit($started['public_id'], $started['token'], false, wp_generate_uuid4(), array(array('question_id' => $question, 'option' => 'A')));
paper_to_quiz_release_assert(is_array($result) && $result['ranking_eligible'] && $result['ranking']['total'] === 1, 'The installed ZIP could not rank a guest submission.');
paper_to_quiz_release_assert(false !== $wpdb->update($db->table('revisions'), array('window_end_utc' => gmdate('Y-m-d H:i:s', time() - 60)), array('id' => $revision)), 'The synthetic exam could not finish.');
$list = $attempts->ranking_list($assessment, 1, 20, 'release');
paper_to_quiz_release_assert(is_array($list) && $list['items'][0]['display_name'] === 'Release G.', 'The installed ZIP could not show its masked list.');
$purge = new AssessmentPurgeService($db, $assets);
foreach (array($state['assessment'], $assessment) as $id) {
	paper_to_quiz_release_assert(false !== $wpdb->update($db->table('assessments'), array('status' => 'trash'), array('id' => $id)), 'Fixture cleanup could not mark the assessment.');
	paper_to_quiz_release_assert(! is_wp_error($purge->purge($id)), 'Fixture cleanup failed.');
}
require_once ABSPATH . 'wp-admin/includes/user.php';
paper_to_quiz_release_assert(wp_delete_user($state['member']), 'The synthetic member could not be removed.');
paper_to_quiz_release_assert(delete_option($state_option), 'The upgrade fixture state could not be removed.');
echo "PTQ_RELEASE_UPGRADE=passed\n";
