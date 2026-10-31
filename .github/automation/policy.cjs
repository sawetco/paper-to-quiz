'use strict';

function version(value) {
  const match = typeof value === 'string' && value.match(/^[~^=<>\s]*(\d+)\.(\d+)\.(\d+)(?:\s*)$/);
  return match ? match.slice(1).map(Number) : null;
}
function safeVersion(before, after) {
  const a = version(before), b = version(after);
  return String(before).match(/^[~^=<>\s]*/)?.[0].trim() === String(after).match(/^[~^=<>\s]*/)?.[0].trim() && !!a && !!b && a[0] === b[0] && (a[0] !== 0 || a[1] === b[1]) &&
    (b[1] > a[1] || b[1] === a[1] && b[2] >= a[2]);
}
function dependencyMapSafe(before, after) {
  if (typeof before === 'string' || typeof after === 'string') return before === after || safeVersion(before, after);
  if (!before || !after || Array.isArray(before) || Array.isArray(after) || typeof before !== 'object' || typeof after !== 'object') return false;
  const a=Object.keys(before).sort(),b=Object.keys(after).sort();
  return JSON.stringify(a)===JSON.stringify(b) && a.every(key=>dependencyMapSafe(before[key],after[key]));
}
function manifestSafe(before, after) {
  const a=JSON.parse(before),b=JSON.parse(after);
  for(const section of ['dependencies','devDependencies','optionalDependencies','overrides','resolutions']){
    if(!dependencyMapSafe(a[section]||{},b[section]||{}))return false;
    delete a[section];delete b[section];
  }
  return require('node:util').isDeepStrictEqual(a,b);
}
function actionPatchSafe(patch) {
  const removed = [], added = [];
  for (const line of (patch || '').split('\n')) {
    if (!/^[+-](?![+-])/.test(line)) continue;
    const normalized = line.slice(1).replace(/(uses:\s+[\w./-]+)@[0-9a-f]{40}(\s*#.*)?$/, '$1@PIN');
    if (!normalized.endsWith('@PIN')) return false;
    (line[0] === '-' ? removed : added).push(normalized);
  }
  return removed.length > 0 && JSON.stringify(removed) === JSON.stringify(added);
}
function acceptableChecks(checks, statuses, required) {
  if (!required.length) return false;
  const latest = new Map();
  for (const c of [...checks].sort((a,b)=>a.id-b.id)) if (c.app?.slug === 'github-actions') latest.set(c.name,c);
  if ([...latest.values()].some(c => c.status !== 'completed' || ['failure','cancelled','timed_out','action_required','startup_failure','stale'].includes(c.conclusion))) return false;
  for (const c of statuses) if (['failure','error','pending'].includes(c.state)) return false;
  return required.every(name => latest.get(name)?.status === 'completed' && latest.get(name)?.conclusion === 'success');
}
function sameRepository(pr, fullName) {
  return pr.base?.repo?.full_name === fullName && pr.head?.repo?.full_name === fullName;
}
module.exports = { version, safeVersion, manifestSafe, actionPatchSafe, acceptableChecks, sameRepository };
