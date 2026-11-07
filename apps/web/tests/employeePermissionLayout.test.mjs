import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  moveRoleColumn, orderedRoleColumns, permissionDisplayValue, permissionEnabled, permissionOptions, permissionSections, shiftRoleColumn,
} from '../src/employeePermissionLayout.js';

test('Employees matrix reflects backend boolean defaults and respects explicit overrides', () => {
  assert.equal(permissionEnabled('platform-admin', 'employees.read'), true);
  assert.equal(permissionEnabled('personnel-officer', 'employees.hire'), true);
  assert.equal(permissionEnabled('hr', 'employees.salary.read'), false);
  assert.equal(permissionEnabled('sales-head', 'employees.fot.plan'), true);
  assert.equal(permissionEnabled('managers', 'employees.fot.plan'), true);
  assert.equal(permissionEnabled('accounting', 'employees.fot.view'), true);
  assert.equal(permissionEnabled('platform-tester', 'employees.manage'), false);
  assert.equal(permissionEnabled('personnel-officer', 'employees.hire', [
    { role: 'personnel-officer', permission: 'employees.hire', enabled: false },
  ]), false);
  assert.equal(permissionEnabled('hr', 'employees.salary.read', [
    { role: 'hr', permission: 'employees.salary.read', enabled: true },
  ]), true);
});

test('Role scope selectors reflect authoritative backend scopes and saved overrides', () => {
  const policies = {
    managers: {
      'employees.read': { options: ['deny', 'own', 'all'], default: 'own' },
      'employees.salary.read': { options: ['deny', 'own', 'all'], default: 'own' },
    },
    'direction-hr': {
      'employees.read': { options: ['deny', 'own', 'all'], default: 'own' },
    },
    'personnel-officer': {
      'employees.read': { options: ['deny', 'all'], default: 'all' },
    },
    accounting: {
      'employees.salary.read': { options: ['deny', 'all'], default: 'all' },
    },
  };
  assert.equal(permissionDisplayValue('managers', 'employees.salary.read', [], policies), 'own');
  assert.deepEqual(permissionOptions('managers', 'employees.salary.read', policies).map(o => o.value), ['deny', 'own', 'all']);
  assert.equal(permissionDisplayValue('managers', 'employees.salary.read', [
    { role: 'managers', permission: 'employees.salary.read', enabled: true, scope: 'all' },
  ], policies), 'all');
  assert.equal(permissionDisplayValue('managers', 'employees.salary.read', [
    { role: 'managers', permission: 'employees.salary.read', enabled: false },
  ], policies), 'deny');
  assert.equal(permissionDisplayValue('direction-hr', 'employees.read', [], policies), 'own');
  assert.equal(permissionDisplayValue('personnel-officer', 'employees.read', [], policies), 'all');
  assert.deepEqual(permissionOptions('personnel-officer', 'employees.read', policies).map(o => o.value), ['deny', 'all']);
  assert.equal(permissionDisplayValue('accounting', 'employees.salary.read', [], policies), 'all');
  assert.deepEqual(permissionOptions('managers', 'services.employees', policies).map(o => o.value), ['deny', 'allow']);
  assert.equal(permissionDisplayValue('managers', 'employees.fot.approve', [], policies), 'allow');
  assert.equal(permissionDisplayValue('hr', 'employees.salary.read', [], policies), 'deny');
  const hardGate = { managers: { 'organization.manage': { options: ['deny'], default: 'deny' } } };
  assert.deepEqual(permissionOptions('managers', 'organization.manage', hardGate).map(o => o.value), ['deny']);
  assert.equal(permissionDisplayValue('managers', 'organization.manage', [
    { role: 'managers', permission: 'organization.manage', enabled: true },
  ], hardGate), 'deny');
});

test('Permission sections include every permission once and keep stable group order', () => {
  const grouped = permissionSections({
    'services.clients': 'Вход: Клиенты',
    'staff_positions.read': 'Просмотр должностей',
    'employees.read': 'Просмотр сотрудников',
    'audit.read': 'Аудит',
    'unknown.key': 'Другое',
    'organization.manage': 'Управление оргструктурой',
  });
  assert.deepEqual(grouped.map(group => group.key), [
    'employees', 'organization', 'administration', 'services', 'other',
  ]);
  const keys = grouped.flatMap(group => group.permissions.map(permission => permission.key));
  assert.equal(keys.length, 6);
  assert.equal(new Set(keys).size, 6);
});

