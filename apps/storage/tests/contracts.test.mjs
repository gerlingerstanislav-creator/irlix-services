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
    ['/trash', 'trash'],
    ['/v1', 'v1'],
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
  assert.ok(vue.includes('class="storage-switch"'));
  assert.ok(vue.includes('aria-pressed="layout === \'list\'"'));
  assert.ok(vue.includes('aria-pressed="layout === \'grid\'"'));
  assert.ok(!vue.includes('<UiViewSelect v-model="layout"'));
  assert.ok(vue.includes('<UiIcon name="list"'));
  assert.ok(vue.includes('viewBox="0 0 24 24"'));
  assert.ok(css.includes('.storage-switch{'));
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

test('Storage list is flush and rectangular, tiles use authenticated lazy thumbnails', () => {
  const css = text('../src/storage.css');
  const wrapper = text('../src/StorageApp.vue');
  assert.ok(css.includes('.storage-main{flex:1;min-width:0;padding:16px 0 0;'));
  assert.ok(css.includes('.storage-content{min-height:270px;flex:1;'));
  assert.ok(css.includes('border-radius:0;overflow:auto'));
  assert.ok(vue.includes('thumbnailUrl(item)'));
  assert.ok(vue.includes('loading="lazy"'));
  assert.ok(vue.includes('decoding="async"'));
  assert.ok(vue.includes('@error="failedThumbs.add(item.key)"'));
  assert.ok(vue.includes('class="storage-tile-actions"'));
  assert.ok(vue.includes('class="storage-download-cell" @click.stop'));
  assert.ok(vue.includes('<UiActionMenu :items="actionsFor(item)"'));
  assert.ok(wrapper.includes('@breadcrumbs="onBreadcrumbs"'));
  assert.ok(wrapper.includes('folderLabels.value'));
});

