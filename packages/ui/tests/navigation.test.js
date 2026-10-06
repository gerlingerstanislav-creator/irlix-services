import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { serviceGroups, getVisibleServiceGroups, isPlatformAdminAccess } from '../src/serviceCatalog.js';

test('catalog keeps contour order, unique services and safe admin visibility', () => {
  assert.deepEqual(serviceGroups.map(group => group.key), ['employees', 'clients', 'it', 'recruitment', 'system']);
  const keys = serviceGroups.flatMap(group => group.items.map(item => item.key));
  assert.equal(new Set(keys).size, keys.length);
  assert.equal(keys.length, 12);
  assert.equal(getVisibleServiceGroups(true).flatMap(group => group.items).length, 12);
  assert.deepEqual(getVisibleServiceGroups(false).flatMap(group => group.items).filter(item => item.platformAdminOnly), []);
  assert.equal(serviceGroups.find(group => group.key === 'recruitment').items.find(item => item.key === 'cv-converter').platformAdminOnly, true);
  assert.equal(getVisibleServiceGroups(false).flatMap(group => group.items).find(item => item.key === 'assessments').available, false);
  assert.equal(isPlatformAdminAccess({ roles: [' PLATFORM_ADMIN '] }), true);
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
