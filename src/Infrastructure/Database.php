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
	private ?string $engine = null;
	private ?\WP_MySQL_On_SQLite $sqlite_driver = null;
	private bool $transaction = false;
	private ?int $previous_busy_timeout = null;
	private int $timeout_depth = 0;

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
		$previous_suppression = $this->wpdb->suppress_errors(true);
		try {
			return $this->bounded(function () use ($operation, $write): mixed {
				for ($attempt = 0; $attempt < 3; ++$attempt) {
					$result = null !== $this->write_interceptor
						? ($this->write_interceptor)($operation, $write) : $write();
					if (false !== $result || ! DatabaseException::is_busy($this->wpdb->last_error)) {
						return $result;
					}
					if ($this->transaction || $attempt === 2) {
						throw new DatabaseException(true);
					}
					usleep(25000 * ($attempt + 1));
				}
				throw new DatabaseException(true);
			});
		} finally {
			$this->wpdb->suppress_errors($previous_suppression);
		}
	}

	/** Recognize the active connection, not a host string or an installed plugin. */
	public function engine(): string {
		if (null !== $this->engine) {
			return $this->engine;
		}
		if ($this->wpdb instanceof \WP_SQLite_DB && is_callable(array($this->wpdb, 'get_driver'))) {
			$driver = $this->wpdb->get_driver();
			if (
				$driver instanceof \WP_MySQL_On_SQLite &&
				defined('SQLITE_DRIVER_VERSION') && version_compare(SQLITE_DRIVER_VERSION, '3.1.2', '>=') &&
				$driver->get_sqlite_pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Inspect the existing wpdb driver's connection; no separate connection or application-data query.
			) {
				$this->sqlite_driver = $driver;
				$this->engine = 'sqlite';
				return $this->engine;
			}
			throw new DatabaseException();
		}
		if (
			(defined('DB_ENGINE') && DB_ENGINE === 'sqlite') ||
			stripos(get_class($this->wpdb), 'sqlite') !== false
		) {
			throw new DatabaseException();
		}
		$this->engine = 'mysql';
		return $this->engine;
	}

	/**
	 * Scope SQLite's busy timeout to plugin work, restoring the caller's setting.
	 * SQL translation and all application writes continue through wpdb.
	 */
	public function bounded(callable $operation): mixed {
		if ($this->engine() !== 'sqlite') {
			return $operation();
		}
		$this->enter_timeout();
		try {
			return $operation();
		} finally {
			$this->leave_timeout();
		}
	}

	/** A null result means contention; an unavailable locking backend throws. */
	public function acquire_lock(string $name, ?string $scope = null): ?DatabaseLock {
		if ($this->engine() === 'mysql') {
			$wpdb = $this->wpdb;
			$result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-scoped, nonblocking mutex.
			if ((string) $result === '1') {
				return new DatabaseLock($this->wpdb, $name);
			}
			if ((string) $result === '0') {
				return null;
			}
			throw new DatabaseException();
		}
		// These read-only pragmas identify the actual database used by this connection.
		$databases = $this->sqlite_driver->get_sqlite_pdo()->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Read connection identity through the official driver's public API; PRAGMA cannot pass through its MySQL parser.
		$file = '';
		foreach ($databases as $database) {
			if ($database['name'] === 'main') {
				$file = (string) $database['file'];
			}
		}
		$real_file = $file !== '' ? realpath($file) : false;
		if ($real_file === false || ! is_file($real_file) || ! function_exists('flock')) {
			throw new DatabaseException();
		}
		$identity_file = DIRECTORY_SEPARATOR === '\\' ? strtolower($real_file) : $real_file;
		$identity = $identity_file . ':' . strtolower($this->wpdb->prefix) . ':' . ($scope ?? $name);
		$path = dirname($real_file) . DIRECTORY_SEPARATOR . '.paper-to-quiz-' . hash('sha256', $identity) . '.lock';
		$stream = @fopen($path, 'c+b'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local descriptor locks are deliberately outside WP_Filesystem.
		if ($stream === false) {
			throw new DatabaseException();
		}
		$would_block = 0;
		if (! flock($stream, LOCK_EX | LOCK_NB, $would_block)) {
			fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close our unowned descriptor.
			if ($would_block) {
				return null;
			}
			throw new DatabaseException();
		}
		return new DatabaseLock(null, $name, $stream);
	}

	private function enter_timeout(): void {
		if ($this->timeout_depth === 0) {
			$pdo = $this->sqlite_driver->get_sqlite_pdo();
			$this->previous_busy_timeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
			$pdo->exec('PRAGMA busy_timeout = ' . min(100, $this->previous_busy_timeout));
		}
		++$this->timeout_depth;
	}

	private function leave_timeout(): void {
		--$this->timeout_depth;
		if ($this->timeout_depth === 0 && null !== $this->previous_busy_timeout) {
			$timeout = $this->previous_busy_timeout;
			$this->previous_busy_timeout = null;
			$this->sqlite_driver->get_sqlite_pdo()->exec('PRAGMA busy_timeout = ' . $timeout);
		}
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
		if ($this->transaction || ($this->engine() === 'sqlite' && $this->sqlite_driver->inTransaction())) {
			throw new DatabaseException();
		}
		if ($this->engine() === 'sqlite') {
			$this->enter_timeout();
		}
		try {
			$result = $this->write('transaction_begin', fn (): int|bool => $this->wpdb->query('START TRANSACTION'));
			if ($result === false) {
				throw new DatabaseException();
			}
			$this->transaction = true;
		} catch (\Throwable $error) {
			if ($this->engine() === 'sqlite') {
				$this->leave_timeout();
			}
			throw $error;
		}
	}

	public function commit(): void {
		if (! $this->transaction) {
			throw new DatabaseException();
		}
		// Never replay COMMIT: the driver can already have rolled back a failed commit.
		$previous_suppression = $this->wpdb->suppress_errors(true);
		try {
			$result = null !== $this->write_interceptor
				? ($this->write_interceptor)('transaction_commit', fn () => $this->wpdb->query('COMMIT'))
				: $this->wpdb->query('COMMIT');
		} finally {
			$this->wpdb->suppress_errors($previous_suppression);
		}
		if ($result === false) {
			throw new DatabaseException(DatabaseException::is_busy($this->wpdb->last_error)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception stores only a boolean, never database text.
		}
		$this->finish_transaction();
	}

	public function rollback(): void {
		if (! $this->transaction) {
			return;
		}
		$previous_suppression = $this->wpdb->suppress_errors(true);
		try {
			if ($this->wpdb->query('ROLLBACK') === false) {
				throw new DatabaseException(DatabaseException::is_busy($this->wpdb->last_error));
			}
		} finally {
			$this->wpdb->suppress_errors($previous_suppression);
			$this->finish_transaction();
		}
	}

	private function finish_transaction(): void {
		$this->transaction = false;
		if ($this->engine() === 'sqlite') {
			$this->leave_timeout();
		}
	}
}
