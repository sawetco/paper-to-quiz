const backports = require("../.github/automation/security-backports.cjs").verify();
"use strict";

const { spawnSync } = require("node:child_process");
const { fingerprint } = require("./security-patches");

fingerprint();
require("../.github/automation/verify-deepmerge.cjs").verify();
const args = process.argv.slice(2);
if (args.some((argument) => argument !== "--omit=dev"))
	throw new Error("Only --omit=dev is supported.");
const result = spawnSync(
	process.execPath,
	[process.env.npm_execpath, "audit", "--json", ...args],
	{ encoding: "utf8" },
);
if (result.error) throw result.error;
if (result.signal || ![0, 1].includes(result.status))
	throw new Error("npm audit failed or was interrupted.");
const report = JSON.parse(result.stdout);
const object = value => value !== null && typeof value === "object" && !Array.isArray(value);
if (!object(report) || report.error || !object(report.vulnerabilities) || !object(report.metadata?.vulnerabilities))
	throw new Error("npm audit did not return a valid vulnerability report.");
const issues = Object.entries(report.vulnerabilities);
if ((result.status === 1 && issues.length === 0) ||
    ["info", "low", "moderate", "high", "critical", "total"].some(key => !Number.isInteger(report.metadata.vulnerabilities[key]) || report.metadata.vulnerabilities[key] < 0) ||
    report.metadata.vulnerabilities.total !== issues.length)
	throw new Error("npm audit returned inconsistent vulnerability counts.");
for (const [, issue] of issues) {
  if (!object(issue) || !Array.isArray(issue.via) || !issue.via.length || !Array.isArray(issue.nodes) || !issue.nodes.length || issue.nodes.some(node => typeof node !== "string") ||
      issue.via.some(via => typeof via === "string" ? !Object.hasOwn(report.vulnerabilities, via) : !object(via) || typeof via.url !== "string"))
    throw new Error("npm audit returned a malformed advisory.");
}
// npm can emit cycles for dependent packages. Every reference component must
// still lead to a concrete advisory, which is classified below exactly once.
function hasAdvisorySource(name) {
  const pending = [name];
  const visited = new Set();
  while (pending.length) {
    const current = pending.pop();
    if (visited.has(current)) continue;
    visited.add(current);
    for (const via of report.vulnerabilities[current].via) {
      if (typeof via !== "string") return true;
      pending.push(via);
    }
  }
  return false;
}
for (const [name] of issues) {
  if (!hasAdvisorySource(name))
    throw new Error("npm audit references have no concrete advisory source.");
}
const mitigated = new Set([
	"https://github.com/advisories/GHSA-vfj7-8cjw-p6xm",
	"https://github.com/advisories/GHSA-hp3w-g68c-fv3c",
]);
const unhandled = [];
for (const [name, issue] of Object.entries(report.vulnerabilities)) {
	for (const advisory of issue.via) {
		if (typeof advisory === "object") {
			const covered = backports.npm(name, advisory, issue.nodes) ||
                (name === "deepmerge" && advisory.url === "https://github.com/advisories/GHSA-gcqx-w48m-w2h4" && issue.nodes.every(node => node === "node_modules/deepmerge")) ||
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
	"Reviewed upstream advisories have locally verified mitigations; npm still reports their original versions.",
);
if (unhandled.length) {
	console.error(unhandled.join("\n"));
	process.exitCode = 1;
} else console.log("No unmitigated advisories.");

