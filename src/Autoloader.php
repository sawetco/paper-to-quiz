<?php

declare(strict_types=1);

namespace PaperToQuiz;

final class Autoloader {
	private const PREFIX = 'PaperToQuiz\\';

	public static function register(): void {
		spl_autoload_register(
			static function (string $class): void {
				if (! str_starts_with($class, self::PREFIX)) {
					return;
				}

				$relative = substr($class, strlen(self::PREFIX));
				$path     = PAPER_TO_QUIZ_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

				if (is_readable($path)) {
					require_once $path;
				}
			}
		);
	}
}
