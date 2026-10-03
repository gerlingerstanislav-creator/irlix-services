import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildStaffTree, departmentOptions, positionsForDepartment, departmentPositions } from '../src/staffTree.js';
const departments = [{ id: 1, name: 'Компания', parent_id: null }, { id: 2, name: 'Отдел', parent_id: 1 }, { id: 3, name: 'Группа', parent_id: 2 }, { id: 4, name: 'Пустой отдел', parent_id: 1 }];
const positions = [{ id: 10, name: 'Должность группы', direction_id: 3 }, { id: 11, name: 'Должность отдела', direction_id: 2 }];
test('default tree shows only departments', () => {
  assert.deepEqual(buildStaffTree(departments, positions).map(r => [r.item.id, r.depth]), [[1,0],[2,1],[3,2],[4,1]]);
});
test('collapse hides a subtree while preserving other departments', () => {
  assert.deepEqual(buildStaffTree(departments, positions, new Set(['2'])).map(r => r.item.id), [1,2,4]);
});
test('all departments can be selected including non-production ones', () => {
  assert.equal(departmentOptions(departments).length, 4);
  assert.equal(departmentOptions(departments)[2].depth, 2);
});
test('position choices require a department and exclude children, other departments and closed positions', () => {
  const list = [...positions, { id: 12, direction_id: 2, closed_at: '2026-10-03' }];
  assert.deepEqual(positionsForDepartment(list, ''), []);
  assert.deepEqual(positionsForDepartment(list, '2').map(p => p.id), [11]);
  assert.deepEqual(positionsForDepartment(list, 3).map(p => p.id), [10]);
});
test('broken hierarchy does not hide legacy records or recurse forever', () => {
  const rows = buildStaffTree([{id:1,name:'A',parent_id:2},{id:2,name:'B',parent_id:1}], [{id:3,name:'Legacy',direction_id:null}]);
  assert.equal(rows.filter(r => r.kind === 'position').length, 0);
  assert.equal(rows.length, 2);
});

test('card catalog contains own positions including closed ones, never child positions', () => {
  const list = [...positions, { id: 12, name: 'Закрытая', direction_id: 2, closed_at: '2026-10-03' }];
  assert.deepEqual(departmentPositions(list, 2).map(p => p.id), [11,12]);
  assert.deepEqual(departmentPositions(list, 1), []);
  assert.deepEqual(departmentPositions(list, null), []);
});
