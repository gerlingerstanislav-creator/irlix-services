import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isPlatformAdminAccess } from '../../../packages/ui/src/serviceCatalog.js';

function setup() {
  const source = readFileSync(new URL('../src/main.js', import.meta.url), 'utf8')
    .replace(/^import .*;$/gm, '').replace(/\nstart\(\);\s*$/, '')
    .replace(/const (renderMigrationState|showToast|migrationApi) =/g, 'let $1 =');
  const context = vm.createContext({
    createBrowserAuth: () => ({}), console, isPlatformAdminAccess,
    document: { getElementById: () => null, querySelectorAll: () => [] },
    window: { clearTimeout() {}, setTimeout(_fn, delay) { context.delay = delay; } },
  });
  vm.runInContext(source + `
    renderMigrationState = () => {};
    showToast = () => {};
    migrationApi = async () => { if (response instanceof Error) throw response; return response; };
    globalThis.api = { migrationTrackedRuns, migrationRunDetails, migrationPendingActions,
      loadRunDetails, withServicePending, scheduleMigrationPoll,
      setResponse(value) { response = value; },
      setState(value) { migrationState = value; } };
    let response;
  `, context);
  return { ...context.api, context };
}

test('exact run stays locked through queued/running and ignores an older terminal run', async () => {
  const s = setup();
  s.migrationTrackedRuns.set('employees', { id: 20, mode: 'dry-run', status: 'queued' });
  s.setResponse({ id: 19, status: 'completed' });
  await s.loadRunDetails('employees', 19);
  assert.equal(s.migrationTrackedRuns.get('employees').id, 20);
  s.setResponse({ id: 20, status: 'running', mode: 'dry-run' });
  await s.loadRunDetails('employees', 20);
  let started = false;
  await s.withServicePending('employees', {}, () => { started = true; });
  assert.equal(started, false);
  s.setResponse({ id: 20, status: 'failed', mode: 'dry-run' });
  await s.loadRunDetails('employees', 20);
  assert.equal(s.migrationTrackedRuns.has('employees'), false);
  assert.equal(s.migrationRunDetails.get('employees').status, 'failed');
});

test('HTTP failure does not invent a terminal job status or release the lock', async () => {
  const s = setup();
  s.migrationTrackedRuns.set('employees', { id: 20, mode: 'inspect', status: 'running' });
  // Error must belong to the VM realm for the mocked API.
  vm.runInContext('api.setResponse(new Error("offline"))', s.context);
  await s.loadRunDetails('employees', 20);
  assert.equal(s.migrationTrackedRuns.get('employees').status, 'running');
  assert.equal(s.migrationTrackedRuns.get('employees').pollError, 'offline');
  s.scheduleMigrationPoll();
  assert.equal(s.context.delay, 1000);
});

test('initial request blocks double click while another service remains independent', async () => {
  const s = setup();
  let release;
  const wait = new Promise(resolve => { release = resolve; });
  const first = s.withServicePending('employees', { action: 'run' }, () => wait);
  let duplicates = 0;
  await s.withServicePending('employees', {}, () => { duplicates++; });
  let other = false;
  await s.withServicePending('vacations', {}, () => { other = true; });
  assert.equal(duplicates, 0);
  assert.equal(other, true);
  release(); await first;
  assert.equal(s.migrationPendingActions.size, 0);
  s.scheduleMigrationPoll();
  assert.equal(s.context.delay, 5000);
});
