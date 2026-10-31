"use strict";
const { posix } = require("node:path");
// Use the same explicit package allowlist as the WordPress ZIP. Build settings,
// translations, licensing and readme changes must not silently miss a release.
function affectsDistribution(product, filename, files = []) {
  if (product === "stone") return /^(?:src\/|scripts\/|version\.json$|README\.md$|CHANGELOG\.md$|LICENSE$|SECURITY\.md$|\.github\/automation\/package-stonewards\.py$)/.test(filename);
  if (/^(?:package(?:-lock)?\.json|composer(?:\.json|\.lock))$/.test(filename)) return true;
  const matches = pattern => filename === pattern || filename.startsWith(`${pattern}/`) || posix.matchesGlob(filename, pattern);
  return files.some(pattern => !pattern.startsWith("!") && matches(pattern)) && !files.some(pattern => pattern.startsWith("!") && matches(pattern.slice(1)));
}
module.exports = { affectsDistribution };
