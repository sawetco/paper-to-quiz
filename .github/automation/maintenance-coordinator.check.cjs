"use strict";
const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
let reviewUnderTest;
const actualExists = fs.existsSync, actualRead = fs.readFileSync;
fs.existsSync = path => path === '.github/automation/security-review.json' ? reviewUnderTest !== undefined : actualExists(path);
fs.readFileSync = (path, ...args) => path === '.github/automation/security-review.json' ? JSON.stringify(reviewUnderTest) : actualRead(path, ...args);
const maintenance = require("./maintenance.cjs");
const config = JSON.parse(
  fs.readFileSync(".github/automation/config.json", "utf8"),
);

function fixture(runs, existing = []) {
  const calls = { created: [], updated: [], retried: [] };
  const rest = {
    repos: {
      get: async () => ({ data: { default_branch: "main" } }),
      getBranch: async () => ({ data: { commit: { sha: "current-source" } } }),
    },
    pulls: { list: async () => ({ data: [] }) },
    actions: {
      listRepoWorkflows: async () => ({data: [...config.workflowNames,...(config.monitoredWorkflows||[])].map((name,id)=>({name,id}))}),
      listWorkflowRuns: async ({workflow_id}) => ({data: {workflow_runs: workflow_id===0 ? runs : [{head_branch:'main',head_sha:'current-source',event:'push',status:'completed',conclusion:'success'}]}}),
      listWorkflowRunsForRepo: async () => ({ data: { workflow_runs: runs } }),
      reRunWorkflowFailedJobs: async (p) => calls.retried.push(p),
    },
    issues: {
      listForRepo: async () => ({ data: existing }),
      create: async (p) => calls.created.push(p),
      update: async (p) => calls.updated.push(p),
    },
  };
  const github = { rest, paginate: async (fn, args) => (await fn(args)).data };
  const summary = {
    addHeading() {
      return this;
    },
    addRaw() {
      return this;
    },
    async write() {},
  };
  const execute = () =>
    maintenance({
      github,
      context: { repo: { owner: "sawetco", repo: "fixture" } },
      core: { summary },
    });
  return { calls, execute, github, rest };
}
const failed = {
  id: 1,
  name: config.workflowNames[0],
  head_branch: "main",
  head_sha: "current-source",
  event: "push",
  status: "completed",
  conclusion: "failure",
  run_attempt: 2,
  html_url: "https://github.com/sawetco/fixture/actions/runs/1",
};
test("a pending new run suppresses notifications from an older failed run", async () => {
  const { calls, execute } = fixture([
    { ...failed, id: 2, status: "in_progress", conclusion: null },
    failed,
  ]);
  await execute();
  assert.deepEqual(calls, { created: [], updated: [], retried: [] });
});
test("failures from an older source commit do not create a fresh attention issue", async () => {
  const { calls, execute } = fixture([
    { ...failed, head_sha: "previous-source" },
  ]);
  await execute();
  assert.equal(calls.created.length, 0);
  assert.equal(calls.retried.length, 0);
});
test("a current failure gets one retry before escalating", async () => {
  const { calls, execute } = fixture([{ ...failed, run_attempt: 1 }]);
  await execute();
  assert.equal(calls.retried.length, 1);
  assert.equal(calls.created.length, 0);
});
test("a persistent current failure creates one actionable issue for the owner", async () => {
  const { calls, execute } = fixture([failed]);
  await execute();
  assert.equal(calls.created.length, 1);
  assert.deepEqual(calls.created[0].assignees, ["sawetco"]);
  assert.match(calls.created[0].body, /actions\/runs\/1/);
  assert.equal(calls.retried.length, 0);
});
test("a resolved attention issue is closed without sending a repeated comment", async () => {
  const issue = {
    number: 7,
    title: "Maintenance requires attention",
    body: "<!-- autonomous-maintenance:v1 -->",
  };
  const { calls, execute } = fixture(
    [{ ...failed, conclusion: "success" }],
    [issue],
  );
  await execute();
  assert.equal(calls.created.length, 0);
  assert.equal(calls.updated[0].state, "closed");
});
test("an outdated dependency PR is classified against its merge base before the App updates it", async () => {
  const f = fixture([]),
    refs = [];
  const pr = {
    number: 8,
    draft: false,
    user: { login: "dependabot[bot]" },
    labels: [],
    html_url: "https://github.com/sawetco/fixture/pull/8",
    mergeable_state: "blocked",
    base: { sha: "current-source", repo: { full_name: "sawetco/fixture" } },
    head: {
      sha: "dependency-head",
      ref: "dependabot/test",
      repo: { full_name: "sawetco/fixture" },
    },
  };
  f.rest.pulls.list = async () => ({ data: [pr] });
  f.rest.pulls.get = async () => ({ data: pr });
  f.rest.pulls.listFiles = async () => ({
    data: [{ filename: "package.json", status: "modified" }],
  });
  f.rest.repos.compareCommitsWithBasehead = async () => ({
    data: { merge_base_commit: { sha: "reviewed-base" }, behind_by: 1 },
  });
  f.rest.repos.getContent = async ({ ref }) => {
    refs.push(ref);
    const value =
      ref === "reviewed-base"
        ? { dependencies: { a: "1.2.3" } }
        : ref === "dependency-head"
          ? { dependencies: { a: "1.2.4" } }
          : {
              dependencies: { a: "1.2.3" },
              scripts: { postinstall: "node newly-reviewed-main.js" },
            };
    return {
      data: {
        encoding: "base64",
        content: Buffer.from(JSON.stringify(value)).toString("base64"),
      },
    };
  };
  f.rest.checks = { listForRef: async () => ({ data: [] }) };
  f.rest.repos.getCombinedStatusForRef = async () => ({ data: { statuses: [] } });
  f.rest.repos.getCommit = async () => ({ data: { commit: { committer: { date: "2020-01-01" } } } });
  const updates = [];
  const appGithub = {
    rest: { pulls: { updateBranch: async (p) => updates.push(p) } },
  };
  const summary = {
    addHeading() {
      return this;
    },
    addRaw() {
      return this;
    },
    async write() {},
  };
  await maintenance({
    github: f.github,
    appGithub,
    context: { repo: { owner: "sawetco", repo: "fixture" } },
    core: { summary },
  });
  assert.deepEqual(refs, ["reviewed-base", "dependency-head"]);
  assert.equal(updates.length, 1);
  assert.equal(updates[0].expected_head_sha, "dependency-head");
  assert.equal(f.calls.created.length, 0);
});


