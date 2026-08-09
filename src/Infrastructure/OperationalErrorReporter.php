<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class OperationalErrorReporter {
	public static function report(string $operation, \Throwable $exception, string $safe_message, int $status): \WP_Error {
		$reference = strtoupper(substr(str_replace('-', '', wp_generate_uuid4()), 0, 8));
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failures need server-side diagnostic metadata without exposing request or exception contents.
			sprintf(
				'[PTQ operational %s %s] %s in %s:%d',
				$operation,
				$reference,
				get_class($exception),
				wp_basename($exception->getFile()),
				$exception->getLine()
			)
		);

		/* translators: %s: Short support reference code. */
		$message = rtrim($safe_message) . ' ' . sprintf(__('Support code: %s', 'paper-to-quiz'), $reference);

		return new \WP_Error(
			$operation,
			$message,
			array(
				'status'    => $status,
				'reference' => $reference,
			)
		);
	}
}
