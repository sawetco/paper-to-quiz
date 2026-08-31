<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

// Exception messages are escaped by their eventual presentation layer.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class Crypto {
	public const MAGIC         = 'PTQ2:';
	public const LEGACY_MAGIC  = 'PTQ1:';
	private const V2_INFO      = 'paper-to-quiz:v2:participant';
	private const LEGACY_INFO  = 'ptq:participant';

	private PrivateKeyProvider $key_provider;

	public function __construct(?PrivateKeyProvider $key_provider = null) {
		$this->key_provider = $key_provider ?? new PrivateKeyProvider();
	}

	public function encrypt_array(array $value): string {
		$plain = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (! is_string($plain)) {
			throw new \RuntimeException(__('Personal data could not be encoded.', 'paper-to-quiz'));
		}

		$iv     = random_bytes(12);
		$tag    = '';
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->v2_key(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($cipher === false) {
			throw new \RuntimeException(__('Personal data could not be encrypted.', 'paper-to-quiz'));
		}

		return self::MAGIC . base64_encode($iv . $tag . $cipher);
	}

	public function decrypt_array(?string $encoded): array {
		try {
			return $this->decrypt_array_strict($encoded);
		} catch (\Throwable) {
			// Participant data is deliberately fail-closed at every presentation
			// boundary. The migration worker uses the strict legacy method below so
			// it can retain an unreadable v1 value and retry it later.
			return array();
		}
	}

	/**
	 * Decrypt a participant value and throw on an invalid marker, ciphertext, or
	 * JSON value. Callers that render participant data should use decrypt_array().
	 *
	 * @throws \RuntimeException When the ciphertext cannot be authenticated.
	 */
	public function decrypt_array_strict(?string $encoded): array {
		if ($encoded === null || $encoded === '') {
			return array();
		}

		[$payload, $key] = $this->payload_and_key($encoded);
		return $this->decrypt_payload($payload, $key);
	}

	/**
	 * Decrypt only the pre-PTQ2 participant format for the migration worker.
	 *
	 * @throws \RuntimeException When the value is not a valid legacy payload.
	 */
	public function decrypt_legacy_array(?string $encoded): array {
		if ($encoded === null || $encoded === '') {
			return array();
		}

		[$marker, $payload] = $this->marker_and_payload($encoded);
		if ($marker === self::MAGIC) {
			throw new \RuntimeException('Participant value is already encrypted with PTQ2.');
		}
		if ($marker !== null) {
			if ($marker !== self::LEGACY_MAGIC) {
				throw new \RuntimeException('Participant value has an unknown encryption marker.');
			}
			$encoded = $payload;
		}

		$payload = base64_decode($encoded, true);
		if ($payload === false) {
			throw new \RuntimeException('Legacy participant value is not valid base64.');
		}

		return $this->decrypt_payload($payload, $this->legacy_key());
	}

	public function is_v2(?string $encoded): bool {
		return is_string($encoded) && str_starts_with($encoded, self::MAGIC);
	}

	private function payload_and_key(string $encoded): array {
		[$marker, $payload] = $this->marker_and_payload($encoded);
		if ($marker === self::MAGIC) {
			$encoded = $payload;
			$key     = $this->v2_key();
		} elseif ($marker !== null) {
			if ($marker !== self::LEGACY_MAGIC) {
				throw new \RuntimeException('Participant value has an unknown encryption marker.');
			}
			$encoded = $payload;
			$key     = $this->legacy_key();
		} else {
			$key = $this->legacy_key();
		}

		$payload = base64_decode($encoded, true);
		if ($payload === false) {
			throw new \RuntimeException('Participant value is not valid base64.');
		}

		return array($payload, (string) $key);
	}

	/**
	 * Split only an explicitly delimited participant marker. A colon cannot
	 * occur in base64, so an unprefixed legacy value beginning with PTQ remains
	 * a legacy value instead of being mistaken for an unknown version.
	 *
	 * @return array{0:?string,1:string}
	 */
	private function marker_and_payload(string $encoded): array {
		$colon = strpos($encoded, ':');
		if ($colon === false || $colon < 4 || ! str_starts_with($encoded, 'PTQ')) {
			return array(null, $encoded);
		}

		return array(substr($encoded, 0, $colon + 1), substr($encoded, $colon + 1));
	}

	private function decrypt_payload(string $payload, string $key): array {
		if (strlen($payload) < 29) {
			throw new \RuntimeException('Participant value is too short.');
		}

		$iv     = substr($payload, 0, 12);
		$tag    = substr($payload, 12, 16);
		$cipher = substr($payload, 28);
		$plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($plain === false) {
			throw new \RuntimeException('Participant value authentication failed.');
		}

		$decoded = json_decode($plain, true);
		if (! is_array($decoded)) {
			throw new \RuntimeException('Participant value is not valid JSON.');
		}

		return $decoded;
	}

	private function v2_key(): string {
		return hash_hkdf('sha256', $this->key_provider->get_key(), 32, self::V2_INFO);
	}

	/**
	 * Explicitly named legacy derivation. Existing participant ciphertexts use
	 * secure_auth as the HKDF salt and must remain readable after PTQ2 rollout.
	 */
	private function legacy_key(): string {
		return hash_hkdf('sha256', $this->key_provider->get_key(), 32, self::LEGACY_INFO, wp_salt('secure_auth'));
	}
}
