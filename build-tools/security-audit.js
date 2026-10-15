"use strict";

const { spawnSync } = require("node:child_process");
const { fingerprint } = require("./security-patches");

fingerprint();
const args = process.argv.slice(2);
if (args.some((argument) => argument !== "--omit=dev"))
	throw new Error("Only --omit=dev is supported.");
const result = spawnSync(
	process.execPath,
	[process.env.npm_execpath, "audit", "--json", ...args],
	{ encoding: "utf8" },
);
if (result.error) throw result.error;
const report = JSON.parse(result.stdout);
if (report.error || !report.vulnerabilities)
	throw new Error("npm audit did not return a valid vulnerability report.");
const mitigated = new Set([
	"https://github.com/advisories/GHSA-vfj7-8cjw-p6xm",
	"https://github.com/advisories/GHSA-hp3w-g68c-fv3c",
]);
const unhandled = [];
for (const [name, issue] of Object.entries(report.vulnerabilities)) {
	for (const advisory of issue.via) {
		if (typeof advisory === "object") {
			const covered =
				mitigated.has(advisory.url) &&
				["braces", "sprintf-js"].includes(name) &&
				issue.nodes.every((node) => node === `node_modules/${name}`);
			if (!covered) unhandled.push(`${name}: ${advisory.title}`);
		}
	}
}
console.log(
	`Raw npm audit: ${JSON.stringify(report.metadata.vulnerabilities)}`,
);
console.log(
	"Two upstream advisories have locally verified mitigations; npm still reports their original versions.",
);
if (unhandled.length) {
	console.error(unhandled.join("\n"));
	process.exitCode = 1;
} else console.log("No unmitigated advisories.");
