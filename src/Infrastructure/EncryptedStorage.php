<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

// Encrypted files are processed as bounded streams. WP_Filesystem would load whole PDFs into memory.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class EncryptedStorage {
	private const MAGIC      = 'PTQ1';
	private const CHUNK_SIZE = 1048576;

	private string $base_dir;
	private string $uploads_error;

	public function __construct() {
		$uploads        = wp_upload_dir();
		$this->base_dir = trailingslashit($uploads['basedir']) . 'ptq-private';
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

		$storage_key = gmdate('Y/m/') . wp_generate_uuid4() . '.ptq';
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

		$key     = $this->derive_key($purpose);
		$hash    = hash_init('sha256');
		$size    = 0;
		$success = false;

		try {
			$this->write_all($output, self::MAGIC);
			while (! feof($input)) {
				$plain = fread($input, self::CHUNK_SIZE);
				if ($plain === false) {
					throw new \RuntimeException(__('The source file could not be read.', 'paper-to-quiz'));
				}
				if ($plain === '') {
					break;
				}

				$iv     = random_bytes(12);
				$tag    = '';
				$cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
				if ($cipher === false) {
					throw new \RuntimeException(__('The file could not be encrypted.', 'paper-to-quiz'));
				}

				$encrypted = $iv . $tag . pack('N', strlen($cipher)) . $cipher;
				$this->write_all($output, $encrypted);
				hash_update($hash, $plain);
				$size += strlen($plain);
			}
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
	 * @param string[] $chunk_keys
	 */
	public function combine(array $chunk_keys): array {
		$this->ensure_base_directory();

		$storage_key = gmdate('Y/m/') . wp_generate_uuid4() . '.ptq';
		$destination = $this->path($storage_key);
		if (! wp_mkdir_p(dirname($destination))) {
			throw new \RuntimeException(__('The storage directory could not be created.', 'paper-to-quiz'));
		}

		$output = fopen($destination, 'wb');
		if (! $output) {
			throw new \RuntimeException(__('The combined file could not be created.', 'paper-to-quiz'));
		}
		$success = false;

		try {
			$this->write_all($output, self::MAGIC);
			foreach ($chunk_keys as $chunk_key) {
				$input = fopen($this->path($chunk_key), 'rb');
				if (! $input || fread($input, 4) !== self::MAGIC) {
					if ($input) {
						fclose($input);
					}
					throw new \RuntimeException(__('The upload chunk could not be verified.', 'paper-to-quiz'));
				}
				if (stream_copy_to_stream($input, $output) === false) {
					fclose($input);
					throw new \RuntimeException(__('The upload chunks could not be combined.', 'paper-to-quiz'));
				}
				fclose($input);
			}
			$success = true;
		} finally {
			fclose($output);
			if (! $success) {
				wp_delete_file($destination);
			}
		}

		$meta = $this->inspect($storage_key, 'source_pdf');
		return array_merge(array('storage_key' => $storage_key), $meta);
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

	public function base_directory(): string {
		return $this->base_dir;
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
		$input = fopen($this->path($storage_key), 'rb');
		if (! $input || fread($input, 4) !== self::MAGIC) {
			if ($input) {
				fclose($input);
			}
			throw new \RuntimeException(__('The encrypted file could not be read.', 'paper-to-quiz'));
		}

		$key = $this->derive_key($purpose);
		try {
			while (! feof($input)) {
				$iv = fread($input, 12);
				if ($iv === '' || $iv === false) {
					break;
				}
				$tag        = fread($input, 16);
				$length_raw = fread($input, 4);
				if (strlen($iv) !== 12 || strlen($tag) !== 16 || strlen($length_raw) !== 4) {
					throw new \RuntimeException(__('The encrypted file is corrupted.', 'paper-to-quiz'));
				}
				$length = unpack('Nlength', $length_raw)['length'];
				$cipher = '';
				while (strlen($cipher) < $length && ! feof($input)) {
					$part = fread($input, $length - strlen($cipher));
					if ($part === false) {
						break;
					}
					$cipher .= $part;
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
	}

	private function derive_key(string $purpose): string {
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

		return hash_hkdf('sha256', $master, 32, 'ptq:' . $purpose, wp_salt('auth'));
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
