"use strict";

const fs = require("node:fs");
const path = require("node:path");
const crypto = require("node:crypto");

// Temporary, fail-closed mitigations until these packages publish fixes.
const changes = [
	[
		"braces/lib/parse.js",
		"      stack.push(block);",
		"      if (stack.length >= 128) throw new RangeError('PTQ: brace nesting limit');\n      stack.push(block);",
		2,
	],
	[
		"braces/lib/compile.js",
		"const walk = (node, parent = {}) => {",
		"const walk = (node, parent = {}, depth = 0) => {\n    if (depth > 128) throw new RangeError('PTQ: brace nesting limit');",
		1,
	],
	[
		"braces/lib/compile.js",
		"walk(child, node)",
		"walk(child, node, depth + 1)",
		1,
	],
	[
		"braces/lib/expand.js",
		"const walk = (node, parent = {}) => {",
		"const walk = (node, parent = {}, depth = 0) => {\n    if (depth > 128) throw new RangeError('PTQ: brace nesting limit');",
		1,
	],
	[
		"braces/lib/expand.js",
		"walk(child, node)",
		"walk(child, node, depth + 1)",
		1,
	],
	[
		"braces/lib/stringify.js",
		"const stringify = (node, parent = {}) => {",
		"const stringify = (node, parent = {}, depth = 0) => {\n    if (depth > 128) throw new RangeError('PTQ: brace nesting limit');",
		1,
	],
	[
		"braces/lib/stringify.js",
		"stringify(child)",
		"stringify(child, {}, depth + 1)",
		1,
	],
	[
		"sprintf-js/src/sprintf.js",
		"                match = parse_tree[i] // convenience purposes only",
		"                match = parse_tree[i] // convenience purposes only\n                if (Number(match[6]) > 10000 || Number(match[7]) > 10000) {\n                    throw new RangeError('PTQ: sprintf size limit')\n                }",
		1,
	],
];

function apply(checkOnly = false) {
	const root = path.resolve(__dirname, "..", "node_modules");
	for (const [name, version] of [
		["braces", "3.0.3"],
		["sprintf-js", "1.0.3"],
		["@wordpress/env", "12.0.0"],
		["simple-git", "4.0.2"],
	]) {
		if (
			JSON.parse(
				fs.readFileSync(path.join(root, name, "package.json"), "utf8"),
			).version !== version
		) {
			throw new Error(
				`Review security mitigation for changed ${name} version.`,
			);
		}
	}
	for (const [file, before, after, count] of changes) {
		const destination = path.join(root, file);
		const source = fs
			.readFileSync(destination, "utf8")
			.replace(/\r\n/g, "\n");
		if (source.split(after).length - 1 === count) continue;
		if (checkOnly || source.split(before).length - 1 !== count) {
			throw new Error(`Missing or changed security mitigation: ${file}`);
		}
		fs.writeFileSync(destination, source.replaceAll(before, after));
	}
}

function fingerprint() {
	apply(true);
	// wp-env 12.0.0 already imports simpleGit by name (upstream gitHead
	// 8d30f92cbbde3ddf781c8799443c649befbbcb88). Pin the reviewed source
	// instead of retaining the obsolete 11.17.0 compatibility rewrite.
	const expected = {
		"@wordpress/env/lib/runtime/docker/download-wp-phpunit.js":
			"7cb2a798baf8b945463b6e9534b53c2dc45608d7aee451132f3559c4decc8ed6",
		"@wordpress/env/lib/download-sources.js":
			"fc69bc9664025cb3b930d0baebebfd7ec20f9e7abe2d3fec14ec165a5d9064c3",
		"braces/lib/parse.js":
			"f87ff5f71835ec0b82eb5b75cd949ff064a603397307f4ec70fa73e1fd5a6c7b",
		"braces/lib/compile.js":
			"3e944c39a2a969e045a6b695c137b937086f334d0d516431ac82623bcddb487b",
		"braces/lib/expand.js":
			"381d46ec12be0993a1089937cc983be8afc70d7951b263efcfa48a09d6564948",
		"braces/lib/stringify.js":
			"eba702934f67331e0f1183b66db4c31c4ae07f9f5e6d6336ceeab3050e1a5c0d",
		"sprintf-js/src/sprintf.js":
			"76690013571082296c9ca28871dc1ae9dd69a9a409487a4a551bfb1b14ee1fd0",
	};
	for (const [file, digest] of Object.entries(expected)) {
		const source = fs
			.readFileSync(
				path.resolve(__dirname, "..", "node_modules", file),
				"utf8",
			)
			.replace(/\r\n/g, "\n");
		if (crypto.createHash("sha256").update(source).digest("hex") !== digest)
			throw new Error(`Security mitigation integrity failed: ${file}`);
	}
}

if (require.main === module) {
	apply(process.argv.includes("--check"));
	fingerprint();
	console.log(
		"Verified temporary braces and sprintf-js security mitigations.",
	);
}

module.exports = { apply, fingerprint };
