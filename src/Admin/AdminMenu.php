<?php

declare(strict_types=1);

namespace PaperToQuiz\Admin;

use PaperToQuiz\Infrastructure\Settings;

final class AdminMenu {
	private const PAGES = array(
		'paper-to-quiz-exams',
		'paper-to-quiz-tests',
		'paper-to-quiz-classes',
		'paper-to-quiz-subjects',
		'paper-to-quiz-results',
		'paper-to-quiz-settings',
	);

	public function register(): void {
		add_menu_page(
			__('Paper to Quiz', 'paper-to-quiz'),
			__('Paper to Quiz', 'paper-to-quiz'),
			'paper_to_quiz_manage_assessments',
			'paper-to-quiz-exams',
			array($this, 'render'),
			'dashicons-welcome-learn-more',
			26
		);

		foreach (self::PAGES as $slug) {
			$label = $this->page_label($slug);
			$capability = $slug === 'paper-to-quiz-results'
				? 'paper_to_quiz_view_results'
				: ($slug === 'paper-to-quiz-settings' ? 'paper_to_quiz_manage_settings' : 'paper_to_quiz_manage_assessments');
			add_submenu_page(
				'paper-to-quiz-exams',
				$label,
				$label,
				$capability,
				$slug,
				array($this, 'render')
			);
		}
	}

	public function enqueue(string $hook): void {
		if (! str_contains($hook, 'paper-to-quiz-') && ! str_contains($hook, 'toplevel_page_paper-to-quiz-exams')) {
			return;
		}

		wp_enqueue_media();

		$asset_file = PAPER_TO_QUIZ_DIR . 'build/admin.asset.php';
		if (! file_exists($asset_file)) {
			wp_die(
				esc_html__('The Paper to Quiz interface has not been built. Please rebuild the production package.', 'paper-to-quiz')
			);
		}
		$asset = require $asset_file;
		wp_enqueue_script(
			'paper-to-quiz-admin',
			PAPER_TO_QUIZ_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations('paper-to-quiz-admin', 'paper-to-quiz');
		if (file_exists(PAPER_TO_QUIZ_DIR . 'build/style-admin.css')) {
			wp_enqueue_style('paper-to-quiz-admin', PAPER_TO_QUIZ_URL . 'build/style-admin.css', array('wp-components'), $asset['version']);
		}

		// These values select an already-authorized SPA view and do not mutate state.
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'paper-to-quiz-exams'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_add_inline_script(
			'paper-to-quiz-admin',
			'window.paperToQuizAdmin=' . wp_json_encode(
				array(
					'restRoot'     => esc_url_raw(rest_url('paper-to-quiz/v1/')),
					'nonce'        => wp_create_nonce('wp_rest'),
					'page'         => $page,
					'assessmentId' => isset($_GET['assessment']) ? absint($_GET['assessment']) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'pluginUrl'    => PAPER_TO_QUIZ_URL,
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
			'paper-to-quiz-exams'    => __('Exams', 'paper-to-quiz'),
			'paper-to-quiz-tests'    => __('Tests', 'paper-to-quiz'),
			'paper-to-quiz-classes'  => __('Classes', 'paper-to-quiz'),
			'paper-to-quiz-subjects' => __('Subjects', 'paper-to-quiz'),
			'paper-to-quiz-results'  => __('Results', 'paper-to-quiz'),
			'paper-to-quiz-settings' => __('Settings', 'paper-to-quiz'),
			default                         => __('Paper to Quiz', 'paper-to-quiz'),
		};
	}
}
