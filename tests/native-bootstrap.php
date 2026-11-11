<?php
/** Shared native-PHP bootstrap, including explicitly named disposable unit copies. */
$wp_load = getenv('PTQ_TEST_WP_LOAD') ?: '/var/www/html/wp-load.php';
$sqlite_copy = getenv('PTQ_TEST_SQLITE_PATH');
if ($sqlite_copy) {
	if (! preg_match('/^ptq-unit-[a-z0-9-]+\.sqlite$/', basename($sqlite_copy)) || ! is_file($sqlite_copy)) {
		throw new RuntimeException('Only an existing named disposable SQLite unit copy may be selected.');
	}
	define('DB_PATH', $sqlite_copy);
}
require_once $wp_load;
if (wp_get_environment_type() !== 'local') {
	throw new RuntimeException('Native tests require a disposable local environment.');
}
$auth_state = getenv('PTQ_TEST_AUTH_STATE');
if ($auth_state) {
	if (! $sqlite_copy || get_option('paper_to_quiz_disposable_test') !== 'sqlite-1.3') {
		throw new RuntimeException('Synthetic salt replay is restricted to a disposable SQLite copy.');
	}
	$transfer_state = json_decode(file_get_contents($auth_state), true, 512, JSON_THROW_ON_ERROR);
	add_filter('salt', static function (string $salt, string $scheme) use ($transfer_state): string {
		return $transfer_state['salts'][$scheme] ?? $salt;
	}, 10, 2);
}
