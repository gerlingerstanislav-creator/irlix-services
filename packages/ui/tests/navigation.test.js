import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { SERVICE_STATUSES, serviceGroups, getVisibleServiceGroups, isPlatformAdminAccess, isPlatformAdministratorAccess } from '../src/serviceCatalog.js';

test('catalog keeps contour order, unique services and safe admin visibility', () => {
  assert.deepEqual(serviceGroups.map(group => group.key), ['employees', 'clients', 'it', 'finance', 'recruitment', 'system']);
  const keys = serviceGroups.flatMap(group => group.items.map(item => item.key));
  assert.equal(new Set(keys).size, keys.length);
  assert.equal(keys.length, 16);
  assert.equal(getVisibleServiceGroups(true).flatMap(group => group.items).length, 1);
  assert.equal(getVisibleServiceGroups(true, {services:Object.fromEntries(keys.map(key=>[key,true]))}).flatMap(group=>group.items).length,15);
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

test('private navigation follows server entitlement map, including Design System', () => {
  const keys = access => getVisibleServiceGroups(true, access).flatMap(group => group.items.map(item => item.key));
  assert.deepEqual(keys({roles:['platform-admin']}), ['dashboard']);
  assert.deepEqual(keys({roles:['platform-tester','account-manager']}), ['dashboard']);
  const services = { dashboard:true, clients:true, timesheets:true, equipment:false, migration:false, 'design-system':true };
  assert.deepEqual(keys({roles:['account-manager'], services}), ['dashboard','clients','timesheets','design-system']);
  assert.deepEqual(keys({roles:['platform-admin'], services:{...services, migration:true}}), ['dashboard','clients','timesheets','design-system']);
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

test('Design System is private and requires an affirmative Employees entitlement', () => {
  const keys = access => getVisibleServiceGroups(false, access).flatMap(group => group.items.map(item => item.key));
  assert.deepEqual(keys(null), ['dashboard']);
  assert.deepEqual(keys({services:{}}), []);
  assert.deepEqual(keys({services:{'design-system':false}}), []);
  assert.deepEqual(keys({services:{'design-system':true}}), ['design-system']);
  const publicServices = serviceGroups.flatMap(group => group.items).filter(service => service.public === true);
  assert.deepEqual(publicServices, []);
});

test('Design System requires Keycloak identity and uses authenticated Employees entitlements', () => {
  const read = path => readFileSync(new URL('../../../' + path, import.meta.url), 'utf8');
  const main = read('apps/design-system/src/main.js');
  const app = read('apps/design-system/src/App.vue');
  const shell = read('packages/ui/src/components/UiAppShell.vue');
  const sidebar = read('packages/ui/src/components/UiAppSidebar.vue');
  assert.match(main, /createBrowserAuth/);
  assert.match(main, /await auth\.init\(\)/);
  assert.match(main, /auth\.fetch\('\/api\/employees\/access\/me'/);
  assert.match(main, /access\?\.services\?\.\['design-system'\]/);
  assert.match(main, /createApp\(App, \{/);
  assert.match(main, /app\.use\(router\)/);
  assert.match(app, /:service-access="serviceAccess"/);
  assert.match(app, /:account-enabled="true"/);
  assert.match(app, /:current-user="currentUser"/);
  for (const component of [shell, sidebar]) assert.match(component, /accountEnabled: \{ type: Boolean, default: true \}/);
  assert.match(shell, /:account-enabled="accountEnabled"/);
  assert.match(sidebar, /v-if="accountEnabled" ref="accountTrigger"/);
  assert.match(sidebar, /v-if="accountEnabled && showAccount" id="sidebar-account"/);
  assert.match(read('services/employees/app/Support/EmployeesAccess.php'), /'design-system' => true/);
  assert.match(read('services/employees/bootstrap/app.php'), /api\(append: \[KeycloakBearer::class/);
});


test('Brandbook navigation requires explicit Employees service entitlement', () => {
  const keys = access => getVisibleServiceGroups(false, access).flatMap(group=>group.items.map(s=>s.key));
  assert.ok(!keys(null).includes('brandbook'));
  assert.ok(!keys({roles:['hr'],services:{dashboard:true}}).includes('brandbook'));
  assert.ok(!keys({roles:['irlix-head'],services:{dashboard:true}}).includes('brandbook'));
  assert.ok(!keys({roles:['top-management'],services:{dashboard:true,brandbook:false}}).includes('brandbook'));
  assert.ok(keys({roles:['top-management'],services:{dashboard:true,brandbook:true}}).includes('brandbook'));
  assert.ok(keys({roles:['platform-admin'],services:{dashboard:true,brandbook:true}}).includes('brandbook'));
  const catalog = serviceGroups.flatMap(g=>g.items).find(item=>item.key==='brandbook');
  assert.notEqual(catalog.public, true);
});

test('shared UI exports components without intercepting service navigation', () => {
  const ui = readFileSync(new URL('../src/index.js', import.meta.url), 'utf8');
  assert.doesNotMatch(ui, /sectionRouting|history\.(?:pushState|replaceState)/);
  for (const app of ['web','vacations','clients','timesheets','finance','specialists','recruitment','cv','brandbook','equipment','design-system','system','migration','portal']) {
    const router = readFileSync(new URL(`../../../apps/${app}/src/router.js`, import.meta.url), 'utf8');
    const routes = readFileSync(new URL(`../../../apps/${app}/src/routes.js`, import.meta.url), 'utf8');
    assert.match(router, /createWebHistory\(import\.meta\.env\.BASE_URL\)/, app);
    assert.match(routes, /not-found/, app);
  }
});

test('private app entry points display forbidden state instead of redirecting to Dashboard', () => {
  for (const app of ['web', 'vacations', 'clients', 'timesheets', 'specialists', 'recruitment', 'cv']) {
    const source = readFileSync(new URL(`../../../apps/${app}/src/main.js`, import.meta.url), 'utf8');
    assert.match(source, /renderNavigationError/ , app);
    assert.doesNotMatch(source, /window\.location\.replace\('\/'\)/, app);
  }
});


test('all frontend error types share an explicit user-facing status contract', async () => {
  const { navigationErrorMessages } = await import('../src/navigationError.js');
  for (const code of [400,401,403,404,409,422,429,500,502,503,504]) {
    assert.ok(navigationErrorMessages[code]?.[0], `Missing ${code} title`);
    assert.ok(navigationErrorMessages[code]?.[1], `Missing ${code} guidance`);
  }
});

test('every frontend owns explicit unknown-route and 404 rendering', () => {
  const source = path => readFileSync(new URL('../../../'+path, import.meta.url), 'utf8');
  for (const app of ['finance','equipment','design-system']) {
    const main = source(`apps/${app}/src/main.js`);
    const component = source(`apps/${app}/src/App.vue`);
    assert.match(main, /app\.use\(router\)/, app);
    assert.match(component, /UiErrorState/, app);
  }
  for (const app of ['web','vacations','clients','timesheets','cv','specialists','recruitment','brandbook']) {
    const component = source(`apps/${app}/src/${app==='recruitment'?'AppMvp.vue':'App.vue'}`);
    assert.match(component, /UiErrorState|404 —/, app);
  }
  for (const app of ['system','migration','portal']) assert.match(source(`apps/${app}/src/main.js`), /404/, app);
});

test('role-protected sections expose forbidden states without replacing the active route', () => {
  const source = app => readFileSync(new URL(`../../../apps/${app}/src/App.vue`, import.meta.url), 'utf8');
  for (const app of ['web','vacations','clients','timesheets','finance']) {
    assert.match(source(app), /403/, app);
  }
  for (const app of ['web','vacations','clients','timesheets']) {
    const routes = readFileSync(new URL(`../../../apps/${app}/src/routes.js`, import.meta.url), 'utf8');
    assert.match(routes, /not-found/, app);
  }
});

test('error surfaces use brand tokens and viewport centering', () => {
  const vue = readFileSync(new URL('../src/components/UiErrorState.vue', import.meta.url), 'utf8');
  const bootstrap = readFileSync(new URL('../src/navigationError.js', import.meta.url), 'utf8');
  for (const source of [vue, bootstrap]) {
    assert.match(source, /place-items:\s*center/);
    assert.match(source, /100dvh/);
    assert.match(source, /irlix-color-primary/);
    assert.match(source, /irlix-color-surface/);
    assert.match(source, /IRLIX SERVICES/);
  }
  assert.doesNotMatch(bootstrap, /margin:12vh auto/);
});

test('embedded System Migration keeps legacy links and query/hash on its own router', async () => {
  const { routes } = await import('../../../apps/system/src/routes.js');
  const embedded = routes.find(route => route.path === '/migration/control/');
  assert.equal(embedded.name, 'migration-control');
  const context = { query: { operation: '123', filter: 'failed' }, hash: '#errors' };
  for (const legacy of ['/migration/','/migration/console/']) {
    const match = routes.find(route => route.path === legacy);
    assert.deepEqual(match.redirect(context), { name: 'migration-control', ...context });
  }
  assert.equal(routes.find(route => route.name === 'not-found').path, '/:pathMatch(.*)*');
  const main = readFileSync(new URL('../../../apps/system/src/main.js', import.meta.url), 'utf8');
  assert.match(main, /if\(!await auth\.init\(\)\)return;/);
  assert.match(main, /await router\.isReady\(\)/);
  assert.match(main, /const restoredTarget = restoredPath \+ window\.location\.search \+ window\.location\.hash/);
  assert.match(main, /router\.replace\(restoredTarget\)/);
});


test('Timesheets launcher resolves only after effective section permissions; explicit deep links remain protected', async () => {
  const { routes } = await import('../../../apps/timesheets/src/routes.js');
  const { chooseTimesheetsLanding, resolveSectionAccess } = await import('../../../apps/timesheets/src/section-access.js');
  const entry = routes.find(route => route.path === '/');
  assert.equal(entry.name, 'entry');
  assert.equal(entry.meta.section, 'entry');
  assert.equal(entry.beforeEnter({ query:{month:'2026-10'}, hash:'' }), true);
  assert.deepEqual(entry.beforeEnter({ query:{section:'partners',month:'2026-10',client_id:'42'},hash:'#staff' }),
    {name:'management',query:{month:'2026-10',client_id:'42'},hash:'#staff'});
  assert.deepEqual(entry.beforeEnter({ query:{section:'mine'},hash:'' }),
    {name:'mine',query:{},hash:''});
  const manager = {
    'timesheets.management.view':{allowed:true,scope:'own'},
    'timesheets.mine.view':{allowed:false,scope:'none'},
  };
  const employee = {'timesheets.mine.view':{allowed:true,scope:'own'}};
  const both = {...employee,...manager};
  assert.equal(chooseTimesheetsLanding(manager),'management');
  assert.equal(chooseTimesheetsLanding(employee),'mine');
  assert.equal(chooseTimesheetsLanding(both),'mine');
  assert.equal(chooseTimesheetsLanding({'timesheets.analytics.view':{allowed:true,scope:'team'}}),'analytics');
  assert.equal(chooseTimesheetsLanding({'timesheets.audit.view':{allowed:true,scope:'team'}}),'audit');
  assert.equal(chooseTimesheetsLanding({'timesheets.management.view':{allowed:false,scope:'own'}}),null);
  assert.equal(resolveSectionAccess('mine','ready',false),'forbidden'); // direct /mine/ stays 403
  const app = readFileSync(new URL('../../../apps/timesheets/src/App.vue',import.meta.url),'utf8');
  assert.match(app,/route\.name === 'entry'/);
  assert.match(app,/router\.replace\(\{ name: entryDestination\.value, query: \{ \.\.\.route\.query \}, hash: route\.hash \}\)/);
  const catalog = serviceGroups.flatMap(group => group.items).find(item=>item.key==='timesheets');
  assert.equal(catalog.href,'/timesheets/');
});
