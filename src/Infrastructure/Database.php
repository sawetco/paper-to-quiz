<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class Database {
	public const TABLE_PREFIX = 'paper_to_quiz_';

	/** @var \wpdb */
	private $wpdb;

	/**
	 * Optional test-only write interceptor.
	 *
	 * @var callable(string, callable): mixed|null
	 */
	private $write_interceptor;

	/**
	 * @param \wpdb|null                    $wpdb              Optional connection for tests.
	 * @param callable(string, callable): mixed|null $write_interceptor Optional test seam.
	 */
	public function __construct(?\wpdb $wpdb = null, ?callable $write_interceptor = null) {
		if (null === $wpdb) {
			global $wpdb;
		}
		$this->wpdb              = $wpdb;
		$this->write_interceptor = $write_interceptor;
	}

	public function wpdb(): \wpdb {
		return $this->wpdb;
	}

	/**
	 * Execute a named database write, optionally through the test interceptor.
	 *
	 * The normal production path delegates directly to the supplied callback. A
	 * test may return false for one operation and delegate all other operations,
	 * allowing failure paths to be exercised without a production flag or query
	 * filter.
	 *
	 * @param string   $operation Stable operation name for diagnostics/tests.
	 * @param callable $write     Database write callback.
	 * @return mixed The callback/interceptor result.
	 */
	public function write(string $operation, callable $write): mixed {
		if (null !== $this->write_interceptor) {
			return ($this->write_interceptor)($operation, $write);
		}

		return $write();
	}

	public function table(string $name): string {
		$allowed = array(
			'assessments',
			'revisions',
			'questions',
			'terms',
			'assets',
			'upload_sessions',
			'attempts',
			'answers',
			'ranking_entries',
			'attempt_subject_scores',
			'result_email_jobs',
		);

		if (! in_array($name, $allowed, true)) {
			throw new \InvalidArgumentException('Unknown table name.');
		}

		return $this->wpdb->prefix . self::TABLE_PREFIX . $name;
	}

	public function begin(): void {
		$this->wpdb->query('START TRANSACTION');
	}

	public function commit(): void {
		$this->wpdb->query('COMMIT');
	}

	public function rollback(): void {
		$this->wpdb->query('ROLLBACK');
	}
}
