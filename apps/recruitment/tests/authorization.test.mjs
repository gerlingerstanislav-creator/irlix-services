import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  canRecruitment, recruitmentAdmission, candidateCreatePayload, offerCreatePayload,
} from '../src/authorization.js';

test('Recruitment service admission is independent in local V2 mode', () => {
  assert.equal(recruitmentAdmission('local', { 'recruitment.workspace.view': true }), true);
  assert.equal(recruitmentAdmission('local', { 'recruitment.candidates.manage': true }), false);
  assert.equal(recruitmentAdmission('local', { 'service:recruitment': true }), false);
  assert.equal(recruitmentAdmission('unknown', { 'recruitment.workspace.view': true }), false);
  assert.equal(recruitmentAdmission('legacy', {}), true);
  assert.equal(canRecruitment('local', {}, 'recruitment.offers.manage'), false);
});

test('Candidate create omits salary without an explicit Recruitment write grant', () => {
  const form = { full_name: 'Synthetic', role: 'Backend', salary_expectation: 'sensitive',
    stack: 'PHP, SQL' };
  const noSalary = candidateCreatePayload(form, 'local', { 'recruitment.candidates.manage': true });
  assert.deepEqual(noSalary.stack, ['PHP', 'SQL']);
  assert.equal(Object.hasOwn(noSalary, 'salary_expectation'), false);
  assert.equal(form.salary_expectation, 'sensitive');
  const allowed = candidateCreatePayload(form, 'local', { 'recruitment.compensation.manage': true });
  assert.equal(allowed.salary_expectation, 'sensitive');
  const deniedBeforeAccess = candidateCreatePayload(form, 'unknown', {});
  assert.equal(Object.hasOwn(deniedBeforeAccess, 'salary_expectation'), false);
});

test('Offer create never copies salary expectation into compensation without write grant', () => {
  const process = { id: 13, candidate: { role: 'QA', salary_expectation: 'private' } };
  const noSalary = offerCreatePayload(process, 'local', { 'recruitment.offers.manage': true });
  assert.equal(Object.hasOwn(noSalary, 'compensation'), false);
  const withSalary = offerCreatePayload(process, 'local', { 'recruitment.compensation.manage': true });
  assert.equal(withSalary.compensation, 'private');
});

test('Recruitment browser bootstraps via service-owned effective grants and hides sensitive fields', () => {
  const main = readFileSync(new URL('../src/main.js', import.meta.url), 'utf8');
  const view = readFileSync(new URL('../src/AppMvp.vue', import.meta.url), 'utf8');
  assert.match(main, /api\/recruitment\/permissions\/me/);
  assert.doesNotMatch(main, /api\/employees\/access\/me/);
  assert.match(view, /can\('recruitment\.compensation\.view'\)/);
  assert.match(view, /can\('recruitment\.compensation\.manage'\)/);
});
