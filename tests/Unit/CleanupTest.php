<?php
/**
 * Bounded continuation and retry tests for private cleanup.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Infrastructure\Cleanup;
use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class CleanupTest extends TestCase {
	private Database $db;
	private EncryptedStorage $storage;
	private AttemptService $attempts;
	/** @var string[] */
	private array $session_ids = array();
	/** @var string[] */
	private array $storage_keys = array();

	public function setUp(): void {
		parent::setUp();
		$this->db      = new Database();
		$this->storage = new EncryptedStorage();
		$assets        = new AssetService($this->db, $this->storage);
		$this->attempts = new AttemptService(
			$this->db,
			new AssessmentService($this->db, $assets),
			new Crypto()
		);
		wp_clear_scheduled_hook(Cleanup::CONTINUATION_HOOK);
	}

	public function tearDown(): void {
		wp_clear_scheduled_hook(Cleanup::CONTINUATION_HOOK);
		foreach ($this->session_ids as $session_id) {
			$this->db->wpdb()->delete($this->db->table('upload_sessions'), array('id' => $session_id), array('%s'));
		}
		foreach ($this->storage_keys as $storage_key) {
			$this->storage->delete($storage_key);
		}
		parent::tearDown();
	}

	public function test_full_upload_batch_schedules_one_continuation_and_drains_remainder(): void {
		for ($index = 0; $index < 51; ++$index) {
			$this->seed_session(gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS - $index));
		}
		$cleanup = new Cleanup($this->db, $this->storage, $this->attempts);

		$cleanup->run();
		$this->assertSame(50, $this->status_count('expired'));
		$this->assertSame(1, $this->status_count('pending'));
		$events = $this->continuation_events();
		$this->assertCount(1, $events);

		wp_unschedule_event($events[0]['timestamp'], Cleanup::CONTINUATION_HOOK, $events[0]['args']);
		$cleanup->run($events[0]['args'][0]);
		$this->assertSame(51, $this->status_count('expired'));
		$this->assertSame(0, $this->status_count('pending'));
		$this->assertCount(0, $this->continuation_events());
	}

	public function test_storage_failure_stays_pending_without_blocking_a_later_session(): void {
		$failed  = $this->seed_session(gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS));
		$healthy = $this->seed_session(gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));
		$failed_key = $this->storage_key($failed);
		$cleanup = new Cleanup(
			$this->db,
			$this->storage,
			$this->attempts,
			fn (string $storage_key): bool => $storage_key === $failed_key
				? false
				: $this->storage->delete($storage_key)
		);

		$cleanup->run();
		$this->assertSame('pending', $this->session_status($failed));
		$this->assertSame('expired', $this->session_status($healthy));
		$this->assertTrue($this->storage->exists($failed_key));
	}

	public function test_database_failure_leaves_the_session_retryable(): void {
		$session_id = $this->seed_session(gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));
		$db = new Database(
			$this->db->wpdb(),
			static fn (string $operation, callable $write): mixed => 'upload_session_expire' === $operation ? false : $write()
		);
		$assets   = new AssetService($db, $this->storage);
		$attempts = new AttemptService($db, new AssessmentService($db, $assets), new Crypto());

		(new Cleanup($db, $this->storage, $attempts))->run();
		$this->assertSame('pending', $this->session_status($session_id));
	}

	private function seed_session(string $expires_at): string {
		$stored = $this->storage->put_string('cleanup-' . wp_generate_uuid4(), 'upload_chunk');
		$this->storage_keys[] = (string) $stored['storage_key'];
		$id = wp_generate_uuid4();
		$this->session_ids[] = $id;
		$inserted = $this->db->wpdb()->insert(
			$this->db->table('upload_sessions'),
			array(
				'id'            => $id,
				'owner_user_id' => 1,
				'original_name' => 'synthetic.pdf',
				'expected_size' => 10,
				'received_size' => 10,
				'chunk_count'   => 1,
				'status'        => 'pending',
				'manifest_json' => wp_json_encode(array(array('storage_key' => $stored['storage_key']))),
				'expires_at'    => $expires_at,
				'created_at'    => current_time('mysql', true),
			)
		);
		$this->assertSame(1, $inserted);
		return $id;
	}

	private function session_status(string $session_id): string {
		return (string) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT status FROM ' . $this->db->table('upload_sessions') . ' WHERE id = %s', $session_id)
		);
	}

	private function status_count(string $status): int {
		if (! $this->session_ids) {
			return 0;
		}
		$placeholders = implode(',', array_fill(0, count($this->session_ids), '%s'));
		return (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare(
				'SELECT COUNT(*) FROM ' . $this->db->table('upload_sessions') . " WHERE id IN ({$placeholders}) AND status = %s",
				...array_merge($this->session_ids, array($status))
			)
		);
	}

	private function storage_key(string $session_id): string {
		$manifest = json_decode(
			(string) $this->db->wpdb()->get_var(
				$this->db->wpdb()->prepare('SELECT manifest_json FROM ' . $this->db->table('upload_sessions') . ' WHERE id = %s', $session_id)
			),
			true
		);
		return (string) $manifest[0]['storage_key'];
	}

	/** @return array<int,array{timestamp:int,args:array<int,mixed>}> */
	private function continuation_events(): array {
		$events = array();
		foreach (_get_cron_array() as $timestamp => $hooks) {
			foreach (($hooks[Cleanup::CONTINUATION_HOOK] ?? array()) as $event) {
				$events[] = array('timestamp' => (int) $timestamp, 'args' => $event['args']);
			}
		}
		return $events;
	}
}
