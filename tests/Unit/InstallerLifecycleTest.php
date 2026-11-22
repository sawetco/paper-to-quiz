<?php
/**
 * Cron lifecycle regression tests.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Infrastructure\Cleanup;
use PaperToQuiz\Infrastructure\Installer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class InstallerLifecycleTest extends TestCase {

	public function test_deactivation_removes_scheduled_events_with_arguments(): void {
		$previous = get_option('cron');
		$time = time() + HOUR_IN_SECONDS;
		$email_hook = 'paper_to_quiz_process_result_emails';
		$email_args = array('queue');
		$cleanup_args = array(array('attempt' => array('id' => 21)));
		$unrelated_hook = 'paper_to_quiz_test_unrelated_event';

		try {
			$this->assertTrue(wp_schedule_single_event($time, $email_hook, $email_args));
			$this->assertTrue(wp_schedule_single_event($time, Cleanup::CONTINUATION_HOOK, $cleanup_args));
			$this->assertTrue(wp_schedule_single_event($time, $unrelated_hook));

			Installer::deactivate();

			$this->assertFalse(wp_next_scheduled($email_hook, $email_args));
			$this->assertFalse(wp_next_scheduled(Cleanup::CONTINUATION_HOOK, $cleanup_args));
			$this->assertSame($time, wp_next_scheduled($unrelated_hook));
		} finally {
			update_option('cron', $previous);
		}
	}
}
