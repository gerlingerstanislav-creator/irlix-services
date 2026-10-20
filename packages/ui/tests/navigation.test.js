import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { SERVICE_STATUSES, serviceGroups, getVisibleServiceGroups, isPlatformAdminAccess, isPlatformAdministratorAccess } from '../src/serviceCatalog.js';

test('catalog keeps contour order, unique services and safe admin visibility', () => {
  assert.deepEqual(serviceGroups.map(group => group.key), ['employees', 'clients', 'it', 'recruitment', 'system']);
  const keys = serviceGroups.flatMap(group => group.items.map(item => item.key));
  assert.equal(new Set(keys).size, keys.length);
  assert.equal(keys.length, 13);
  assert.equal(getVisibleServiceGroups(true).flatMap(group => group.items).length, 1);
  assert.equal(getVisibleServiceGroups(true, {services:Object.fromEntries(keys.map(key=>[key,true]))}).flatMap(group=>group.items).length,12);
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
  assert.equal(isPlatformAdminAccess({ roles: [' PLATFORM_TESTER '] }), true);
  assert.equal(isPlatformAdminAccess({ roles: ['hr'] }), false);
});

test('every business auth adapter exposes authenticated fetch used by the sidebar', async () => {
  const originalWindow = globalThis.window;
  globalThis.window = { location: { origin: 'https://example.test' }, fetch: async () => { throw new Error('Native fetch must not be used'); } };
  const stub = 'data:text/javascript,' + encodeURIComponent('export function createBrowserAuth(){return {fetch:async(input,init)=>({input,init}),logout(){}}}');
  try {
    for (const app of ['web', 'vacations', 'clients', 'timesheets', 'specialists', 'recruitment', 'equipment', 'cv']) {
      const source = readFileSync(new URL(`../../../apps/${app}/src/auth.js`, import.meta.url), 'utf8').replace("'@irlix/auth'", JSON.stringify(stub));
      const { auth } = await import('data:text/javascript,' + encodeURIComponent(source));
      assert.equal(typeof auth.fetch, 'function', app);
      const init = { cache: 'no-store' };
      assert.deepEqual(await auth.fetch('/api/employees/access/me', init), { input: '/api/employees/access/me', init }, app);
    }
  } finally { globalThis.window = originalWindow; }
});

test('navigation follows server entitlement map, never roles alone', () => {
  const keys = access => getVisibleServiceGroups(true, access).flatMap(group => group.items.map(item => item.key));
  assert.deepEqual(keys({roles:['platform-admin']}), ['dashboard']);
  assert.deepEqual(keys({roles:['platform-tester','account-manager']}), ['dashboard']);
  const services = { dashboard:true, clients:true, timesheets:true, equipment:false, migration:false };
  assert.deepEqual(keys({roles:['account-manager'], services}), ['dashboard','clients','timesheets']);
  assert.deepEqual(keys({roles:['platform-admin'], services:{...services, migration:true}}), ['dashboard','clients','timesheets']);
  assert.deepEqual(keys({roles:['platform-tester'], services:{dashboard:true,clients:false,migration:false,timesheets:true}}), ['dashboard','timesheets']);
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
