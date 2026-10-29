'use strict';
const fs=require('node:fs');
module.exports=async({github,context,core})=>{
 const {owner,repo}=context.repo,api=github.rest;
 const branch=(await api.repos.get({owner,repo})).data.default_branch;
 const head=(await api.repos.getBranch({owner,repo,branch})).data.commit.sha;
 if(context.sha!==head){core.info('A newer default commit exists; let its planner run.');return;}
 const product=repo==='paper-to-quiz'?'paper':'stone';
 const current=product==='paper'?JSON.parse(fs.readFileSync('package.json')).version:JSON.parse(fs.readFileSync('version.json')).version;
 const releases=(await api.repos.listReleases({owner,repo,per_page:30})).data;
 const latest=releases.find(r=>!r.draft&&!r.prerelease);
 if(!latest)throw Error('An initial release baseline is required');
 const old=latest.tag_name.replace(/^v/,'');
 if(current!==old){
   const a=current.split('.').map(Number),b=old.split('.').map(Number);
   if(a.some(x=>!Number.isInteger(x))||a.length!==3||!(a[0]>b[0]||a[0]===b[0]&&(a[1]>b[1]||a[1]===b[1]&&a[2]>b[2])))throw Error('Release version must advance');
   await api.actions.createWorkflowDispatch({owner,repo,workflow_id:'release.yml',ref:branch});
   core.info(`Dispatched verified release pipeline for ${current}.`);return;
 }
 const comparison=(await api.repos.compareCommitsWithBasehead({owner,repo,basehead:`${latest.tag_name}...${head}`})).data;
 if(!comparison.files?.some(f=>product==='paper'?/^(src\/|src-js\/|paper-to-quiz\.php|uninstall\.php|package(?:-lock)?\.json|composer(?:\.lock|\.json)|build-tools\/)/.test(f.filename):/^(src\/|scripts\/|version\.json)/.test(f.filename))){core.info('No distributable change since latest release.');return;}
 if(comparison.commits.some(c=>/BREAKING CHANGE|^[\w()-]+!:/m.test(c.commit.message)))throw Error('Breaking release requires the owner to choose and review a major version');
 const parts=old.split('.').map(Number);
 if(comparison.commits.some(c=>/^feat(?:\([^)]*\))?:/m.test(c.commit.message))){parts[1]++;parts[2]=0;}else parts[2]++;
 const next=parts.join('.'),files={};
 if(product==='paper'){
   const manifest=JSON.parse(fs.readFileSync('package.json'));manifest.version=next;files['package.json']=JSON.stringify(manifest,null,'\t')+'\n';
   const lock=JSON.parse(fs.readFileSync('package-lock.json'));lock.version=next;lock.packages[''].version=next;files['package-lock.json']=JSON.stringify(lock,null,'\t')+'\n';
   files['paper-to-quiz.php']=fs.readFileSync('paper-to-quiz.php','utf8').replace(`Version: ${old}`,`Version: ${next}`).replace(`PAPER_TO_QUIZ_VERSION', '${old}'`,`PAPER_TO_QUIZ_VERSION', '${next}'`);
   files['readme.txt']=fs.readFileSync('readme.txt','utf8').replace(`Stable tag: ${old}`,`Stable tag: ${next}`).replace('== Changelog ==',`== Changelog ==\n\n= ${next} =\n* Tested maintenance and dependency updates. See the GitHub release notes for details.`);
   files['languages/paper-to-quiz.pot']=fs.readFileSync('languages/paper-to-quiz.pot','utf8').replace(`Project-Id-Version: Paper to Quiz ${old}`,`Project-Id-Version: Paper to Quiz ${next}`);
 }else{
   files['version.json']=JSON.stringify({version:next},null,2)+'\n';
   for(const p of ['src/StonewardsXRayPlugin.cs','scripts/Install-Stonewards-XRay.ps1','README.md'])files[p]=fs.readFileSync(p,'utf8').replaceAll(old,next);
   files['CHANGELOG.md']=fs.readFileSync('CHANGELOG.md','utf8').replace(/^(# Changelog\s*)/,'$1\n## '+next+'\n\n- Tested maintenance updates; see generated release notes.\n');
 }
 const open=(await api.pulls.list({owner,repo,state:'open',head:`${owner}:automation/release`})).data;
 if(open.length){core.info('A release PR is already being validated.');return;}
 const base=(await api.git.getCommit({owner,repo,commit_sha:head})).data;
 const tree=[];
 for(const [path,content] of Object.entries(files))tree.push({path,mode:'100644',type:'blob',sha:(await api.git.createBlob({owner,repo,content,encoding:'utf-8'})).data.sha});
 const sha=(await api.git.createTree({owner,repo,base_tree:base.tree.sha,tree})).data.sha;
 const commit=(await api.git.createCommit({owner,repo,message:`chore(release): ${next}`,tree:sha,parents:[head]})).data;
 try{await api.git.deleteRef({owner,repo,ref:'heads/automation/release'});}catch(e){if(e.status!==404&&e.status!==422)throw e;}
 await api.git.createRef({owner,repo,ref:'refs/heads/automation/release',sha:commit.sha});
 const pr=(await api.pulls.create({owner,repo,head:'automation/release',base:branch,title:`chore(release): ${next}`,body:'Automatically prepared from tested product changes. Version metadata and distribution channels are validated before publication. This PR is merged only when all required checks succeed.'})).data;
 try{await api.issues.getLabel({owner,repo,name:'automation:release'});}catch(e){if(e.status!==404)throw e;await api.issues.createLabel({owner,repo,name:'automation:release',color:'1d76db'});}
 await api.issues.addLabels({owner,repo,issue_number:pr.number,labels:['automation:release']});
 for(const workflow_id of ['quality.yml','security.yml'])await api.actions.createWorkflowDispatch({owner,repo,workflow_id,ref:'automation/release'});
 await core.summary.addRaw(`Prepared [release ${next}](${pr.html_url}); exact source checks were explicitly dispatched.`).write();
};