const existingIssue={number:7,title:'Maintenance requires attention',body:'<!-- autonomous-maintenance:v1 --> existing unresolved issue'};
function dependencyFixture({checks=[],statuses=[],age=72,action=false,from='v1.2.3',to='v1.2.4',title='Routine dependency update',existing=[existingIssue],runs=[]}={}) {
  const f=fixture(runs,existing);f.calls.merged=[];f.calls.dispatched=[];
  const pr={number:8,title,updated_at:new Date(Date.now()-age*3600000).toISOString(),draft:false,user:{login:'dependabot[bot]'},labels:[],html_url:'https://github.com/sawetco/fixture/pull/8',mergeable:true,mergeable_state:'clean',base:{sha:'current-source',repo:{full_name:'sawetco/fixture'}},head:{sha:'dependency-head',ref:'dependabot/test',repo:{full_name:'sawetco/fixture'}}};
  f.rest.pulls.list=async()=>({data:[pr]});f.rest.pulls.get=async()=>({data:pr});
  f.rest.pulls.listFiles=async()=>({data:[{filename:action?'.github/workflows/ci.yml':'package.json',status:'modified'}]});
  f.rest.pulls.merge=async p=>{f.calls.merged.push(p);return {data:{merged:true}}};
  f.rest.repos.compareCommitsWithBasehead=async()=>({data:{merge_base_commit:{sha:'reviewed-base'},behind_by:0}});
  f.rest.repos.getContent=async({ref})=>({data:{encoding:'base64',content:Buffer.from(action?`jobs:\n  build:\n    steps:\n      - uses: actions/example@${(ref==='reviewed-base'?'a':'b').repeat(40)} # title cannot authorize this\n`:JSON.stringify({dependencies:{a:ref==='reviewed-base'?'1.2.3':'1.2.4'}})).toString('base64')}});
  f.rest.repos.getCommit=async()=>({data:{commit:{committer:{date:pr.updated_at}}}});
  f.rest.repos.listTags=async()=>({data:[{name:from,commit:{sha:'a'.repeat(40)}},{name:to,commit:{sha:'b'.repeat(40)}}]});
  f.rest.checks={listForRef:async()=>({data:checks})};
  f.rest.repos.getCombinedStatusForRef=async()=>({data:{statuses}});
  f.rest.actions.createWorkflowDispatch=async p=>f.calls.dispatched.push(p);
  return f;
}
const green=config.requiredChecks.map((name,id)=>({id,name,app:{slug:'github-actions'},status:'completed',conclusion:'success'}));
for(const kind of ['manifest','action'])test(`${kind} legitimate minor/patch updates still merge expected SHA`,async()=>{const f=dependencyFixture({checks:green,action:kind==='action'});await f.execute();assert.equal(f.calls.merged.length,1);assert.equal(f.calls.merged[0].sha,'dependency-head');assert.ok(f.calls.dispatched.length);});
for(const [from,to,title] of [['v1.2.3','v2.0.0','Routine dependency update'],['v0.1.0','v0.2.0','Bump example from v0.1.0 to v0.2.0']])test(`unsafe Action ${from}→${to} never merges regardless of title`,async()=>{const f=dependencyFixture({checks:green,action:true,from,to,title});await f.execute();assert.equal(f.calls.merged.length,0);assert.ok(f.calls.updated[0].body.includes('major update'));});
test('fresh missing checks do not spam or close unresolved issue',async()=>{const f=dependencyFixture({age:1});await f.execute();assert.equal(f.calls.created.length,0);assert.equal(f.calls.updated.length,0);assert.equal(f.calls.merged.length,0)});
test('aged missing checks escalate without closing issue',async()=>{const f=dependencyFixture();await f.execute();assert.match(f.calls.updated[0].body,/missing/);assert.equal(f.calls.updated[0].state,undefined)});
for(const conclusion of ['skipped','neutral','startup_failure','stale'])test(`terminal required ${conclusion} escalates end to end`,async()=>{const f=dependencyFixture({checks:green.map(c=>({...c,conclusion}))});await f.execute();assert.equal(f.calls.merged.length,0);assert.match(f.calls.updated[0].body,new RegExp(conclusion))});
test('failed external status with successful Actions checks escalates',async()=>{const f=dependencyFixture({checks:green,statuses:[{context:'external',state:'failure'}]});await f.execute();assert.match(f.calls.updated[0].body,/external: failure/);assert.equal(f.calls.merged.length,0)});
test('fresh pending required check retains issue without spam',async()=>{const f=dependencyFixture({age:1,checks:green.map(c=>({...c,status:'in_progress',conclusion:null}))});await f.execute();assert.equal(f.calls.updated.length,0)});
test('stuck pending required check escalates after 24 hours',async()=>{const f=dependencyFixture({checks:green.map(c=>({...c,status:'in_progress',conclusion:null}))});await f.execute();assert.match(f.calls.updated[0].body,/24 hours/)});
test('bounded retry retains unresolved existing issue',async()=>{const f=dependencyFixture({checks:green.map(c=>({...c,conclusion:'failure'})),runs:[{...failed,head_sha:'dependency-head',run_attempt:1}]});await f.execute();assert.equal(f.calls.retried.length,1);assert.equal(f.calls.updated.length,0)});
test('pending default-branch retry does not close existing issue',async()=>{const f=fixture([{...failed,status:'in_progress',conclusion:null}],[existingIssue]);await f.execute();assert.equal(f.calls.updated.length,0)});
for(const conclusion of ['cancelled','startup_failure','stale'])test(`terminal monitored ${conclusion} does not close issue`,async()=>{const f=fixture([{...failed,conclusion}],[existingIssue]);await f.execute();assert.ok(f.calls.updated[0].body);assert.equal(f.calls.updated[0].state,undefined)});
test('security review expiry creates attention and preserves issue at boundary',async()=>{
  try {
    reviewUnderTest={reviewBefore:new Date(Date.now()).toISOString(),advisories:['GHSA-reviewed']};
    const f=fixture([{...failed,conclusion:'success'}],[existingIssue]);await f.execute();
    assert.match(f.calls.updated[0].body,/Security exception review is overdue/);assert.equal(f.calls.updated[0].state,undefined);
    const g=fixture([{...failed,conclusion:'success'}]);await g.execute();assert.equal(g.calls.created.length,1);
    reviewUnderTest={reviewBefore:new Date(Date.now()+86400000).toISOString()};
    const h=fixture([{...failed,conclusion:'success'}],[existingIssue]);await h.execute();assert.equal(h.calls.updated[0].state,'closed');
  } finally {reviewUnderTest=undefined;}
});
test('non-failure terminal monitor state escalates without invalid failed-jobs retry',async()=>{const f=fixture([{...failed,conclusion:'cancelled',run_attempt:1}],[existingIssue]);await f.execute();assert.equal(f.calls.retried.length,0);assert.ok(f.calls.updated[0].body)});

