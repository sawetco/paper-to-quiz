'use strict';
const fs=require('node:fs'),path=require('node:path'),{createHash}=require('node:crypto');
const root=process.cwd(),directory=path.join(root,'node_modules/deepmerge');
if(JSON.parse(fs.readFileSync(path.join(directory,'package.json'))).version!=='4.3.1')throw Error('Review changed deepmerge version');
for(const file of ['index.js','dist/cjs.js','dist/umd.js']) {
 const p=path.join(directory,file);let s=fs.readFileSync(p,'utf8').replace(/\r\n/g,'\n');
 if(!s.includes('function keyIsReserved(key)')) {
  const indent=file==='dist/umd.js'?'\t':'';
  const before=indent+'function propertyIsUnsafe(target, key) {';
  const after=indent+"function keyIsReserved(key) {\n"+indent+"\treturn key === '__proto__' || key === 'constructor' || key === 'prototype'\n"+indent+'}\n\n'+before+'\n'+indent+'\tif (keyIsReserved(key)) return true';
  if(s.split(before).length!==2)throw Error('Unreviewed deepmerge source');s=s.replace(before,after);
  const copy=indent+'\t\tgetKeys(target).forEach(function(key) {';
  if(s.split(copy).length!==2)throw Error('Unreviewed deepmerge target copying');s=s.replace(copy,copy+'\n'+indent+'\t\t\tif (keyIsReserved(key)) return');
  fs.writeFileSync(p,s);
 }
}
require('./verify-deepmerge.cjs').verify();
