<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

// Exception messages are escaped by their eventual presentation layer.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class Crypto {
	public function encrypt_array(array $value): string {
		$plain = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (! is_string($plain)) {
			throw new \RuntimeException(__('Personal data could not be encoded.', 'paper-to-quiz'));
		}

		$iv     = random_bytes(12);
		$tag    = '';
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($cipher === false) {
			throw new \RuntimeException(__('Personal data could not be encrypted.', 'paper-to-quiz'));
		}

		return base64_encode($iv . $tag . $cipher);
	}

	public function decrypt_array(?string $encoded): array {
		if (! $encoded) {
			return array();
		}

		$payload = base64_decode($encoded, true);
		if ($payload === false || strlen($payload) < 29) {
			return array();
		}

		$iv     = substr($payload, 0, 12);
		$tag    = substr($payload, 12, 16);
		$cipher = substr($payload, 28);
		$plain  = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($plain === false) {
			return array();
		}

		$decoded = json_decode($plain, true);
		return is_array($decoded) ? $decoded : array();
	}

	private function key(): string {
		$master = defined('PTQ_PRIVATE_STORAGE_KEY') ? (string) PTQ_PRIVATE_STORAGE_KEY : '';
		if ($master === '') {
			$encoded = (string) get_option('ptq_storage_key', '');
			if ($encoded === '') {
				$encoded = base64_encode(random_bytes(32));
				add_option('ptq_storage_key', $encoded, '', false);
			}
			$decoded = base64_decode($encoded, true);
			if ($decoded === false) {
				throw new StorageException(
					'The private storage key is corrupt.',
					__('The private storage key is corrupt. Restore it from backup or rotate it in wp-config.php.', 'paper-to-quiz')
				);
			}
			$master = $decoded;
		}

		return hash_hkdf('sha256', $master, 32, 'ptq:participant', wp_salt('secure_auth'));
	}
}
