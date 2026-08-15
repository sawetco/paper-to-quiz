<?php
/**
 * Prefix migration regression checks for disposable/local WordPress sites.
 *
 * Run with PAPER_TO_QUIZ_ALLOW_REGRESSION=1. Set
 * PAPER_TO_QUIZ_MIGRATION_INSPECT_ONLY=1 with --skip-plugins to capture the
 * legacy state before the updated plugin is loaded.
 */

if (! in_array(wp_get_environment_type(), array('local', 'development'), true)) {
	throw new RuntimeException('Prefix migration regression checks are local-only.');
}
if (getenv('PAPER_TO_QUIZ_ALLOW_REGRESSION') !== '1') {
	throw new RuntimeException('Set PAPER_TO_QUIZ_ALLOW_REGRESSION=1 to enable regression checks.');
}

function paper_to_quiz_migration_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

function paper_to_quiz_migration_table_exists(wpdb $wpdb, string $table): bool {
	$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
	return is_string($found) && strtolower($found) === strtolower($table);
}

global $wpdb;
$tables = array(
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

if (getenv('PAPER_TO_QUIZ_MIGRATION_SIMULATE') === '1') {
	foreach ($tables as $name) {
		$legacy    = $wpdb->prefix . 'ptq_' . $name;
		$canonical = $wpdb->prefix . 'paper_to_quiz_' . $name;
		paper_to_quiz_migration_assert(! paper_to_quiz_migration_table_exists($wpdb, $legacy), 'Simulation found a pre-existing legacy table: ' . $name);
		paper_to_quiz_migration_assert(paper_to_quiz_migration_table_exists($wpdb, $canonical), 'Simulation canonical table is missing: ' . $name);
		$renamed = $wpdb->query($wpdb->prepare('RENAME TABLE %i TO %i', $canonical, $legacy));
		paper_to_quiz_migration_assert(false !== $renamed, 'Simulation could not rename table: ' . $name);
	}

	foreach (array('settings', 'db_version', 'storage_key') as $suffix) {
		$canonical = 'paper_to_quiz_' . $suffix;
		$legacy    = 'ptq_' . $suffix;
		$value     = get_option($canonical, null);
		update_option($legacy, $value, false);
		delete_option($canonical);
	}

	$administrator = get_role('administrator');
	paper_to_quiz_migration_assert($administrator instanceof WP_Role, 'Administrator role is unavailable for simulation.');
	foreach (array('manage_assessments', 'publish_assessments', 'view_results', 'manage_settings') as $suffix) {
		$administrator->add_cap('ptq_' . $suffix);
		$administrator->remove_cap('paper_to_quiz_' . $suffix);
	}
	wp_clear_scheduled_hook('paper_to_quiz_daily_cleanup');
	wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ptq_' . 'daily_cleanup');

	paper_to_quiz_migration_assert(PaperToQuiz\Infrastructure\Installer::repair_schema(), 'Simulated prefix migration failed.');
	PaperToQuiz\Infrastructure\Installer::ensure_schedules();
}

$state = array(
	'legacy'    => array(),
	'canonical' => array(),
	'options'   => array(
		'legacy_db_version'       => get_option('ptq_' . 'db_version', null),
		'canonical_db_version'    => get_option('paper_to_quiz_db_version', null),
		'legacy_storage_hash'     => hash('sha256', (string) get_option('ptq_' . 'storage_key', '')),
		'canonical_storage_hash'  => hash('sha256', (string) get_option('paper_to_quiz_storage_key', '')),
	),
);

foreach ($tables as $name) {
	$legacy    = $wpdb->prefix . 'ptq_' . $name;
	$canonical = $wpdb->prefix . 'paper_to_quiz_' . $name;
	$state['legacy'][$name] = paper_to_quiz_migration_table_exists($wpdb, $legacy)
		? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $legacy))
		: null;
	$state['canonical'][$name] = paper_to_quiz_migration_table_exists($wpdb, $canonical)
		? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $canonical))
		: null;
}

if (getenv('PAPER_TO_QUIZ_MIGRATION_INSPECT_ONLY') !== '1') {
	foreach ($tables as $name) {
		paper_to_quiz_migration_assert($state['legacy'][$name] === null, 'A legacy table remains: ' . $name);
		paper_to_quiz_migration_assert($state['canonical'][$name] !== null, 'A canonical table is missing: ' . $name);
	}
	paper_to_quiz_migration_assert('1.3.0' === $state['options']['canonical_db_version'], 'Database version was not migrated and upgraded.');
	paper_to_quiz_migration_assert(null === $state['options']['legacy_db_version'], 'Legacy database option remains.');
	paper_to_quiz_migration_assert(false === get_option('ptq_' . 'settings', false), 'Legacy settings option remains.');
	paper_to_quiz_migration_assert(false === get_option('ptq_' . 'storage_key', false), 'Legacy storage-key option remains.');
	paper_to_quiz_migration_assert(false !== get_option('paper_to_quiz_storage_key', false), 'Storage key was not migrated.');

	$administrator = get_role('administrator');
	paper_to_quiz_migration_assert($administrator instanceof WP_Role, 'Administrator role is unavailable.');
	foreach (array('manage_assessments', 'publish_assessments', 'view_results', 'manage_settings') as $suffix) {
		paper_to_quiz_migration_assert($administrator->has_cap('paper_to_quiz_' . $suffix), 'Canonical capability is missing: ' . $suffix);
		paper_to_quiz_migration_assert(! $administrator->has_cap('ptq_' . $suffix), 'Legacy capability remains: ' . $suffix);
	}
	paper_to_quiz_migration_assert(false !== wp_next_scheduled('paper_to_quiz_daily_cleanup'), 'Canonical cleanup schedule is missing.');
	paper_to_quiz_migration_assert(false === wp_next_scheduled('ptq_' . 'daily_cleanup'), 'Legacy cleanup schedule remains.');
}

echo wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
