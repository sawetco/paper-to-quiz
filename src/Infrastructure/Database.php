<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class Database {
	/** @var \wpdb */
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function wpdb(): \wpdb {
		return $this->wpdb;
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

		return $this->wpdb->prefix . 'ptq_' . $name;
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
