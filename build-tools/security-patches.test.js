const { spawnSync } = require("node:child_process");

test("rejects hostile nesting and format sizes while preserving normal tools", () => {
	const result = spawnSync(
		process.execPath,
		[
			"-e",
			`
const assert = require('node:assert/strict');
const {apply}=require('./build-tools/security-patches'); apply(true);
const braces=require('braces');
assert.deepEqual(braces.expand('src/{admin,student}/*.tsx'),['src/admin/*.tsx','src/student/*.tsx']);
for(const fn of ['parse','compile','expand','stringify']) {
 for(const pair of [['{','}'],['(',')']]) assert.throws(()=>braces[fn](pair[0].repeat(4000)+'a'+pair[1].repeat(4000)),/PTQ: brace nesting limit/);
}
const {sprintf,vsprintf}=require('sprintf-js');
assert.equal(sprintf('%04d %.2f %s',7,1.25,'ok'),'0007 1.25 ok');
assert.equal(vsprintf('%s %d',['ok',2]),'ok 2');
for(const format of ['%1000000000s','%.1000000000f','%.1000000000g']) assert.throws(()=>sprintf(format,1),/PTQ: sprintf size limit/);
`,
		],
		{ cwd: process.cwd(), timeout: 5000, encoding: "utf8" },
	);
	expect(result.error).toBeUndefined();
	expect(result.stderr).toBe("");
	expect(result.status).toBe(0);
});
