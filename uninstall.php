<?php
/**
 * Uninstall cleanup for Paper to Quiz.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

(static function (): void {
	$settings = get_option('paper_to_quiz_settings', array());
	$purge    = is_array($settings) && ! empty($settings['purge_on_uninstall']);

	if (! $purge) {
		return;
	}

	if (! defined('PAPER_TO_QUIZ_DIR')) {
		define('PAPER_TO_QUIZ_DIR', plugin_dir_path(__FILE__));
	}

	require_once PAPER_TO_QUIZ_DIR . 'src/Autoloader.php';

	\PaperToQuiz\Autoloader::register();
	\PaperToQuiz\Infrastructure\Uninstaller::purge();
})();
