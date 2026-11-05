import test from 'node:test';
import assert from 'node:assert/strict';
import { moveRoleTo, orderedRoles, roleTreeOptions, shiftRole } from '../src/permissionRoleLayout.js';

const hierarchy = [
  { kind: 'group', key: 'department:1', label: 'Ирликс', depth: 0 },
  { kind: 'role', key: 'org:irlix', label: 'Подразделение: Ирликс', depth: 1 },
  { kind: 'role', key: 'head:irlix', label: 'Руководитель: Ирликс', depth: 1 },
  { kind: 'group', key: 'department:2', label: 'Клиентская служба', depth: 1 },
  { kind: 'role', key: 'org:client-service', label: 'Подразделение: Клиентская служба', depth: 2 },
  { kind: 'role', key: 'head:client-service', label: 'Руководитель: Клиентская служба', depth: 2 },
  { kind: 'group', key: 'department:3', label: 'Продажи', depth: 2 },
  { kind: 'role', key: 'org:sales', label: 'Подразделение: Продажи', depth: 3 },
  { kind: 'role', key: 'head:sales', label: 'Руководитель: Продажи', depth: 3 },
  { kind: 'group', key: 'department:4', label: 'Пустое подразделение', depth: 1 },
  { kind: 'group', key: 'other', label: 'Прочее', depth: 0 },
  { kind: 'role', key: 'special:system-admin', label: 'Системный администратор', depth: 1 },
  { kind: 'role', key: 'special:platform-admin', label: 'Администратор платформы', depth: 1 },
];
const available = hierarchy.filter(item => item.kind === 'role').map(item => ({
  key: item.key, label: item.label, locked: item.key === 'special:platform-admin',
}));

test('role selector preserves organizational ancestry and groups remaining special roles at bottom', () => {
  const selected = [{ key: 'org:irlix' }, { key: 'head:client-service' }];
  const options = roleTreeOptions(hierarchy, available, selected);
  assert.deepEqual(options.map(x => x.label), [
    'Ирликс',
    'Руководитель: Ирликс',
    'Клиентская служба',
    'Подразделение: Клиентская служба',
    'Продажи',
    'Подразделение: Продажи',
    'Руководитель: Продажи',
    'Прочее',
    'Системный администратор',
  ]);
  assert.deepEqual(options.map(x => x.depth), [0, 1, 1, 2, 2, 3, 3, 0, 1]);
  assert.equal(options.find(x => x.value === 'special:platform-admin'), undefined);
  assert.equal(options.find(x => x.label === 'Пустое подразделение'), undefined);
  assert.ok(options.filter(x => x.kind === 'group').every(x => x.value !== undefined));
});

test('unrecognized catalog roles stay selectable in Прочее without inventing organization hierarchy', () => {
  const options = roleTreeOptions([], [
    { key: 'special:system-admin', label: 'Системный администратор' },
  ], []);
  assert.deepEqual(options, [
    { kind: 'group', value: 'group:other-unmapped', label: 'Прочее', depth: 0 },
    { value: 'special:system-admin', label: 'Системный администратор', depth: 1 },
  ]);
});

test('columns follow organization preorder initially and keep personal changes across catalog reloads', () => {
  const columns = [
    { key: 'special:platform-admin' },
    { key: 'head:sales' },
    { key: 'org:irlix' },
    { key: 'special:system-admin' },
  ];
  assert.deepEqual(orderedRoles(columns, hierarchy).map(x => x.key), [
    'org:irlix', 'head:sales', 'special:system-admin', 'special:platform-admin',
  ]);
  assert.deepEqual(orderedRoles(columns, hierarchy, [
    'special:system-admin', 'head:sales', 'missing-role',
  ]).map(x => x.key), [
    'special:system-admin', 'head:sales', 'org:irlix', 'special:platform-admin',
  ]);
});

test('drag placement and keyboard arrows move only order, preserving role payloads', () => {
  const a = { key: 'org:irlix', label: 'Ирликс' };
  const b = { key: 'org:sales', label: 'Продажи' };
  const c = { key: 'special:platform-admin', label: 'Администратор платформы', locked: true };
  const roles = [a, b, c];
  assert.deepEqual(moveRoleTo(roles, 'special:platform-admin', 'org:irlix'), [c, a, b]);
  assert.deepEqual(shiftRole(roles, 'org:sales', 1), [a, c, b]);
  assert.strictEqual(shiftRole(roles, 'org:irlix', -1), roles);
  assert.strictEqual(moveRoleTo(roles, 'missing', 'org:irlix'), roles);
  assert.deepEqual(roles, [a, b, c]);
});
