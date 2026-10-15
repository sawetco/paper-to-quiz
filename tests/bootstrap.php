<?php
/**
 * PHPUnit bootstrap for the Paper to Quiz plugin.
 *
 * This suite loads the disposable real WordPress install from `/var/www/html/wp-load.php`
 * so that `$wpdb`, `wp_salt()`, `wp_json_encode()`, and friends are available to
 * the units under test. The polyfill base class `Yoast\PHPUnitPolyfills\TestCases\TestCase`
 * is used instead of `\WP_UnitTestCase`.
 *
 * @package PaperToQuiz\Tests
 */

declare(strict_types=1);

if (defined('PAPER_TO_QUIZ_PHPUNIT_BOOTSTRAP_LOADED')) {
    return;
}
define('PAPER_TO_QUIZ_PHPUNIT_BOOTSTRAP_LOADED', true);

// WordPress wp_die() can exit with status zero before PHPUnit runs any tests.
register_shutdown_function(static function (): void {
    if (! defined('PAPER_TO_QUIZ_PHPUNIT_BOOTSTRAP_READY')) {
        fwrite(STDERR, "Paper to Quiz PHPUnit bootstrap did not complete.\n");
        exit(1);
    }
});

$wp_load = '/var/www/html/wp-load.php';

if (! is_readable($wp_load)) {
    throw new RuntimeException(
        'WordPress install not found at /var/www/html/wp-load.php. ' .
        'The phpunit bootstrap expects to run inside the wp-env `cli` container ' .
        '(`npm run test:php`). Adjust the path in tests/bootstrap.php if your layout differs.'
    );
}

/*
 * Load the real WordPress install. `wp-load.php` defines ABSPATH, sets up $wpdb,
 * loads pluggable functions (wp_salt, wp_json_encode), and registers the default
 * option API. We skip the WP test suite intentionally (see docblock).
 */
require_once $wp_load;

// Load the plugin itself so its classes, constants, and autoloader are available.
require_once dirname(__DIR__) . '/paper-to-quiz.php';

define('PAPER_TO_QUIZ_PHPUNIT_BOOTSTRAP_READY', true);
