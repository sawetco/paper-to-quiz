"use strict";
const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { updatePluginHeader, preparePaperRelease } = require("./release-plan.cjs");
test("WordPress release headers preserve aligned whitespace and reject baseline drift", () => {
  for (const gap of [" ", "     ", "\t"]) {
    const source = `/**\n * Version:${gap}1.2.0\n */\n`;
    assert.equal(updatePluginHeader(source, "1.2.0", "1.2.1"), source.replace("1.2.0", "1.2.1"));
  }
  assert.throws(() => updatePluginHeader("no header", "1.2.0", "1.2.1"), /baseline/);
  assert.throws(() => updatePluginHeader(" * Version: 1.1.0", "1.2.0", "1.2.1"), /baseline/);
});
test("the real next-version snapshot passes the existing full metadata validator without changing source", () => {
  const root = path.resolve(__dirname, "../..");
  const source = fs.readFileSync(path.join(root, "paper-to-quiz.php"), "utf8");
  const current = JSON.parse(fs.readFileSync(path.join(root, "package.json"), "utf8")).version;
  const parts = current.split(".").map(Number); parts[2]++;
  const next = parts.join(".");
  const files = preparePaperRelease(current, next, root);
  assert.match(files["paper-to-quiz.php"], new RegExp(`Version:[ \\t]+${next.replaceAll(".", "\\.")}`));
  assert(files["paper-to-quiz.php"].includes(`PAPER_TO_QUIZ_VERSION', '${next}'`));
  assert(files["readme.txt"].includes(`Stable tag: ${next}`));
  assert.equal(fs.readFileSync(path.join(root, "paper-to-quiz.php"), "utf8"), source);
});
