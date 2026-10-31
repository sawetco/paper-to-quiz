"use strict";
const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { affectsDistribution } = require("./distribution-paths.cjs");
const root = path.resolve(__dirname, "../..");
test("all shipped WordPress build/configuration/documentation paths trigger a release", { skip: !fs.existsSync(path.join(root, "package.json")) }, () => {
  const files = JSON.parse(fs.readFileSync(path.join(root, "package.json"), "utf8")).files;
  for (const name of ["src/Plugin.php", "src-js/admin/index.tsx", "build-tools/pdfjs-browser-loader.js", "webpack.config.js", "tsconfig.json", "languages/paper-to-quiz.pot", "readme.txt", "README.md", "LICENSE", "third-party-licenses.txt", "package-lock.json"]) assert(affectsDistribution("paper", name, files), name);
  for (const name of [".github/workflows/release.yml", "docs/automated-maintenance.md", "tests/release-upgrade.php", "build-tools/security-audit.js", "build-tools/check-release-metadata.js", "src-js/student/assessment.test.ts", "languages/tr_TR.mo"]) assert(!affectsDistribution("paper", name, files), name);
});
test("Stonewards release detection covers every packaged top-level document and installer source", () => {
  for (const name of ["src/StonewardsXRayPlugin.cs", "scripts/Install-Stonewards-XRay.ps1", "version.json", "README.md", "CHANGELOG.md", "LICENSE", "SECURITY.md", ".github/automation/package-stonewards.py"]) assert(affectsDistribution("stone", name), name);
  for (const name of [".github/workflows/release.yml", "docs/automated-maintenance.md", "LICENSE-unrelated"]) assert(!affectsDistribution("stone", name), name);
});
