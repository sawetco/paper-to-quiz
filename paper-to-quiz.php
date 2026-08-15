<?php
/**
 * Plugin Name: Paper to Quiz
 * Plugin URI:  https://github.com/sawetco/paper-to-quiz
 * Description: Convert PDF exams and worksheets into secure, image-based WordPress quizzes.
 * Version:     1.1.0
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

define('PAPER_TO_QUIZ_VERSION', '1.1.0');
define('PAPER_TO_QUIZ_DB_VERSION', '1.3.0');
define('PAPER_TO_QUIZ_FILE', __FILE__);
define('PAPER_TO_QUIZ_DIR', plugin_dir_path(__FILE__));
define('PAPER_TO_QUIZ_URL', plugin_dir_url(__FILE__));

require_once PAPER_TO_QUIZ_DIR . 'src/Autoloader.php';

\PaperToQuiz\Autoloader::register();

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
