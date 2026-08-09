<?php

declare(strict_types=1);

namespace PaperToQuiz\Rest;

final class BinaryResponse extends \WP_REST_Response {
	public function __construct(
		public readonly string $storage_key,
		public readonly string $purpose,
		public readonly string $mime,
		public readonly string $filename,
		public readonly int $byte_size = 0
	) {
		parent::__construct(null, 200);
	}
}
