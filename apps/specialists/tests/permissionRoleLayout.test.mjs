import test from 'node:test';
import assert from 'node:assert/strict';
import { roleTreeOptions, orderedRoles, moveRoleTo, shiftRole } from '../src/permissionRoleLayout.js';

const tree = [
  { kind: 'group', key: 'organization', label: 'Компания', depth: 0 },
  { kind: 'group', key: 'engineering', label: 'Разработка', depth: 1 },
  { kind: 'role', key: 'org:backend', depth: 2 },
  { kind: 'role', key: 'head:backend', depth: 2 },
];
const catalog = [
  { key: 'org:backend', label: 'Подразделение: Backend' },
  { key: 'head:backend', label: 'Руководитель: Backend' },
  { key: 'special:platform-admin', label: 'Администратор платформы', locked: true },
  { key: 'special:auditor', label: 'Аудитор' },
];

test('tree selector contains available roles but never configured or locked roles', () => {
  const result = roleTreeOptions(tree, catalog, [{ key: 'org:backend' }]);
  assert.deepEqual(result.map(entry => entry.value), [
    'organization', 'engineering', 'head:backend', 'group:other-unmapped', 'special:auditor',
  ]);
  assert.equal(result.at(-1).depth, 1);
});

test('default columns follow organization tree; custom order is only visual', () => {
  const roles = [catalog[3], catalog[1], catalog[0]];
  assert.deepEqual(orderedRoles(roles, tree).map(role => role.key), [
    'org:backend', 'head:backend', 'special:auditor',
  ]);
  assert.deepEqual(orderedRoles(roles, tree, ['special:auditor']).map(role => role.key), [
    'special:auditor', 'org:backend', 'head:backend',
  ]);
});

test('drag and arrow reordering never change the underlying role objects', () => {
  const roles = [catalog[0], catalog[1], catalog[3]];
  const moved = moveRoleTo(roles, 'special:auditor', 'org:backend');
  assert.deepEqual(moved.map(role => role.key), [
    'special:auditor', 'org:backend', 'head:backend',
  ]);
  assert.deepEqual(shiftRole(moved, 'org:backend', 1).map(role => role.key), [
    'special:auditor', 'head:backend', 'org:backend',
  ]);
  assert.deepEqual(roles.map(role => role.key), [
    'org:backend', 'head:backend', 'special:auditor',
  ]);
});
