#!/usr/bin/env node
// Safe QA orchestrator. Never connects to an environment or mutates business data.
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, writeFileSync, appendFileSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { makePlan } from './plan.mjs';

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const args = process.argv.slice(2);
function arg(name, fallback = null) {
  const i = args.indexOf('--' + name);
  if (i < 0) return fallback;
  if (i === args.length - 1 || args[i + 1].startsWith('--')) throw new Error('Missing value for --' + name);
  return args[i + 1];
}
const scope = arg('scope', 'changed');
const base = arg('base', process.env.QA_BASE || '');
const out = resolve(root, arg('out', '.qa-results'));
let changedPaths = null, selectionWarning = null;
if (scope === 'changed' && base && !/^0+$/.test(base)) {
  const diff = spawnSync('git', ['diff', '--name-only', '--diff-filter=ACMR', base + '...HEAD'], {
    cwd: root, encoding: 'utf8'
  });
  if (diff.status === 0) changedPaths = diff.stdout.trim().split('\n').filter(Boolean);
  else selectionWarning = 'Unable to compare with base; running all suites: ' + diff.stderr.trim();
}
const plan = makePlan(changedPaths, scope);
mkdirSync(out, { recursive: true });
function collect(paths) {
  const found = [];
  for (const path of paths) {
    const absolute = resolve(root, path);
    if (!existsSync(absolute)) throw new Error('QA suite target missing: ' + path);
    if (statSync(absolute).isDirectory()) {
      const selected = readdirSync(absolute).filter(n => /\.test\.(?:mjs|cjs|js)$/.test(n)).sort();
      if (!selected.length) throw new Error('QA suite has no tests: ' + path);
      found.push(...selected.map(n => join(path, n)));
    } else found.push(path);
  }
  return found;
}
const results = [];
for (const suite of plan.suites) {
  const paths = collect(suite.files);
  process.stdout.write('\n=== QA / ' + suite.label + ' (' + paths.length + ' files) ===\n');
  const start = Date.now();
  const run = spawnSync(process.execPath, ['--test', ...paths], {
    cwd: root, encoding: 'utf8', timeout: 180000, maxBuffer: 8 * 1024 * 1024,
    env: { ...process.env, FORCE_COLOR: '0', NO_COLOR: '1' }
  });
  if (run.stdout) process.stdout.write(run.stdout);
  if (run.stderr) process.stderr.write(run.stderr);
  const ok = run.status === 0 && !run.error;
  results.push({
    id: suite.id, label: suite.label, tests: paths, passed: ok,
    exitCode: run.status, durationMs: Date.now() - start,
    error: run.error ? String(run.error.message) : null
  });
}
const failed = results.filter(x => !x.passed);
const sha = process.env.GITHUB_SHA || spawnSync('git', ['rev-parse', 'HEAD'], {
  cwd: root, encoding: 'utf8'
}).stdout.trim();
const report = {
  agent: 'QA', sha, createdAt: new Date().toISOString(),
  scope, selectionWarning, targets: plan.targets, changedPaths: plan.changedPaths,
  results, passed: failed.length === 0, gaps: plan.gaps,
  browserCovered: plan.browserCovered, note: plan.note
};
const lines = [
  '# QA report', '',
  '- SHA: ' + sha,
  '- Scope: ' + scope,
  '- Result: **' + (report.passed ? 'PASS' : 'FAIL') + '** (' + results.length + ' suites, ' + failed.length + ' failed)',
  '- Browser E2E in this job: **NOT TESTED** (see separate QA browser scenarios job for synthetic Chromium checks)',
  selectionWarning ? '- Selection warning: ' + selectionWarning : '',
  '', '| Suite | Result | Duration |', '|---|---|---:|',
  ...results.map(x => '| ' + x.label + ' | ' + (x.passed ? 'PASS' : 'FAIL') + ' | ' + x.durationMs + ' ms |'),
  '', '## Unverified domains',
  ...(plan.gaps.length ? plan.gaps.map(x => '- ' + x) : ['- No unmapped domains detected. Browser/backend integration remains unverified.']),
  '', '## Scope limitation', plan.note, ''
];
const markdown = lines.join('\n');
writeFileSync(join(out, 'report.json'), JSON.stringify(report, null, 2) + '\n');
writeFileSync(join(out, 'report.md'), markdown + '\n');
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, markdown + '\n');
process.stdout.write('\n' + markdown + '\n');
process.exitCode = report.passed ? 0 : 1;