test('Hiding a column is only a display change; admin cannot be hidden', () => {
  const catalog = {
    'platform-admin': 'Администратор платформы',
    hr: 'HR',
    accounting: 'Бухгалтерия',
  };
  assert.deepEqual(orderedRoleColumns(catalog).map(role => role.key), ['platform-admin', 'hr', 'accounting']);
  assert.deepEqual(orderedRoleColumns(catalog, ['accounting']).map(role => role.key), ['accounting', 'platform-admin']);
  assert.deepEqual(orderedRoleColumns({ 'platform-admin': 'Admin', managers: 'Руководители', hr: 'HR' }, ['hr']).map(role => role.key), ['hr', 'platform-admin', 'managers']);
  assert.deepEqual(orderedRoleColumns({ 'platform-admin': 'Admin', managers: 'Managers', 'direction-hr': 'HR направлений' }, ['managers']).map(role => role.key), ['managers', 'platform-admin', 'direction-hr']);
  assert.deepEqual(orderedRoleColumns(catalog, ['deleted-role', 'hr', 'hr']).map(role => role.key), ['hr', 'platform-admin']);
});

test('Mouse drag and keyboard arrows only reorder the role objects', () => {
  const admin = { key: 'platform-admin' };
  const hr = { key: 'hr' };
  const finance = { key: 'accounting' };
  const roles = [admin, hr, finance];
  assert.deepEqual(moveRoleColumn(roles, 'accounting', 'platform-admin'), [finance, admin, hr]);
  assert.deepEqual(shiftRoleColumn(roles, 'hr', 1), [admin, finance, hr]);
  assert.strictEqual(moveRoleColumn(roles, 'missing', 'hr'), roles);
  assert.strictEqual(shiftRoleColumn(roles, 'hr', -2), roles);
  assert.deepEqual(roles, [admin, hr, finance]);
});

test('Employees UI keeps permission management and presentation distinct', () => {
  const view = readFileSync(new URL('../src/components/EmployeePermissionsView.vue', import.meta.url), 'utf8');
  assert.match(view, /UiSearchSelect/);
  assert.match(view, /permissionEnabled/);
  assert.match(view, /permissionDisplayValue/);
  assert.match(view, /permissionOptions/);
  assert.match(view, /Только своего направления|option in permissionOptions/);
  assert.match(view, /:disabled="role.locked \|\| !!saving"/);
  assert.match(view, /Область каждого действия задаётся отдельно/);
  assert.match(view, /matrix.scope_policies/);
  assert.match(view, /@drop="dropColumn/);
});

test('Sidebar and Fot filters keep the three requested navigation groups and placeholder-only filters', () => {
  const sidebar = readFileSync(new URL('../src/components/AppSidebar.vue', import.meta.url), 'utf8');
  const fot = readFileSync(new URL('../src/components/EmployeeFotView.vue', import.meta.url), 'utf8');
  assert.ok(sidebar.indexOf("id: 'employees'") < sidebar.indexOf("id: 'fot'"));
  assert.ok(sidebar.indexOf("id: 'fot'") < sidebar.indexOf("id: 'positions'"));
  assert.ok(sidebar.indexOf("id: 'positions'") < sidebar.indexOf("id: 'staffPositions'"));
  assert.ok(sidebar.indexOf("id: 'staffPositions'") < sidebar.indexOf("id: 'systemAccounts'"));
  assert.match(sidebar, /groupStart: index > 0 && itemIndex === 0/);
  assert.match(sidebar, /:bottom-items="bottomItems"/);
  assert.match(sidebar, /const bottomItems = computed/);
  assert.match(sidebar, /id: 'permissions'/);
  assert.match(sidebar, /id: 'audit'/);
  assert.doesNotMatch(fot, /<span>Направления<\/span>|<span>Статус трудоустройства<\/span>/);
  assert.match(fot, /placeholder="Все направления"/);
  assert.match(fot, /placeholder="Все статусы"/);
});
