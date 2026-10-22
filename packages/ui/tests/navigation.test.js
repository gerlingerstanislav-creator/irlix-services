import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { SERVICE_STATUSES, serviceGroups, getVisibleServiceGroups, isPlatformAdminAccess, isPlatformAdministratorAccess } from '../src/serviceCatalog.js';

test('catalog keeps contour order, unique services and safe admin visibility', () => {
  assert.deepEqual(serviceGroups.map(group => group.key), ['employees', 'clients', 'it', 'finance', 'recruitment', 'system']);
  const keys = serviceGroups.flatMap(group => group.items.map(item => item.key));
  assert.equal(new Set(keys).size, keys.length);
  assert.equal(keys.length, 14);
  assert.equal(getVisibleServiceGroups(true).flatMap(group => group.items).length, 2);
  assert.equal(getVisibleServiceGroups(true, {services:Object.fromEntries(keys.map(key=>[key,true]))}).flatMap(group=>group.items).length,13);
  assert.deepEqual(getVisibleServiceGroups(false).flatMap(group => group.items).filter(item => item.platformAdminOnly), []);
  assert.equal(serviceGroups.find(group => group.key === 'recruitment').items.find(item => item.key === 'cv-converter').platformAdminOnly, true);
  assert.equal(serviceGroups.flatMap(group => group.items).find(item => item.key === 'assessments').available, false);
  const services = serviceGroups.flatMap(group => group.items);
  const validStatuses = new Set(Object.values(SERVICE_STATUSES));
  assert.ok(services.every(service => validStatuses.has(service.status)));
  // validate lifecycle status scale sentinel
  assert.deepEqual([...validStatuses].sort(), ['In development', 'Planned', 'Preproduction', 'Production'].sort());
  assert.equal(services.find(service => service.key === 'employees').status, 'Preproduction');
  assert.equal(services.find(service => service.key === 'vacations').status, 'Preproduction');
  assert.equal(services.find(service => service.key === 'timesheets').status, 'Preproduction');
  assert.equal(services.find(service => service.key === 'assessments').status, 'Planned');
  assert.equal(services.find(service => service.key === 'specialists').status, 'In development');
  assert.equal(services.find(service => service.key === 'recruitment').status, 'In development');
  assert.equal(services.find(service => service.key === 'cv-converter').status, 'In development');
  assert.equal(isPlatformAdminAccess({ roles: [' PLATFORM_ADMIN '] }), true);
  assert.equal(isPlatformAdminAccess({ roles: [' PLATFORM_TESTER '] }), false);
  assert.equal(isPlatformAdminAccess({ roles: ['hr'] }), false);
});

test('every business auth adapter exposes authenticated fetch used by the sidebar', async () => {
  const originalWindow = globalThis.window;
  globalThis.window = { location: { origin: 'https://example.test' }, fetch: async () => { throw new Error('Native fetch must not be used'); } };
  const stub = 'data:text/javascript,' + encodeURIComponent('export function createBrowserAuth(){return {fetch:async(input,init)=>({input,init}),logout(){}}}');
  try {
    for (const app of ['web', 'vacations', 'clients', 'timesheets', 'specialists', 'recruitment', 'equipment', 'cv', 'finance']) {
      const source = readFileSync(new URL(`../../../apps/${app}/src/auth.js`, import.meta.url), 'utf8').replace("'@irlix/auth'", JSON.stringify(stub));
      const { auth } = await import('data:text/javascript,' + encodeURIComponent(source));
      assert.equal(typeof auth.fetch, 'function', app);
      const init = { cache: 'no-store' };
      assert.deepEqual(await auth.fetch('/api/employees/access/me', init), { input: '/api/employees/access/me', init }, app);
    }
  } finally { globalThis.window = originalWindow; }
});

test('private navigation follows server entitlement map, with an explicit public catalog exception', () => {
  const keys = access => getVisibleServiceGroups(true, access).flatMap(group => group.items.map(item => item.key));
  assert.deepEqual(keys({roles:['platform-admin']}), ['dashboard','design-system']);
  assert.deepEqual(keys({roles:['platform-tester','account-manager']}), ['dashboard','design-system']);
  const services = { dashboard:true, clients:true, timesheets:true, equipment:false, migration:false };
  assert.deepEqual(keys({roles:['account-manager'], services}), ['dashboard','clients','timesheets','design-system']);
  assert.deepEqual(keys({roles:['platform-admin'], services:{...services, migration:true}}), ['dashboard','clients','timesheets','design-system']);
  assert.deepEqual(keys({roles:['platform-tester'], services:{dashboard:true,clients:false,migration:false,timesheets:true}}), ['dashboard','timesheets','design-system']);
});

test('strict administrator check rejects testers and missing access', () => {
  for (const access of [null, {}, { roles: ['platform-tester'] }, { roles: ['hr'] }]) {
    assert.equal(isPlatformAdministratorAccess(access), false);
  }
  for (const roles of [['platform-admin'], [' PLATFORM_ADMIN '], ['platform-tester', 'platform-admin']]) {
    assert.equal(isPlatformAdministratorAccess({ roles }), true);
  }
  assert.ok(!getVisibleServiceGroups(true, { roles: ['hr'] }).flatMap(group => group.items).some(item => item.key === 'migration'));
});

test('only the public catalog bypasses missing or denied entitlements', () => {
  const keys = access => getVisibleServiceGroups(false, access).flatMap(group => group.items.map(item => item.key));
  assert.deepEqual(keys(null), ['dashboard', 'design-system']);
  for (const services of [{}, { 'design-system': false }, { 'design-system': true }, { system: false, clients: false }]) {
    assert.deepEqual(keys({ services }), ['design-system']);
  }
  const publicServices = serviceGroups.flatMap(group => group.items).filter(service => service.public === true);
  assert.deepEqual(publicServices.map(service => service.key), ['design-system']);
  assert.equal(publicServices[0].platformAdminOnly, undefined);
});

test('public catalog mounts without auth, private service callbacks or account UI', () => {
  const read = path => readFileSync(new URL('../../../' + path, import.meta.url), 'utf8');
  const main = read('apps/design-system/src/main.js');
  const app = read('apps/design-system/src/App.vue');
  const shell = read('packages/ui/src/components/UiAppShell.vue');
  const sidebar = read('packages/ui/src/components/UiAppSidebar.vue');
  assert.match(main, /createApp\(App\)\.mount/);
  assert.doesNotMatch(main, /createBrowserAuth|auth\.init|auth\.fetch|window\.location/);
  assert.doesNotMatch(app, /platform-admin="true"|platform-access|defineProps|Демо пользователь|← Дашборд/);
  assert.match(app, /:account-enabled="false"/);
  assert.match(app, /:current-user="null"/);
  assert.match(app, /const publicServiceAccess = \{ services: \{\} \}/);
  for (const component of [shell, sidebar]) assert.match(component, /accountEnabled: \{ type: Boolean, default: true \}/);
  assert.match(shell, /:account-enabled="accountEnabled"/);
  assert.match(sidebar, /v-if="accountEnabled" ref="accountTrigger"/);
  assert.match(sidebar, /v-if="accountEnabled && showAccount" id="sidebar-account"/);
  assert.match(read('services/employees/app/Support/EmployeesAccess.php'), /'design-system' => true/);
  assert.match(read('services/employees/bootstrap/app.php'), /api\(append: \[KeycloakBearer::class/);
});
