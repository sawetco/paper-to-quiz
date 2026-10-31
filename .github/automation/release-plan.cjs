'use strict';
const fs=require('node:fs');
const {affectsDistribution}=require('./distribution-paths.cjs');
module.exports=async({github,context,core})=>{
 const {owner,repo}=context.repo,api=github.rest;
 const workflowApi=new github.constructor({auth:process.env.WORKFLOW_TOKEN}).rest;
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
   await workflowApi.actions.createWorkflowDispatch({owner,repo,workflow_id:'release.yml',ref:branch});
   core.info(`Dispatched verified release pipeline for ${current}.`);return;
 }
 const comparison=(await api.repos.compareCommitsWithBasehead({owner,repo,basehead:`${latest.tag_name}...${head}`})).data;
 const shipped=product==='paper'?JSON.parse(fs.readFileSync('package.json')).files:[];
 if(!comparison.files?.some(f=>affectsDistribution(product,f.filename,shipped) || f.previous_filename && affectsDistribution(product,f.previous_filename,shipped))){core.info('No distributable change since latest release.');return;}
 if(comparison.commits.some(c=>/BREAKING CHANGE|^[\w()-]+!:/m.test(c.commit.message)))throw Error('Breaking release requires the owner to choose and review a major version');
 const parts=old.split('.').map(Number);
 if(comparison.commits.some(c=>/^feat(?:\([^)]*\))?:/m.test(c.commit.message))){parts[1]++;parts[2]=0;}else parts[2]++;
 const next=parts.join('.'),files={};
 if(product==='paper'){
   Object.assign(files,preparePaperRelease(current,next));
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
 try{await workflowApi.issues.getLabel({owner,repo,name:'automation:release'});}catch(e){if(e.status!==404)throw e;await workflowApi.issues.createLabel({owner,repo,name:'automation:release',color:'1d76db'});}
 await workflowApi.issues.addLabels({owner,repo,issue_number:pr.number,labels:['automation:release']});

 await core.summary.addRaw(`Prepared [release ${next}](${pr.html_url}); normal required PR checks start automatically through the maintenance App.`).write();
};


function updatePluginHeader(source,current,next){
 const pattern=/^([ \t]*\*[ \t]*Version:[ \t]*)(\S+)([ \t]*)$/m;
 const match=source.match(pattern);
 if(!match || match[2]!==current)throw Error('Plugin header version does not match the release baseline');
 return source.replace(pattern,(_line,prefix,_old,suffix)=>prefix+next+suffix);
}
function preparePaperRelease(current,next,root=process.cwd()){
 const path=require('node:path'),os=require('node:os');
 const read=file=>fs.readFileSync(path.join(root,file),'utf8');
 const files={},manifest=JSON.parse(read('package.json')),lock=JSON.parse(read('package-lock.json'));
 if(manifest.version!==current || lock.version!==current || lock.packages[''].version!==current)throw Error('Release baseline metadata differs');
 manifest.version=next;files['package.json']=JSON.stringify(manifest,null,'\t')+'\n';
 lock.version=next;lock.packages[''].version=next;files['package-lock.json']=JSON.stringify(lock,null,'\t')+'\n';
 files['paper-to-quiz.php']=updatePluginHeader(read('paper-to-quiz.php'),current,next).replace(`PAPER_TO_QUIZ_VERSION', '${current}'`,`PAPER_TO_QUIZ_VERSION', '${next}'`);
 files['readme.txt']=read('readme.txt').replace(`Stable tag: ${current}`,`Stable tag: ${next}`).replace('== Changelog ==',`== Changelog ==\n\n= ${next} =\n* Tested maintenance and dependency updates. See the GitHub release notes for details.`);
 files['languages/paper-to-quiz.pot']=read('languages/paper-to-quiz.pot').replace(`Project-Id-Version: Paper to Quiz ${current}`,`Project-Id-Version: Paper to Quiz ${next}`);
 // Validate the complete proposed snapshot BEFORE creating remote blobs or a PR.
 const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'ptq-release-metadata-'));
 try{
  for(const [name,content]of Object.entries(files)){const target=path.join(temporary,name);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,content);}
  fs.copyFileSync(path.join(root,'third-party-licenses.txt'),path.join(temporary,'third-party-licenses.txt'));
  const errors=require('../../build-tools/check-release-metadata.js').checkMetadata(temporary,`v${next}`);
  if(errors.length)throw Error(errors.join('\n'));
 }finally{fs.rmSync(temporary,{recursive:true,force:true});}
 return files;
}
module.exports.updatePluginHeader=updatePluginHeader;
module.exports.preparePaperRelease=preparePaperRelease;
