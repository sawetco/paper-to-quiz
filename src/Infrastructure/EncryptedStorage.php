<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

// Encrypted files are processed as bounded streams. WP_Filesystem would load whole PDFs into memory.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class EncryptedStorage {
	public const MAGIC        = 'PTQ2';
	public const LEGACY_MAGIC = 'PTQ1';
	public const CHUNK_SIZE   = 1048576;

	private const V2_INFO_PREFIX     = 'paper-to-quiz:v2:file:';
	private const LEGACY_INFO_PREFIX = 'ptq:';

	private string $base_dir;
	private string $uploads_error;
	private PrivateKeyProvider $key_provider;

	public function __construct(?PrivateKeyProvider $key_provider = null) {
		$this->key_provider = $key_provider ?? new PrivateKeyProvider();
		$uploads        = wp_upload_dir();
		$this->base_dir = trailingslashit($uploads['basedir']) . 'paper-to-quiz-private';
		$this->uploads_error = (string) ($uploads['error'] ?? '');
	}

	public function ensure_base_directory(): void {
		if ($this->uploads_error !== '') {
			throw new StorageException(
				'WordPress upload directory error: ' . $this->uploads_error,
				__('The upload directory is unavailable. Check the hosting file permissions.', 'paper-to-quiz')
			);
		}

		if ((! is_dir($this->base_dir) && ! wp_mkdir_p($this->base_dir)) || ! is_writable($this->base_dir)) {
			throw new \RuntimeException(__('The private storage directory could not be created.', 'paper-to-quiz'));
		}

		if (! extension_loaded('openssl') || ! in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
			throw new \RuntimeException(__('File security is unavailable on the server.', 'paper-to-quiz'));
		}

		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
		);

		foreach ($guards as $name => $contents) {
			$path = $this->base_dir . DIRECTORY_SEPARATOR . $name;
			if (! file_exists($path)) {
				$written = file_put_contents($path, $contents, LOCK_EX);
				if ($written === false || $written !== strlen($contents)) {
					throw new StorageException(
						'Private storage guard could not be written: ' . $name,
						__('The file security directory could not be prepared. Check the hosting file permissions.', 'paper-to-quiz')
					);
				}
			}
		}
	}

	public function is_available(): bool {
		try {
			$this->ensure_base_directory();
		} catch (\Throwable) {
			return false;
		}

		return is_dir($this->base_dir) && is_writable($this->base_dir);
	}

	public function put_string(string $contents, string $purpose): array {
		$this->ensure_base_directory();
		$temp = \tempnam($this->base_dir, 'ptq-');
		if ($temp === false || ! str_starts_with(wp_normalize_path($temp), trailingslashit(wp_normalize_path($this->base_dir)))) {
			throw new \RuntimeException(__('The temporary file could not be created.', 'paper-to-quiz'));
		}

		try {
			$written = file_put_contents($temp, $contents, LOCK_EX);
			if ($written === false || $written !== strlen($contents)) {
				throw new \RuntimeException(__('The file could not be written to temporary storage.', 'paper-to-quiz'));
			}
			return $this->put_file($temp, $purpose);
		} finally {
			wp_delete_file($temp);
		}
	}

	public function put_file(string $source, string $purpose): array {
		$this->ensure_base_directory();

		$storage_key = $this->new_storage_key();
		$destination = $this->path($storage_key);
		if (! wp_mkdir_p(dirname($destination))) {
			throw new \RuntimeException(__('The storage directory could not be created.', 'paper-to-quiz'));
		}

		$input = fopen($source, 'rb');
		if (! $input) {
			throw new \RuntimeException(__('The file could not be opened for storage.', 'paper-to-quiz'));
		}
		$output = fopen($destination, 'wb');
		if (! $output) {
			fclose($input);
			throw new \RuntimeException(__('The file could not be opened for storage.', 'paper-to-quiz'));
		}

		$key     = $this->derive_v2_key($purpose);
		$hash    = hash_init('sha256');
		$size    = 0;
		$success = false;

		try {
			$this->write_all($output, self::MAGIC);
			$this->write_plain_stream($input, $output, $key, $hash, $size);
			$success = true;
		} finally {
			fclose($input);
			fclose($output);
			if (! $success) {
				wp_delete_file($destination);
			}
		}

		return array(
			'storage_key' => $storage_key,
			'byte_size'   => $size,
			'sha256'      => hash_final($hash),
		);
	}

	/**
	 * Combine upload chunks while re-encrypting their plaintext into PTQ2.
	 * This keeps pending PTQ1 uploads readable across an upgrade and never
	 * concatenates a legacy ciphertext under a PTQ2 header.
	 *
	 * @param string[] $chunk_keys
	 */
	public function combine(array $chunk_keys): array {
		$this->ensure_base_directory();

		$storage_key = $this->new_storage_key();
		$destination = $this->path($storage_key);
		if (! wp_mkdir_p(dirname($destination))) {
			throw new \RuntimeException(__('The storage directory could not be created.', 'paper-to-quiz'));
		}

		$output = fopen($destination, 'wb');
		if (! $output) {
			throw new \RuntimeException(__('The combined file could not be created.', 'paper-to-quiz'));
		}
		$hash    = hash_init('sha256');
		$size    = 0;
		$key     = $this->derive_v2_key('source_pdf');
		$success = false;

		try {
			$this->write_all($output, self::MAGIC);
			foreach ($chunk_keys as $chunk_key) {
				$this->stream_path(
					$this->path($chunk_key),
					'source_pdf',
					function (string $plain) use ($output, $key, $hash, &$size): void {
						$this->write_all($output, $this->encrypt_chunk($plain, $key));
						hash_update($hash, $plain);
						$size += strlen($plain);
					}
				);
			}
			$success = true;
		} finally {
			fclose($output);
			if (! $success) {
				wp_delete_file($destination);
			}
		}

		return array(
			'storage_key' => $storage_key,
			'byte_size'   => $size,
			'sha256'      => hash_final($hash),
		);
	}

	public function get_contents(string $storage_key, string $purpose): string {
		$contents = '';
		$this->stream(
			$storage_key,
			$purpose,
			static function (string $chunk) use (&$contents): void {
				$contents .= $chunk;
			}
		);
		return $contents;
	}

	public function prefix(string $storage_key, string $purpose, int $length): string {
		$result = '';
		$this->stream(
			$storage_key,
			$purpose,
			static function (string $chunk) use (&$result, $length): void {
				if (strlen($result) < $length) {
					$result .= substr($chunk, 0, $length - strlen($result));
				}
			}
		);
		return $result;
	}

	public function output(string $storage_key, string $purpose): void {
		$this->stream(
			$storage_key,
			$purpose,
			static function (string $chunk): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
	}

	public function delete(string $storage_key): void {
		$path = $this->path($storage_key);
		if (is_file($path)) {
			wp_delete_file($path);
		}
	}

	public function exists(string $storage_key): bool {
		return is_file($this->path($storage_key));
	}

	public function is_v2(string $storage_key): bool {
		$input = fopen($this->path($storage_key), 'rb');
		if (! $input) {
			return false;
		}
		try {
			return fread($input, 4) === self::MAGIC;
		} finally {
			fclose($input);
		}
	}

	public function verify(string $storage_key, string $purpose, int $expected_size, string $expected_sha256): bool {
		if ($expected_size < 0 || ! preg_match('/^[a-f0-9]{64}$/i', $expected_sha256)) {
			return false;
		}
		try {
			$meta = $this->inspect($storage_key, $purpose);
			return (int) $meta['byte_size'] === $expected_size
				&& hash_equals(strtolower($expected_sha256), (string) $meta['sha256']);
		} catch (\Throwable) {
			return false;
		}
	}

	public function base_directory(): string {
		return $this->base_dir;
	}

	/**
	 * Re-encrypt one PTQ1 file into a verified PTQ2 temporary file, then publish
	 * it under a new storage key. The migration swaps the database pointer only
	 * after this method returns, so readers always observe a complete file.
	 *
	 * @return array{storage_key:string,byte_size:int,sha256:string}
	 */
	public function migrate_legacy(string $storage_key, string $purpose, int $expected_size, string $expected_sha256): array {
		$this->ensure_base_directory();
		if ($expected_size < 0 || ! preg_match('/^[a-f0-9]{64}$/i', $expected_sha256)) {
			throw new \RuntimeException('Legacy asset metadata is invalid.');
		}

		$source      = $this->path($storage_key);
		$new_key     = $this->new_storage_key();
		$destination = $this->path($new_key);
		if (! wp_mkdir_p(dirname($destination))) {
			throw new \RuntimeException(__('The migration directory could not be created.', 'paper-to-quiz'));
		}
		$temp = tempnam(dirname($destination), 'ptq-migrate-');
		if ($temp === false || ! str_starts_with(wp_normalize_path($temp), trailingslashit(wp_normalize_path(dirname($destination))))) {
			throw new \RuntimeException(__('The migration temporary file could not be created.', 'paper-to-quiz'));
		}

		$output  = null;
		$renamed = false;
		$hash    = hash_init('sha256');
		$size    = 0;
		$success = false;
		try {
			$output = fopen($temp, 'wb');
			if (! $output) {
				throw new \RuntimeException(__('The migration temporary file could not be opened.', 'paper-to-quiz'));
			}
			$this->write_all($output, self::MAGIC);
			$key = $this->derive_v2_key($purpose);
			$this->stream_path(
				$source,
				$purpose,
				function (string $plain) use ($output, $key, $hash, &$size): void {
					$this->write_all($output, $this->encrypt_chunk($plain, $key));
					hash_update($hash, $plain);
					$size += strlen($plain);
				},
				self::LEGACY_MAGIC
			);
			$actual_hash = hash_final($hash);
			if ($size !== $expected_size || ! hash_equals(strtolower($expected_sha256), $actual_hash)) {
				throw new \RuntimeException('Legacy asset plaintext verification failed.');
			}
			fflush($output);
			fclose($output);
			$output = null;
			if (! rename($temp, $destination)) {
				throw new \RuntimeException('The migrated file could not be published atomically.');
			}
			$renamed = true;
			$success = true;
		} finally {
			if (is_resource($output)) {
				fclose($output);
			}
			if (! $success) {
				if ($renamed) {
					wp_delete_file($destination);
				} else {
					wp_delete_file($temp);
				}
			}
		}

		return array(
			'storage_key' => $new_key,
			'byte_size'   => $size,
			'sha256'      => strtolower($expected_sha256),
		);
	}

	private function inspect(string $storage_key, string $purpose): array {
		$hash = hash_init('sha256');
		$size = 0;
		$this->stream(
			$storage_key,
			$purpose,
			static function (string $chunk) use ($hash, &$size): void {
				hash_update($hash, $chunk);
				$size += strlen($chunk);
			}
		);

		return array(
			'byte_size' => $size,
			'sha256'    => hash_final($hash),
		);
	}

	private function stream(string $storage_key, string $purpose, callable $consumer): void {
		$this->stream_path($this->path($storage_key), $purpose, $consumer);
	}

	/**
	 * Read one encrypted file as bounded plaintext chunks.
	 *
	 * @return string The detected file magic.
	 */
	private function stream_path(string $path, string $purpose, callable $consumer, ?string $required_magic = null): string {
		$input = fopen($path, 'rb');
		if (! $input) {
			throw new \RuntimeException(__('The encrypted file could not be read.', 'paper-to-quiz'));
		}

		$magic = '';
		try {
			$magic = fread($input, 4);
			if (! is_string($magic) || ! in_array($magic, array(self::MAGIC, self::LEGACY_MAGIC), true)) {
				throw new \RuntimeException('The encrypted file has an unknown marker.');
			}
			if ($required_magic !== null && $magic !== $required_magic) {
				throw new \RuntimeException('The encrypted file is not in the expected legacy format.');
			}

			$key = $magic === self::MAGIC
				? $this->derive_v2_key($purpose)
				: $this->derive_legacy_key($purpose);
			while (true) {
				$iv = fread($input, 12);
				if ($iv === false) {
					throw new \RuntimeException('The encrypted file could not be read.');
				}
				if ($iv === '') {
					break;
				}

				$tag        = $this->read_exact($input, 16);
				$length_raw = $this->read_exact($input, 4);
				if (strlen($iv) !== 12 || strlen($tag) !== 16 || strlen($length_raw) !== 4) {
					throw new \RuntimeException(__('The encrypted file is corrupted.', 'paper-to-quiz'));
				}
				$unpacked = unpack('Nlength', $length_raw);
				$length   = is_array($unpacked) ? (int) ($unpacked['length'] ?? 0) : 0;
				if ($length < 1 || $length > self::CHUNK_SIZE) {
					throw new \RuntimeException('The encrypted file chunk length is invalid.');
				}
				$cipher = $this->read_exact($input, $length);
				if (strlen($cipher) !== $length) {
					throw new \RuntimeException(__('The encrypted file is corrupted.', 'paper-to-quiz'));
				}
				$plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
				if ($plain === false) {
					throw new \RuntimeException(__('File authentication failed.', 'paper-to-quiz'));
				}
				$consumer($plain);
			}
		} finally {
			fclose($input);
		}

		return $magic;
	}

	private function derive_v2_key(string $purpose): string {
		return hash_hkdf('sha256', $this->key_provider->get_key(), 32, self::V2_INFO_PREFIX . $purpose);
	}

	/**
	 * Explicit legacy derivation. PTQ1 files were salted by wp_salt('auth').
	 */
	private function derive_legacy_key(string $purpose): string {
		return hash_hkdf('sha256', $this->key_provider->get_key(), 32, self::LEGACY_INFO_PREFIX . $purpose, wp_salt('auth'));
	}

	private function new_storage_key(): string {
		return gmdate('Y/m/') . wp_generate_uuid4() . '.ptq';
	}

	private function encrypt_chunk(string $plain, string $key): string {
		$iv     = random_bytes(12);
		$tag    = '';
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($cipher === false) {
			throw new \RuntimeException(__('The file could not be encrypted.', 'paper-to-quiz'));
		}
		return $iv . $tag . pack('N', strlen($cipher)) . $cipher;
	}

	/**
	 * @param resource $input
	 * @param resource $output
	 */
	private function write_plain_stream($input, $output, string $key, $hash, int &$size): void {
		while (! feof($input)) {
			$plain = fread($input, self::CHUNK_SIZE);
			if ($plain === false) {
				throw new \RuntimeException(__('The source file could not be read.', 'paper-to-quiz'));
			}
			if ($plain === '') {
				break;
			}

			$this->write_all($output, $this->encrypt_chunk($plain, $key));
			hash_update($hash, $plain);
			$size += strlen($plain);
		}
	}

	/**
	 * @param resource $stream
	 */
	private function read_exact($stream, int $length): string {
		$contents = '';
		while (strlen($contents) < $length && ! feof($stream)) {
			$part = fread($stream, $length - strlen($contents));
			if ($part === false || $part === '') {
				break;
			}
			$contents .= $part;
		}
		return $contents;
	}

	/**
	 * @param resource $stream
	 */
	private function write_all($stream, string $contents): void {
		$offset = 0;
		$length = strlen($contents);
		while ($offset < $length) {
			$written = fwrite($stream, substr($contents, $offset));
			if ($written === false || $written === 0) {
				throw new \RuntimeException(__('The file could not be written to storage.', 'paper-to-quiz'));
			}
			$offset += $written;
		}
	}

	private function path(string $storage_key): string {
		$normalized = str_replace('\\', '/', $storage_key);
		if (str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
			throw new \InvalidArgumentException('Invalid storage key.');
		}
		return $this->base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
	}
}
