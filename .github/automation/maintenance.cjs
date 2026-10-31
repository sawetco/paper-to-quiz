'use strict';
const fs = require('node:fs');
const {spawnSync}=require('node:child_process');
function structuredSafe(kind,path,before,after){
 const result=spawnSync('python3',['.github/automation/classify-manifest.py'],{input:JSON.stringify({kind,path,before,after}),encoding:'utf8',timeout:10000});
 return result.status===0 && result.stdout.trim()==='safe';
}
const {manifestSafe,acceptableChecks,sameRepository} = require('./policy.cjs');

const {actionVerifier, checkBlockers} = require('./maintenance-guards.cjs');

module.exports = async ({github,context,core,appGithub}) => {
  const config = JSON.parse(fs.readFileSync('.github/automation/config.json','utf8'));
  const {owner,repo} = context.repo;
  const fullName = `${owner}/${repo}`;
  const api = github.rest;
  const appLogin = process.env.MAINTENANCE_APP_SLUG ? `${process.env.MAINTENANCE_APP_SLUG}[bot]` : '';
  const notes = [];
  const attention = [];
  let unresolved = false;
  const verifyActions = actionVerifier(github);
  const reviewPath = '.github/automation/security-review.json';
  if (fs.existsSync(reviewPath)) {
    const review = JSON.parse(fs.readFileSync(reviewPath, 'utf8'));
    if (!Number.isFinite(Date.parse(review.reviewBefore)) || Date.now() >= Date.parse(review.reviewBefore))
      attention.push('- Security exception review is overdue; review the advisory evidence in security-review.json.');
  }
  const getText = async (path,ref) => {
    const {data} = await api.repos.getContent({owner,repo,path,ref});
    if (Array.isArray(data) || data.encoding !== 'base64') throw new Error('Expected file content');
    return Buffer.from(data.content,'base64').toString('utf8');
  };
  const dispatch = async (workflow,ref) => api.actions.createWorkflowDispatch({owner,repo,workflow_id:workflow,ref});
  const defaultBranch = (await api.repos.get({owner,repo})).data.default_branch;
  const defaultHead = (await api.repos.getBranch({owner,repo,branch:defaultBranch})).data.commit.sha;
  const pulls = await github.paginate(api.pulls.list,{owner,repo,state:'open',base:defaultBranch,per_page:100});
  for (const summary of pulls) {
    const pr = (await api.pulls.get({owner,repo,pull_number:summary.number})).data;
    if (pr.draft || !sameRepository(pr,fullName)) continue;
    const release = ['github-actions[bot]',appLogin].includes(pr.user.login) && pr.head.ref === 'automation/release';
    const contributions = repo === 'sawetco' && ['github-actions[bot]',appLogin].includes(pr.user.login) && pr.head.ref === 'automation/contributions';
    if (pr.user.login !== 'dependabot[bot]' && !release && !contributions) continue;
    const files = await github.paginate(api.pulls.listFiles,{owner,repo,pull_number:pr.number,per_page:100});
    if((release && !pr.labels.some(l=>l.name==='automation:release')) || (contributions && !pr.labels.some(l=>l.name==='automation:contributions')))continue;
    const comparison=(await api.repos.compareCommitsWithBasehead({owner,repo,basehead:`${pr.base.sha}...${pr.head.sha}`})).data;
    const changeBase=comparison.merge_base_commit?.sha;
    if(!changeBase){attention.push(`- [#${pr.number}](${pr.html_url}): cannot determine the reviewed change base.`);continue;}
    let safe = true;
    if (contributions) {
      safe = files.length > 0 && files.every(f=>['data/github-contributions.json','.github/readme-stats.svg'].includes(f.filename)) && pr.labels.some(l=>l.name==='automation:contributions');
    } else if (release) {
      safe = files.length > 0 && files.every(f=>config.releaseFiles?.includes(f.filename)) && pr.labels.some(l=>l.name==='automation:release');
      // Generated version PRs are owned by the trusted release planner; no arbitrary source paths are accepted.
      if (safe) {
        const compare = comparison;
        safe = compare.commits.length <= 10 && compare.commits.every(c=>[pr.user.login,appLogin].includes(c.author?.login) && (c.commit.message.startsWith('chore(release): ') || c.parents.length===2 && /^Merge branch /.test(c.commit.message)));
      }
    } else {
      for (const f of files) {
        if (f.status !== 'modified') {safe=false;break;}
        if (/(^|\/)package\.json$/.test(f.filename)) {
          if (!manifestSafe(await getText(f.filename,changeBase),await getText(f.filename,pr.head.sha))) safe=false;
        } else if (f.filename.startsWith('.github/workflows/')) {
          if (!await verifyActions(await getText(f.filename, changeBase), await getText(f.filename, pr.head.sha))) safe = false;
        } else if (/(^|\/)requirements(?:-[\w-]+)?\.(?:txt|in)$/.test(f.filename)) {
          if(!structuredSafe('pip',f.filename,await getText(f.filename,changeBase),await getText(f.filename,pr.head.sha)))safe=false;
        } else if (f.filename.endsWith('Cargo.toml') || f.filename.endsWith('Cargo.lock')) {
          if(!structuredSafe('cargo', f.filename, await getText(f.filename,changeBase), await getText(f.filename,pr.head.sha)))safe=false;
        } else if (f.filename.endsWith('composer.json')) {
          if(!structuredSafe('composer',f.filename,await getText(f.filename,changeBase),await getText(f.filename,pr.head.sha)))safe=false;
        } else if (!/(^|\/)(package-lock\.json|pnpm-lock\.yaml|bun\.lock|composer\.lock|Cargo\.lock)$/.test(f.filename)) safe=false;
      }
      // Lockfile-only groups must state a minor/patch update, not silently bundle a major.
      if(!files.some(f=>/(package\.json|requirements.*\.(?:txt|in)|Cargo\.(toml|lock)|composer\.json|workflows\/)/.test(f.filename))) safe=false;
    }
    if (!safe) {attention.push(`- [#${pr.number}](${pr.html_url}): major update or a change outside the automatic dependency policy.`);continue;}
    if (comparison.behind_by > 0 || pr.mergeable_state === 'behind') {
      unresolved = true;
      if(!appGithub){attention.push(`- [#${pr.number}](${pr.html_url}): finish the maintenance GitHub App installation so branch updates can run CI without human approval.`);continue;}
      try {
        await appGithub.rest.pulls.updateBranch({owner,repo,pull_number:pr.number,expected_head_sha:pr.head.sha});
        notes.push(`Updated #${pr.number} against ${defaultBranch}.`);
      } catch {attention.push(`- [#${pr.number}](${pr.html_url}): automatic branch update failed; resolve the conflict.`);}
      continue;
    }
    if (pr.mergeable === false) {attention.push(`- [#${pr.number}](${pr.html_url}): merge conflict.`);continue;}
    const checks=await github.paginate(api.checks.listForRef,{owner,repo,ref:pr.head.sha,per_page:100});
    const status=(await api.repos.getCombinedStatusForRef({owner,repo,ref:pr.head.sha})).data;
    if (!acceptableChecks(checks, status.statuses, config.requiredChecks)) {
      unresolved = true;
      // Anchor grace to this head, not editable PR metadata or issue activity.
      const headCommit = (await api.repos.getCommit({owner, repo, ref: pr.head.sha})).data;
      const problems = checkBlockers(checks, status.statuses, config.requiredChecks, headCommit.commit?.committer?.date || pr.created_at);
      if (problems.length) {
        const runs = (await api.actions.listWorkflowRunsForRepo({owner, repo, head_sha: pr.head.sha, per_page: 100})).data.workflow_runs;
        const retry = runs.find(r => r.status === 'completed' && r.conclusion === 'failure' && r.run_attempt < 2 && config.workflowNames.includes(r.name));
        if (retry) {
          await api.actions.reRunWorkflowFailedJobs({owner, repo, run_id: retry.id});
          notes.push(`Retried failed jobs for #${pr.number} once.`);
        } else {
          attention.push(`- [#${pr.number}](${pr.html_url}): ${problems.join('; ')}. [Inspect checks](${pr.html_url}/checks).`);
        }
      }
      continue;
    }
    // Re-read immediately before merge: never merge a SHA other than the one we classified and tested.
    const fresh=(await api.pulls.get({owner,repo,pull_number:pr.number})).data;
    if (fresh.head.sha !== pr.head.sha || fresh.base.sha !== pr.base.sha) { unresolved = true; continue; }
    try {
      const result=(await api.pulls.merge({owner,repo,pull_number:pr.number,sha:pr.head.sha,merge_method:'squash'})).data;
      if(!result.merged)throw new Error('Not merged');
      notes.push(`Merged tested ${release?'release':'dependency'} PR #${pr.number}.`);
      // GITHUB_TOKEN merges do not trigger push workflows. Dispatch source-only checks explicitly for the actual merge commit.
      if (!contributions) await dispatch(config.qualityWorkflow, defaultBranch);
      if(config.securityWorkflow)await dispatch(config.securityWorkflow,defaultBranch);
      for(const workflow of config.supplementalWorkflows || [])await dispatch(workflow,defaultBranch);
      if(release)await dispatch('release.yml',defaultBranch);
      else if(config.releaseFiles)await dispatch('release-plan.yml',defaultBranch);
      unresolved = true;
      break; // Serialize merges: the next PR must be tested against this new base.
    } catch {attention.push(`- [#${pr.number}](${pr.html_url}): GitHub blocked the merge; inspect branch requirements.`);}
  }
  // Daily fallback catches production/quality failures without a new commit. Routine successes stay quiet.
  const workflows = await github.paginate(api.actions.listRepoWorkflows, {owner, repo, per_page: 100});
  for (const name of [...config.workflowNames, ...(config.monitoredWorkflows || [])]) {
    const workflow = workflows.find(w => w.name === name);
    if (!workflow) { unresolved = true; attention.push(`- ${name}: monitored workflow is missing.`); continue; }
    const recent = (await api.actions.listWorkflowRuns({owner, repo, workflow_id: workflow.id, branch: defaultBranch, per_page: 100})).data.workflow_runs;
    const run = recent.find(r => r.head_branch === defaultBranch && r.event !== 'pull_request');
    if (!run || run.head_sha !== defaultHead || run.status !== 'completed') unresolved = true;
    if(run && run.status==='completed' && run.head_sha===defaultHead && ['failure','timed_out','action_required','cancelled','startup_failure','stale'].includes(run.conclusion)) {
      if(run.run_attempt<2 && run.conclusion==="failure") {await api.actions.reRunWorkflowFailedJobs({owner,repo,run_id:run.id});unresolved = true;
        notes.push(`Retried ${name} once.`);}
      else attention.push(`- [${name}](${run.html_url}): failed again and needs investigation.`);
    }
  }
  const title='Maintenance requires attention';
  const marker='<!-- autonomous-maintenance:v1 -->';
  const issues=await github.paginate(api.issues.listForRepo,{owner,repo,state:'open',creator:'github-actions[bot]',per_page:100});
  const issue=issues.find(i=>!i.pull_request && i.title===title && i.body?.includes(marker));
  const unique=[...new Set(attention)].sort();
  if(unique.length) {
    const body=`${marker}\nRoutine passing updates are handled automatically. Only the following items need a decision or a repair:\n\n${unique.join('\n')}\n\nLast inspected default commit: \`${defaultHead.slice(0,12)}\`. No credentials or user data are included.`;
    if(issue) {if(issue.body!==body)await api.issues.update({owner,repo,issue_number:issue.number,body});}
    else await api.issues.create({owner,repo,title,body,assignees:[owner]});
  } else if (issue && !unresolved)await api.issues.update({owner,repo,issue_number:issue.number,state:'closed',state_reason:'completed'});
  await core.summary.addHeading('Autonomous maintenance').addRaw(notes.length?notes.join('\n'):'No automatic changes were necessary.').addRaw(`\nActionable items: ${unique.length}.`).write();
};
