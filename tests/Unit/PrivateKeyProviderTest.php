<?php
/**
 * Unit tests for PaperToQuiz\Infrastructure\PrivateKeyProvider.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Infrastructure\Crypto;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\PrivateKeyProvider;
use PaperToQuiz\Infrastructure\StorageException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PrivateKeyProviderTest extends TestCase {
	public function test_existing_option_is_decoded_and_returned(): void {
		$expected = random_bytes(32);
		$encoded = base64_encode($expected);
		$provider = $this->provider_returning($encoded);

		$actual = $provider->get_key();

		$this->assertSame(hash('sha256', $expected), hash('sha256', $actual));
	}

	public function test_missing_option_is_generated_persisted_and_reused(): void {
		$persisted  = null;
		$write_count = 0;
		$provider   = new PrivateKeyProvider(
			static function (string $option, mixed $default = null) use (&$persisted): mixed {
				return $persisted ?? $default;
			},
			static function (string $option, string $value) use (&$persisted, &$write_count): bool {
				++$write_count;
				$persisted = $value;
				return true;
			}
		);

		$first  = $provider->get_key();
		$second = $provider->get_key();

		$this->assertSame(1, $write_count);
		$this->assertIsString($persisted);
		$decoded = base64_decode((string) $persisted, true);
		$this->assertIsString($decoded);
		$this->assertSame(hash('sha256', $first), hash('sha256', $second));
		$this->assertSame(hash('sha256', $first), hash('sha256', $decoded));
	}

	public function test_losing_insert_uses_the_persisted_winner(): void {
		$winner      = random_bytes(32);
		$persisted   = null;
		$write_count = 0;
		$provider    = new PrivateKeyProvider(
			static function (string $option, mixed $default = null) use (&$persisted): mixed {
				return $persisted ?? $default;
			},
			static function (string $option, string $candidate) use (&$persisted, &$write_count, $winner): bool {
				++$write_count;
				$persisted = base64_encode($winner);
				return false;
			}
		);

		$actual = $provider->get_key();

		$this->assertSame(1, $write_count);
		$this->assertSame(hash('sha256', $winner), hash('sha256', $actual));
		$this->assertIsString($persisted);
		$persisted_key = base64_decode((string) $persisted, true);
		$this->assertIsString($persisted_key);
		$this->assertSame(hash('sha256', $winner), hash('sha256', $persisted_key));
	}

	public function test_invalid_base64_fails_without_disclosing_option_value(): void {
		$invalid = 'not-a-valid-base64-key';
		$provider = $this->provider_returning($invalid);

		try {
			$provider->get_key();
			$this->fail('Expected an invalid private storage key to throw.');
		} catch (StorageException $exception) {
			$this->assertSame(
				'The private storage key is corrupt. Restore it from backup or rotate it in wp-config.php.',
				$exception->user_message
			);
			$this->assertStringNotContainsString($invalid, $exception->getMessage());
		}
	}

	public function test_wrong_length_fails_without_disclosing_option_value(): void {
		$encoded  = base64_encode(random_bytes(31));
		$provider = $this->provider_returning($encoded);

		try {
			$provider->get_key();
			$this->fail('Expected a wrong-length private storage key to throw.');
		} catch (StorageException $exception) {
			$this->assertSame(
				'The private storage key is corrupt. Restore it from backup or rotate it in wp-config.php.',
				$exception->user_message
			);
			$this->assertStringNotContainsString($encoded, $exception->getMessage());
		}
	}

	public function test_encrypted_storage_round_trip_uses_injected_provider(): void {
		$provider = $this->provider_returning(base64_encode(random_bytes(32)));
		$crypto   = new Crypto($provider);
		$storage  = new EncryptedStorage($provider);
		$contents = 'encrypted file round-trip fixture';
		$stored   = $storage->put_string($contents, 'source_pdf');

		try {
			$payload = array('contents' => $contents);
			$this->assertSame($payload, $crypto->decrypt_array($crypto->encrypt_array($payload)));
			$this->assertSame(strlen($contents), $stored['byte_size']);
			$this->assertSame(hash('sha256', $contents), $stored['sha256']);
			$this->assertSame($contents, $storage->get_contents($stored['storage_key'], 'source_pdf'));
		} finally {
			$storage->delete($stored['storage_key']);
		}
	}

	private function provider_returning(mixed $value): PrivateKeyProvider {
		return new PrivateKeyProvider(
			static function (string $option, mixed $default = null) use ($value): mixed {
				return $value;
			},
			static function (string $option, string $candidate): bool {
				return false;
			}
		);
	}
}
