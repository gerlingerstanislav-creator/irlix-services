#!/usr/bin/env node
// Safe backend QA syntax gate: lint changed PHP source without databases or production access.
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, statSync, writeFileSync, appendFileSync } from 'node:fs';
import { resolve, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const args = process.argv.slice(2);
function opt(k, fallback = '') {
  const n = args.indexOf('--' + k);
  return n === -1 ? fallback : args[n + 1];
}
const base = opt('base', process.env.QA_BASE || '');
const scope = opt('scope', 'changed');
const out = resolve(root, '.qa-results/backend');
mkdirSync(out, { recursive: true });
function findAllPhp(dir, found = []) {
  if (!existsSync(dir)) return found;
  for (const item of readdirSync(dir, { withFileTypes: true })) {
    if (['vendor', 'node_modules', '.git'].includes(item.name)) continue;
    const path = join(dir, item.name);
    if (item.isDirectory()) findAllPhp(path, found);
    else if (item.isFile() && item.name.endsWith('.php')) found.push(relative(root, path));
  }
  return found;
}
export function selectPhp(files) {
  return [...new Set(files.filter(x => x.endsWith('.php') && !x.split('/').includes('vendor') && !x.split('/').includes('node_modules')))].sort();
}
let files = null, warning = '';
if (scope === 'changed' && base && !/^0+$/.test(base)) {
  const r = spawnSync('git', ['diff', '--name-only', '--diff-filter=ACMR', base + '...HEAD'], { cwd: root, encoding: 'utf8' });
  if (r.status === 0) files = r.stdout.trim().split('\n').filter(Boolean);
  else warning = 'Unable to calculate diff; linting all project PHP source.';
}
if (files === null || scope === 'all') files = findAllPhp(join(root, 'services'));
const paths = selectPhp(files);
const errors = [];
if (paths.length) {
  const php = spawnSync('php', ['-v'], { cwd: root, encoding: 'utf8' });
  if (php.status !== 0) errors.push('PHP CLI unavailable; cannot lint ' + paths.length + ' files.');
  else for (const path of paths) {
    if (!existsSync(resolve(root, path)) || !statSync(resolve(root, path)).isFile()) continue;
    const r = spawnSync('php', ['-l', path], { cwd: root, encoding: 'utf8', timeout: 15000 });
    if (r.status !== 0) errors.push(path + ': ' + (r.stderr || r.stdout || r.error?.message || r.status).trim());
  }
}
const status = errors.length ? 'FAIL' : paths.length ? 'PASS' : 'NOT TESTED';
const report = {
  agent: 'QA', kind: 'backend-php-syntax',
  scope, status, phpFilesChecked: paths.length, paths, errors, warning,
  note: 'PHP -l syntax checks only. Backend execution, database consistency, authorization and API integration are NOT TESTED.'
};
const markdown = [
  '# QA backend PHP lint',
  '- Status: **' + status + '**',
  '- PHP files checked: ' + paths.length,
  warning ? '- Note: ' + warning : '',
  ...errors.map(x => '- FAIL: ' + x),
  '- Real API/DB/roles integration: **NOT TESTED**'
].filter(Boolean).join('\n') + '\n';
writeFileSync(join(out, 'report.json'), JSON.stringify(report, null, 2) + '\n');
writeFileSync(join(out, 'report.md'), markdown);
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, markdown);
console.log(markdown);
process.exitCode = errors.length ? 1 : 0;
