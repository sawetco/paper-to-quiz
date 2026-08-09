<?php

declare(strict_types=1);

namespace PaperToQuiz\Admin;

use PaperToQuiz\Infrastructure\Settings;

final class AdminMenu {
	private const PAGES = array(
		'ptq-exams',
		'ptq-tests',
		'ptq-classes',
		'ptq-subjects',
		'ptq-results',
		'ptq-settings',
	);

	public function register(): void {
	add_menu_page(
			__('Paper to Quiz', 'paper-to-quiz'),
			__('Paper to Quiz', 'paper-to-quiz'),
			'ptq_manage_assessments',
			'ptq-exams',
			array($this, 'render'),
			'dashicons-welcome-learn-more',
			26
		);

		foreach (self::PAGES as $slug) {
			$label = $this->page_label($slug);
			$capability = $slug === 'ptq-results'
				? 'ptq_view_results'
				: ($slug === 'ptq-settings' ? 'ptq_manage_settings' : 'ptq_manage_assessments');
			add_submenu_page(
				'ptq-exams',
				$label,
				$label,
				$capability,
				$slug,
				array($this, 'render')
			);
		}
	}

	public function enqueue(string $hook): void {
		if (! str_contains($hook, 'ptq-') && ! str_contains($hook, 'toplevel_page_ptq-exams')) {
			return;
		}

		wp_enqueue_media();

		$asset_file = PTQ_DIR . 'build/admin.asset.php';
		if (! file_exists($asset_file)) {
			wp_die(
				esc_html__('The Paper to Quiz interface has not been built. Please rebuild the production package.', 'paper-to-quiz')
			);
		}
		$asset = require $asset_file;
		wp_enqueue_script(
			'ptq-admin',
			PTQ_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations('ptq-admin', 'paper-to-quiz', PTQ_DIR . 'languages');
		if (file_exists(PTQ_DIR . 'build/style-admin.css')) {
			wp_enqueue_style('ptq-admin', PTQ_URL . 'build/style-admin.css', array('wp-components'), $asset['version']);
		}

		// These values select an already-authorized SPA view and do not mutate state.
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'ptq-exams'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_add_inline_script(
			'ptq-admin',
			'window.ptqAdmin=' . wp_json_encode(
				array(
					'restRoot'     => esc_url_raw(rest_url('ptq/v1/')),
					'nonce'        => wp_create_nonce('wp_rest'),
					'page'         => $page,
					'assessmentId' => isset($_GET['assessment']) ? absint($_GET['assessment']) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'pluginUrl'    => PTQ_URL,
					'settings'     => Settings::get(),
				)
			) . ';',
			'before'
		);
	}

	public function render(): void {
		echo '<div class="wrap ptq-admin-wrap"><div id="ptq-admin-root"><p>';
		esc_html_e('Quiz management is loading…', 'paper-to-quiz');
		echo '</p></div></div>';
	}

	private function page_label(string $slug): string {
		return match ($slug) {
			'ptq-exams'    => __('Exams', 'paper-to-quiz'),
			'ptq-tests'    => __('Tests', 'paper-to-quiz'),
			'ptq-classes'  => __('Classes', 'paper-to-quiz'),
			'ptq-subjects' => __('Subjects', 'paper-to-quiz'),
			'ptq-results'  => __('Results', 'paper-to-quiz'),
			'ptq-settings' => __('Settings', 'paper-to-quiz'),
			default        => __('Paper to Quiz', 'paper-to-quiz'),
		};
	}
}
