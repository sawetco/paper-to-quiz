<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

/** Safe database failure metadata; never includes SQL or connection details. */
final class DatabaseException extends \RuntimeException {
	public function __construct(public readonly bool $retryable = false) {
		parent::__construct('The database operation could not be completed.');
	}

	public static function is_busy(string $error): bool {
		return (bool) preg_match('/SQLITE_BUSY|SQLITE_LOCKED|database (?:table |schema )?is locked|database is busy/i', $error);
	}

	public function rest_error(): \WP_Error {
		return new \WP_Error(
			'paper_to_quiz_database_unavailable',
			__('The database is temporarily unavailable. Please try again.', 'paper-to-quiz'),
			array('status' => 503, 'retryable' => $this->retryable)
		);
	}
}
