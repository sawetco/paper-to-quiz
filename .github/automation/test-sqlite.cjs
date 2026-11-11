'use strict';
/** Native PHP/WordPress SQLite CI, isolated from every Studio/customer directory. */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const {createHash} = require('node:crypto');
const {spawnSync, spawn} = require('node:child_process');
const root = process.cwd();
const home = fs.mkdtempSync(path.join(process.env.RUNNER_TEMP || os.tmpdir(), 'ptq-sqlite-ci-'));
const site = path.join(home, 'wordpress');
const core = process.env.PTQ_WP_VERSION;
const driver = process.env.PTQ_SQLITE_VERSION;
assert.match(core || '', /^\d+\.\d+\.\d+$/);
assert.ok(['3.1.2', '3.1.3'].includes(driver));
const env = {...process.env, PTQ_TEST_WP_LOAD: path.join(site, 'wp-load.php'), PTQ_REST_HTTP_BASE: 'http://127.0.0.1:8898', WP_ENVIRONMENT_TYPE: 'local', PAPER_TO_QUIZ_ALLOW_REGRESSION: '1', PAPER_TO_QUIZ_ALLOW_E2E: '1'};
function run(command, args, extra = {}) {
	const result = spawnSync(command, args, {cwd: root, env: {...env, ...extra}, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024});
	process.stdout.write(result.stdout || '');
	process.stderr.write(result.stderr || '');
	if (result.status !== 0) throw new Error(command + ' failed: ' + args[0]);
	return result.stdout;
}
const wp = (...args) => run('wp', ['--path=' + site, ...args]);
const fixture = action => run('php', [path.join(root, 'tests/sqlite/fixture.php'), action]);
async function download(url, destination, expectedHash) {
	const response = await fetch(url);
	assert.ok(response.ok, 'Download failed: ' + url);
	const bytes = Buffer.from(await response.arrayBuffer());
	if (expectedHash) assert.equal(createHash('sha256').update(bytes).digest('hex'), expectedHash);
	fs.writeFileSync(destination, bytes);
}
(async () => {
	let server;
	let log;
	try {
		fs.mkdirSync(site);
		wp('core', 'download', '--version=' + core, '--locale=en_US');
		const driverZip = path.join(home, 'sqlite.zip');
		await download('https://downloads.wordpress.org/plugin/sqlite-database-integration.' + driver + '.zip', driverZip);
		run('unzip', ['-q', driverZip, '-d', path.join(site, 'wp-content/plugins')]);
		const integration = path.join(site, 'wp-content/plugins/sqlite-database-integration');
		fs.copyFileSync(path.join(integration, 'db.copy'), path.join(site, 'wp-content/db.php'));
		wp('config', 'create', '--dbname=ptq_sqlite', '--dbuser=unused', '--dbpass=unused', '--skip-check');
		wp('config', 'set', 'DB_PATH', path.join(site, 'wp-content/database/.ht.sqlite'));
		wp('config', 'set', 'WP_ENVIRONMENT_TYPE', 'local');
		wp('config', 'set', 'DISABLE_WP_CRON', 'true', '--raw');
		wp('core', 'install', '--url=http://127.0.0.1:8898', '--title=PTQSQLiteCI', '--admin_user=admin', '--admin_password=paper-to-quiz-integration', '--admin_email=ptq@example.invalid', '--skip-email');
		// Match Studio/wp-env's pretty REST URLs used by the shared browser suite.
		wp('rewrite', 'structure', '/%postname%/');
		wp('rewrite', 'flush');
		assert.equal(wp('core', 'version').trim(), core);
		wp('option', 'update', 'paper_to_quiz_disposable_test', 'sqlite-1.3');
		const zip = path.join(root, '.package-under-test/paper-to-quiz.zip');
		wp('plugin', 'install', zip, '--activate');
		run('wp', ['--path=' + site, 'eval-file', path.join(root, 'tests/release-upgrade.php')], {PTQ_UPGRADE_PHASE: 'clean', PTQ_EXPECTED_VERSION: '1.3.0'});
		run('composer', ['install', '--no-interaction', '--prefer-dist']);
		run('php', ['vendor/bin/phpunit']);
		wp('plugin', 'deactivate', 'paper-to-quiz');
		wp('plugin', 'delete', 'paper-to-quiz');
		const baseline = path.join(home, 'baseline.zip');
		await download('https://github.com/sawetco/paper-to-quiz/releases/download/v1.2.7/paper-to-quiz.zip', baseline, 'bae5d3d21a1e54b24fc1499bf17e186d75fbe82cb2b83c64d4af1d1dac84c6bd');
		wp('plugin', 'install', baseline, '--activate');
		fixture('seed');
		wp('plugin', 'install', zip, '--force', '--activate');
		fixture('worker');
		fixture('verify');
		run(process.execPath, [path.join(root, 'build-tools/run-sqlite-concurrency.js')]);
		fixture('park-email');
		log = fs.openSync(path.join(home, 'server.log'), 'a');
		server = spawn('php', ['-S', '127.0.0.1:8898', '-t', site], {env, stdio: ['ignore', log, log]});
		server.on('error', error => {throw error;});
		let ready = false;
		for (let i = 0; i < 40; ++i) {
			try { if ((await fetch(env.PTQ_REST_HTTP_BASE)).ok) {ready = true; break;} } catch {}
			await new Promise(resolve => setTimeout(resolve, 100));
		}
		assert.ok(ready, 'Native WordPress server did not become ready.');
		const restProbe = await fetch(env.PTQ_REST_HTTP_BASE + '/wp-json/paper-to-quiz/v1');
		assert.ok(restProbe.ok && restProbe.headers.get('content-type')?.includes('application/json'), 'Pretty REST routing must return JSON, not the theme HTML.');
		assert.equal((await restProbe.json()).namespace, 'paper-to-quiz/v1');
		wp('eval-file', path.join(root, 'tests/data-regression.php'));
		wp('eval-file', path.join(root, 'tests/rest-regression.php'));
		const output = wp('eval-file', path.join(root, 'tests/e2e-fixture.php'));
		const e2e = {PTQ_BASE_URL: 'http://127.0.0.1:8898'};
		for (const key of ['PTQ_E2E_ASSESSMENT_ID', 'PTQ_E2E_RANKING_PUBLIC', 'PTQ_E2E_RANKING_MEMBER', 'PTQ_E2E_RANKING_REVIEW']) {
			e2e[key] = output.match(new RegExp(key + '=(\\d+)'))?.[1];
			assert.ok(e2e[key], 'Missing E2E fixture: ' + key);
		}
		run(process.execPath, ['node_modules/@playwright/test/cli.js', 'test'], e2e);
		wp('plugin', 'deactivate', 'paper-to-quiz');
		wp('plugin', 'activate', 'paper-to-quiz');
		fixture('verify');
		console.log('PTQ_SQLITE_CI=passed core=' + core + ' driver=' + driver);
	} finally {
		if (server) server.kill('SIGTERM');
		if (log !== undefined) fs.closeSync(log);
		// The runner discards its directory; retain it on failure for diagnostics.
		console.log('SQLite diagnostics: ' + home);
	}
})().catch(error => {console.error(error); process.exitCode = 1;});
