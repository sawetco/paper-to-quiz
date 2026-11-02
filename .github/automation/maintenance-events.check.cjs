'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const workflow=fs.readFileSync('.github/workflows/maintenance.yml','utf8');
const expression=workflow.split('\n').find(l=>l.startsWith('    if: ')).slice(8);
const guard=new Function('github','vars','format',`return (${expression});`);
function allowed(event_name,action,login='dependabot[bot]',label='unrelated') {
  return guard({event_name,event:{workflow_run:{name:'Quality',conclusion:'success'},action,label:{name:label},pull_request:{user:{login}}}},{MAINTENANCE_APP_SLUG:'maint'},(_,slug)=>`${slug}[bot]`);
}
for(const action of ['opened','synchronize','reopened','ready_for_review'])
  test(`trusted ${action} retained`,()=>assert.equal(allowed('pull_request_target',action),true));
test('unrelated label avoids runner, release/contributions labels retained',()=>{
  assert.equal(allowed('pull_request_target','labeled'),false);
  for(const label of ['automation:release','automation:contributions']) assert.equal(allowed('pull_request_target','labeled','maint[bot]',label),true);
});
test('untrusted source cannot use a matching label',()=>assert.equal(allowed('pull_request_target','labeled','outsider','automation:release'),false));
for(const event of ['workflow_run','schedule','workflow_dispatch'])
  test(`${event} recovery/final gate retained`,()=>assert.equal(allowed(event,'completed'),true));
test('privileged checkout remains default-branch-only and does not persist credentials',()=>{
  assert.match(workflow,/ref: main/); assert.match(workflow,/persist-credentials: false/);
  assert.doesNotMatch(workflow,/ref:.*head/);
});
