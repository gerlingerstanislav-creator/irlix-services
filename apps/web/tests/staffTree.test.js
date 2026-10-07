import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildStaffTree, departmentOptions, positionsForDepartment, departmentPositions, employeeTreeOptions } from '../src/staffTree.js';
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


test('employee selectors show department ancestry with selectable people and unlinked fallback', () => {
  const result = employeeTreeOptions(departments, [{id:1,full_name:'Synthetic child',department_id:3},{id:2,full_name:'Synthetic orphan',department_id:null}]);
  assert.deepEqual(result.map(r => [r.value,r.depth]), [['department:1',0],['department:2',1],['department:3',2],['1',3],['department:unlinked',0],['2',1]]);
  assert.equal(result.filter(r => r.kind === 'group').length,4);
  assert.deepEqual(employeeTreeOptions(departments, []), []);
});


test('employee registry position filter is multi-select and action forms are modal', () => {
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  const card = readFileSync(new URL('../src/components/EmployeeCardDrawer.vue', import.meta.url), 'utf8');
  assert.match(app, /const positionFilter = ref\(\[\]\)/);
  assert.match(app, /#filter-position[\s\S]*multiple/);
  assert.match(app, /positionFilter\.value\.some/);
  assert.match(card, /openActionModal\('dismiss'\)/);
  assert.match(card, /openActionModal\('cooperation'\)/);
  assert.match(card, /openActionModal\('salary'\)/);
  assert.match(card, /Пересмотр зарплаты/);
  assert.match(card, /salaryForm\.position_id/);
});


test('employees exposes separate positions page with tree management actions', () => {
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  const sidebar = readFileSync(new URL('../src/components/AppSidebar.vue', import.meta.url), 'utf8');
  const catalog = readFileSync(new URL('../src/components/PositionsCatalogView.vue', import.meta.url), 'utf8');
  assert.match(app, /staffPositions: '\/employees\/positions'/);
  assert.match(app, /currentSection === 'staffPositions'/);
  assert.match(sidebar, /label: 'Должности'/);
  assert.match(catalog, /UiTreeToggle/);
  assert.match(catalog, /\+ Должность/);
  assert.match(catalog, /Редактировать/);
  assert.match(catalog, /Удалить/);
  assert.match(catalog, /OrganizationEntityDrawer/);
});


test('positions import action lives on positions catalog page', () => {
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  const catalog = readFileSync(new URL('../src/components/PositionsCatalogView.vue', import.meta.url), 'utf8');
  assert.doesNotMatch(app, /currentSection === 'positions'[\s\S]{0,220}Импорт должностей/);
  assert.match(app, /currentSection === 'staffPositions'[\s\S]{0,220}Импорт должностей/);
  assert.match(app, /openImportCatalogPositions/);
  assert.doesNotMatch(app, /openImportStaffPositions/);
  assert.match(catalog, /defineExpose\(\{ openCreate, openImport \}\)/);
  assert.match(catalog, /\/api\/employees\/staff-positions\/import/);
});


test('positions catalog uses compact row density', () => {
  const catalog = readFileSync(new URL('../src/components/PositionsCatalogView.vue', import.meta.url), 'utf8');
  assert.match(catalog, /positions-catalog-table td \{ min-height: 0; height: 29px; padding: 2px 8px;/);
  assert.match(catalog, /ui-button--compact\) \{ height: 23px; min-height: 23px;/);
  assert.match(catalog, /ui-badge\) \{ min-height: 18px;/);
});
