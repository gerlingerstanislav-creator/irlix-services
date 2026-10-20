import { test } from 'node:test';
import assert from 'node:assert/strict';
import { makePlan } from '../plan.mjs';

const ids = p => p.suites.map(x => x.id);

test('QA always self-tests and targets relevant services', () => {
  assert.deepEqual(ids(makePlan(['apps/clients/src/workflow.js'])), ['clients', 'qa']);
  assert.deepEqual(ids(makePlan(['docs/README.md'])), ['qa']);
  assert.deepEqual(ids(makePlan(['services/timesheets/routes/api.php'])), ['clients', 'timesheets', 'qa']);
});
test('shared UI and auth changes check direct consumers', () => {
  const ui = ids(makePlan(['packages/ui/src/theme.js']));
  assert(ui.includes('clients') && ui.includes('timesheets') && ui.includes('employees') && ui.includes('ui'));
  const auth = ids(makePlan(['packages/auth/src/index.js']));
  assert(auth.includes('auth') && auth.includes('clients') && auth.includes('employees'));
});
test('no diff and explicit all run the full suite', () => {
  assert.deepEqual(ids(makePlan()), ids(makePlan([], 'all')));
  assert(ids(makePlan()).length >= 7);
});
test('new service areas remain visible while Equipment has dedicated QA', () => {
  const plan = makePlan(['services/equipment/app/Controller.php', 'services/new-service/handler.php']);
  assert(ids(plan).includes('equipment'));
  assert(!plan.gaps.includes('equipment'));
  assert(plan.gaps.includes('Unmapped source changes require manual QA scoping'));
  assert.equal(plan.browserCovered, false);
});
test('explicit scope does not falsely expand testing', () => {
  assert.deepEqual(ids(makePlan([], 'clients')), ['clients', 'qa']);
  const plan = makePlan([], 'vacations');
  assert.deepEqual(ids(plan), ['qa']);
  assert(plan.gaps.includes('vacations'));
});
test('invalid request fails closed', () => {
  assert.throws(() => makePlan([], 'whatever'), /Unknown QA scope/);
  assert.throws(() => makePlan([42]), /changedPaths/);
});

test('QA/CI changes trigger full suite, never self-validation alone', () => {
  const all = ids(makePlan([], 'all'));
  assert.deepEqual(ids(makePlan(['scripts/qa/run.mjs'])), all);
  assert.deepEqual(ids(makePlan(['.github/workflows/ci.yml'])), all);
  assert.deepEqual(ids(makePlan(['.github/workflows/qa.yml'])), all);
});
