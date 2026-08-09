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
use PaperToQuiz\Infrastructure\Installer;
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
		Installer::maybe_upgrade();

		$db                  = new Database();
		$this->storage       = new EncryptedStorage();
		$crypto              = new Crypto();
		$assets              = new AssetService($db, $this->storage);
		$terms               = new TermService($db);
		$purge_service       = new AssessmentPurgeService($db, $assets);
		$assessment_service  = new AssessmentService($db, $assets, $terms, $purge_service);
		$attempt_service     = new AttemptService($db, $assessment_service, $crypto);
		$email_service       = new ResultEmailService($db, $attempt_service);
		$admin_controller    = new AdminController($db, $this->storage, $assets, $assessment_service, $attempt_service, $terms, $purge_service);
		$public_controller   = new PublicController($attempt_service);
		$menu                = new AdminMenu();
		$shortcode           = new Shortcode($assessment_service);
		$privacy             = new PrivacyManager($db, $crypto, $attempt_service);
		$cleanup             = new Cleanup($db, $this->storage, $attempt_service);
		$settings            = new Settings();

		add_filter('cron_schedules', array(Installer::class, 'cron_schedules'));
		Installer::ensure_schedules();
		add_action('admin_menu', array($menu, 'register'));
		add_action('admin_enqueue_scripts', array($menu, 'enqueue'));
		add_action('admin_init', array($settings, 'register'));
		add_action('rest_api_init', array($admin_controller, 'register_routes'));
		add_action('rest_api_init', array($public_controller, 'register_routes'));
		add_filter('rest_pre_serve_request', array($this, 'serve_binary'), 10, 4);
		add_action('ptq_daily_cleanup', array($cleanup, 'run'));
		add_action('ptq_attempt_completed', array($email_service, 'enqueue'));
		add_action('ptq_process_result_emails', array($email_service, 'process'));

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
