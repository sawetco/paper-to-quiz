"use strict";

function checkBlockers(checks, statuses, required, observedAt, now = Date.now()) {
  const latest = new Map();
  for (const c of [...checks].sort((a,b) => a.id-b.id))
    if (c.app?.slug === 'github-actions') latest.set(c.name,c);
  const age = now - Date.parse(observedAt);
  // Unknown age is not evidence that a check is freshly pending.
  const graceExpired = !Number.isFinite(age) || age < 0 || age >= 2 * 60 * 60 * 1000;
  const pendingExpired = !Number.isFinite(age) || age < 0 || age >= 24 * 60 * 60 * 1000;
  const problems = [];
  for (const name of required) {
    const c = latest.get(name);
    if (!c && graceExpired) problems.push(`${name}: missing for at least the observation grace period`);
    else if (c?.status === 'completed' && c.conclusion !== 'success') problems.push(`${name}: ${c.conclusion || 'unknown conclusion'}`);
    else if (c && c.status !== 'completed' && pendingExpired) problems.push(`${name}: pending beyond 24 hours`);
  }
  for (const c of latest.values()) {
    if (c.status === 'completed' && ['failure','timed_out','cancelled','action_required','startup_failure','stale'].includes(c.conclusion))
      problems.push(`${c.name}: ${c.conclusion}`);
    else if (c.status !== 'completed' && pendingExpired) problems.push(`${c.name}: pending beyond 24 hours`);
  }
  for (const s of statuses) {
    if (['failure','error'].includes(s.state) || (s.state === 'pending' && pendingExpired))
      problems.push(`${s.context}: ${s.state}`);
  }
  if (!required.length) problems.push('No required checks configured');
  return [...new Set(problems)].sort();
}
module.exports = {checkBlockers};
