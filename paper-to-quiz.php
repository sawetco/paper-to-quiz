<?php
/**
 * Plugin Name: Paper to Quiz
 * Plugin URI:  https://github.com/sawetco/paper-to-quiz
 * Description: Convert PDF exams and worksheets into secure, image-based WordPress quizzes.
 * Version:     1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author:      Samet Dönmez
 * Author URI:  https://github.com/sawetco
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: paper-to-quiz
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('PTQ_VERSION', '1.0.0');
define('PTQ_DB_VERSION', '1.2.0');
define('PTQ_FILE', __FILE__);
define('PTQ_DIR', plugin_dir_path(__FILE__));
define('PTQ_URL', plugin_dir_url(__FILE__));

require_once PTQ_DIR . 'src/Autoloader.php';

\PaperToQuiz\Autoloader::register();

add_filter(
	'load_textdomain_mofile',
	static function (string $mofile, string $domain): string {
		if ('paper-to-quiz' !== $domain) {
			return $mofile;
		}

		$locale  = apply_filters('plugin_locale', determine_locale(), $domain); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core locale filter.
		$bundled = PTQ_DIR . 'languages/paper-to-quiz-' . $locale . '.mo';
		return is_readable($bundled) ? $bundled : $mofile;
	},
	10,
	2
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\PaperToQuiz\Infrastructure\Installer::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\PaperToQuiz\Infrastructure\Installer::deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\PaperToQuiz\Plugin::instance()->boot();
	}
);
