'use strict';
const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=process.cwd(),home=path.join(process.env.RUNNER_TEMP||root,'.ptq-package-test');fs.mkdirSync(home,{recursive:true});
const version=JSON.parse(fs.readFileSync('package.json')).version;
const config=path.join(home,'config.json');
fs.writeFileSync(config,JSON.stringify({core:'WordPress/WordPress#7.1.3',phpVersion:'8.1',port:8896,testsEnvironment:false,plugins:[],mappings:{'wp-content/ptq-fixtures':path.join(root,'tests'),'wp-content/ptq-dist':path.join(root,'.package-under-test')},config:{WP_ENVIRONMENT_TYPE:'local'}}));
const env={...process.env,WP_ENV_HOME:path.join(home,'environment')};
function run(args){const p=spawnSync(path.join(root,'node_modules/.bin/wp-env'),['--config',config,...args],{env,stdio:'inherit'});if(p.status!==0)throw Error('Disposable package upgrade check failed');}
const wp=(...args)=>run(['run','cli','wp',...args]);
const fixture=phase=>run(['run','cli','env','WP_ENVIRONMENT_TYPE=local','PAPER_TO_QUIZ_ALLOW_REGRESSION=1',`PTQ_EXPECTED_VERSION=${version}`,`PTQ_UPGRADE_PHASE=${phase}`,'wp','eval-file','wp-content/ptq-fixtures/release-upgrade.php']);
try{
 run(['start']);wp('core','install','--url=http://localhost:8896','--title=SyntheticPackageCheck','--admin_user=package-check','--admin_password=isolated-local-only','--admin_email=package@example.test','--skip-email');
 // wp-env maps fixtures only; plugin contents come exclusively from the tested archive.
 wp('plugin','install','wp-content/ptq-dist/paper-to-quiz.zip','--activate');fixture('clean');
 run(['reset','all']);
 wp('core','install','--url=http://localhost:8896','--title=SyntheticUpgradeCheck','--admin_user=package-check','--admin_password=isolated-local-only','--admin_email=package@example.test','--skip-email');
 wp('plugin','install','wp-content/ptq-dist/baseline.zip','--force','--activate');fixture('seed');
 wp('plugin','install','wp-content/ptq-dist/paper-to-quiz.zip','--force','--activate');fixture('verify');
 console.log('The exact distribution ZIP passed clean installation and the retained 1.1.6 upgrade regression.');
}finally{try{run(['stop']);}catch{}}
