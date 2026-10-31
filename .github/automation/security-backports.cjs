"use strict";
// ABI-preserving backports of upstream minimatch 77a0f5c and node-glob 4682e8c.
// Registry versions remain visible; accepting an advisory requires these exact
// installed bytes AND bounded behavioral probes, never an advisory-only ignore.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { createHash, randomUUID } = require("node:crypto");
const { createRequire } = require("node:module");
const { spawnSync } = require("node:child_process");
const { pathToFileURL } = require("node:url");
const data = require("./security-backports.json");
const sha = (value) => createHash("sha256").update(value).digest("hex");
function installed(root) {
  const visited = new Set(), modules = new Set(), result = [];
  function walk(directory) {
    if (!fs.existsSync(directory)) return;
    const resolved = fs.realpathSync(directory);
    if (modules.has(resolved)) return;
    modules.add(resolved);
    for (const entry of fs.readdirSync(resolved, { withFileTypes: true })) {
      if (entry.name.startsWith(".")) continue;
      const folder = path.join(resolved, entry.name);
      if (entry.name.startsWith("@")) { walk(folder); continue; }
      if (!entry.isDirectory() && !entry.isSymbolicLink()) continue;
      const actual = fs.realpathSync(folder);
      if (visited.has(actual)) continue;
      visited.add(actual);
      const manifest = path.join(actual, "package.json");
      if (fs.existsSync(manifest)) {
        const metadata = JSON.parse(fs.readFileSync(manifest, "utf8"));
        if (Object.hasOwn(data.advisories, metadata.name)) result.push({ directory: actual, ...metadata });
      }
      walk(path.join(actual, "node_modules"));
      for (let parent = path.dirname(actual); parent !== path.dirname(parent); parent = path.dirname(parent)) {
        if (path.basename(parent) === "node_modules") { walk(parent); break; }
      }
    }
  }
  walk(path.join(root, "node_modules"));
  return result;
}
function applicable(item) {
  // Unknown/prerelease versions require review instead of falling out of range.
  assert(/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(item.version), `Unreviewed package version: ${item.version}`);
  const [major, minor, patch] = item.version.split(".").map(Number);
  return item.name === "minimatch"
    ? major < 11 || (major === 11 && minor === 0 && patch < 1)
    : item.name === "glob" && major >= 9 && (major < 14 || (major === 14 && minor === 0 && patch < 2));
}
function bunCovered(name, advisory, allCopies, verified) {
  const affected = allCopies.filter(item => item.name === name && applicable(item));
  return data.advisories[name] === advisory?.url && affected.length > 0 &&
    affected.every(item => verified.includes(item));
}
function checkFiles(item, apply, root) {
  const policy = data.packages[`${item.name}@${item.version}`];
  assert(policy, `Unreviewed security backport: ${item.name}@${item.version}`);
  for (const [filename, record] of Object.entries(policy.files)) {
    const target = path.join(item.directory, filename);
    const source = fs.readFileSync(target, "utf8");
    if (sha(source) === record.patched) continue;
    assert(apply && sha(source) === record.original, `Unverified installed source: ${item.name}@${item.version}/${filename}`);
    let patched = source;
    for (const operation of record.operations) {
      assert.equal(patched.split(operation.before).length - 1, operation.count);
      patched = patched.split(operation.before).join(operation.after);
    }
    assert.equal(sha(patched), record.patched);
    const actual = fs.realpathSync(target);
    const relative = path.relative(fs.realpathSync(path.join(root, "node_modules")), actual);
    assert(relative && !relative.startsWith("..") && !path.isAbsolute(relative), "Patch target escaped this install");
    // Atomic replacement detaches pnpm/Bun store hardlinks. Never mutate a shared
    // cache inode, another checkout, or the global package store in place.
    const temporary = `${target}.${randomUUID()}.tmp`;
    try {
      fs.writeFileSync(temporary, patched, { flag: "wx", mode: fs.statSync(target).mode });
      fs.renameSync(temporary, target);
    } finally { if (fs.existsSync(temporary)) fs.unlinkSync(temporary); }
  }
}
async function probe(directory) {
  const req = createRequire(path.join(directory, "package.json"));
  const metadata = req("./package.json");
  const rootModule = req(directory);
  if (metadata.name === "minimatch") {
    const entries = [rootModule];
    if (Number(metadata.version.split(".")[0]) >= 9) entries.push(await import(pathToFileURL(path.join(directory, "dist/esm/index.js"))));
    for (const matchModule of entries) {
      const match = matchModule.minimatch || matchModule;
      const Klass = matchModule.Minimatch;
      assert(match("src/deep/example.ts", "src/**/*.ts"));
      assert(!match("src/deep/example.js", "src/**/*.ts"));
      if (Number(metadata.version.split(".")[0]) < 9) {
        assert.equal(Klass.prototype.firstPhasePreProcess, undefined, "Legacy classification changed");
        continue;
      }
      const value = new Klass(Array(30).fill("**/../a/b").join("/"), { optimizationLevel: 2 });
      assert(value.set.length <= 512, "Unbounded globpart amplification");
      const many = Array.from({ length: 1001 }, (_, i) => [`file${i}`]);
      value.partsMatch = () => { throw Error("Quadratic deduplication must be skipped"); };
      assert.equal(value.secondPhasePreProcess(many), many);
    }
  } else {
    const old = metadata.version.startsWith("9.");
    const entries = [req(old ? "./dist/cjs/walker.js" : "./dist/commonjs/walker.js"), await import(pathToFileURL(path.join(directory, old ? "dist/mjs/walker.js" : "dist/esm/walker.js")))];
    for (const { GlobUtil } of entries) {
      const signal = AbortSignal.abort();
      const util = new GlobUtil([], {}, { signal, platform: process.platform });
      for (const method of ["walkCB", "walkCB2", "walkCBSync", "walkCB2Sync"]) {
        let callbacks = 0;
        const cb = () => { callbacks++; };
        const processor = { processPatterns() { throw Error("Traversal continued after abort"); } };
        const args = method.includes("2") ? [{}, [], processor, cb] : [{}, [], cb];
        util[method](...args);
        assert.equal(callbacks, 1, `${method} must stop immediately after abort`);
      }
    }
    for (const mod of [rootModule, await import(pathToFileURL(path.join(directory, old ? "dist/mjs/index.js" : metadata.version.startsWith("13.") ? "dist/esm/index.min.js" : "dist/esm/index.js")))]) {
      assert.throws(() => mod.globSync("**/*", { signal: AbortSignal.abort(new Error("expected abort")) }), /expected abort/);
      const amplified = new mod.Glob(Array(30).fill("**/../a/b").join("/"), {});
      assert(amplified.patterns.length <= 512, "Bundled minimatch amplification remains");
    }
  }
}
function verify(root = process.cwd(), apply = false) {
  root = path.resolve(root);
  const allCopies = installed(root);
  const verified = allCopies.filter(applicable);
  assert(verified.length, "No installed minimatch/glob copies found");
  for (const item of verified) {
    checkFiles(item, apply, root);
    if (!apply) {
      const child = spawnSync(process.execPath, [__filename, "--probe", item.directory], { encoding: "utf8", timeout: 5000, maxBuffer: 1024 * 1024, windowsHide: true });
      assert(!child.error && child.status === 0, `Security regression failed: ${item.name}@${item.version}\n${child.stderr || child.error || ""}`);
    }
  }
  const covered = (name, url, version) => data.advisories[name] === url && verified.some(item => item.name === name && item.version === version);
  return {
    copies: verified.length,
    pnpm(advisory) {
      return !!advisory?.findings?.length && advisory.findings.every(finding => covered(advisory.module_name, advisory.url, finding.version));
    },
    npm(name, advisory, nodes) {
      return data.advisories[name] === advisory.url && Array.isArray(nodes) && nodes.length > 0 && nodes.every(node => {
        const candidate = path.resolve(root, node);
        // npm aggregates all nodes for a package, even glob 7 which is outside
        // this advisory's >=9 range. Check the actual installed version too.
        return fs.existsSync(candidate) && allCopies.some(item => item.name === name && item.directory === fs.realpathSync(candidate) && (verified.includes(item) || name === "glob" && Number(item.version.split(".")[0]) < 9));
      });
    },
    bun(name, advisory) {
      return bunCovered(name, advisory, allCopies, verified);
    },
  };
}
if (require.main === module) {
  if (process.argv[2] === "--probe") probe(process.argv[3]).catch(error => { console.error(error); process.exitCode = 1; });
  else console.log(`Verified ${verify(process.argv[3] || process.cwd(), process.argv[2] === "--apply").copies} reviewed security backports.`);
}
module.exports = { verify, installed, checkFiles, applicable, probe, bunCovered };

