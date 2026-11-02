'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const maintenance = require('./maintenance.cjs');
const config = JSON.parse(fs.readFileSync('.github/automation/config.json', 'utf8'));

function fixture() {
  const calls = { updates: [], retries: [], reads: [] };
  const green = { head_branch: 'main', head_sha: 'current', event: 'push', status: 'completed', conclusion: 'success', run_attempt: 2, html_url: 'https://example.test/run' };
  const evidence = {
    releaseRun: { ...green, head_sha: 'published' },
    releases: [{ tag_name: 'v1.2.3', draft: false, prerelease: false, published_at: '2026-11-01T00:00:00Z' }],
    pkg: { version: '1.2.3', files: ['src', 'README.md'] },
    comparison: { status: 'ahead', behind_by: 0, base_commit: { sha: 'published' }, merge_base_commit: { sha: 'published' }, files: [{ filename: '.github/workflows/maintenance.yml', status: 'modified' }] },
    pulls: [], otherRuns: {},
  };
  const workflows = [...config.workflowNames, ...config.monitoredWorkflows].map(name => ({ name, id: name }));
  const rest = {
    repos: {
      get: async () => ({ data: { default_branch: 'main' } }),
      getBranch: async () => ({ data: { commit: { sha: 'current' } } }),
      listReleases: async () => ({ data: evidence.releases }),
      getContent: async p => {
        calls.reads.push(p);
        return { data: { encoding: 'base64', content: Buffer.from(JSON.stringify(evidence.pkg)).toString('base64') } };
      },
      compareCommitsWithBasehead: async p => { calls.reads.push(p); return { data: evidence.comparison }; },
    },
    pulls: { list: async () => ({ data: evidence.pulls }), get: async () => ({ data: evidence.pulls[0] }) },
    actions: {
      listRepoWorkflows: async () => ({ data: workflows }),
      listWorkflowRuns: async ({ workflow_id }) => ({ data: { workflow_runs: workflow_id === 'Release' ? evidence.releaseRun ? [evidence.releaseRun] : [] : [evidence.otherRuns[workflow_id] || green] } }),
      reRunWorkflowFailedJobs: async p => calls.retries.push(p),
    },
    issues: {
      listForRepo: async () => ({ data: [{ number: 65, title: 'Maintenance requires attention', body: '<!-- autonomous-maintenance:v1 --> stale item' }] }),
      update: async p => calls.updates.push(p),
      create: async () => { throw Error('Unexpected new issue'); },
    },
  };
  const summary = { addHeading() { return this; }, addRaw() { return this; }, async write() {} };
  return { calls, evidence, rest, execute: () => maintenance({ github: { rest, paginate: async (fn, args) => (await fn(args)).data }, context: { repo: { owner: 'sawetco', repo: 'paper-to-quiz' } }, core: { summary } }) };
}
const closed = f => f.calls.updates.some(p => p.state === 'closed');
test('internal-only commits close stale attention with an older successful Release', async () => {
  const f = fixture(); await f.execute(); assert.equal(closed(f), true);
  assert.equal(f.calls.reads[0].ref, 'current');
  assert.equal(f.calls.reads[1].basehead, 'v1.2.3...current');
  assert.equal(f.calls.retries.length, 0);
});
test('published baseline can prove no release required without a retained Release run', async () => {
  const f = fixture(); f.evidence.releaseRun = null; await f.execute(); assert.equal(closed(f), true);
});
for (const filename of ['src/plugin.php', 'README.md', 'package-lock.json']) test(`distributable ${filename} still waits for Release`, async () => {
  const f = fixture(); f.evidence.comparison.files = [{ filename, status: 'modified' }]; await f.execute(); assert.equal(closed(f), false);
});
test('renaming a shipped file outside distribution still waits', async () => {
  const f = fixture(); f.evidence.comparison.files = [{ filename: 'internal.md', previous_filename: 'README.md', status: 'renamed' }]; await f.execute(); assert.equal(closed(f), false);
});
test('a bumped version awaiting publication still waits', async () => {
  const f = fixture(); f.evidence.pkg.version = '1.2.4'; await f.execute(); assert.equal(closed(f), false);
});
test('an existing draft release plan still waits', async () => {
  const f = fixture(); f.evidence.pulls = [{ number: 67, draft: true, head: { ref: 'automation/release' } }]; await f.execute(); assert.equal(closed(f), false);
});
for (const head_sha of ['published', 'current']) {
  test(`pending Release at ${head_sha} still waits`, async () => {
    const f = fixture(); Object.assign(f.evidence.releaseRun, { head_sha, status: 'in_progress', conclusion: null }); await f.execute(); assert.equal(closed(f), false);
  });
  test(`actual Release failure at ${head_sha} remains actionable`, async () => {
    const f = fixture(); Object.assign(f.evidence.releaseRun, { head_sha, conclusion: 'failure' }); await f.execute(); assert.equal(closed(f), false); assert.match(f.calls.updates[0].body, /Release.*failed/);
    assert.equal(f.calls.retries.length, 0);
  });
  for (const conclusion of ['skipped', 'neutral', null]) test(`ambiguous Release ${conclusion} at ${head_sha} fails closed`, async () => {
    const f = fixture(); Object.assign(f.evidence.releaseRun, { head_sha, conclusion }); await f.execute(); assert.equal(closed(f), false);
  });
}
for (const name of ['Quality', 'Security', 'CodeQL', 'Plan product release']) test(`${name} freshness remains required`, async () => {
  const f = fixture(); f.evidence.otherRuns[name] = { ...f.evidence.releaseRun }; await f.execute(); assert.equal(closed(f), false);
});
const uncertain = {
  'missing baseline': f => { f.evidence.releases = []; },
  'draft baseline': f => { f.evidence.releases[0].draft = true; },
  'unpublished baseline': f => { delete f.evidence.releases[0].published_at; },
  'missing files': f => { delete f.evidence.comparison.files; },
  'truncated files': f => { f.evidence.comparison.files = Array.from({ length: 300 }, () => ({ filename: '.github/internal', status: 'modified' })); },
  'diverged baseline': f => { f.evidence.comparison.status = 'diverged'; },
  'unrelated baseline': f => { f.evidence.comparison.merge_base_commit.sha = 'other'; },
  'missing rename source': f => { f.evidence.comparison.files[0].status = 'renamed'; },
  'unknown file status': f => { f.evidence.comparison.files[0].status = 'unknown'; },
  'missing filename': f => { f.evidence.comparison.files = [{}]; },
  'missing allowlist': f => { delete f.evidence.pkg.files; },
  'API error': f => { f.rest.repos.listReleases = async () => { throw Error('Unavailable'); }; },
  'malformed metadata': f => { f.rest.repos.getContent = async () => ({ data: { encoding: 'base64', content: 'eA==' } }); },
};
for (const [name, mutate] of Object.entries(uncertain)) test(`${name} fails closed`, async () => {
  const f = fixture(); mutate(f); await f.execute(); assert.equal(closed(f), false);
});

test('current publication failure retains the existing single-retry behavior', async () => {
  const f = fixture(); Object.assign(f.evidence.releaseRun, { head_sha: 'current', conclusion: 'failure', run_attempt: 1 });
  await f.execute(); assert.equal(closed(f), false); assert.equal(f.calls.retries.length, 1);
});
