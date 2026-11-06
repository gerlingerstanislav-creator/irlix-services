import test from 'node:test';
import assert from 'node:assert/strict';
import { fotOverviewQuery } from '../src/fotFilters.js';
import { departmentOptions } from '../src/staffTree.js';

test('FOT sends selected departments and statuses without losing multiselect values', () => {
  const params = fotOverviewQuery(2026, ['10', '20'], ['Уволен', 'Трудоустроен']);
  assert.equal(params.get('year'), '2026');
  assert.deepEqual(params.getAll('department_ids[]'), ['10', '20']);
  assert.deepEqual(params.getAll('employment_statuses[]'), ['Уволен', 'Трудоустроен']);
  assert.deepEqual([...fotOverviewQuery(2026).keys()], ['year']);
});
test('Department options preserve organizational nesting in the FOT multi-select', () => {
  const options = departmentOptions([
    { id: 1, parent_id: null, name: 'Компания' },
    { id: 2, parent_id: 1, name: 'Разработка' },
    { id: 3, parent_id: 2, name: 'Backend' },
  ]);
  assert.deepEqual(options.map(item => [item.value, item.depth]),
    [['1', 0], ['2', 1], ['3', 2]]);
});
