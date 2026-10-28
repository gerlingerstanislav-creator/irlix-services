import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const text = path => readFileSync(new URL(path, import.meta.url), 'utf8');
const vue = text('../src/StorageDrive.vue');
const api = text('../../../services/storage-api/app/main.py');
const ingress = text('../../../infra/nginx/irlix-services.conf');
const compose = text('../../../docker-compose.storage.yml');

test('Drive supports real S3-backed browsing, upload, folders and previews', () => {
  for (const path of ['/items', '/folders', '/files']) {
    assert.ok(vue.includes(path), 'missing API operation ' + path);
  }
  for (const text of ['Папка', 'Загрузить файлы', 'Поиск в папке', 'preview=true',
                       '<iframe', '<img', '<pre', 'На дашборд']) {
    assert.ok(vue.includes(text), 'missing UI capability ' + text);
  }
});

test('Shared IRLIX chrome and clean /storage/ SSO are used everywhere', () => {
  const wrapper = text('../src/StorageApp.vue');
  const entry = text('../src/main.js');
  const vite = text('../vite.config.js');
  const portal = text('../../portal/src/main.js');
  const index = text('../index.html');
  assert.ok(wrapper.includes('<UiAppShell'));
  assert.ok(wrapper.includes('service="storage"'));
  assert.ok(wrapper.includes('service-name="Хранилище"'));
  assert.ok(wrapper.includes(':service-access="access"'));
  assert.ok(wrapper.includes('auth.fetch(\'/api/employees/access/me\''));
  assert.ok(wrapper.includes('isPlatformAdministratorAccess(result)'));
  assert.ok(wrapper.includes('auth.logout()'));
  assert.ok(entry.includes("import StorageApp from './StorageApp.vue'"));
  assert.ok(vite.includes("base: '/storage/'"));
  assert.ok(index.includes('IRLIX · Хранилище'));
  assert.ok(portal.includes("window.location.replace('/storage/')"));
  assert.ok(!portal.includes('hostname}:8110'));
  assert.ok(ingress.includes('location /storage/ {'));
  assert.ok(ingress.includes('location /api/storage/ {'));
  assert.ok(ingress.includes('location @storage_pilot_login {'));
  assert.ok(ingress.includes('location = /_storage_pilot_gate {'));
  assert.ok(!ingress.includes('location /storage/ { return 302'));
  assert.ok(compose.includes('STORAGE_PUBLIC_ORIGIN: ${IRLIX_CANONICAL_ORIGIN:-http://services.lan}'));
  assert.ok(!compose.includes('STORAGE_PUBLIC_ORIGIN: ${IRLIX_CANONICAL_ORIGIN:-http://services.lan}:8110'));
});

test('Storage release waits for both new container origins before cleanup', () => {
  const cleanup = text('../../../scripts/storage/retire-filestash.sh');
  assert.ok(cleanup.includes('while [ "$attempt" -le 30 ]'));
  assert.ok(cleanup.includes('curl -fsS --connect-timeout 2 --max-time 4 http://127.0.0.1:8115/'));
  assert.ok(cleanup.includes('http://127.0.0.1:8114/health'));
  assert.ok(cleanup.includes('storage_ready=true'));
  assert.ok(cleanup.includes('if [ "$storage_ready" != true ]; then'));
  assert.ok(cleanup.indexOf('Storage Drive and API readiness confirmed.') < cleanup.indexOf('docker ps -aq'));
});

test('Storage remains admin-only, with backend gate and never exposes credentials', () => {
  assert.match(api, /async def guard\(request: Request\):/);
  assert.match(api, /_storage_pilot_access/);
  assert.match(api, /X-Irlix-Storage-Request/);
  assert.match(api, /ROOT = "pilot-ui\/"|ROOT = "pilot-ui\/"/);
  assert.doesNotMatch(vue, /STORAGE_S3_SECRET_KEY|STORAGE_S3_ACCESS_KEY|aws_secret_access_key/);
  assert.ok(ingress.includes('auth_request /_storage_pilot_gate;'));
  assert.ok(ingress.includes('127.0.0.1:8114'));
  assert.ok(ingress.includes('127.0.0.1:8115'));
  assert.match(compose, /127\.0\.0\.1:8114:8000/);
  assert.match(compose, /127\.0\.0\.1:8115:80/);
});

test('No business attachment or destructive object endpoints exist in the pilot', () => {
  assert.doesNotMatch(api, /@app\.delete\(/);
  assert.doesNotMatch(api, /@app\.(post|put|patch)\("\/business/);
  assert.ok(api.includes('MAX_FILE = 10 * 1024 * 1024'));
});

test('Storage routes keep folders in history, reject unknown URLs and separate the future shared view', async () => {
  const { routes } = await import('../src/routes.js');
  assert.deepEqual(routes.map(route => [route.path, route.name]), [
    ['/', 'files'],
    ['/available', 'available'],
    ['/:pathMatch(.*)*', 'not-found'],
  ]);
  const entry = text('../src/main.js');
  const router = text('../src/router.js');
  const wrapper = text('../src/StorageApp.vue');
  assert.ok(entry.includes('.use(router)'));
  assert.ok(router.includes('createWebHistory(import.meta.env.BASE_URL)'));
  assert.ok(wrapper.includes('@update:section="navigateSection"'));
  assert.ok(wrapper.includes("router.push({ name: id })"));
  assert.ok(wrapper.includes("route.query.folder"));
  assert.ok(wrapper.includes("encodeURIComponent(segments.slice(0, index + 1)"));
  assert.ok(wrapper.includes('UiErrorState v-else :status="404"'));
  assert.ok(wrapper.includes("activeSection === 'available'"));
  assert.ok(wrapper.includes('общий доступ не подключён'));
  assert.ok(!wrapper.includes('fetch(\'/api/storage/shared'));
});

test('Drive has one compact action/filter row, shared buttons and transient corner toasts', () => {
  const css = text('../src/storage.css');
  assert.ok(vue.includes('class="storage-toolbar"'));
  assert.ok(vue.includes('<UiButton variant="secondary"'));
  assert.ok(vue.includes('<UiViewSelect v-model="layout"'));
  assert.ok(vue.includes('class="storage-toolbar-tools"'));
  assert.ok(vue.includes('class="storage-actions"'));
  assert.ok(!vue.includes('class="storage-main-top"'));
  assert.ok(!vue.includes('class="storage-heading"'));
  assert.ok(!vue.includes('class="storage-crumbs"'));
  assert.ok(vue.includes('class="storage-toast"'));
  assert.ok(vue.includes('window.setTimeout('));
  assert.ok(vue.includes('window.clearTimeout('));
  assert.ok(vue.includes("if (serial === requestSerial) loading.value = false"));
  assert.ok(vue.includes('watch(folder, () =>'));
  assert.ok(vue.includes("router.push({ name: 'files'"));
  assert.ok(css.includes('.storage-toast{position:fixed;right:20px;bottom:20px;'));
  assert.ok(css.includes('height:var(--irlix-control-height,27px)'));
});