async function queueFixture({ pending = false, failedUpdate = false, existing = [], behind = true, conflict = false, age = 1 } = {}) {
  const f = dependencyFixture({ checks: green, existing, age });
  const original = (await f.rest.pulls.get()).data;
  const prs = [8,9,10].map(number => ({ ...original, number,
    head: { ...original.head, sha: `head-${number}` },
    mergeable: number === 8 && conflict ? false : true,
  }));
  f.rest.pulls.list = async () => ({ data: prs });
  f.rest.pulls.get = async ({ pull_number }) => ({ data: prs.find(p=>p.number===pull_number) });
  f.rest.repos.compareCommitsWithBasehead = async () => ({ data: {merge_base_commit:{sha:'reviewed-base'},behind_by:behind?1:0} });
  f.rest.checks.listForRef = async ({ref}) => ({ data: pending && ref==='head-8' ? green.map(c=>({...c,status:'in_progress',conclusion:null})) : green });
  f.calls.branchUpdates=[];
  const appGithub={rest:{pulls:{updateBranch:async p=>{
    f.calls.branchUpdates.push(p);
    if(failedUpdate) throw Error('uncertain API outcome');
  }}}};
  const summary={addHeading(){return this},addRaw(){return this},async write(){}};
  f.execute=(event={})=>maintenance({github:f.github,appGithub,context:{repo:{owner:'sawetco',repo:'fixture'},...event},core:{summary}});
  return f;
}
test('three behind safe PRs cause exactly one SHA-guarded update',async()=>{
  const f=await queueFixture(); await f.execute();
  assert.deepEqual(f.calls.branchUpdates.map(p=>[p.pull_number,p.expected_head_sha]),[[8,'head-8']]);
  assert.equal(f.calls.merged.length,0);
});
test('next event while selected PR runs checks cannot update another PR',async()=>{
  const f=await queueFixture({pending:true}); await f.execute(); await f.execute();
  assert.equal(f.calls.branchUpdates.length,0); assert.equal(f.calls.merged.length,0);
});
test('unknown branch-update outcome consumes one attempt and persists exact-head failure marker',async()=>{
  const f=await queueFixture({failedUpdate:true}); await f.execute();
  assert.equal(f.calls.branchUpdates.length,1);
  assert.match(f.calls.created[0].body,/maintenance-update-failed:8:head-8/);
  const next=await queueFixture({existing:[{...existingIssue,body:f.calls.created[0].body}]});
  await next.execute(); assert.equal(next.calls.branchUpdates[0].pull_number,9);
});
test('changed head is eligible after earlier exact-head update failure',async()=>{
  const f=await queueFixture({existing:[{...existingIssue,body:'<!-- autonomous-maintenance:v1 --> <!-- maintenance-update-failed:8:old-head -->'}]});
  await f.execute(); assert.equal(f.calls.branchUpdates[0].pull_number,8);
});
test('known conflict cannot starve later candidates',async()=>{
  const f=await queueFixture({conflict:true}); await f.execute();
  assert.equal(f.calls.branchUpdates[0].pull_number,9);
});
test('stuck pending head escalates and allows another head to progress',async()=>{
  const f=await queueFixture({pending:true,age:25}); await f.execute();
  assert.equal(f.calls.branchUpdates[0].pull_number,9);
  assert.match(f.calls.created[0].body,/pending beyond 24 hours/);
});
test('last required completion permits exactly one merge and retains explicit postmerge checks',async()=>{
  const f=await queueFixture({behind:false}); await f.execute();
  assert.equal(f.calls.merged.length,1); assert.equal(f.calls.branchUpdates.length,0);
  for(const workflow of [config.qualityWorkflow,config.securityWorkflow,...config.supplementalWorkflows||[]])
    assert.ok(f.calls.dispatched.some(p=>p.workflow_id===workflow));
});

