'use strict';
const fs = require('node:fs');
const {spawnSync}=require('node:child_process');
function structuredSafe(kind,path,before,after){
 const result=spawnSync('python3',['.github/automation/classify-manifest.py'],{input:JSON.stringify({kind,path,before,after}),encoding:'utf8',timeout:10000});
 return result.status===0 && result.stdout.trim()==='safe';
}
const {manifestSafe,actionPatchSafe,acceptableChecks,sameRepository,safeVersion} = require('./policy.cjs');

module.exports = async ({github,context,core}) => {
  const config = JSON.parse(fs.readFileSync('.github/automation/config.json','utf8'));
  const {owner,repo} = context.repo;
  const fullName = `${owner}/${repo}`;
  const api = github.rest;
  const notes = [];
  const attention = [];
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
    const release = pr.user.login === 'github-actions[bot]' && pr.head.ref === 'automation/release';
    const contributions = repo === 'sawetco' && pr.user.login === 'github-actions[bot]' && pr.head.ref === 'automation/contributions';
    if (pr.user.login !== 'dependabot[bot]' && !release && !contributions) continue;
    const files = await github.paginate(api.pulls.listFiles,{owner,repo,pull_number:pr.number,per_page:100});
    let safe = true;
    if (contributions) {
      safe = files.length > 0 && files.every(f=>['data/github-contributions.json','.github/readme-stats.svg'].includes(f.filename)) && pr.labels.some(l=>l.name==='automation:contributions');
    } else if (release) {
      safe = files.length > 0 && files.every(f=>config.releaseFiles?.includes(f.filename)) && pr.labels.some(l=>l.name==='automation:release');
      // Generated version PRs are owned by the trusted release planner; no arbitrary source paths are accepted.
      if (safe) {
        const compare = (await api.repos.compareCommitsWithBasehead({owner,repo,basehead:`${pr.base.sha}...${pr.head.sha}`})).data;
        safe = compare.commits.length <= 10 && compare.commits.every(c=>c.author?.login==='github-actions[bot]' && (c.commit.message.startsWith('chore(release): ') || c.parents.length===2 && /^Merge branch /.test(c.commit.message)));
      }
    } else {
      for (const f of files) {
        if (f.status !== 'modified') {safe=false;break;}
        if (/(^|\/)package\.json$/.test(f.filename)) {
          if (!manifestSafe(await getText(f.filename,pr.base.sha),await getText(f.filename,pr.head.sha))) safe=false;
        } else if (f.filename.startsWith('.github/workflows/')) {
          if (!actionPatchSafe(f.patch)) safe=false;
          // Major action versions require review even when the executable diff is only a SHA.
          if (/from\s+v?(\d+)[\s\S]*?to\s+v?(\d+)/i.test(pr.title)) {
            const m=pr.title.match(/from\s+v?(\d+)[\s\S]*?to\s+v?(\d+)/i); if(m[1]!==m[2])safe=false;
          }
        } else if (/(^|\/)requirements(?:-[\w-]+)?\.txt$/.test(f.filename)) {
          const a=(await getText(f.filename,pr.base.sha)).trim().split('\n');
          const b=(await getText(f.filename,pr.head.sha)).trim().split('\n');
          if(a.length!==b.length)safe=false;
          else for(let i=0;i<a.length;i++) if(a[i]!==b[i]) {
            const x=a[i].match(/^([\w.-]+)([>=~!<]+)([\d.]+)\s*$/), y=b[i].match(/^([\w.-]+)([>=~!<]+)([\d.]+)\s*$/);
            if(!x||!y||x[1]!==y[1]||x[2]!==y[2]||!safeVersion(x[3],y[3]))safe=false;
          }
        } else if (f.filename.endsWith('Cargo.toml') || f.filename.endsWith('Cargo.lock')) {
          if(!structuredSafe('cargo', f.filename, await getText(f.filename,pr.base.sha), await getText(f.filename,pr.head.sha)))safe=false;
        } else if (f.filename.endsWith('composer.json')) {
          if(!structuredSafe('composer',f.filename,await getText(f.filename,pr.base.sha),await getText(f.filename,pr.head.sha)))safe=false;
        } else if (!/(^|\/)(package-lock\.json|pnpm-lock\.yaml|bun\.lock|composer\.lock|Cargo\.lock)$/.test(f.filename)) safe=false;
      }
      // Lockfile-only groups must state a minor/patch update, not silently bundle a major.
      if(!files.some(f=>/(package\.json|requirements.*\.txt|Cargo\.(toml|lock)|composer\.json|workflows\/)/.test(f.filename))) safe=false;
    }
    if (!safe) {attention.push(`- [#${pr.number}](${pr.html_url}): major update or a change outside the automatic dependency policy.`);continue;}
    if (pr.mergeable_state === 'behind') {
      try {
        await api.pulls.updateBranch({owner,repo,pull_number:pr.number,expected_head_sha:pr.head.sha});
        notes.push(`Updated #${pr.number} against ${defaultBranch}.`);
      } catch {attention.push(`- [#${pr.number}](${pr.html_url}): automatic branch update failed; resolve the conflict.`);}
      continue;
    }
    if (pr.mergeable === false) {attention.push(`- [#${pr.number}](${pr.html_url}): merge conflict.`);continue;}
    const checks=await github.paginate(api.checks.listForRef,{owner,repo,ref:pr.head.sha,per_page:100});
    const status=(await api.repos.getCombinedStatusForRef({owner,repo,ref:pr.head.sha})).data;
    if(!acceptableChecks(checks,status.statuses,config.requiredChecks)) {
      const failed=checks.filter(c=>c.app?.slug==='github-actions' && c.status==='completed' && ['failure','timed_out','cancelled','action_required'].includes(c.conclusion));
      if(failed.length) {
        const runs=(await api.actions.listWorkflowRunsForRepo({owner,repo,head_sha:pr.head.sha,per_page:30})).data.workflow_runs;
        const retry=runs.find(r=>r.conclusion==='failure' && r.run_attempt<2 && config.workflowNames.includes(r.name));
        if(retry) {
          await api.actions.reRunWorkflowFailedJobs({owner,repo,run_id:retry.id});
          notes.push(`Retried failed jobs for #${pr.number} once.`);
        } else attention.push(`- [#${pr.number}](${pr.html_url}): checks still fail after the bounded retry. [Inspect checks](${pr.html_url}/checks).`);
      }
      continue;
    }
    // Re-read immediately before merge: never merge a SHA other than the one we classified and tested.
    const fresh=(await api.pulls.get({owner,repo,pull_number:pr.number})).data;
    if(fresh.head.sha!==pr.head.sha || fresh.base.sha!==pr.base.sha)continue;
    try {
      const result=(await api.pulls.merge({owner,repo,pull_number:pr.number,sha:pr.head.sha,merge_method:'squash'})).data;
      if(!result.merged)throw new Error('Not merged');
      notes.push(`Merged tested ${release?'release':'dependency'} PR #${pr.number}.`);
      // GITHUB_TOKEN writes do not trigger push workflows. Dispatch source-only checks explicitly for the actual merge commit.
      await dispatch(config.qualityWorkflow,defaultBranch);
      if(config.securityWorkflow)await dispatch(config.securityWorkflow,defaultBranch);
      if(release)await dispatch('release.yml',defaultBranch);
      else if(config.releaseFiles)await dispatch('release-plan.yml',defaultBranch);
      break; // Serialize merges: the next PR must be tested against this new base.
    } catch {attention.push(`- [#${pr.number}](${pr.html_url}): GitHub blocked the merge; inspect branch requirements.`);}
  }
  // Daily fallback catches production/quality failures without a new commit. Routine successes stay quiet.
  const recent=(await api.actions.listWorkflowRunsForRepo({owner,repo,per_page:60})).data.workflow_runs;
  for(const name of [...config.workflowNames,...(config.monitoredWorkflows||[])]) {
    const run=recent.find(r=>r.name===name && r.head_branch===defaultBranch && r.event!=='pull_request' && r.status==='completed');
    if(run && ['failure','timed_out','action_required'].includes(run.conclusion)) {
      if(run.run_attempt<2) {await api.actions.reRunWorkflowFailedJobs({owner,repo,run_id:run.id});notes.push(`Retried ${name} once.`);}
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
  } else if(issue)await api.issues.update({owner,repo,issue_number:issue.number,state:'closed',state_reason:'completed'});
  await core.summary.addHeading('Autonomous maintenance').addRaw(notes.length?notes.join('\n'):'No automatic changes were necessary.').addRaw(`\nActionable items: ${unique.length}.`).write();
};
