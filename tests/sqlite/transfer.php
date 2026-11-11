<?php
/** Synthetic MySQL -> independent SQLite copy integrity gate. Never a site migration tool. */

use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;

if (! defined('ABSPATH')) {
	require dirname(__DIR__) . '/native-bootstrap.php';
}
if (wp_get_environment_type() !== 'local' || get_option('paper_to_quiz_disposable_test') !== 'sqlite-1.3') {
	throw new RuntimeException('Transfer checks require a marked disposable local site.');
}
$db = new Database();
$wpdb = $db->wpdb();
$action = getenv('PTQ_TRANSFER_ACTION') ?: ($argv[1] ?? '');
$path = getenv('PTQ_TRANSFER_PATH');
if (! $path) {
	throw new RuntimeException('A private synthetic transfer fixture path is required.');
}
$tables = array('assessments', 'revisions', 'questions', 'terms', 'assets', 'upload_sessions', 'attempts', 'answers', 'ranking_entries', 'attempt_subject_scores', 'result_email_jobs');

function paper_to_quiz_transfer_rows(wpdb $wpdb, Database $db, string $table, array $columns): array {
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', $db->table($table)), ARRAY_A);
	if (! is_array($rows)) {
		throw new RuntimeException('The synthetic table could not be read.');
	}
	foreach ($rows as &$row) {
		foreach ($columns as $column) {
			$key = $column['Field'];
			if (null === $row[$key]) {
				continue;
			}
			if (preg_match('/^(?:bigint|int|smallint|tinyint|mediumint)/i', $column['Type'])) {
				$row[$key] = (string) (int) $row[$key];
			} elseif (preg_match('/^(?:decimal|float|double)/i', $column['Type'])) {
				$value = (string) $row[$key];
				$row[$key] = str_contains($value, '.') ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value;
			}
		}
		ksort($row);
	}
	return $rows;
}

if ($action === 'export') {
	if ($db->engine() !== 'mysql') {
		throw new RuntimeException('The source must be the disposable MySQL/MariaDB fixture.');
	}
	$data = array('format' => 1, 'tables' => array(), 'columns' => array(), 'options' => array(), 'files' => array(), 'salts' => array());
	foreach ($tables as $table) {
		$data['columns'][$table] = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i', $db->table($table)), ARRAY_A);
		$data['tables'][$table] = paper_to_quiz_transfer_rows($wpdb, $db, $table, $data['columns'][$table]);
	}
	foreach ($wpdb->get_results($wpdb->prepare('SELECT option_name,option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('paper_to_quiz_') . '%'), ARRAY_A) as $option) {
		$data['options'][$option['option_name']] = $option['option_value'];
	}
	foreach (array('auth', 'secure_auth', 'logged_in', 'nonce') as $scheme) {
		$data['salts'][$scheme] = wp_salt($scheme);
	}
	$state = get_option('paper_to_quiz_sqlite_fixture');
	if (! is_array($state) || count($state['receipts']) !== 40) {
		throw new RuntimeException('The synthetic source fixture is incomplete.');
	}
	$storage = new EncryptedStorage();
	$storage_key = $state['private_file']['storage_key'];
	$data['files'][$storage_key] = base64_encode(file_get_contents($storage->base_directory() . '/' . $storage_key));
	if (file_put_contents($path, wp_json_encode($data)) === false) {
		throw new RuntimeException('The private synthetic export could not be written.');
	}
	echo "PTQ_MYSQL_SYNTHETIC_EXPORT=passed\n";
	return;
}

if ($db->engine() !== 'sqlite' || ! getenv('PTQ_TEST_SQLITE_PATH') || DB_PATH !== getenv('PTQ_TEST_SQLITE_PATH')) {
	throw new RuntimeException('The target must be an independent named SQLite unit copy.');
}
$data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (($data['format'] ?? null) !== 1 || array_keys($data['tables']) !== $tables) {
	throw new RuntimeException('Unexpected synthetic transfer format.');
}
if ($action === 'import') {
	$storage = new EncryptedStorage();
	$storage->ensure_base_directory();
	foreach ($data['files'] as $key => $encoded) {
		if (! preg_match('/^\d{4}\/\d{2}\/[a-f0-9-]{36}\.ptq$/', $key)) {
			throw new RuntimeException('Unsafe synthetic storage key.');
		}
		$destination = $storage->base_directory() . '/' . $key;
		if (is_file($destination)) {
			throw new RuntimeException('Do not overwrite any existing encrypted file.');
		}
		wp_mkdir_p(dirname($destination));
		$bytes = base64_decode($encoded, true);
		if (! is_string($bytes) || file_put_contents($destination, $bytes) !== strlen($bytes)) {
			throw new RuntimeException('Synthetic private file import failed.');
		}
	}
	try {
		$db->begin();
		foreach (array_reverse($tables) as $table) {
			if ($wpdb->query($wpdb->prepare('DELETE FROM %i', $db->table($table))) === false) {
				throw new RuntimeException('Synthetic target cleanup failed.');
			}
		}
		foreach ($tables as $table) {
			foreach ($data['tables'][$table] as $row) {
				if ($wpdb->insert($db->table($table), $row) !== 1) {
					throw new RuntimeException('Synthetic row import failed.');
				}
			}
		}
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('paper_to_quiz_') . '%'));
		foreach ($data['options'] as $name => $value) {
			if ($wpdb->insert($wpdb->options, array('option_name' => $name, 'option_value' => $value, 'autoload' => 'off')) !== 1) {
				throw new RuntimeException('Synthetic option import failed.');
			}
		}
		$db->commit();
		wp_cache_flush();
	} catch (Throwable $error) {
		$db->rollback();
		throw $error;
	}
}
foreach ($tables as $table) {
	$actual = paper_to_quiz_transfer_rows($wpdb, $db, $table, $data['columns'][$table]);
	if ($actual !== $data['tables'][$table]) {
		throw new RuntimeException('Transferred table mismatch: ' . $table);
	}
}
foreach ($data['options'] as $name => $value) {
	if ($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $name)) !== $value) {
		throw new RuntimeException('Transferred option mismatch.');
	}
}
echo "PTQ_MYSQL_TO_SQLITE_INTEGRITY=passed tables=" . count($tables) . "\n";