test('Rename operations are server-backed with metadata-only persistence and no file deletion', () => {
  const css = text('../src/storage.css');
  assert.ok(vue.includes("await api('/rename', { method: 'POST', body })"));
  assert.ok(vue.includes('class="storage-modal storage-small-modal" @submit.prevent="renameItem"'));
  assert.ok(vue.includes("item.kind === 'file'"));
  assert.ok(vue.includes('aria-label="Свойства объекта"'));
  assert.ok(css.includes('.storage-properties{'));
  assert.ok(api.includes('META_ROOT = "pilot-ui-meta/"'));
  assert.ok(api.includes('@app.post("/rename", dependencies=[Depends(guard)])'));
  assert.ok(api.includes('@app.get("/thumbnails", dependencies=[Depends(guard)])'));
  assert.ok(api.includes('Image.MAX_IMAGE_PIXELS = 12_000_000'));
  assert.doesNotMatch(api, /delete_object\s*\(/);
});

test('Pilot trash is reversible and denies direct reads, no physical object deletion', () => {
  const screen = text('../src/StorageTrash.vue');
  const lifecycle = text('../../../services/storage-api/app/lifecycle.py');
  const routes = text('../src/routes.js');
  const shell = text('../src/StorageApp.vue');
  assert.ok(routes.includes("name: 'trash'"));
  assert.ok(shell.includes('StorageTrash v-else-if="activeSection === \'trash\'"'));
  assert.ok(shell.includes("id: 'trash', label: 'Корзина'"));
  assert.ok(vue.includes("await api('/trash', { method: 'POST', body: form })"));
  assert.ok(vue.includes('aria-label="Перемещение в корзину"'));
  assert.ok(screen.includes("request('/restore', { method: 'POST', body: form })"));
  assert.ok(api.includes('@app.get("/trash", dependencies=[Depends(guard)])'));
  assert.ok(api.includes('@app.post("/trash", dependencies=[Depends(guard)])'));
  assert.ok(api.includes('@app.post("/restore", dependencies=[Depends(guard)])'));
  assert.ok(api.includes('_ensure_visible(client, "file", key)'));
  assert.ok(api.includes('_ensure_visible(client, "file", identity)'));
  assert.ok(api.includes('_ensure_folder_visible(client, folder)'));
  assert.ok(lifecycle.includes('TRASH_ROOT = "pilot-ui-trash/"'));
  assert.ok(lifecycle.includes('state not in _ALLOWED_STATES'));
  assert.ok(!lifecycle.includes('delete_object('));
  assert.ok(!api.includes('delete_object('));
});

test('V1 is isolated from legacy files and uses shared employee picker', () => {
  const app = text('../src/StorageApp.vue');
  const screen = text('../src/StorageV1.vue');
  const router = text('../src/routes.js');
  assert.ok(router.includes("name: 'v1'"));
  assert.ok(app.includes('<StorageV1 v-else-if="activeSection === \'v1\'"'));
  assert.ok(screen.includes('UiSearchSelect v-model="shareRecipient"'));
  assert.ok(screen.includes("request('/objects/'"));
  assert.ok(screen.includes("request('/folders'"));
  assert.ok(screen.includes("request('/files'"));
  assert.ok(screen.includes("'/api/storage/v1'"));
  assert.ok(screen.includes("mode.value === 'trash'"));
  assert.ok(screen.includes("isAdmin.value || item.owner_employee_id === currentEmployeeId.value"));
});

test('V1 operations support immutable versions, scoped search, bulk actions, and quota', () => {
  const v1 = text('../src/StorageV1.vue');
  const server = text('../../../services/storage-api/app/v1_api.py');
  const versions = text('../../../services/storage-api/migrations/002_file_versions.sql');
  const compose = text('../../../docker-compose.storage.yml');
  assert.ok(v1.includes('searchQuery = ref('));
  assert.ok(v1.includes("'/search?q='"));
  assert.ok(v1.includes("'/versions'"));
  assert.ok(v1.includes('UiCheckbox'));
  assert.ok(v1.includes('async function submitBulkMove()'));
  assert.ok(v1.includes("operationItem && !bulkMoving"));
  assert.ok(v1.includes("request('/stats')"));
  for (const route of ['/search', '/stats', '/folders/targets',
    '/objects/{object_id}/move', '/objects/{object_id}/copy',
    '/objects/{object_id}/versions']) {
    assert.ok(server.includes(route), route);
  }
  assert.ok(server.includes('_require_capacity(repo'));
  assert.ok(server.includes('@router.get("/preview/{object_id}")'));
  assert.ok(server.includes('Content-Security-Policy'));
  assert.ok(server.includes('X-Content-Type-Options'));
  assert.ok(server.includes('Несоответствие содержимого и расширения'));
  assert.ok(!server.includes('".html": "text/html"'));
  assert.ok(v1.includes('async function openPreview(item)'));
  assert.ok(v1.includes('URL.revokeObjectURL(previewUrl.value)'));
  assert.ok(v1.includes('sandbox=""'));
  assert.ok(compose.includes('STORAGE_V1_QUOTA_BYTES:'));
  assert.ok(versions.includes('storage.file_versions'));
  assert.ok(versions.includes('storage.reject_version_mutation'));
  assert.ok(!server.includes('delete_object('));
});

test('Storage production smoke distinguishes public static shell from protected data APIs', () => {
  const smoke = text('../../../scripts/verify-stand.sh');
  const uiRoute = ingress.split('location /storage/ {')[1]?.split('\n    }')[0];
  assert.ok(uiRoute, 'Storage static UI route must exist');
  assert.ok(!uiRoute.includes('auth_request'), 'Static V1 SPA is public but never contains file data');
  assert.ok(ingress.includes('location /api/storage/ {'));
  assert.ok(ingress.includes('location /api/storage/v1/ {'));
  assert.ok(smoke.includes('Anonymous Storage static SPA'));
  assert.ok(smoke.includes('Anonymous Storage static JS'));
  assert.ok(smoke.includes('Anonymous Storage legacy API still admin gated" http://127.0.0.1/api/storage/items 302'));
  assert.ok(smoke.includes('Anonymous Storage V1 API denied" http://127.0.0.1/api/storage/v1/items 401'));
  assert.ok(smoke.includes('Storage pilot gate internal-only" http://127.0.0.1/_storage_pilot_gate 404'));
  assert.ok(smoke.includes('Storage V1 gate internal-only" http://127.0.0.1/_storage_v1_gate 404'));
  assert.ok(!smoke.includes('Anonymous Storage canonical route" http://127.0.0.1/storage/ 302'));
});
