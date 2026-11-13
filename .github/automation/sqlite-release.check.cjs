'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, 'test-sqlite.cjs'), 'utf8');

for (const version of ['1.4.0', '2.0.0']) {
	test('SQLite clean-install gate expects source release ' + version, async () => {
		const calls = [];
		const processMock = {
			cwd: () => '/synthetic',
			env: {PTQ_WP_VERSION: '7.1.3', PTQ_SQLITE_VERSION: '3.1.3', PTQ_EXPECTED_VERSION: 'stale'},
			stdout: {write() {}}, stderr: {write() {}},
		};
		const modules = {
			'node:fs': {
				readFileSync: () => JSON.stringify({version}),
				mkdtempSync: () => '/synthetic/disposable',
				mkdirSync() {}, writeFileSync() {}, copyFileSync() {},
			},
			'node:child_process': {spawnSync(command, args, options) {
				calls.push({command, args, options});
				// Stop before PHPUnit/browser setup, after observing the real fixture invocation.
				return {status: args.includes('eval-file') ? 1 : 0,
					stdout: args.includes('version') ? '7.1.3\n' : '', stderr: ''};
			}},
		};
		vm.runInNewContext(source, {
			require: name => modules[name] || require(name), process: processMock,
			console: {log() {}, error() {}}, Buffer,
			fetch: async () => ({ok: true, arrayBuffer: async () => new ArrayBuffer(0)}),
		});
		await new Promise(resolve => setImmediate(resolve));
		const fixture = calls.find(call => call.args.includes('eval-file'));
		assert.ok(fixture, 'The clean-install fixture must run.');
		assert.equal(fixture.options.env.PTQ_UPGRADE_PHASE, 'clean');
		assert.equal(fixture.options.env.PTQ_EXPECTED_VERSION, version);
		assert.ok(fixture.args.some(arg => arg.endsWith('tests/release-upgrade.php')));
		assert.equal(processMock.exitCode, 1, 'Fixture failures must fail the gate.');
	});
}
