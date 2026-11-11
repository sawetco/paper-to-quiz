/** Real WordPress, shared SQLite file, independent native PHP processes. */
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');

const php = process.env.PTQ_PHP_BINARY || 'php';
const fixture = path.resolve(__dirname, '../tests/sqlite/fixture.php');
if (!process.env.PTQ_TEST_WP_LOAD) {
	throw new Error('PTQ_TEST_WP_LOAD must identify a marked disposable local WordPress install.');
}
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ptq-sqlite-concurrency-'));
const report = [];
function start(action, env = {}) {
	const child = spawn(php, [fixture, action], {env: {...process.env, ...env}, windowsHide: true});
	let stdout = '';
	let stderr = '';
	child.stdout.on('data', chunk => { stdout += chunk; });
	child.stderr.on('data', chunk => { stderr += chunk; });
	const done = new Promise((resolve, reject) => {
		child.on('error', reject);
		child.on('exit', code => {
			if (code === 0) resolve({stdout: stdout.trim(), stderr: stderr.trim()});
			else reject(new Error(action + ' exited ' + code + ': ' + stdout + '\n' + stderr));
		});
	});
	return {child, done, output: () => stdout};
}
async function run(action, env = {}) {
	const result = await start(action, env).done;
	process.stdout.write(action + ': ' + result.stdout + '\n');
	return result.stdout;
}
async function waitFor(predicate, description) {
	const until = Date.now() + 15000;
	while (!predicate()) {
		if (Date.now() > until) throw new Error('Timed out: ' + description);
		await new Promise(resolve => setTimeout(resolve, 20));
	}
}
async function killAt(action, marker, env = {}) {
	const task = start(action, env);
	// Deliberately killed processes are expected to have nonzero exit status.
	task.done.catch(() => {});
	await waitFor(() => task.output().includes(marker), action);
	task.child.kill('SIGKILL');
	try { await task.done; } catch {}
}
async function add(unqueued = false) {
	return JSON.parse(await run('add', {PTQ_UNQUEUED: unqueued ? '1' : '0'})).index;
}

(async () => {
	try {
		await run('inspect');
		await run('verify');
		// Prove mutual exclusion and OS crash recovery without leases or mock locks.
		const owner = start('hold-lock');
		owner.done.catch(() => {});
		await waitFor(() => owner.output().includes('acquired'), 'lock owner');
		assert.equal(await run('lock'), 'contended');
		owner.child.kill('SIGKILL');
		try { await owner.done; } catch {}
		assert.equal(await run('lock'), 'acquired');
		report.push('cross-process lock contention and killed-owner recovery');

		const parallelIndexes = [];
		for (let i = 0; i < 40; ++i) parallelIndexes.push(await add(true));
		await Promise.all(parallelIndexes.map(async index => {
			for (let attempt = 0; attempt < 4; ++attempt) {
				const result = JSON.parse((await start('submit', {PTQ_RECEIPT_INDEX: String(index)}).done).stdout);
				if (result.status === 'processing') return;
				assert.equal(result.status, 503);
			}
			throw new Error('Parallel receipt did not recover from contention.');
		}));
		// Synchronize real processes before competing for the worker mutex.
		const barrier = path.join(temp, 'workers-go');
		const workers = Array.from({length: 3}, () => start('worker', {PTQ_BARRIER: barrier}));
		await waitFor(() => fs.readdirSync(temp).filter(name => name.startsWith('workers-go.')).length === 3, 'three PHP processes at barrier');
		fs.writeFileSync(barrier, 'go');
		await Promise.all(workers.map(worker => worker.done));
		// Bounded workers may leave a continuation batch; drain without changing receipts.
		await run('worker');
		await run('verify');
		report.push('three simultaneous queue workers, 40 pending submissions, unique outcomes');

		const index = await add(true);
		const receipts = await Promise.all(Array.from({length: 3}, () => start('submit', {PTQ_RECEIPT_INDEX: String(index)}).done));
		receipts.forEach(result => assert.match(result.stdout, /processing/));
		await run('worker');
		await run('verify');
		report.push('simultaneous duplicate receipt requests');

		for (const operation of ['transaction_begin', 'finalize_answer_insert', 'finalize_ranking_insert', 'result_email_enqueue', 'transaction_commit']) {
			await add();
			await run('fault', {PTQ_FAIL_OPERATION: operation});
			await run('verify-pending');
			await run('retry');
			await run('worker');
			await run('verify');
			report.push(operation + ' failure rolls back and recovers');
		}
		await add();
		await killAt('crash-before', 'crash-ready');
		await run('verify-pending');
		await run('worker');
		await run('verify');
		await add();
		await killAt('crash-after', 'crash-ready');
		await run('verify');
		report.push('process killed before commit and between commit and completion hook');

		const busyIndex = await add(true);
		const writer = start('hold-write');
		writer.done.catch(() => {});
		await waitFor(() => writer.output().includes('write-held'), 'SQLite writer');
		for (const deferred of ['0', '1']) {
			const started = Date.now();
			const busy = JSON.parse(await run('busy-submit', {PTQ_RECEIPT_INDEX: String(busyIndex), PTQ_DEFERRED: deferred}));
			assert.equal(busy.status, 503);
			assert.ok(Date.now() - started < 5000, 'Busy handling must be bounded including WordPress bootstrap.');
		}
		writer.child.kill('SIGKILL');
		try { await writer.done; } catch {}
		await run('submit', {PTQ_RECEIPT_INDEX: String(busyIndex)});
		await run('worker');
		await run('verify');
		report.push('real SQLITE_BUSY for immediate and deferred submissions, bounded 503 and recovery');

		await run('damage-schema');
		const schemas = await Promise.all(Array.from({length: 3}, () => start('schema').done));
		assert.ok(schemas.some(result => result.stdout.includes('schema-ready')));
		assert.equal(await run('schema'), 'schema-ready');
		await run('verify');
		report.push('missing index repair with three concurrent schema initializers');
		await run('email-ready');
		const emailLog = path.join(temp, 'email-log');
		await Promise.all(Array.from({length: 3}, () => start('email-worker', {PTQ_EMAIL_TEST_LOG: emailLog}).done));
		assert.deepEqual(fs.readFileSync(emailLog, 'utf8').trim().split('\n'), ['claimed-send']);
		report.push('three simultaneous email workers, one conditional claim and one synthetic send');
		process.stdout.write('PTQ_SQLITE_CONCURRENCY=' + JSON.stringify({passed: report}) + '\n');
	} finally {
		for (const entry of fs.readdirSync(temp)) fs.unlinkSync(path.join(temp, entry));
		fs.rmdirSync(temp);
	}
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
