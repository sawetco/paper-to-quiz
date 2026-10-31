"use strict";
const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { createHash } = require("node:crypto");
const { checkFiles } = require("./security-backports.cjs");
const data = require("./security-backports.json");
test("backports detach hardlinks, reject tampering and reject unknown source versions", () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "backport-regression-"));
  const directory = path.join(root, "node_modules/minimatch");
  const original = "// original reviewed source\n";
  const patched = "// patched reviewed source\n";
  const digest = value => createHash("sha256").update(value).digest("hex");
  const item = { name: "minimatch", version: "0.0.0-regression", directory };
  data.packages["minimatch@0.0.0-regression"] = { files: { "index.js": { original: digest(original), patched: digest(patched), operations: [{ before: "original", after: "patched", count: 1 }] } } };
  try {
    fs.mkdirSync(directory, { recursive: true });
    const store = path.join(root, "shared-store-file");
    fs.writeFileSync(store, original);
    const target = path.join(directory, "index.js");
    fs.linkSync(store, target);
    assert.throws(() => checkFiles(item, false, root), /Unverified installed source/);
    checkFiles(item, true, root);
    assert.equal(fs.readFileSync(target, "utf8"), patched);
    assert.equal(fs.readFileSync(store, "utf8"), original, "Shared package-store inode changed");
    checkFiles(item, false, root);
    fs.writeFileSync(target, patched + "// tampered\n");
    assert.throws(() => checkFiles(item, true, root), /Unverified installed source/);
    assert.throws(() => checkFiles({ ...item, version: "0.0.1-unreviewed" }, true, root), /Unreviewed security backport/);
    assert.throws(() => checkFiles({ ...item, directory: root }, true, root));
  } finally {
    delete data.packages["minimatch@0.0.0-regression"];
    fs.rmSync(root, { recursive: true, force: true });
  }
});
