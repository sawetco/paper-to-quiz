<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

/** Owns exactly one database-session lock or local file descriptor. */
final class DatabaseLock {
	/** @var resource|null */
	private $stream;
	private ?\wpdb $connection;

	/** @param resource|null $stream Exclusive local lock descriptor. */
	public function __construct(?\wpdb $connection, private readonly string $name, $stream = null) {
		$this->connection = $connection;
		$this->stream = $stream;
	}

	public function release(): void {
		if (is_resource($this->stream)) {
			// Never unlink: replacing a locked inode would create two independent locks.
			flock($this->stream, LOCK_UN);
			fclose($this->stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The descriptor owns the kernel lock.
			$this->stream = null;
		}
		if (null !== $this->connection) {
			$connection = $this->connection;
			$this->connection = null;
			$result = $connection->get_var($connection->prepare('SELECT RELEASE_LOCK(%s)', $this->name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only the owning database session can release this lock.
			if ((string) $result !== '1') {
				throw new DatabaseException();
			}
		}
	}

	public function __destruct() {
		try {
			$this->release();
		} catch (\Throwable) {
			// Connection termination also releases MySQL locks; destructors must not throw.
		}
	}
}
