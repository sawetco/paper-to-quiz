<?php

declare(strict_types=1);

namespace PaperToQuiz;

use PaperToQuiz\Admin\AdminMenu;
use PaperToQuiz\Application\AssessmentPurgeService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Application\ResultEmailService;
use PaperToQuiz\Application\TermService;
use PaperToQuiz\Frontend\Shortcode;
use PaperToQuiz\Infrastructure\Cleanup;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\EncryptionMigration;
use PaperToQuiz\Infrastructure\Installer;
use PaperToQuiz\Infrastructure\PrivateKeyProvider;
use PaperToQuiz\Infrastructure\Settings;
use PaperToQuiz\Privacy\PrivacyManager;
use PaperToQuiz\Rest\AdminController;
use PaperToQuiz\Rest\BinaryResponse;
use PaperToQuiz\Rest\PublicController;

final class Plugin {
	private static ?self $instance = null;
	private EncryptedStorage $storage;

	public static function instance(): self {
		if (! self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if (! Installer::maybe_upgrade()) {
			add_action('admin_notices', static function (): void {
				if (current_user_can('activate_plugins')) {
					echo '<div class="notice notice-error"><p>' . esc_html__('Paper to Quiz could not verify its database. Check that the database and its local locking directory are writable, then try again. SQLite requires native PHP and SQLite Database Integration 3.1.2 or newer.', 'paper-to-quiz') . '</p></div>';
				}
			});
			add_filter('rest_pre_dispatch', static function ($result, $server, \WP_REST_Request $request) {
				return str_starts_with($request->get_route(), '/paper-to-quiz/v1/')
					? (new \PaperToQuiz\Infrastructure\DatabaseException(true))->rest_error() : $result;
			}, 10, 3);
			return;
		}

		$db                  = new Database();
		// Scope every SQLite endpoint's direct wpdb calls, including atomic counters
		// and upload metadata, without changing other plugins' connection settings.
		add_filter('rest_endpoints', static function (array $routes) use ($db): array {
			if ($db->engine() !== 'sqlite') {
				return $routes;
			}
			foreach ($routes as $path => &$handlers) {
				if (! str_starts_with($path, '/paper-to-quiz/v1/')) {
					continue;
				}
				foreach ($handlers as &$handler) {
					if (! is_array($handler) || ! isset($handler['callback']) || ! is_callable($handler['callback'])) {
						continue;
					}
					$callback = $handler['callback'];
					$handler['callback'] = static function (\WP_REST_Request $request) use ($db, $callback) {
						try {
							$result = $db->bounded(static fn () => $callback($request));
							if (is_wp_error($result) && \PaperToQuiz\Infrastructure\DatabaseException::is_busy($db->wpdb()->last_error)) {
								return (new \PaperToQuiz\Infrastructure\DatabaseException(true))->rest_error();
							}
							return $result;
						} catch (\PaperToQuiz\Infrastructure\DatabaseException $error) {
							return $error->rest_error();
						} catch (\Throwable $error) {
							return \PaperToQuiz\Infrastructure\OperationalErrorReporter::report('paper_to_quiz_request_failed', $error, __('The request could not be completed. Please try again.', 'paper-to-quiz'), 500);
						}
					};
				}
				unset($handler);
			}
			unset($handlers);
			return $routes;
		});
		$key_provider        = new PrivateKeyProvider();
		$this->storage       = new EncryptedStorage($key_provider);
		$crypto              = new Crypto($key_provider);
		$encryption_migration = new EncryptionMigration($db, $crypto, $this->storage);
		$assets              = new AssetService($db, $this->storage);
		$terms               = new TermService($db);
		$purge_service       = new AssessmentPurgeService($db, $assets);
		$assessment_service  = new AssessmentService($db, $assets, $terms, $purge_service);
		$attempt_service     = new AttemptService($db, $assessment_service, $crypto);
		$email_service       = new ResultEmailService($db, $attempt_service);
		$admin_controller    = new AdminController($db, $this->storage, $assets, $assessment_service, $attempt_service, $terms, $purge_service, $encryption_migration);
		$public_controller   = new PublicController($attempt_service);
		$menu                = new AdminMenu();
		$shortcode           = new Shortcode($assessment_service);
		$privacy             = new PrivacyManager($db, $crypto, $attempt_service);
		$cleanup             = new Cleanup($db, $this->storage, $attempt_service);
		$settings            = new Settings();

		add_filter('cron_schedules', array(Installer::class, 'cron_schedules'));
		add_action('init', array(Installer::class, 'ensure_schedules'));
		add_action('admin_menu', array($menu, 'register'));
		add_action('admin_enqueue_scripts', array($menu, 'enqueue'));
		add_action('admin_init', array($settings, 'register'));
		add_action('rest_api_init', array($admin_controller, 'register_routes'));
		add_action('rest_api_init', array($public_controller, 'register_routes'));
		add_filter('rest_pre_serve_request', array($this, 'serve_binary'), 10, 4);
		add_action('paper_to_quiz_daily_cleanup', array($cleanup, 'run'));
		add_action(Cleanup::CONTINUATION_HOOK, array($cleanup, 'run'), 10, 1);
		add_action('paper_to_quiz_attempt_completed', array($email_service, 'enqueue'));
		add_action('paper_to_quiz_process_result_emails', array($email_service, 'process'));
		add_action('paper_to_quiz_process_encryption_migration', array($encryption_migration, 'run'));
		add_action('paper_to_quiz_process_submissions', array($attempt_service, 'process_submissions'));
		add_action('paper_to_quiz_continue_submissions', array($attempt_service, 'process_submissions'));

		$shortcode->register();
		$privacy->register();
	}

	public function serve_binary(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool {
		if (! $result instanceof BinaryResponse) {
			return $served;
		}

		$server->send_header('Content-Type', $result->mime);
		$server->send_header('Content-Disposition', 'inline; filename="' . sanitize_file_name($result->filename) . '"');
		$server->send_header('X-Content-Type-Options', 'nosniff');
		$cache_control = $result->purpose === 'question_image'
			? 'private, max-age=86400, immutable'
			: 'private, no-store, max-age=0';
		$server->send_header('Cache-Control', $cache_control);
		$server->send_header('Referrer-Policy', 'no-referrer');
		if ($result->byte_size > 0) {
			$server->send_header('Content-Length', (string) $result->byte_size);
		}
		$this->storage->output($result->storage_key, $result->purpose);
		return true;
	}
}
