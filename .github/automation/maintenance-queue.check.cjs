'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { queuePlan } = require('./maintenance-queue.cjs');
const now = Date.parse('2026-11-02T12:00:00Z');
const green = { id: 1, name: 'Quality', app: { slug: 'github-actions' }, status: 'completed', conclusion: 'success' };
function candidate(number, changes = {}) {
  return { pr: { number, mergeable: true }, comparison: { behind_by: 1 }, checks: [green], status: { statuses: [] }, observedAt: new Date(now - 3600000).toISOString(), ...changes };
}
for (const status of ['queued', 'in_progress', 'waiting', 'pending'])
  test(`active ${status} head holds the entire queue across events`, () => {
    const active = candidate(9, { checks: [{ ...green, status, conclusion: null }] });
    for (const entries of [[candidate(10),active], [active,candidate(10)]])
      assert.equal(queuePlan(entries, ['Quality'], now).wait, true);
  });
test('fresh missing checks hold queue, old missing checks permit recovery', () => {
  assert.equal(queuePlan([candidate(1,{checks:[]})],['Quality'],now).wait,true);
  assert.equal(queuePlan([candidate(1,{checks:[],observedAt:'2020-01-01'})],['Quality'],now).wait,false);
});
test('stuck checks expire after 24 hours, never authorize merge', () => {
  const c=candidate(1,{checks:[{...green,status:'in_progress'}], observedAt:new Date(now-24*3600000).toISOString()});
  assert.equal(queuePlan([c],['Quality'],now).wait,false);
});
test('external pending blocks queue; latest Actions success supersedes old pending', () => {
  assert.equal(queuePlan([candidate(1,{status:{statuses:[{state:'pending'}]}})],['Quality'],now).wait,true);
  assert.equal(queuePlan([candidate(1,{checks:[{...green,id:0,status:'in_progress'},green]})],['Quality'],now).wait,false);
});
test('oldest eligible candidate precedes newer green routine PRs', () => {
  const ready=candidate(3,{comparison:{behind_by:0}});
  assert.deepEqual(queuePlan([candidate(5),ready,candidate(1)],['Quality'],now).ordered.map(c=>c.pr.number),[1,3,5]);
});
test('a conflict cannot hold the queue with stale queued checks', () => {
  assert.equal(queuePlan([candidate(1,{pr:{number:1,mergeable:false},checks:[]})],['Quality'],now).wait,false);
});

test('newer running routine checks cannot block an older eligible fix', () => {
  const older=candidate(1);
  const newer=candidate(2,{checks:[{...green,status:'in_progress'}]});
  assert.equal(queuePlan([newer,older],['Quality'],now).wait,false);
});
test('failed exact head does not block a newer candidate with its pending checks', () => {
  const failed=candidate(1,{updateBlocked:true,checks:[{...green,status:'in_progress'}]});
  assert.equal(queuePlan([failed,candidate(2)],['Quality'],now).wait,false);
});
