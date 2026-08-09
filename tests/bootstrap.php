<?php
/**
 * PHPUnit bootstrap for the Paper to Quiz plugin.
 *
 * The preferred path (the WordPress PHPUnit test suite via wp-env's
 * `testsEnvironment`) is unavailable here: `.wp-env.json` sets
 * `testsEnvironment: false`, and that option is deprecated in wp-env 11.12
 * with no auto-provided WP test suite (`/var/www/html/phpunit` does not exist).
 *
 * This fallback loads the real WordPress install from `/var/www/html/wp-load.php`
 * so that `$wpdb`, `wp_salt()`, `wp_json_encode()`, and friends are available to
 * the units under test. The polyfill base class `Yoast\PHPUnitPolyfills\TestCases\TestCase`
 * is used instead of `\WP_UnitTestCase`.
 *
 * @package PaperToQuiz\Tests
 */

declare(strict_types=1);

if (defined('PTQ_PHPUNIT_BOOTSTRAP_LOADED')) {
    return;
}
define('PTQ_PHPUNIT_BOOTSTRAP_LOADED', true);

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
