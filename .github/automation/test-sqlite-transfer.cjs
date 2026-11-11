'use strict';
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const root = process.cwd();
const home = fs.mkdtempSync(path.join(process.env.RUNNER_TEMP || os.tmpdir(), 'ptq-transfer-ci-'));
const site = path.join(home, 'wordpress');
const exchange = path.join(home, 'exchange');
fs.mkdirSync(exchange);
const config = path.join(home, 'mysql.json');
const dist = path.join(home, 'dist');
const nativeEnv = {...process.env, WP_ENVIRONMENT_TYPE: 'local', PTQ_TEST_WP_LOAD: path.join(site, 'wp-load.php')};
function run(command, args, env = nativeEnv) {
	const result = spawnSync(command, args, {cwd: root, env, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024});
	process.stdout.write(result.stdout || '');
	process.stderr.write(result.stderr || '');
	if (result.status !== 0) throw new Error('Synthetic transfer gate failed: ' + command);
	return result.stdout;
}
const mysqlEnv = {...process.env, WP_ENV_HOME: path.join(home, 'mysql-home')};
const mysql = args => run(process.execPath, [path.join(root, 'node_modules/@wordpress/env/bin/wp-env'), '--config', config, ...args], mysqlEnv);
const mysqlWp = (...args) => mysql(['run', 'cli', 'wp', ...args]);
const wp = (...args) => run('wp', ['--path=' + site, ...args]);
async function download(url, destination) {
	const response = await fetch(url);
	assert.ok(response.ok);
	fs.writeFileSync(destination, Buffer.from(await response.arrayBuffer()));
}
(async () => {
	try {
		run('unzip', ['-q', '.package-under-test/paper-to-quiz.zip', '-d', dist]);
		fs.writeFileSync(config, JSON.stringify({core: 'WordPress/WordPress#7.1.3', phpVersion: '8.1', port: 8899, testsEnvironment: false, plugins: [], mappings: {
			'wp-content/plugins/paper-to-quiz': path.join(dist, 'paper-to-quiz'),
			'wp-content/ptq-fixtures': path.join(root, 'tests'),
			'wp-content/ptq-exchange': exchange
		}, config: {WP_ENVIRONMENT_TYPE: 'local', DISABLE_WP_CRON: true}}));
		mysql(['start']);
		mysqlWp('core', 'install', '--url=http://localhost:8899', '--title=PTQTransferSource', '--admin_user=admin', '--admin_password=synthetic-transfer-only', '--admin_email=ptq@example.invalid', '--skip-email');
		mysqlWp('plugin', 'activate', 'paper-to-quiz');
		mysqlWp('option', 'update', 'paper_to_quiz_disposable_test', 'sqlite-1.3');
		mysql(['run', 'cli', 'env', 'PTQ_SQLITE_ACTION=seed', 'wp', 'eval-file', 'wp-content/ptq-fixtures/sqlite/fixture.php']);
		mysql(['run', 'cli', 'env', 'PTQ_TRANSFER_ACTION=export', 'PTQ_TRANSFER_PATH=wp-content/ptq-exchange/data.json', 'wp', 'eval-file', 'wp-content/ptq-fixtures/sqlite/transfer.php']);
		fs.mkdirSync(site);
		wp('core', 'download', '--version=7.1.3', '--locale=en_US');
		const driverZip = path.join(home, 'sqlite.zip');
		await download('https://downloads.wordpress.org/plugin/sqlite-database-integration.3.1.3.zip', driverZip);
		run('unzip', ['-q', driverZip, '-d', path.join(site, 'wp-content/plugins')]);
		fs.copyFileSync(path.join(site, 'wp-content/plugins/sqlite-database-integration/db.copy'), path.join(site, 'wp-content/db.php'));
		wp('config', 'create', '--dbname=ptq_transfer', '--dbuser=unused', '--dbpass=unused', '--skip-check');
		wp('config', 'set', 'WP_ENVIRONMENT_TYPE', 'local');
		wp('config', 'set', 'DISABLE_WP_CRON', 'true', '--raw');
		wp('core', 'install', '--url=http://localhost:8897', '--title=PTQTransferTarget', '--admin_user=admin', '--admin_password=synthetic-transfer-only', '--admin_email=ptq@example.invalid', '--skip-email');
		wp('option', 'update', 'paper_to_quiz_disposable_test', 'sqlite-1.3');
		wp('plugin', 'install', path.resolve('.package-under-test/paper-to-quiz.zip'), '--activate');
		const copy = path.join(home, 'ptq-unit-transfer.sqlite');
		wp('eval', '$s=new SQLite3(DB_PATH,SQLITE3_OPEN_READONLY);$t=new SQLite3(base64_decode("' + Buffer.from(copy).toString('base64') + '"));if(!$s->backup($t))throw new RuntimeException("Backup failed");$t->close();$s->close();');
		const transferEnv = {...nativeEnv, PTQ_TEST_SQLITE_PATH: copy, PTQ_TRANSFER_PATH: path.join(exchange, 'data.json')};
		run('php', ['tests/sqlite/transfer.php', 'import'], transferEnv);
		const verifyEnv = {...transferEnv, PTQ_TEST_AUTH_STATE: transferEnv.PTQ_TRANSFER_PATH};
		run('php', ['tests/sqlite/fixture.php', 'worker'], verifyEnv);
		run('php', ['tests/sqlite/fixture.php', 'verify'], verifyEnv);
		console.log('PTQ_SYNTHETIC_MYSQL_TO_SQLITE=passed (rows, IDs, ciphertext, private source and queued receipts)');
	} finally {
		try { mysql(['stop']); } catch {}
		// Never upload exchange/data.json: even synthetic exports include key material.
	}
})().catch(error => {console.error(error); process.exitCode = 1;});
