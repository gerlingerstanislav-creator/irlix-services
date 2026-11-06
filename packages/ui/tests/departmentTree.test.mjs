import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { departmentTreeOptions, normalizeDepartmentSelection, employeesWithinDepartments } from '../src/departmentTree.js';

const departments = [
  { id: 6, name: 'QA', parent_id: 2 },
  { id: 5, name: 'Frontend', parent_id: 2 },
  { id: 1, name: 'Company', parent_id: null },
  { id: 2, name: 'Development', parent_id: 1 },
  { id: 3, name: 'Clients', parent_id: 1 },
  { id: 8, name: 'Backend', parent_id: 2 },
];
const people = [
  { id: 11, department_id: 8 },
  { id: 12, department_id: 5 },
  { id: 13, department_id: 6 },
  { id: 14, department_id: 3 },
];
test('organization tree sorts siblings and nests all branches as selectable entries', () => {
  assert.deepEqual(departmentTreeOptions(departments).map(o => [o.value,o.depth]), [
    ['1',0],['3',1],['2',1],['8',2],['5',2],['6',2],
  ]);
  assert.deepEqual(departmentTreeOptions(departments).map(o => o.kind), Array(6).fill('option'));
});
test('selection is persisted as IDs and expands nested descendants without siblings', () => {
  assert.deepEqual(normalizeDepartmentSelection(departments, ['2','2','unknown','8']), ['2','8']);
  assert.deepEqual(employeesWithinDepartments(people, departments, ['2']).map(x => x.id), [11,12,13]);
  assert.deepEqual(employeesWithinDepartments(people, departments, ['8','3']).map(x => x.id), [11,14]);
  assert.deepEqual(employeesWithinDepartments(people, departments, []), people);
});
test('malformed cyclic org hierarchy cannot spin forever or hide people', () => {
  const cycle = [{ id: 1, name: 'A', parent_id: 2 },{ id: 2, name: 'B', parent_id: 1 }];
  assert.equal(departmentTreeOptions(cycle).length, 2);
});
test('shared UI includes searchable trees and a consistent green superscript grade', () => {
  const root = new URL('../src/components/', import.meta.url);
  const selector = readFileSync(new URL('UiDepartmentTreeSelect.vue', root), 'utf8');
  const base = readFileSync(new URL('UiSearchSelect.vue', root), 'utf8');
  const grade = readFileSync(new URL('UiTechnologyGrade.vue', root), 'utf8');
  assert.match(selector, /departmentTreeOptions/);
  assert.match(selector, /multiple teleport/);
  assert.match(base, /parentValue/);
  assert.match(base, /createCandidate/);
  assert.match(grade, /<sup v-if="grade"/);
  assert.match(grade, /--irlix-status-success-text/);
});

test('compact selector typography applies to teleported popup and drawer pickers', () => {
  const fs = readFileSync;
  const root = new URL('../src/components/', import.meta.url);
  const select = fs(new URL('UiSearchSelect.vue', root), 'utf8');
  const tree = fs(new URL('UiDepartmentTreeSelect.vue', root), 'utf8');
  assert.match(select, /compact: \{ type: Boolean, default: false \}/);
  assert.match(select, /'ui-search-select__menu--compact': compact/);
  assert.match(select, /\.ui-search-select__menu--compact/);
  assert.match(select, /--irlix-font-size-table/);
  assert.match(tree, /multiple teleport compact/);
});
