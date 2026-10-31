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