test('operator urgent selection can progress despite another running PR, without weakening checks',async()=>{
  const f=await queueFixture({pending:true});
  await f.execute({eventName:'workflow_dispatch',ref:'refs/heads/main',payload:{inputs:{priority_pr:'9'}}});
  assert.deepEqual(f.calls.branchUpdates.map(p=>p.pull_number),[9]);
  const running=await queueFixture({pending:true});
  await running.execute({eventName:'workflow_dispatch',ref:'refs/heads/main',payload:{inputs:{priority_pr:'8'}}});
  assert.equal(running.calls.branchUpdates.length,0);
});
test('priority input on nondefault dispatch or untrusted event cannot bypass queue',async()=>{
  for(const event of [{eventName:'workflow_dispatch',ref:'refs/heads/feature'},{eventName:'pull_request_target',ref:'refs/heads/main'}]){
    const f=await queueFixture({pending:true});
    await f.execute({...event,payload:{inputs:{priority_pr:'9'}}});
    assert.equal(f.calls.branchUpdates.length,0);
  }
});

test('adversarial newer green and running arrivals do not starve the older behind fix across passes',async()=>{
  const f=await queueFixture();
  const original=(await f.rest.pulls.list()).data;
  let epoch=0;
  f.rest.pulls.list=async()=>({data:[original[0],...Array.from({length:epoch+1},(_,i)=>({
    ...original[1],number:100+i,head:{...original[1].head,sha:`routine-${i}`}
  }))]});
  f.rest.pulls.get=async({pull_number})=>({data:(await f.rest.pulls.list()).data.find(p=>p.number===pull_number)});
  f.rest.repos.compareCommitsWithBasehead=async({basehead})=>({data:{merge_base_commit:{sha:'reviewed-base'},behind_by:basehead.endsWith('head-8')&&epoch===0?1:0}});
  f.rest.checks.listForRef=async({ref})=>({data:ref==='head-8'&&epoch>0&&epoch<12
    ?green.map(c=>({...c,status:'in_progress',conclusion:null}))
    :ref.startsWith('routine-')&&Number(ref.split('-')[1])%2
      ?green.map(c=>({...c,status:'in_progress',conclusion:null})):green});
  await f.execute();
  assert.deepEqual(f.calls.branchUpdates.map(p=>p.pull_number),[8]);
  for(epoch=1;epoch<12;epoch++){
    await f.execute();
    assert.equal(f.calls.branchUpdates.length,1);
    assert.equal(f.calls.merged.length,0);
  }
  await f.execute();
  assert.equal(f.calls.merged[0].pull_number,8);
  assert.equal(f.calls.merged[0].sha,'head-8');
  assert.equal(f.calls.merged.length,1);
});
test('skipping a failed exact head never updates the next head while its checks run',async()=>{
  const f=await queueFixture({existing:[{...existingIssue,body:'<!-- autonomous-maintenance:v1 --> <!-- maintenance-update-failed:8:head-8 -->'}]});
  f.rest.checks.listForRef=async({ref})=>({data:ref==='head-9'?green.map(c=>({...c,status:'in_progress',conclusion:null})):green});
  await f.execute();
  assert.equal(f.calls.branchUpdates.length,0);
});

test('one failed-job retry reserves the pass across several failing PRs',async()=>{
  const f=await queueFixture({behind:false,age:25});
  f.rest.checks.listForRef=async()=>({data:green.map(c=>({...c,conclusion:'failure'}))});
  f.rest.actions.listWorkflowRunsForRepo=async()=>({data:{workflow_runs:[{...failed,run_attempt:1}]}});
  await f.execute();
  assert.equal(f.calls.retried.length,1);
  assert.equal(f.calls.branchUpdates.length,0);
  assert.equal(f.calls.merged.length,0);
});
