<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

// Exception messages are escaped by their eventual presentation layer.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class PrivateKeyProvider {
	private const OPTION_NAME = 'paper_to_quiz_storage_key';

	/** @var \Closure(string, mixed): mixed */
	private \Closure $option_reader;

	/** @var \Closure(string, string, string, bool): mixed */
	private \Closure $option_writer;

	/**
	 * @param callable(string, mixed): mixed|null $option_reader
	 * @param callable(string, string, string, bool): mixed|null $option_writer
	 */
	public function __construct(?callable $option_reader = null, ?callable $option_writer = null) {
		$this->option_reader = $option_reader instanceof \Closure
			? $option_reader
			: (null !== $option_reader ? \Closure::fromCallable($option_reader) : static function (string $option, mixed $default = ''): mixed {
				return get_option($option, $default);
			});
		$this->option_writer = $option_writer instanceof \Closure
			? $option_writer
			: (null !== $option_writer ? \Closure::fromCallable($option_writer) : static function (string $option, string $value, string $_deprecated = '', bool $autoload = false): mixed {
				return add_option($option, $value, '', $autoload);
			});
	}

	/**
	 * Return the raw master key used to derive purpose-specific keys.
	 *
	 * @throws StorageException When the configured key is missing or invalid.
	 */
	public function get_key(): string {
		if (defined('PAPER_TO_QUIZ_PRIVATE_STORAGE_KEY')) {
			$constant = constant('PAPER_TO_QUIZ_PRIVATE_STORAGE_KEY');
			if (! is_string($constant) || $constant === '') {
				throw $this->corrupt_key_exception();
			}

			return $constant;
		}

		$encoded = ($this->option_reader)(self::OPTION_NAME, null);
		if ($encoded === null || $encoded === '') {
			$candidate = base64_encode(random_bytes(32));
			($this->option_writer)(self::OPTION_NAME, $candidate, '', false);
			$encoded = ($this->option_reader)(self::OPTION_NAME, null);
		}

		if (! is_string($encoded) || $encoded === '') {
			throw $this->corrupt_key_exception();
		}

		$decoded = base64_decode($encoded, true);
		if ($decoded === false || strlen($decoded) !== 32) {
			throw $this->corrupt_key_exception();
		}

		return $decoded;
	}

	private function corrupt_key_exception(): StorageException {
		return new StorageException(
			'The private storage key is corrupt.',
			__('The private storage key is corrupt. Restore it from backup or rotate it in wp-config.php.', 'paper-to-quiz')
		);
	}
}
