import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DEFAULT_EMPLOYMENT_STATUSES, employmentStatusesFromQuery, matchesEmploymentStatus } from '../src/employeeStatusFilter.js';

test('employee registry defaults to employed and awaiting employment', () => {
  assert.deepEqual(employmentStatusesFromQuery(new URLSearchParams()), [...DEFAULT_EMPLOYMENT_STATUSES]);
  assert.deepEqual([...DEFAULT_EMPLOYMENT_STATUSES], ['Трудоустроен', 'Ожидает трудоустройства']);
});

test('employee registry accepts one or several statuses in deep links', () => {
  const single = new URLSearchParams({ employment_status: 'Уволен' });
  assert.deepEqual(employmentStatusesFromQuery(single), ['Уволен']);
  const multiple = new URLSearchParams();
  multiple.append('employment_status', 'Трудоустроен');
  multiple.append('employment_status', 'Уволен');
  assert.deepEqual(employmentStatusesFromQuery(multiple), ['Трудоустроен', 'Уволен']);
});

test('cleared statuses show everyone, including after route reset/reload', () => {
  assert.deepEqual(employmentStatusesFromQuery(new URLSearchParams('employment_status=')), []);
  assert.equal(matchesEmploymentStatus([], 'Уволен'), true);
  assert.equal(matchesEmploymentStatus(DEFAULT_EMPLOYMENT_STATUSES, 'Уволен'), false);
  assert.equal(matchesEmploymentStatus(DEFAULT_EMPLOYMENT_STATUSES, 'Трудоустроен'), true);
  assert.equal(matchesEmploymentStatus(DEFAULT_EMPLOYMENT_STATUSES, 'Ожидает трудоустройства'), true);
  assert.equal(matchesEmploymentStatus(['Уволен', 'Трудоустроен'], 'Уволен'), true);
});

test('employee registry wires status multi-select and explicit clear route', () => {
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  assert.match(app, /const statusFilter = ref\(\[\.\.\.DEFAULT_EMPLOYMENT_STATUSES\]\)/);
  assert.match(app, /#filter-status[^\n]*UiSearchSelect[^\n]*multiple/);
  assert.match(app, /statusFilter\.value = employmentStatusesFromQuery\(params\)/);
  assert.match(app, /matchesEmploymentStatus\(statusFilter\.value, employee\.employment_status\)/);
  assert.match(app, /router\.replace\(\{ name: 'employees', query: \{ employment_status: '' \} \}\)/);
});
