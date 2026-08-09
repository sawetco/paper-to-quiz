<?php

declare(strict_types=1);

namespace PaperToQuiz\Frontend;

use PaperToQuiz\Application\AssessmentService;

final class Shortcode {
	public function __construct(private readonly AssessmentService $assessments) {
	}

	public function register(): void {
		add_shortcode('paper_to_quiz', array($this, 'render'));
	}

	public function render(array|string $attributes = array()): string {
		$attributes    = shortcode_atts(array('id' => 0), (array) $attributes, 'paper_to_quiz');
		$assessment_id = absint($attributes['id']);
		if (! $assessment_id) {
			return $this->notice(__('This item could not be found.', 'paper-to-quiz'));
		}

		$record = $this->assessments->get($assessment_id, true);
		if (! $record || $record['assessment']['status'] !== 'published' || ! $record['revision']) {
			return $this->notice(__('This item is currently unavailable.', 'paper-to-quiz'));
		}

		if ($record['revision']['access_mode'] === 'login_required' && (! is_user_logged_in() || ! current_user_can('read'))) {
			$this->enqueue();
			$current_url = get_permalink() ?: home_url('/');
			$class_color = sanitize_hex_color((string) ($record['revision']['class_color'] ?? ''));
			$style       = $class_color ? ' style="--ptq-primary:' . esc_attr($class_color) . '"' : '';
			$output      = '<section class="ptq-login-required"' . $style . ' aria-labelledby="ptq-login-title-' . $assessment_id . '">';
			$output     .= '<h2 id="ptq-login-title-' . $assessment_id . '">' . esc_html($record['revision']['title']) . '</h2>';
			$output     .= '<p>' . esc_html__('You must log in to your account to participate.', 'paper-to-quiz') . '</p>';
			$output     .= '<p><a class="ptq-button ptq-button--primary" href="' . esc_url(wp_login_url($current_url)) . '">' . esc_html__('Log in', 'paper-to-quiz') . '</a>';
			if (get_option('users_can_register')) {
				$output .= ' <a class="ptq-button" href="' . esc_url(wp_registration_url()) . '">' . esc_html__('Register', 'paper-to-quiz') . '</a>';
			}
			$output .= '</p></section>';
			return $output;
		}

		$this->enqueue();
		return sprintf(
			'<div class="ptq-student-root" data-assessment-id="%1$d" data-rest-root="%2$s" data-nonce="%3$s" data-style-url="%4$s"><p class="ptq-loading-placeholder">%5$s</p></div>',
			$assessment_id,
			esc_attr(rest_url('ptq/v1/')),
			esc_attr(is_user_logged_in() ? wp_create_nonce('wp_rest') : ''),
			esc_attr(PTQ_URL . 'build/style-student.css?ver=' . PTQ_VERSION),
			esc_html__('Preparing…', 'paper-to-quiz')
		);
	}

	private function enqueue(): void {
		$asset_file = PTQ_DIR . 'build/student.asset.php';
		if (! file_exists($asset_file)) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script(
			'ptq-student',
			PTQ_URL . 'build/student.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations('ptq-student', 'paper-to-quiz', PTQ_DIR . 'languages');
	}

	private function notice(string $message): string {
		$this->enqueue();
		return '<div class="ptq-notice" role="status">' . esc_html($message) . '</div>';
	}
}
