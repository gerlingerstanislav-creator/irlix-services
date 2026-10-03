import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildStaffTree, departmentOptions } from '../src/staffTree.js';
const departments = [{ id: 1, name: 'Компания', parent_id: null }, { id: 2, name: 'Отдел', parent_id: 1 }, { id: 3, name: 'Группа', parent_id: 2 }, { id: 4, name: 'Пустой отдел', parent_id: 1 }];
const positions = [{ id: 10, name: 'Должность группы', direction_id: 3 }, { id: 11, name: 'Должность отдела', direction_id: 2 }];
test('positions are nested under their own departments with all ancestor levels', () => {
  const rows = buildStaffTree(departments, positions);
  assert.deepEqual(rows.map(r => [r.item.id, r.depth]), [[1,0],[2,1],[11,2],[3,2],[10,3],[4,1]]);
});
test('collapse hides a subtree while preserving the other department', () => {
  assert.deepEqual(buildStaffTree(departments, positions, new Set(['2'])).map(r => r.item.id), [1,2,4]);
});
test('all departments can be selected including non-production ones', () => {
  assert.equal(departmentOptions(departments).length, 4);
  assert.equal(departmentOptions(departments)[2].depth, 2);
});
test('broken hierarchy or legacy linkage does not silently hide rows or recurse forever', () => {
  const rows = buildStaffTree([{id:1,name:'A',parent_id:2},{id:2,name:'B',parent_id:1}], [{id:3,name:'Legacy',direction_id:null}]);
  assert.equal(rows.filter(r => r.kind === 'position').length, 1);
  assert.equal(rows.length, 4);
});
