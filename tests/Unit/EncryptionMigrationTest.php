<?php
/**
 * Synthetic PTQ1/PTQ2 format and bounded migration coverage.
 *
 * The fixtures are disposable and use a deterministic test-only key provider;
 * no real participant values or production storage keys are used.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\EncryptionMigration;
use PaperToQuiz\Infrastructure\PrivateKeyProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class EncryptionMigrationTest extends TestCase {
	private const TEST_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

	private Database $db;
	private PrivateKeyProvider $provider;
	private Crypto $crypto;
	private EncryptedStorage $storage;
	private EncryptionMigration $migration;

	/** @var int[] */
	private array $attempt_ids = array();

	/** @var int[] */
	private array $asset_ids = array();

	/** @var string[] */
	private array $storage_keys = array();

	/** @var array<string,mixed> */
	private array $options_before = array();

	public function setUp(): void {
		parent::setUp();

		$this->db       = new Database();
		$this->provider = new PrivateKeyProvider(
			static fn (string $option, mixed $default = null): mixed => self::TEST_KEY,
			static fn (string $option, string $value): bool => false
		);
		$this->crypto    = new Crypto($this->provider);
		$this->storage   = new EncryptedStorage($this->provider);
		$this->migration = new EncryptionMigration($this->db, $this->crypto, $this->storage);
		foreach (array(
			EncryptionMigration::STATE_OPTION,
			EncryptionMigration::PARTICIPANT_CURSOR_OPTION,
			EncryptionMigration::ASSET_CURSOR_OPTION,
			EncryptionMigration::FAILURES_OPTION,
		) as $option) {
			$this->options_before[$option] = get_option($option, false);
		}
		$this->set_option(EncryptionMigration::STATE_OPTION, array('status' => 'pending', 'processed' => 0, 'failed' => 0));
		$this->set_option(EncryptionMigration::PARTICIPANT_CURSOR_OPTION, 0);
		$this->set_option(EncryptionMigration::ASSET_CURSOR_OPTION, 0);
		$this->set_option(EncryptionMigration::FAILURES_OPTION, array('participant' => array(), 'asset' => array()));
	}

	public function tearDown(): void {
		$wpdb = $this->db->wpdb();
		foreach ($this->attempt_ids as $id) {
			$wpdb->delete($this->db->table('attempts'), array('id' => $id), array('%d'));
		}
		foreach ($this->asset_ids as $id) {
			$wpdb->delete($this->db->table('assets'), array('id' => $id), array('%d'));
		}
		foreach (array_unique($this->storage_keys) as $storage_key) {
			try {
				$this->storage->delete($storage_key);
			} catch (\Throwable) {
				// A deliberately corrupt path must never make test cleanup unsafe.
			}
		}
		foreach ($this->options_before as $option => $value) {
			if ($value === false) {
				delete_option($option);
			} else {
				update_option($option, $value, false);
			}
		}

		parent::tearDown();
	}

	public function test_v2_writes_and_legacy_reads_are_distinguishable(): void {
		$value = array('first_name' => 'Test', 'nested' => array('value' => 1));
		$v2    = $this->crypto->encrypt_array($value);
		$v1    = $this->legacy_participant($value);

		$this->assertStringStartsWith(Crypto::MAGIC, $v2);
		$this->assertSame($value, $this->crypto->decrypt_array($v2));
		$this->assertSame($value, $this->crypto->decrypt_array($v1));
		$this->assertSame($value, $this->crypto->decrypt_legacy_array($v1));
		$this->assertSame($value, $this->crypto->decrypt_array(Crypto::LEGACY_MAGIC . $v1));
	}

	public function test_unknown_and_tampered_markers_fail_closed(): void {
		$encoded = $this->crypto->encrypt_array(array('value' => 1));
		$this->assertSame(array(), $this->crypto->decrypt_array('PTQ3:' . substr($encoded, strlen(Crypto::MAGIC))));
		$this->assertSame(array(), $this->crypto->decrypt_array('PTQ2:not-base64'));
		$legacy = $this->legacy_participant_with_iv(array('value' => 'ptq-prefix'), hex2bin('3d3400000000000000000000'));
		$this->assertStringStartsWith('PTQ', $legacy);
		$this->assertSame(array('value' => 'ptq-prefix'), $this->crypto->decrypt_array($legacy));
	}

	public function test_v2_domain_separation_does_not_reuse_participant_key_for_files(): void {
		$crypto_method = new \ReflectionMethod($this->crypto, 'v2_key');
		$crypto_method->setAccessible(true);
		$storage_method = new \ReflectionMethod($this->storage, 'derive_v2_key');
		$storage_method->setAccessible(true);

		$this->assertNotSame(
			$crypto_method->invoke($this->crypto),
			$storage_method->invoke($this->storage, 'source_pdf')
		);
	}

	public function test_v2_participant_data_survives_a_simulated_wordpress_salt_change(): void {
		$value = array('value' => 'stable');
		$v2    = $this->crypto->encrypt_array($value);
		$v1    = $this->legacy_participant($value);
		$filter = static function (string $salt, string $scheme): string {
			return 'secure_auth' === $scheme ? $salt . '-changed' : $salt;
		};
		add_filter('salt', $filter, 10, 2);
		try {
			$this->assertSame($value, $this->crypto->decrypt_array($v2));
			$this->assertSame(array(), $this->crypto->decrypt_array($v1));
		} finally {
			remove_filter('salt', $filter, 10);
		}
	}

	public function test_combine_reencrypts_legacy_chunks_as_ptq2(): void {
		$first  = $this->write_legacy_file("first\n", 'source_pdf');
		$second = $this->write_legacy_file("second\n", 'source_pdf');
		$stored = $this->storage->combine(array($first, $second));
		$this->storage_keys[] = (string) $stored['storage_key'];

		$this->assertSame("first\nsecond\n", $this->storage->get_contents($stored['storage_key'], 'source_pdf'));
		$this->assertStringStartsWith(EncryptedStorage::MAGIC, $this->read_magic($stored['storage_key']));
	}

	public function test_migration_rewrites_participant_and_streams_a_large_asset(): void {
		$participant_id = $this->insert_attempt($this->legacy_participant(array('email' => 'synthetic@example.test')));
		$plain         = str_repeat("%PDF-synthetic\n", 90000);
		$legacy_key    = $this->write_legacy_file($plain, 'question_image');
		$asset_id      = $this->insert_asset($legacy_key, 'question_image', $plain);

		$status = $this->migration->run();
		$row    = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare('SELECT participant_data FROM ' . $this->db->table('attempts') . ' WHERE id = %d', $participant_id),
			ARRAY_A
		);
		$asset = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare('SELECT storage_key,byte_size,sha256 FROM ' . $this->db->table('assets') . ' WHERE id = %d', $asset_id),
			ARRAY_A
		);

		$this->assertSame('complete', $status['status']);
		$this->assertIsArray($row);
		$this->assertStringStartsWith(Crypto::MAGIC, (string) $row['participant_data']);
		$this->assertSame(array('email' => 'synthetic@example.test'), $this->crypto->decrypt_array((string) $row['participant_data']));
		$this->assertIsArray($asset);
		$this->assertNotSame($legacy_key, $asset['storage_key']);
		$this->assertStringStartsWith(EncryptedStorage::MAGIC, $this->read_magic((string) $asset['storage_key']));
		$this->assertSame($plain, $this->storage->get_contents((string) $asset['storage_key'], 'question_image'));
		$this->assertSame(strlen($plain), (int) $asset['byte_size']);
		$this->assertSame(hash('sha256', $plain), $asset['sha256']);
		$this->assertFalse($this->storage->exists($legacy_key));
	}

	public function test_failed_row_is_recorded_and_retried_without_advancing_its_data(): void {
		$bad_id  = $this->insert_attempt('PTQ3corrupt');
		$good_id = $this->insert_attempt($this->legacy_participant(array('value' => 'ok')));

		$first = $this->migration->run();
		$this->assertSame('pending', $first['status']);
		$this->assertSame(1, $first['failures']);
		$this->assertSame('PTQ3corrupt', $this->participant($bad_id));
		$this->assertStringStartsWith(Crypto::MAGIC, (string) $this->participant($good_id));

		$this->db->wpdb()->update(
			$this->db->table('attempts'),
			array('participant_data' => $this->legacy_participant(array('value' => 'fixed'))),
			array('id' => $bad_id),
			array('%s'),
			array('%d')
		);
		$second = $this->migration->run();
		$this->assertSame('complete', $second['status']);
		$this->assertSame(0, $second['failures']);
		$this->assertSame(array('value' => 'fixed'), $this->crypto->decrypt_array((string) $this->participant($bad_id)));
	}

	public function test_persistent_failures_reserve_fresh_work_after_a_full_corrupt_batch(): void {
		for ($index = 0; $index < EncryptionMigration::PARTICIPANT_BATCH_SIZE; ++$index) {
			$this->insert_attempt('PTQ3:corrupt-' . $index);
		}
		$good = $this->legacy_participant(array('value' => 'fresh'));
		$good_id = $this->insert_attempt($good);

		$first = $this->migration->run();
		$this->assertSame('pending', $first['status']);
		$this->assertSame(EncryptionMigration::PARTICIPANT_BATCH_SIZE, $first['failures']);
		$this->assertSame($good, $this->participant($good_id));

		$second = $this->migration->run();
		$this->assertSame('pending', $second['status'], 'Failures remain pending but must not block fresh work.');
		$this->assertSame(EncryptionMigration::PARTICIPANT_BATCH_SIZE, $second['failures']);
		$this->assertSame(array('value' => 'fresh'), $this->crypto->decrypt_array((string) $this->participant($good_id)));
		$this->assertSame($good_id, (int) get_option(EncryptionMigration::PARTICIPANT_CURSOR_OPTION, 0));
	}

	public function test_corrupt_asset_remains_ptq1_and_is_reported_without_plaintext_details(): void {
		$plain      = "legacy asset\n";
		$legacy_key = $this->write_legacy_file($plain, 'question_image');
		$path       = $this->storage->base_directory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $legacy_key);
		$contents   = file_get_contents($path);
		$this->assertIsString($contents);
		$contents[-1] = $contents[-1] === "A" ? "B" : "A";
		file_put_contents($path, $contents, LOCK_EX);
		$asset_id = $this->insert_asset($legacy_key, 'question_image', $plain);

		$status = $this->migration->run();
		$this->assertSame('pending', $status['status']);
		$this->assertSame(1, $status['failures']);
		$this->assertSame($legacy_key, $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT storage_key FROM ' . $this->db->table('assets') . ' WHERE id = %d', $asset_id)
		));
	}

	public function test_fresh_install_state_is_complete_and_repeated_run_is_idempotent(): void {
		delete_option(EncryptionMigration::STATE_OPTION);
		$this->migration->initialize(true);
		$this->assertSame('complete', $this->migration->status()['status']);
		$this->assertSame('complete', $this->migration->run()['status']);
	}

	private function legacy_participant(array $value): string {
		return $this->legacy_participant_with_iv($value, random_bytes(12));
	}

	private function legacy_participant_with_iv(array $value, string $iv): string {
		$plain  = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$tag    = '';
		$key    = hash_hkdf('sha256', base64_decode(self::TEST_KEY, true), 32, 'ptq:participant', wp_salt('secure_auth'));
		$cipher = openssl_encrypt((string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		return base64_encode($iv . $tag . (string) $cipher);
	}

	private function write_legacy_file(string $plain, string $purpose): string {
		$this->storage->ensure_base_directory();
		$key         = gmdate('Y/m/') . 'synthetic-' . wp_generate_uuid4() . '.ptq';
		$path        = $this->storage->base_directory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
		wp_mkdir_p(dirname($path));
		$legacy_key  = hash_hkdf('sha256', base64_decode(self::TEST_KEY, true), 32, 'ptq:' . $purpose, wp_salt('auth'));
		$handle      = fopen($path, 'wb');
		$this->assertIsResource($handle);
		fwrite($handle, EncryptedStorage::LEGACY_MAGIC);
		for ($offset = 0; $offset < strlen($plain); $offset += EncryptedStorage::CHUNK_SIZE) {
			$chunk = substr($plain, $offset, EncryptedStorage::CHUNK_SIZE);
			$iv    = random_bytes(12);
			$tag   = '';
			$cipher = openssl_encrypt($chunk, 'aes-256-gcm', $legacy_key, OPENSSL_RAW_DATA, $iv, $tag);
			fwrite($handle, $iv . $tag . pack('N', strlen((string) $cipher)) . (string) $cipher);
		}
		fclose($handle);
		$this->storage_keys[] = $key;
		return $key;
	}

	private function insert_attempt(string $participant_data): int {
		$now = gmdate('Y-m-d H:i:s');
		$this->db->wpdb()->insert(
			$this->db->table('attempts'),
			array(
				'public_id'        => wp_generate_uuid4(),
				'token_hash'       => hash('sha256', wp_generate_uuid4()),
				'assessment_id'    => 1,
				'revision_id'      => 1,
				'participant_type' => 'guest',
				'participant_data' => $participant_data,
				'status'           => 'submitted',
				'submission_id'    => wp_generate_uuid4(),
				'started_at'      => $now,
				'last_activity_at' => $now,
				'submitted_at'    => $now,
			)
		);
		$id = (int) $this->db->wpdb()->insert_id;
		$this->attempt_ids[] = $id;
		$this->assertGreaterThan(0, $id);
		return $id;
	}

	private function insert_asset(string $storage_key, string $type, string $plain): int {
		$this->db->wpdb()->insert(
			$this->db->table('assets'),
			array(
				'type'        => $type,
				'storage_key' => $storage_key,
				'mime'        => 'image/png',
				'byte_size'   => strlen($plain),
				'sha256'      => hash('sha256', $plain),
				'ref_count'   => 1,
				'created_at'  => gmdate('Y-m-d H:i:s'),
			)
		);
		$id = (int) $this->db->wpdb()->insert_id;
		$this->asset_ids[] = $id;
		$this->assertGreaterThan(0, $id);
		return $id;
	}

	private function participant(int $id): ?string {
		$value = $this->db->wpdb()->get_var(
			$this->db->wpdb()->prepare('SELECT participant_data FROM ' . $this->db->table('attempts') . ' WHERE id = %d', $id)
		);
		return is_string($value) ? $value : null;
	}

	private function read_magic(string $storage_key): string {
		$path = $this->storage->base_directory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storage_key);
		$handle = fopen($path, 'rb');
		$this->assertIsResource($handle);
		$magic = fread($handle, 4);
		fclose($handle);
		return (string) $magic;
	}

	private function set_option(string $name, mixed $value): void {
		delete_option($name);
		add_option($name, $value, '', false);
	}
}
