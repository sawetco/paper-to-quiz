"use strict";
const {safeVersion} = require('./policy.cjs');

// Versions come from the Action publisher's repository, never PR titles/comments.
// Fail closed on API errors, missing stable full-version tags or ambiguous aliases.
function actionVerifier(github) {
  const cache = new Map();
  async function versions(repository) {
    if (!cache.has(repository)) {
      const [owner, repo] = repository.split('/');
      cache.set(repository, (async () => {
          const tags = [];
          // Bound API work; an incomplete catalog cannot establish unambiguous versions.
          for (let page = 1; page <= 20; page++) {
            const {data} = await github.rest.repos.listTags({owner, repo, per_page: 100, page});
            tags.push(...data);
            if (data.length < 100) break;
            if (page === 20) throw new Error('Action tag catalog exceeds safe lookup limit');
          }
          // GitHub listTags exposes commit.sha for both lightweight and annotated tags.

          const result = new Map();
          for (const tag of tags) {
            const match = /^v?(\d+\.\d+\.\d+)$/.exec(tag.name);
            if (!match || !/^[0-9a-f]{40}$/.test(tag.commit?.sha || '')) continue;
            const values = result.get(tag.commit.sha) || new Set();
            values.add(match[1]);
            result.set(tag.commit.sha, values);
          }
          return result;
        })());
    }
    return cache.get(repository);
  }
  return async (before, after) => {
    const pins = text => {
      const items = [];
      let blockIndent = null;
      const ancestors = [];
      const lines = text.split('\n');
      const normalized = lines.map(line => {
        const indent = line.search(/\S/);
        if (blockIndent !== null && (indent < 0 || indent > blockIndent)) return line;
        blockIndent = null;
        if (/:[ \t]*[|>][+\-0-9]*[ \t]*(?:#.*)?$/.test(line)) {
          blockIndent = indent;
          return line;
        }
        // Conservatively reject multiline quoted scalars rather than mistake their
        // literal contents for workflow nodes. Normal one-line quoted values work.
        const quoted = /:[ \t]*(["'])(.*)$/.exec(line);
        if (quoted && !new RegExp(quoted[1] + '[ \\t]*(?:#.*)?$').test(quoted[2]))
          throw new Error('Multiline quoted YAML needs manual review');
        const entry = /^([ \t]*)(- )?([A-Za-z_][\w-]*):(?:[ \t]|$)/.exec(line);
        const keyIndent = entry ? entry[1].length + (entry[2] ? 2 : 0) : indent;
        if (indent >= 0 && !line.trimStart().startsWith('#'))
          while (ancestors.length && ancestors.at(-1).indent >= keyIndent) ancestors.pop();
        const path = ancestors.map(a => a.key);
        const actionNode = entry?.[3] === 'uses' && path[0] === 'jobs' &&
          (path.length === 2 || (path.length === 3 && path[2] === 'steps'));
        if (entry) ancestors.push({indent: keyIndent, key: entry[3]});
        if (!actionNode) return line;
        return line.replace(/^([ \t]*-?[ \t]*uses:[ \t]+)([\w.-]+\/[\w.-]+)([\w./-]*)@([0-9a-f]{40})([ \t]*#.*)?$/,
          (_, prefix, repository, path, sha) => {
            items.push({repository, path, sha});
            return `${prefix}${repository}${path}@PIN`;
          });
      }).join('\n');
      return {items, normalized};
    };
    let a, b;
    try { a = pins(before); b = pins(after); } catch { return false; }
    if (!a.items.length || a.normalized !== b.normalized || a.items.length !== b.items.length) return false;
    let changed = false;
    try {
      for (let i = 0; i < a.items.length; i++) {
        const old = a.items[i], next = b.items[i];
        if (old.sha === next.sha) continue;
        changed = true;
        const tags = await versions(old.repository);
        const from = tags.get(old.sha), to = tags.get(next.sha);
        if (from?.size !== 1 || to?.size !== 1 || !safeVersion([...from][0], [...to][0])) return false;
      }
      return changed;
    } catch {
      return false;
    }
  };
}

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
module.exports = {actionVerifier, checkBlockers};
