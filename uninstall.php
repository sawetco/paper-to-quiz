<?php
/**
 * Uninstall cleanup for Paper to Quiz.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

(static function (): void {
	$settings = get_option('ptq_settings', array());
	$purge    = is_array($settings) && ! empty($settings['purge_on_uninstall']);

	if (! $purge) {
		return;
	}

	if (! defined('PTQ_DIR')) {
		// PTQ_ is the plugin's permanent public prefix.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		define('PTQ_DIR', plugin_dir_path(__FILE__));
	}

	require_once PTQ_DIR . 'src/Autoloader.php';

	\PaperToQuiz\Autoloader::register();
	\PaperToQuiz\Infrastructure\Uninstaller::purge();
})();
