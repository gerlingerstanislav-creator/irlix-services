#!/usr/bin/env node
// Trusted-base GitHub Action: measures elapsed PR/role intervals; never executes PR code.
import { appendFileSync, readFileSync } from 'node:fs';
import { renderTimingSummary, SUMMARY_MARKER, EVENT_MARKER } from './task-metrics.mjs';
import { routeChanges } from './route.mjs';

const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
const repository = process.env.GITHUB_REPOSITORY;
const token = process.env.GITHUB_TOKEN;
if (!/^[-\w.]+\/[-\w.]+$/.test(repository ?? '') || !token) throw new Error('Missing GitHub repository or token');
let number = event.pull_request?.number ?? (event.issue?.pull_request ? event.issue.number : null);
if (event.comment) {
  // Ignore untrusted comments; never execute comment text.
  if (!['OWNER', 'MEMBER', 'COLLABORATOR'].includes(event.comment.author_association) ||
      !String(event.comment.body ?? '').includes(EVENT_MARKER)) process.exit(0);
}
const api = 'https://api.github.com/repos/' + repository;
async function request(path, method = 'GET', body) {
  const res = await fetch(api + path, {
    method,
    headers: {
      Authorization: 'Bearer ' + token,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      ...(body ? { 'Content-Type': 'application/json' } : {})
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
    signal: AbortSignal.timeout(30000)
  });
  if (!res.ok) throw new Error('GitHub ' + method + ' ' + path + ': ' + res.status + ' ' + (await res.text()).slice(0, 120));
  return res.json();
}
async function pages(path) {
  const all = [];
  for (let page = 1; page <= 10; page++) {
    const sep = path.includes('?') ? '&' : '?';
    const items = await request(path + sep + 'per_page=100&page=' + page);
    if (!Array.isArray(items)) throw new Error('Expected array from GitHub');
    all.push(...items);
    if (items.length < 100) return all;
  }
  throw new Error('Too many GitHub pages; avoiding incomplete time report');
}
if (event.ref === 'refs/heads/development' && event.after) {
  // push workflows are loaded from development even before they exist on main.
  const sha = String(event.after);
  if (!/^[0-9a-f]{40}$/.test(sha)) throw new Error('Invalid push SHA');
  const associated = await request('/commits/' + sha + '/pulls');
  const matched = Array.isArray(associated) && associated.find(p =>
    p.base?.ref === 'development' && p.merged_at && p.merge_commit_sha === sha);
  if (!matched) {
    console.log('No matching merged PR for development SHA ' + sha.slice(0, 12) + '; nothing to measure.');
    process.exit(0);
  }
  number = matched.number;
}
if (!Number.isInteger(number) || number <= 0) throw new Error('Expected a pull request event');
const pr = await request('/pulls/' + number);
if (pr.base?.ref !== 'development' || pr.base?.repo?.full_name !== repository ||
    pr.head?.repo?.full_name !== repository || pr.draft) {
  console.log('Unsupported PR scope; skipping.');
  process.exit(0);
}
const [comments, files] = await Promise.all([
  pages('/issues/' + number + '/comments'),
  pages('/pulls/' + number + '/files')
]);
const routed = routeChanges(files.map(x => x.filename));
const roles = [
  'PM', 'Analyst',
  ...routed.developers,
  ...(routed.developers.length ? [] : ['Developer']),
  ...(routed.ui ? ['Designer'] : []),
  ...(routed.shared || routed.services.length > 1 ? ['PlatformEngineer'] : []),
  'QA'
];
const summary = renderTimingSummary({ pr, comments, roles });
const existing = comments.find(x => x.user?.login === 'github-actions[bot]' && x.body?.includes(SUMMARY_MARKER));
if (existing) {
  if (existing.body !== summary) await request('/issues/comments/' + existing.id, 'PATCH', { body: summary });
} else await request('/issues/' + number + '/comments', 'POST', { body: summary });
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, summary + '\n');
console.log('Task timing summary refreshed for #' + number + ' (' + (pr.merged_at ? 'merged' : pr.state) + ')');
