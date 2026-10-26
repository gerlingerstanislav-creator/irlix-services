import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { employeeManagementActions } from '../src/employeeManagementActions.js';

const allPermissions = {
  'employees.hire': true, 'employees.transfer': true, 'employees.manage': true,
  'employees.salary.read': true, 'employees.salary.manage': true, 'access.manage': true,
};
const available = (employee, permissions = allPermissions, state) =>
  employeeManagementActions(employee, permissions, state).map(action => action.id);

test('employed person gets all authorized card-level actions regardless of active tab', () => {
  const actions = available({ login: 'employee', employment_status: 'Трудоустроен', keycloak_user_id: 'synthetic' });
  assert.deepEqual(actions, ['transfer', 'cooperation', 'salary', 'onboarding', 'dismiss', 'delete']);
});

test('pending and dismissed employees receive only lifecycle actions appropriate to status', () => {
  assert.deepEqual(available({ login: 'employee', employment_status: 'Ожидает трудоустройства' }), ['hire', 'salary', 'delete']);
  assert.deepEqual(available({ login: 'employee', employment_status: 'Уволен' }), ['rehire', 'salary', 'delete']);
});

test('permissions and protected admin preserve prior restrictions', () => {
  assert.deepEqual(available({ login: 'admin', employment_status: 'Трудоустроен', keycloak_user_id: 'synthetic' }), ['cooperation', 'salary', 'onboarding']);
  assert.deepEqual(available({ login: 'staff', employment_status: 'Трудоустроен', keycloak_user_id: 'synthetic' }, {}), []);
  assert.deepEqual(available({ login: 'staff', employment_status: 'Трудоустроен' }, { 'employees.transfer': true }), ['transfer']);
  assert.deepEqual(available({ login: 'staff', employment_status: 'Трудоустроен' }, { 'employees.salary.manage': true }), []);
  assert.deepEqual(available(null), []);
});

test('destructive actions are marked and in-progress requests cannot be repeated', () => {
  const actions = employeeManagementActions({ login: 'staff', employment_status: 'Трудоустроен', keycloak_user_id: 'synthetic' }, allPermissions, { deleting: true, resendingOnboarding: true });
  assert.equal(actions.find(a => a.id === 'delete')?.danger, true);
  assert.equal(actions.find(a => a.id === 'dismiss')?.danger, true);
  assert.equal(actions.find(a => a.id === 'delete')?.disabled, true);
  assert.equal(actions.find(a => a.id === 'onboarding')?.disabled, true);
});

test('card management actions live only in header menu; forms remain available', () => {
  const component = readFileSync(new URL('../src/components/EmployeeCardDrawer.vue', import.meta.url), 'utf8');
  assert.match(component, /class="employee-card-top-actions"><UiActionMenu v-if="managementActions.length"/);
  assert.match(component, /@select="selectManagementAction"/);
  assert.match(component, /activeTab.value = 'info';\s+lifecycleMode.value = id/);
  assert.match(component, /openActionModal\(id\)/);
  assert.match(component, /return resendOnboarding\(\)/);
  assert.match(component, /return deleteEmployee\(\)/);
  assert.doesNotMatch(component, /class="lifecycle-actions"/);
  assert.doesNotMatch(component, /class="employee-card-actions"/);
  assert.match(component, /lifecycleMode==='hire'/);
  assert.match(component, /lifecycleMode==='rehire'/);
  assert.match(component, /actionModal === 'salary'/);
  assert.match(component, /aria-label="Закрыть карточку"/);
});
