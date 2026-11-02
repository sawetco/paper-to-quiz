"use strict";

// GitHub PR numbers give stable oldest-first ordering. New green PRs and newer
// running checks must not displace an older eligible dependency/security fix.
function headWaiting({ checks, status, observedAt }, required, now = Date.now()) {
  const age = now - Date.parse(observedAt);
  if (!Number.isFinite(age) || age < 0) return false;
  const latest = new Map();
  for (const c of [...checks].sort((a, b) => a.id - b.id))
    if (c.app?.slug === 'github-actions') latest.set(c.name, c);
  const pending = [...latest.values()].some(c => c.status !== 'completed') ||
    status.statuses.some(s => s.state === 'pending');
  const missing = required.some(name => !latest.has(name));
  return (pending && age < 24 * 3600000) || (missing && age < 2 * 3600000);
}
function queuePlan(candidates, required, now = Date.now()) {
  const ordered = [...candidates].sort((a, b) => a.pr.number - b.pr.number);
  const first = ordered.find(c => c.pr.mergeable !== false && !c.updateBlocked);
  return { wait: !!first && headWaiting(first, required, now), ordered };
}
module.exports = { queuePlan, headWaiting };
