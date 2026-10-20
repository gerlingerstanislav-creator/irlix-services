import { test } from 'node:test';
import assert from 'node:assert/strict';
import { browserPlan, browserCases } from '../browser-plan.mjs';

const ids = p => p.cases.map(x => x.id);
test('browser scope activates Clients only for Clients source and tests', () => {
  assert.deepEqual(ids(browserPlan(['apps/clients/src/App.vue'])), ['clients-registry', 'clients-partner-members']);
  assert.deepEqual(ids(browserPlan([], 'clients')), ['clients-registry', 'clients-partner-members']);
});
test('migration source activates migration browser scenario', () => {
  assert.deepEqual(ids(browserPlan(['apps/migration/src/main.js'])), ['migration-console']);
});
test('shared UI/auth and runner changes activate all existing browser checks', () => {
  for (const path of ['packages/ui/src/index.js', 'packages/auth/src/index.js', 'scripts/qa/browser.mjs', '.github/workflows/ci.yml']) {
    assert.deepEqual(ids(browserPlan([path])), browserCases.map(x => x.id), path);
  }
});
test('docs-only changes do not claim browser coverage', () => {
  assert.deepEqual(ids(browserPlan(['docs/QA.md'])), []);
  assert.deepEqual(ids(browserPlan([], 'vacations')), []);
});
test('no diff means full safe fallback', () => {
  assert.deepEqual(ids(browserPlan(null, 'changed')), browserCases.map(x => x.id));
  assert.deepEqual(ids(browserPlan([], 'all')), browserCases.map(x => x.id));
});
test('browser planning must not claim real backend coverage', () => {
  const p = browserPlan([], 'all');
  assert.match(p.note, /No live backend/);
  assert.equal(new Set(p.cases.map(x => x.id)).size, p.cases.length);
});

test('catalog changes exercise navigation and registry contracts together', () => {
  const plan = browserPlan(['apps/design-system/src/App.vue']);
  assert.deepEqual(ids(plan), ['design-system-navigation', 'ui-registry-table']);
  assert.deepEqual(plan.apps, ['design-system']);
});
