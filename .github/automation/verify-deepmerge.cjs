'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {createHash}=require('node:crypto');
const {createRequire}=require('node:module');
const digest=data=>createHash('sha256').update(data.toString().replace(/\r\n/g,'\n')).digest('hex');
function verify(root=process.cwd()) {
  assert.equal(digest(fs.readFileSync(path.join(root,'patches/deepmerge@4.3.1.patch'))),'f441e75bc0c393b1f00d53c5bc43f997cb99c3af563c30b39aaf22eaf847e438');
  const folders=[],seen=new Set(),visitedModules=new Set();
  function walk(directory) {
    if(!fs.existsSync(directory))return;
    const location=fs.realpathSync(directory);if(visitedModules.has(location))return;visitedModules.add(location);
    for(const ent of fs.readdirSync(directory,{withFileTypes:true})) {
      if(ent.name.startsWith('.'))continue;
      const entry=path.join(directory,ent.name);
      if(ent.name.startsWith('@')) {walk(entry);continue;}
      if(!ent.isDirectory()&&!ent.isSymbolicLink())continue;
      const actual=fs.realpathSync(entry);if(seen.has(actual))continue;seen.add(actual);
      if(ent.name==='deepmerge'&&fs.existsSync(path.join(actual,'package.json')))folders.push(actual);
      walk(path.join(actual,'node_modules'));
      for(let parent=path.dirname(actual);parent!==path.dirname(parent);parent=path.dirname(parent)){if(path.basename(parent)==='node_modules'){walk(parent);break;}}
    }
  }
  walk(path.join(root,'node_modules'));
  assert(folders.length>0,'No installed deepmerge copy found');
  for(const directory of folders) {
    assert.equal(JSON.parse(fs.readFileSync(path.join(directory,'package.json'),'utf8')).version,'4.3.1');
    for(const [file,hash] of [['dist/cjs.js','272def8315390012efb6d695b5d72bde0fd89d696af4cd139017db0b2a0c4bfe'],['dist/umd.js','19873686645a3d84e046664a9dad73d85f773530c92ce487fe099cf963e85102']])assert.equal(digest(fs.readFileSync(path.join(directory,file))),hash,`Unreviewed installed entrypoint: ${file}`);
    const merge=createRequire(path.join(directory,'package.json'))('./dist/cjs.js');
    const bad=JSON.parse('{"__proto__":{"admin":true},"constructor":{"prototype":{"admin":true}},"prototype":{"admin":true}}');
    for(const [a,b] of [[{},bad],[bad,{}],[Object.create(null),bad],[{nested:bad},{nested:bad}]]) {
      const out=merge(a,b);assert.equal(Object.getPrototypeOf(out),Object.prototype);assert.equal(out.admin,undefined);
      for(const key of ['__proto__','constructor','prototype'])assert.equal(Object.hasOwn(out,key),false);
      if(out.nested)assert.equal(Object.getPrototypeOf(out.nested),Object.prototype);
    }
    assert.deepEqual(merge({a:{b:1},list:[1]},{a:{c:2},list:[2]}),{a:{b:1,c:2},list:[1,2]});
  }
  return folders.length;
}
if(require.main===module)console.log(`Verified ${verify()} patched deepmerge copies and hostile-input regressions.`);
module.exports={verify};
