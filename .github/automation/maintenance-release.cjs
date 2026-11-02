'use strict';
const { affectsDistribution } = require('./distribution-paths.cjs');

// Only positive, exact-head evidence can make publication optional. Use the
// planner's published baseline and ZIP path classifier, never log text or PR code.
async function releaseNotRequired({ api, owner, repo, head, pulls }) {
  if (repo !== 'paper-to-quiz' || pulls.some(pr => pr.head?.ref === 'automation/release')) return false;
  try {
    const releases = (await api.repos.listReleases({ owner, repo, per_page: 30 })).data;
    const latest = releases.find(r => !r.draft && !r.prerelease);
    if (!latest?.published_at || !/^v?\d+\.\d+\.\d+$/.test(latest.tag_name)) return false;
    const content = (await api.repos.getContent({ owner, repo, path: 'package.json', ref: head })).data;
    if (content.encoding !== 'base64' || typeof content.content !== 'string') return false;
    const pkg = JSON.parse(Buffer.from(content.content, 'base64').toString('utf8'));
    if (pkg.version !== latest.tag_name.replace(/^v/, '') || !Array.isArray(pkg.files) ||
        !pkg.files.length || !pkg.files.every(p => typeof p === 'string' && p.length)) return false;
    const comparison = (await api.repos.compareCommitsWithBasehead({
      owner, repo, basehead: `${latest.tag_name}...${head}`,
    })).data;
    // GitHub caps comparison files at 300; a full page cannot prove absence.
    if (!['ahead', 'identical'].includes(comparison.status) || comparison.behind_by !== 0 ||
        !comparison.base_commit?.sha || comparison.merge_base_commit?.sha !== comparison.base_commit.sha ||
        !Array.isArray(comparison.files) || comparison.files.length >= 300) return false;
    return comparison.files.every(file => typeof file.filename === 'string' && file.filename.length &&
      ['added', 'removed', 'modified', 'renamed', 'copied', 'changed', 'unchanged'].includes(file.status) &&
      (file.status !== 'renamed' || typeof file.previous_filename === 'string' && file.previous_filename.length) &&
      !affectsDistribution('paper', file.filename, pkg.files) &&
      (!file.previous_filename || !affectsDistribution('paper', file.previous_filename, pkg.files)));
  } catch {
    return false; // Missing permissions, baseline, or comparison evidence keeps attention open.
  }
}
module.exports = { releaseNotRequired };
