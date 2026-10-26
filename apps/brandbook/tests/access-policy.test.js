import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {getVisibleServiceGroups, serviceGroups} from '../../../packages/ui/src/serviceCatalog.js';

const root = new URL('../../../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');

test('Brandbook is not a public launcher service or an inherited management role', () => {
  const service = serviceGroups.flatMap(g=>g.items).find(s=>s.key==='brandbook');
  assert.equal(service.public,undefined);
  const keys = access=>getVisibleServiceGroups(false,access).flatMap(g=>g.items.map(x=>x.key));
  for (const access of [null,{}, {roles:['irlix-head'],services:{}},
    {roles:['top-management'],services:{brandbook:false}},
    {roles:['platform-tester'],services:{brandbook:false}}]) {
    assert.ok(!keys(access).includes('brandbook'));
  }
  assert.ok(keys({roles:['top-management'],services:{brandbook:true}}).includes('brandbook'));
  assert.ok(keys({roles:['platform-admin'],services:{brandbook:true}}).includes('brandbook'));
});

test('Host routes protect the origin, every asset, and deny internal gate access', () => {
  const nginx = read('infra/nginx/irlix-services.conf');
  const gated = nginx.match(/location \/brandbook\/ \{([^}]*)\}/)?.[1] || '';
  assert.match(gated,/auth_request \/_brandbook_gate;/);
  assert.match(gated,/proxy_pass http:\/\/127\.0\.0\.1:8105\//);
  assert.match(gated,/error_page 401 = @brandbook_login;/);
  const login = nginx.match(/location @brandbook_login \{([^}]*)\}/)?.[1] || '';
  assert.match(login,/return 302 \/\?brandbook=1;/);
  assert.match(gated,/private, no-store/);
  const internal = nginx.match(/location = \/_brandbook_gate \{([^}]*)\}/)?.[1] || '';
  assert.match(internal,/\binternal;/);
  assert.match(internal,/\/\_brandbook_access/);
  assert.match(internal,/proxy_set_header Cookie \$http_cookie/);
  assert.doesNotMatch(nginx,/location\s+\/brandbook\/downloads\//,'no downloadable asset bypasses gate');
});

test('Employees issues a short-lived opaque cookie from signed JWT only', () => {
  const middleware=read('services/employees/app/Http/Middleware/EmployeesAuthorization.php');
  const routes=read('services/employees/routes/access.php');
  const controller=read('services/employees/app/Http/Controllers/BrandbookSessionController.php');
  const service=read('services/employees/app/Services/BrandbookSessionService.php');
  const server=read('services/employees/routes/web.php');
  const access=read('services/employees/app/Services/BrandbookAccessService.php');
  const gate=read('services/employees/app/Support/BrandbookGate.php');
  assert.match(middleware,/api\/brandbook\/session/);
  assert.match(middleware,/X-Irlix-Role-Preview/);
  assert.match(routes,/Route::post\('\/brandbook\/session', \[BrandbookSessionController::class, 'store'\]/);
  assert.match(controller,/identity\['sub'\]/);
  assert.match(service,/BrandbookGate::hasRole/);
  assert.match(service,/where\('keycloak_user_id'/);
  assert.match(service,/BrandbookGate::active/);
  assert.match(controller,/Crypt::encryptString/);
  assert.match(controller,/cookie\('irlix_brandbook'/);
  assert.match(controller,/true, false, 'lax'/);
  assert.match(server,/Route::get\('\/_brandbook_access', \[BrandbookGateController::class, 'check'\]/);
  assert.match(access,/Crypt::decryptString/);
  assert.match(access,/where\('keycloak_user_id'/);
  assert.match(access,/whereKey/);
  assert.match(gate,/TopManagement/);
  assert.match(gate,/PlatformAdmin/);
  assert.match(gate,/expires_at/);
});

test('Portal OIDC navigation cannot grant access without Employees approval', () => {
  const portal=read('apps/portal/src/main.js');
  assert.match(portal,/brandbook\/session/);
  assert.match(portal,/auth\.fetch\('/);
  assert.match(portal,/response\.status === 403/);
  assert.match(portal,/window\.location\.replace\('\/brandbook\/'\)/);
  const smoke=read('scripts/verify-stand.sh');
  for(const path of ['brandbook/', 'brandbook/colors/', 'brandbook/downloads/irlix-logo-dark-background.svg'])
    assert.ok(smoke.includes(path),'no anonymous smoke for '+path);
});

test('Portal legacy Brandbook route retains Employees SSO gate and SPA fallback', () => {
  const nginx = read('apps/portal/nginx.conf');
  const gate = nginx.match(/location = \/_brandbook_fallback_gate \{([^}]*)\}/)?.[1] || '';
  const route = nginx.match(/location \/brandbook\/ \{([^}]*)\}/)?.[1] || '';
  assert.match(gate, /internal;/);
  assert.match(gate, /proxy_pass http:\/\/employees:8000\/_brandbook_access/);
  assert.match(gate, /proxy_set_header Cookie \$http_cookie/);
  assert.match(route, /auth_request \/_brandbook_fallback_gate;/);
  assert.match(route, /error_page 401 = @brandbook_login_fallback;/);
  const login = nginx.match(/location @brandbook_login_fallback \{([^}]*)\}/)?.[1] || '';
  assert.match(login, /return 302 \/\?brandbook=1;/);
  assert.match(route, /proxy_pass http:\/\/brandbook;/);
  assert.match(nginx, /location = \/brandbook \{ return 301 \/brandbook\/; \}/);
});

test('Brandbook shell gets real authenticated Employees access, never a static one-service map', () => {
  const main = read('apps/brandbook/src/main.js');
  const app = read('apps/brandbook/src/App.vue');
  assert.match(main, /createBrowserAuth/);
  assert.match(main, /await auth\.init\(\)/);
  assert.match(main, /auth\.fetch\('\/api\/employees\/access\/me'/);
  assert.match(main, /access\?\.services\?\.brandbook !== true/);
  assert.match(main, /createApp\(App, \{/);
  assert.match(main, /serviceAccess: access/);
  assert.match(app, /:service-access="serviceAccess"/);
  assert.match(app, /:current-user="currentUser"/);
  assert.match(app, /@logout="emit\('logout'\)"/);
  assert.doesNotMatch(app, /:service-access="\{services:\{brandbook:true\}\}"/);
});
