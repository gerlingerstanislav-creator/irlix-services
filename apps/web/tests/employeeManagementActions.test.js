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

test('only platform admin can create Keycloak access for an employed unprovisioned identity', () => {
  const imported = { login: 'synthetic.imported', employment_status: 'Трудоустроен', identity_status: 'not_provisioned', keycloak_user_id: null };
  const flags = { platformAdmin: true };
  assert.ok(available(imported, allPermissions, flags).includes('provision-keycloak'));
  assert.ok(!available(imported, allPermissions).includes('provision-keycloak'));
  assert.ok(!available(imported, { 'employees.manage': true }, flags).includes('provision-keycloak'));
  assert.ok(!available({ ...imported, identity_status: 'provisioning' }, allPermissions, flags).includes('provision-keycloak'));
  assert.ok(!available({ ...imported, keycloak_user_id: 'existing' }, allPermissions, flags).includes('provision-keycloak'));
  assert.ok(!available({ ...imported, employment_status: 'Уволен' }, allPermissions, flags).includes('provision-keycloak'));
  assert.ok(!available({ ...imported, login: 'admin' }, allPermissions, flags).includes('provision-keycloak'));
  const busy = employeeManagementActions(imported, allPermissions, { platformAdmin: true, provisioningKeycloak: true });
  assert.equal(busy.find(action => action.id === 'provision-keycloak')?.disabled, true);
});

test('destructive actions are marked and in-progress requests cannot be repeated', () => {
  const actions = employeeManagementActions({ login: 'staff', employment_status: 'Трудоустроен', keycloak_user_id: 'synthetic' }, allPermissions, { deleting: true, resendingOnboarding: true });
  assert.equal(actions.find(a => a.id === 'delete')?.danger, true);
  assert.equal(actions.find(a => a.id === 'dismiss')?.danger, true);
  assert.equal(actions.find(a => a.id === 'delete')?.disabled, true);
  assert.equal(actions.find(a => a.id === 'onboarding')?.disabled, true);
});

test('card management menu remains available without hiding onboarding and forms', () => {
  const component = readFileSync(new URL('../src/components/EmployeeCardDrawer.vue', import.meta.url), 'utf8');
  assert.match(component, /class="employee-card-top-actions"><UiActionMenu v-if="managementActions.length"/);
  assert.match(component, /@select="selectManagementAction"/);
  assert.match(component, /activeTab.value = 'info';\s+lifecycleMode.value = id/);
  assert.match(component, /openActionModal\(id\)/);
  assert.match(component, /return resendOnboarding\(\)/);
  assert.match(component, /return provisionKeycloak\(\)/);
  assert.match(component, /return deleteEmployee\(\)/);
  assert.doesNotMatch(component, /class="lifecycle-actions"/);
  assert.doesNotMatch(component, /class="employee-card-actions"/);
  assert.match(component, /lifecycleMode==='hire'/);
  assert.match(component, /lifecycleMode==='rehire'/);
  assert.match(component, /actionModal === 'salary'/);
  assert.match(component, /aria-label="Закрыть карточку"/);
});

test('employee card preserves visible onboarding and no unsolicited rights tab', () => {
  const component = readFileSync(new URL('../src/components/EmployeeCardDrawer.vue', import.meta.url), 'utf8');
  const routes = readFileSync(new URL('../../../services/employees/routes/onboarding.php', import.meta.url), 'utf8');
  const permissions = readFileSync(new URL('../src/components/EmployeePermissionsView.vue', import.meta.url), 'utf8');

  // Manual onboarding is a confirmed business workflow, not a hidden menu-only action.
  assert.match(component, /class="employee-identity-actions"/);
  assert.match(component, /canManageAccess && canManage && employee\.keycloak_user_id/);
  assert.match(component, /@click="resendOnboarding"/);
  assert.match(component, /Отправить письмо повторно/);
  assert.match(component, /Отправить письмо/);
  assert.match(component, /@click="provisionKeycloak"/);
  assert.match(component, /employee\.identity_status === 'not_provisioned'/);
  assert.match(routes, /\/employees\/\{employee\}\/onboarding-email/);
  assert.match(routes, /\/employees\/\{employee\}\/keycloak-provision/);
  assert.match(permissions, /<template>/);

  // Role permissions are a separate Employees page, not a tab added to the HR card.
  assert.doesNotMatch(component, /activeTab\s*===?\s*['"]rights['"]/);
  assert.doesNotMatch(component, />Права<\/button>/);
  assert.doesNotMatch(component, /\/api\/employees\/access\/permissions\/effective\//);
  assert.match(component, /activeTab==='info'/);
  assert.match(component, /activeTab === 'employment'/);
  assert.match(component, /activeTab === 'salary'/);
});

test('production navigation smoke uses menuitem and explicit close button after header actions moved', () => {
  const smoke = readFileSync(new URL('../../../scripts/ci/check_employees_navigation.cjs', import.meta.url), 'utf8');
  assert.match(smoke, /getByRole\('button',\{name:'Действия с сотрудником',exact:true\}\)\.click\(\)/);
  assert.match(smoke, /getByRole\('menuitem',\{name:'Перевести в другое направление',exact:true\}\)\.click\(\)/);
  assert.match(smoke, /getByRole\('button',\{name:'Закрыть карточку',exact:true\}\)\.click\(\)/);
  assert.doesNotMatch(smoke, /employeeCard\.locator\('\.employee-card-top button'\)\.click\(\)/);
});
