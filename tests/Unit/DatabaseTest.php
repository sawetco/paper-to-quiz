<?php
/** Real-connection transaction and ownership regression tests. */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\DatabaseException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class DatabaseTest extends TestCase {
	public function test_sqlite_debug_query_errors_do_not_leak_into_response_output(): void {
		$db = new Database();
		if ($db->engine() !== 'sqlite') {
			$this->markTestSkipped('SQLite-only error-output boundary.');
		}
		$wpdb = $db->wpdb();
		$previous_show = $wpdb->show_errors;
		$previous_suppression = $wpdb->suppress_errors(false);
		$wpdb->show_errors = true;
		ob_start();
		try {
			$result = $db->bounded(fn () => $wpdb->query('SELECT * FROM paper_to_quiz_missing_debug_fixture'));
			$output = ob_get_contents();
			$this->assertFalse($result);
			$this->assertSame('', $output);
			$this->assertFalse($wpdb->suppress_errors);
		} finally {
			ob_end_clean();
			$wpdb->show_errors = $previous_show;
			$wpdb->suppress_errors($previous_suppression);
			$wpdb->flush();
		}
	}

	public function test_real_sqlite_busy_commit_rolls_back_without_replaying(): void {
		$db = new Database();
		if ($db->engine() !== 'sqlite') {
			$this->markTestSkipped('Requires a real SQLite commit failure.');
		}
		$wpdb = $db->wpdb();
		$driver = $wpdb->get_driver();
		$pdo = $driver->get_sqlite_pdo();
		$journal_mode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
		$key = 'ptq-busy-commit-' . wp_generate_uuid4();
		$reader = null;
		try {
			// A reader in rollback-journal mode permits BEGIN IMMEDIATE and writes,
			// but prevents the writer's COMMIT from obtaining its exclusive lock.
			$this->assertSame('delete', $pdo->query('PRAGMA journal_mode=DELETE')->fetchColumn());
			$reader = new \PDO('sqlite:' . DB_PATH);
			$reader->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$reader->beginTransaction();
			$reader->query('SELECT option_name FROM ' . $wpdb->options . ' LIMIT 1')->fetchAll();
			$db->begin();
			$this->assertSame(1, $wpdb->insert($wpdb->options, array('option_name' => $key, 'option_value' => 'uncommitted', 'autoload' => 'off')));
			$caught = false;
			try {
				$db->commit();
			} catch (DatabaseException $error) {
				$caught = $error->retryable;
			}
			$this->assertTrue($caught, 'The actual SQLite COMMIT must fail with SQLITE_BUSY.');
			$db->rollback();
			$reader->rollBack();
			$reader = null;
			$this->assertFalse($driver->inTransaction());
			$this->assertNull($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $key)));
		} finally {
			$db->rollback();
			if ($reader && $reader->inTransaction()) {
				$reader->rollBack();
			}
			$reader = null;
			$pdo->query('PRAGMA journal_mode=' . $journal_mode)->fetchColumn();
			$wpdb->delete($wpdb->options, array('option_name' => $key));
		}
	}

	public function test_failed_commit_keeps_writes_rollbackable(): void {
		$db = new Database(null, static fn (string $operation, callable $write) => $operation === 'transaction_commit' ? false : $write());
		$key = 'ptq-transaction-' . wp_generate_uuid4();
		$wpdb = $db->wpdb();
		try {
			$db->begin();
			$this->assertSame(1, $wpdb->insert($wpdb->options, array('option_name' => $key, 'option_value' => 'uncommitted', 'autoload' => 'off')));
			try {
				$db->commit();
				$this->fail('A failed commit must throw.');
			} catch (DatabaseException) {
				$db->rollback();
			}
			$this->assertNull($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $key)));
		} finally {
			$db->rollback();
			$wpdb->delete($wpdb->options, array('option_name' => $key));
		}
	}

	public function test_failed_begin_does_not_open_a_transaction(): void {
		$db = new Database(null, static fn (string $operation, callable $write) => $operation === 'transaction_begin' ? false : $write());
		try {
			$db->begin();
			$this->fail('A failed begin must throw.');
		} catch (DatabaseException $error) {
			$this->assertFalse($error->retryable);
			$db->rollback();
		}
		$healthy = new Database();
		$healthy->begin();
		$healthy->rollback();
	}

	public function test_stale_released_handle_cannot_release_new_owner(): void {
		$db = new Database();
		$name = 'ptq-test-' . wp_generate_uuid4();
		$first = $db->acquire_lock($name);
		$this->assertNotNull($first);
		$first->release();
		$next = $db->acquire_lock($name);
		$this->assertNotNull($next);
		try {
			$first->release();
			if ($db->engine() === 'sqlite') {
				$this->assertNull((new Database())->acquire_lock($name));
			} else {
				$this->assertSame('1', (string) $db->wpdb()->get_var($db->wpdb()->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name)));
			}
		} finally {
			$next->release();
		}
	}

	public function test_sqlite_timeout_restored_after_commit_rollback_and_failed_begin(): void {
		$db = new Database();
		if ($db->engine() !== 'sqlite') {
			$this->markTestSkipped('SQLite-only connection configuration.');
		}
		$pdo = $db->wpdb()->get_driver()->get_sqlite_pdo();
		$initial = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
		foreach (array('commit', 'rollback') as $finish) {
			$db->begin();
			$this->assertLessThanOrEqual(100, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
			$db->$finish();
			$this->assertSame($initial, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
		}
		$failed = new Database(null, static fn () => false);
		try {
			$failed->begin();
		} catch (DatabaseException) {
			$this->assertSame($initial, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
		}
	}
}
