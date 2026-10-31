"use strict";
const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.resolve(__dirname, "../../build-tools/security-audit.js"), "utf8");
const clean = { vulnerabilities: {}, metadata: { vulnerabilities: {info:0,low:0,moderate:0,high:0,critical:0,total:0} } };
function run(result) {
 const process = { argv: ["node", "audit"], execPath:"node", env:{npm_execpath:"npm"}, exitCode:0 };
 const require = name => name === "node:child_process" ? {spawnSync:()=>result} : name.endsWith("security-patches") ? {fingerprint(){}} : {verify:()=>({npm:()=>false})};
 try { vm.runInNewContext(source,{process,require,console:{log(){},error(){}}}); } catch { return 1; }
 return process.exitCode;
}
test("valid empty audit succeeds",()=>assert.equal(run({status:0,stdout:JSON.stringify(clean)}),0));
for (const [name, result] of [
 ["status 2",{status:2,stdout:JSON.stringify(clean)}], ["status null",{status:null,stdout:JSON.stringify(clean)}], ["signal",{status:0,signal:"SIGTERM",stdout:JSON.stringify(clean)}], ["error",{status:0,error:Error("spawn"),stdout:JSON.stringify(clean)}], ["nonzero empty",{status:1,stdout:JSON.stringify(clean)}], ["bad JSON",{status:0,stdout:"{"}], ["array",{status:0,stdout:"[]"}], ["null",{status:0,stdout:"null"}], ["missing counts",{status:0,stdout:JSON.stringify({vulnerabilities:{},metadata:{vulnerabilities:{high:0}}})}], ["array vulnerabilities",{status:0,stdout:JSON.stringify({...clean,vulnerabilities:[]})}]
]) test(name,()=>assert.equal(run(result),1));
for (const [name,issue,expected] of [
 ["unresolved",{via:[{url:"unknown",title:"new advisory"}],nodes:["node_modules/foo"]},1],
 ["malformed via",{via:{},nodes:["node_modules/foo"]},1],
 ["dangling reference",{via:["missing"],nodes:["node_modules/foo"]},1],
 ["cyclic reference",{via:["foo"],nodes:["node_modules/foo"]},1],
 ["empty nodes",{via:[{url:"unknown"}],nodes:[]},1]
]) test(name,()=>assert.equal(run({status:1,stdout:JSON.stringify({vulnerabilities:{foo:issue},metadata:{vulnerabilities:{info:0,low:0,moderate:0,high:1,critical:0,total:1}}})}),expected));

test("known locally fingerprinted braces report succeeds",()=>assert.equal(run({status:1,stdout:JSON.stringify({vulnerabilities:{braces:{via:[{url:"https://github.com/advisories/GHSA-vfj7-8cjw-p6xm"}],nodes:["node_modules/braces"]}},metadata:{vulnerabilities:{info:0,low:0,moderate:0,high:1,critical:0,total:1}}})}),0));

for (const [label,url,expected] of [
 ["reviewed", "https://github.com/advisories/GHSA-vfj7-8cjw-p6xm", 0],
 ["unknown", "https://github.com/advisories/GHSA-unknown", 1]
]) test(`npm dependency cycle with ${label} concrete advisory`,()=>assert.equal(run({status:1,stdout:JSON.stringify({
 vulnerabilities:{braces:{via:["consumer",{url}],nodes:["node_modules/braces"]},consumer:{via:["braces"],nodes:["node_modules/consumer"]}},
 metadata:{vulnerabilities:{info:0,low:0,moderate:0,high:2,critical:0,total:2}}
})}),expected));
