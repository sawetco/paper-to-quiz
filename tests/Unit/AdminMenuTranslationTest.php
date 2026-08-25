<?php
/**
 * Regression tests for JavaScript translations in lazy admin chunks.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Admin\AdminMenu;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class AdminMenuTranslationTest extends TestCase {
	private const HANDLES = array(
		'paper-to-quiz-admin',
		'paper-to-quiz-admin-wizard',
		'paper-to-quiz-admin-pdf-editor',
	);

	public function tearDown(): void {
		foreach (self::HANDLES as $handle) {
			wp_dequeue_script($handle);
			wp_deregister_script($handle);
		}

		parent::tearDown();
	}

	public function test_lazy_admin_chunks_are_registered_as_translated_dependencies(): void {
		$menu = new AdminMenu();
		$menu->enqueue('toplevel_page_paper-to-quiz-exams');

		$scripts = wp_scripts();
		$admin   = $scripts->registered['paper-to-quiz-admin'];
		$this->assertContains('paper-to-quiz-admin-wizard', $admin->deps);
		$this->assertContains('paper-to-quiz-admin-pdf-editor', $admin->deps);

		$expected_sources = array(
			'paper-to-quiz-admin-wizard'     => 'build/admin-wizard.js',
			'paper-to-quiz-admin-pdf-editor' => 'build/admin-pdf-editor.js',
		);
		foreach ($expected_sources as $handle => $source) {
			$this->assertArrayHasKey($handle, $scripts->registered);
			$chunk = $scripts->registered[$handle];
			$this->assertStringEndsWith($source, $chunk->src);
			$this->assertSame('paper-to-quiz', $chunk->textdomain);
		}
	}
}
