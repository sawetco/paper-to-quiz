<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class StorageException extends \RuntimeException {
	public function __construct(
		string $technical_message,
		public readonly string $user_message
	) {
		parent::__construct($technical_message);
	}
}
