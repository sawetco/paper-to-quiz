'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const actualExists = fs.existsSync;
fs.existsSync = path => path === '.github/automation/security-review.json' ? false : actualExists(path);
const maintenance=require('./maintenance.cjs');
const config=JSON.parse(fs.readFileSync('.github/automation/config.json','utf8'));

function fixture(runs,existing=[]){
 const calls={created:[],updated:[],retried:[]};
 const rest={
  repos:{get:async()=>({data:{default_branch:'main'}}),getBranch:async()=>({data:{commit:{sha:'current-source'}}})},
  pulls:{list:async()=>({data:[]})},
  actions: {
      listRepoWorkflows: async () => ({data: [...config.workflowNames,...(config.monitoredWorkflows||[])].map((name,id)=>({name,id}))}),
      listWorkflowRuns: async ({workflow_id}) => ({data: {workflow_runs: workflow_id===0 ? runs : [{head_branch:'main',head_sha:'current-source',event:'push',status:'completed',conclusion:'success'}]}}),listWorkflowRunsForRepo:async()=>({data:{workflow_runs:runs}}),reRunWorkflowFailedJobs:async p=>calls.retried.push(p)},
  issues:{listForRepo:async()=>({data:existing}),create:async p=>calls.created.push(p),update:async p=>calls.updated.push(p)},
 };
 const github={rest,paginate:async(fn,args)=>(await fn(args)).data};
 const summary={addHeading(){return this;},addRaw(){return this;},async write(){}};
 const execute=()=>maintenance({github,context:{repo:{owner:'sawetco',repo:'fixture'}},core:{summary}});
 return {calls,execute,github,rest};
}
const failed={id:1,name:config.workflowNames[0],head_branch:'main',head_sha:'current-source',event:'push',status:'completed',conclusion:'failure',run_attempt:2,html_url:'https://github.com/sawetco/fixture/actions/runs/1'};
test('a pending new run suppresses notifications from an older failed run',async()=>{
 const {calls,execute}=fixture([{...failed,id:2,status:'in_progress',conclusion:null},failed]);await execute();assert.deepEqual(calls,{created:[],updated:[],retried:[]});
});
test('failures from an older source commit do not create a fresh attention issue',async()=>{
 const {calls,execute}=fixture([{...failed,head_sha:'previous-source'}]);await execute();assert.equal(calls.created.length,0);assert.equal(calls.retried.length,0);
});
test('a current failure gets one retry before escalating',async()=>{
 const {calls,execute}=fixture([{...failed,run_attempt:1}]);await execute();assert.equal(calls.retried.length,1);assert.equal(calls.created.length,0);
});
test('a persistent current failure creates one actionable issue for the owner',async()=>{
 const {calls,execute}=fixture([failed]);await execute();assert.equal(calls.created.length,1);assert.deepEqual(calls.created[0].assignees,['sawetco']);assert.match(calls.created[0].body,/actions\/runs\/1/);assert.equal(calls.retried.length,0);
});
test('a resolved attention issue is closed without sending a repeated comment',async()=>{
 const issue={number:7,title:'Maintenance requires attention',body:'<!-- autonomous-maintenance:v1 -->'};
 const {calls,execute}=fixture([{...failed,conclusion:'success'}],[issue]);await execute();assert.equal(calls.created.length,0);assert.equal(calls.updated[0].state,'closed');
});
test('an outdated dependency PR is classified against its merge base before the App updates it',async()=>{
 const f=fixture([]),refs=[];
 const pr={number:8,draft:false,user:{login:'dependabot[bot]'},labels:[],html_url:'https://github.com/sawetco/fixture/pull/8',mergeable_state:'blocked',base:{sha:'current-source',repo:{full_name:'sawetco/fixture'}},head:{sha:'dependency-head',ref:'dependabot/test',repo:{full_name:'sawetco/fixture'}}};
 f.rest.pulls.list=async()=>({data:[pr]});f.rest.pulls.get=async()=>({data:pr});
 f.rest.pulls.listFiles=async()=>({data:[{filename:'package.json',status:'modified'}]});
 f.rest.repos.compareCommitsWithBasehead=async()=>({data:{merge_base_commit:{sha:'reviewed-base'},behind_by:1}});
 f.rest.repos.getContent=async({ref})=>{refs.push(ref);const value=ref==='reviewed-base'?{dependencies:{a:'1.2.3'}}:ref==='dependency-head'?{dependencies:{a:'1.2.4'}}:{dependencies:{a:'1.2.3'},scripts:{postinstall:'node newly-reviewed-main.js'}};return {data:{encoding:'base64',content:Buffer.from(JSON.stringify(value)).toString('base64')}};};
 const updates=[];const appGithub={rest:{pulls:{updateBranch:async p=>updates.push(p)}}};
 const summary={addHeading(){return this;},addRaw(){return this;},async write(){}};
 await maintenance({github:f.github,appGithub,context:{repo:{owner:'sawetco',repo:'fixture'}},core:{summary}});
 assert.deepEqual(refs,['reviewed-base','dependency-head']);assert.equal(updates.length,1);assert.equal(updates[0].expected_head_sha,'dependency-head');assert.equal(f.calls.created.length,0);
});
