#!/usr/bin/env node
// QA browser runner: build local Vite previews and execute existing synthetic Playwright scenarios.
// Never use production/test URLs, secrets or real business data.
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync, appendFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { browserPlan } from './browser-plan.mjs';

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const argv = process.argv.slice(2);
function arg(key, fallback = null) {
  const at = argv.indexOf('--' + key);
  if (at < 0) return fallback;
  if (!argv[at + 1] || argv[at + 1].startsWith('--')) throw new Error('Missing --' + key + ' value');
  return argv[at + 1];
}
const scope = arg('scope', 'changed');
const base = arg('base', process.env.QA_BASE || '');
const out = resolve(root, arg('out', '.qa-results/browser'));
mkdirSync(out, { recursive: true });
let changedPaths = null;
let selectionWarning = '';
if (scope === 'changed' && base && !/^0+$/.test(base)) {
  const diff = spawnSync('git', ['diff', '--name-only', '--diff-filter=ACMR', base + '...HEAD'], { cwd: root, encoding: 'utf8' });
  if (diff.status === 0) changedPaths = diff.stdout.trim().split('\n').filter(Boolean);
  else selectionWarning = 'Cannot resolve changed paths: checking all browser suites. ' + (diff.stderr || '').trim();
}
const plan = browserPlan(changedPaths, scope);
const results = [];
const servers = [];
const rootDir = '/tmp/irlix-qa-playwright';
const playwrightModule = join(rootDir, 'node_modules/playwright');
const executableFromEnv = process.env.CHROMIUM_EXECUTABLE;
let setupFailure = null;

function command(cmd, args, cwd = root, extraEnv = {}, timeout = 360000) {
  const result = spawnSync(cmd, args, {
    cwd, encoding: 'utf8', timeout, maxBuffer: 24 * 1024 * 1024,
    env: { ...process.env, ...extraEnv }
  });
  const text = [result.stdout, result.stderr].filter(Boolean).join('\n');
  if (result.error || result.status !== 0) throw new Error([cmd, ...args].join(' ') + '\n' + text.slice(-12000) + '\n' + (result.error?.message || 'exit ' + result.status));
  return text;
}
function chromePath() {
  if (executableFromEnv) return executableFromEnv;
  const found = spawnSync('sh', ['-c', 'command -v google-chrome || command -v google-chrome-stable || command -v chromium || command -v chromium-browser'], { encoding: 'utf8' });
  if (found.status !== 0 || !found.stdout.trim()) throw new Error('System Chromium/Chrome executable unavailable. Browser tests NOT RUN.');
  return found.stdout.trim().split('\n')[0];
}
async function startPreview(app, port) {
  const appDir = join(root, 'apps', app);
  const bin = join(appDir, 'node_modules/vite/bin/vite.js');
  const proc = spawn(process.execPath, [bin, 'preview', '--host', '127.0.0.1', '--port', String(port), '--strictPort'], {
    cwd: appDir,
    env: { ...process.env, NODE_ENV: 'production' },
    stdio: ['ignore', 'pipe', 'pipe']
  });
  let serverOutput = '';
  for (const stream of [proc.stdout, proc.stderr]) stream.on('data', data => { serverOutput += String(data).slice(-2000); });
  servers.push(proc);
  const url = 'http://127.0.0.1:' + port + '/' + app + '/';
  for (let i = 0; i < 80; i++) {
    if (proc.exitCode !== null) throw new Error('Preview ' + app + ' exited: ' + serverOutput);
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(400) });
      if (response.ok) return url;
    } catch {}
    await delay(250);
  }
  throw new Error('Local preview not ready for ' + app + ': ' + serverOutput);
}
const sha = process.env.GITHUB_SHA || spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).stdout.trim();
try {
  if (plan.cases.length) {
    const chrome = chromePath();
    // Explicitly suppress Playwright browser downloads; reuse the runner's Chrome only.
    if (!existsSync(playwrightModule)) {
      command('npm', ['install', '--prefix', rootDir, '--no-audit', '--no-fund', 'playwright@1.56.1'], root, { PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD: '1' });
    }
    for (const app of plan.apps) {
      const cwd = join(root, 'apps', app);
      command('npm', app === 'migration' ? ['ci', '--no-audit', '--no-fund'] : ['install', '--no-audit', '--no-fund'], cwd);
      command('npm', ['run', 'build'], cwd);
      await startPreview(app, plan.cases.find(x => x.app === app).port);
    }
    for (const scenario of plan.cases) {
      const started = Date.now();
      const env = {
        ...process.env,
        PLAYWRIGHT_MODULE: playwrightModule,
        CHROMIUM_EXECUTABLE: chrome,
        CLIENTS_PREVIEW: 'http://127.0.0.1:5193',
        EQUIPMENT_PREVIEW: 'http://127.0.0.1:5195',
        MIGRATION_PREVIEW: 'http://127.0.0.1:5173'
      };
      const run = spawnSync(process.execPath, [scenario.test], {
        cwd: root, env, encoding: 'utf8', timeout: 300000, maxBuffer: 24 * 1024 * 1024
      });
      const log = [run.stdout, run.stderr, run.error?.message].filter(Boolean).join('\n');
      const passed = run.status === 0 && !run.error;
      const result = {
        id: scenario.id, suite: scenario.suite, label: scenario.label,
        passed, durationMs: Date.now() - started,
        status: run.status, error: run.error?.message || null
      };
      results.push(result);
      writeFileSync(join(out, scenario.id + '.log'), log + '\n');
      console.log('QA BROWSER / ' + scenario.id + ': ' + (passed ? 'PASS' : 'FAIL'));
      if (!passed) console.error(log.slice(-12000));
    }
  }
} catch (error) {
  setupFailure = String(error.stack || error);
  console.error(setupFailure);
} finally {
  for (const child of servers) {
    if (child.exitCode === null) child.kill('SIGTERM');
  }
}
const status = setupFailure || results.some(x => !x.passed) ? 'FAIL' : (results.length ? 'PASS' : 'NOT TESTED');
const report = {
  agent: 'QA', kind: 'synthetic-browser', sha, scope, status,
  changedPaths: plan.changedPaths, selectionWarning,
  suites: results, setupFailure,
  missing: plan.missing, note: plan.note
};
const summary = [
  '# QA browser checks',
  '',
  '- SHA: ' + sha,
  '- Status: **' + status + '**',
  '- Execution: localhost-only frontend, synthetic API and auth',
  '- Browser scenarios executed: ' + results.length,
  setupFailure ? '- Setup failure: ' + setupFailure.split('\n')[0] : '',
  selectionWarning ? '- Scope warning: ' + selectionWarning : '',
  '',
  '| Scenario | Status | Duration |', '|---|---|---:|',
  ...results.map(x => '| ' + x.label + ' | ' + (x.passed ? 'PASS' : 'FAIL') + ' | ' + x.durationMs + ' ms |'),
  '',
  '- Backend integration: **NOT TESTED**',
  '- Real roles/TEST/production: **NOT TESTED**',
  '- Visual pixel comparison: **NOT TESTED**',
  ''
].filter((x, i) => !(i > 0 && x === '' && false)).join('\n');
writeFileSync(join(out, 'report.json'), JSON.stringify(report, null, 2) + '\n');
writeFileSync(join(out, 'report.md'), summary + '\n');
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, summary + '\n');
console.log(summary);
process.exitCode = status === 'FAIL' ? 1 : 0;
