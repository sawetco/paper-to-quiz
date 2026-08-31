<?php
/**
 * Concurrency and failure-path tests for asset reference accounting.
 *
 * The two Database instances in the interleaving tests share the disposable
 * wp-env connection and use the named write seam to schedule the competing
 * operation at the conditional write boundary.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\StorageException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class AssetServiceTest extends TestCase {

	private Database $db;
	private EncryptedStorage $storage;

	/** @var int[] */
	private array $asset_ids = array();

	/** @var string[] */
	private array $storage_keys = array();

	public function setUp(): void {
		parent::setUp();

		$this->db      = new Database();
		$this->storage = new EncryptedStorage();
	}

	public function tearDown(): void {
		$wpdb = $this->db->wpdb();
		foreach (array_values(array_unique($this->asset_ids)) as $asset_id) {
			$wpdb->delete($this->db->table('assets'), array('id' => $asset_id), array('%d'));
		}
		foreach (array_values(array_unique($this->storage_keys)) as $storage_key) {
			try {
				$this->storage->delete($storage_key);
			} catch (\Throwable) {
				// Invalid-key fixtures deliberately exercise the safe cleanup path.
			}
		}

		parent::tearDown();
	}

	public function test_retain_increments_a_live_row_and_rejects_missing_or_failed_writes(): void {
		$asset_id = $this->create_asset();
		$assets   = new AssetService($this->db, $this->storage);

		$assets->retain($asset_id);
		$this->assertSame(2, $this->ref_count($asset_id));

		try {
			$assets->retain(999999999);
			$this->fail('A missing asset must not be retained silently.');
		} catch (StorageException $exception) {
			$this->assertSame(
				'The file reference could not be found. Please refresh and try again.',
				$exception->user_message
			);
		}

		$db = new Database(
			$this->db->wpdb(),
			static function (string $operation, callable $write): mixed {
				return 'asset_retain' === $operation ? false : $write();
			}
		);
		try {
			(new AssetService($db, $this->storage))->retain($asset_id);
			$this->fail('A failed retain write must throw a safe exception.');
		} catch (StorageException $exception) {
			$this->assertSame('The file reference could not be updated. Please try again.', $exception->user_message);
			$this->assertStringNotContainsString($this->db->table('assets'), $exception->getMessage());
		}
		$this->assertSame(2, $this->ref_count($asset_id));
	}

	public function test_release_decrements_without_deleting_a_live_file(): void {
		$asset_id = $this->create_asset(2);
		$assets   = new AssetService($this->db, $this->storage);
		$key      = (string) $assets->get($asset_id)['storage_key'];

		$assets->release($asset_id);

		$this->assertSame(1, $this->ref_count($asset_id));
		$this->assertTrue($this->storage->exists($key));
	}

	public function test_two_releases_remove_the_row_and_file_without_underflow(): void {
		$asset_id = $this->create_asset();
		$assets   = new AssetService($this->db, $this->storage);
		$key      = (string) $assets->get($asset_id)['storage_key'];

		$assets->release($asset_id);
		$assets->release($asset_id);

		$this->assertNull($assets->get($asset_id));
		$this->assertFalse($this->storage->exists($key));
		$this->assertSame(0, $this->ref_count($asset_id));
	}

	public function test_retain_racing_with_final_release_keeps_the_live_row_and_file(): void {
		$asset_id = $this->create_asset();
		$db_two   = new Database($this->db->wpdb());
		$assets_two = new AssetService($db_two, $this->storage);
		$raced      = false;
		$db_one     = new Database(
			$this->db->wpdb(),
			static function (string $operation, callable $write) use (&$raced, $assets_two, $asset_id): mixed {
				if ('asset_release_delete' === $operation && ! $raced) {
					$raced = true;
					$assets_two->retain($asset_id);
				}
				return $write();
			}
		);
		$assets_one = new AssetService($db_one, $this->storage);
		$key        = (string) $assets_one->get($asset_id)['storage_key'];

		$assets_one->release($asset_id);

		$this->assertTrue($raced, 'The retain/release interleaving was not exercised.');
		$this->assertSame(1, $this->ref_count($asset_id));
		$this->assertTrue($this->storage->exists($key));
	}

	public function test_two_releases_racing_for_the_last_references_remove_row_and_file_once(): void {
		$asset_id = $this->create_asset(2);
		$db_two   = new Database($this->db->wpdb());
		$assets_two = new AssetService($db_two, $this->storage);
		$raced      = false;
		$db_one     = new Database(
			$this->db->wpdb(),
			static function (string $operation, callable $write) use (&$raced, $assets_two, $asset_id): mixed {
				if ('asset_release_decrement' === $operation && ! $raced) {
					$raced = true;
					$assets_two->release($asset_id);
				}
				return $write();
			}
		);
		$assets_one = new AssetService($db_one, $this->storage);
		$key        = (string) $assets_one->get($asset_id)['storage_key'];

		$assets_one->release($asset_id);

		$this->assertTrue($raced, 'The two-release interleaving was not exercised.');
		$this->assertNull($assets_one->get($asset_id));
		$this->assertFalse($this->storage->exists($key));
		$this->assertSame(0, $this->ref_count($asset_id));
	}

	public function test_release_database_failures_leave_the_live_row_and_file(): void {
		$asset_id = $this->create_asset();
		$assets   = new AssetService(
			new Database(
				$this->db->wpdb(),
				static function (string $operation, callable $write): mixed {
					return in_array($operation, array('asset_release_decrement', 'asset_release_delete'), true) ? false : $write();
				}
			),
			$this->storage
		);
		$key = (string) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT storage_key FROM ' . $this->db->table('assets') . ' WHERE id=%d', $asset_id)
		);

		try {
			$assets->release($asset_id);
			$this->fail('A failed decrement write must throw.');
		} catch (StorageException $exception) {
			$this->assertSame('The file reference could not be updated. Please try again.', $exception->user_message);
		}
		$this->assertSame(1, $this->ref_count($asset_id));
		$this->assertTrue($this->storage->exists($key));

		$db = new Database(
			$this->db->wpdb(),
			static function (string $operation, callable $write): mixed {
				return 'asset_release_delete' === $operation ? false : $write();
			}
		);
		try {
			(new AssetService($db, $this->storage))->release($asset_id);
			$this->fail('A failed final-delete write must throw.');
		} catch (StorageException $exception) {
			$this->assertSame('The file reference could not be updated. Please try again.', $exception->user_message);
		}
		$this->assertSame(1, $this->ref_count($asset_id));
		$this->assertTrue($this->storage->exists($key));
	}

	public function test_storage_failure_removes_database_row_before_reporting_safe_error(): void {
		$asset_id = $this->insert_asset('../invalid-storage-key');
		$assets   = new AssetService($this->db, $this->storage);

		try {
			$assets->release($asset_id);
			$this->fail('A storage deletion failure must throw a safe exception.');
		} catch (StorageException $exception) {
			$this->assertSame('The file could not be removed. Please try again.', $exception->user_message);
			$this->assertStringNotContainsString('../invalid-storage-key', $exception->getMessage());
		}

		$this->assertNull($assets->get($asset_id), 'A failed storage cleanup must not leave a live database reference.');
	}

	private function create_asset(int $ref_count = 1): int {
		$stored = $this->storage->put_string(
			'asset reference fixture ' . wp_generate_uuid4(),
			'question_image'
		);
		$this->storage_keys[] = (string) $stored['storage_key'];
		$asset_id = $this->insert_asset((string) $stored['storage_key'], $ref_count, $stored);
		return $asset_id;
	}

	/**
	 * @param array<string,mixed> $stored
	 */
	private function insert_asset(string $storage_key, int $ref_count = 1, array $stored = array()): int {
		if ($stored) {
			$byte_size = (int) $stored['byte_size'];
			$sha256    = (string) $stored['sha256'];
		} else {
			$byte_size = 1;
			$sha256    = hash('sha256', $storage_key);
		}
		$inserted = $this->db->wpdb()->insert(
			$this->db->table('assets'),
			array(
				'type'        => 'question_image',
				'storage_key' => $storage_key,
				'mime'        => 'image/png',
				'byte_size'   => $byte_size,
				'sha256'      => $sha256,
				'width'       => null,
				'height'      => null,
				'ref_count'   => $ref_count,
				'created_at'  => gmdate('Y-m-d H:i:s'),
			),
			array('%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s')
		);
		$this->assertSame(1, $inserted, 'The asset fixture could not be inserted.');
		$asset_id = (int) $this->db->wpdb()->insert_id;
		$this->asset_ids[] = $asset_id;
		return $asset_id;
	}

	private function ref_count(int $asset_id): int {
		return (int) $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT ref_count FROM ' . $this->db->table('assets') . ' WHERE id=%d', $asset_id)
		);
	}
}
