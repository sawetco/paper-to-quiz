<?php
/** Real-connection transaction and ownership regression tests. */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\DatabaseException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class DatabaseTest extends TestCase {
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
