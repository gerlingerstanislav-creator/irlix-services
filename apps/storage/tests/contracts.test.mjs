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

test('Public Storage bootstrap does not bypass protected file APIs', () => {
  const smoke = text('../../../scripts/verify-stand.sh');
  const storageLocation = ingress.split('location /storage/ {')[1]?.split('location = /storage {')[0];
  assert.ok(storageLocation, 'canonical Storage frontend location missing');
  assert.doesNotMatch(storageLocation, /auth_request\s+/,
    'Storage SPA must be reachable before browser OIDC can issue Storage cookies');
  assert.ok(ingress.includes('location /api/storage/ {'));
  assert.ok(ingress.includes('location /api/storage/v1/ {'));
  assert.ok(ingress.includes('auth_request /_storage_pilot_gate;'));
  assert.ok(ingress.includes('auth_request /_storage_v1_gate;'));
  assert.ok(smoke.includes('check_body "Storage frontend (public shell)"'));
  assert.ok(smoke.includes('check_status "Storage JS (public shell)"'));
  assert.ok(smoke.includes('check_status "Anonymous Storage legacy API"'));
  assert.ok(smoke.includes('check_status "Anonymous Storage V1 API"'));
  assert.ok(!smoke.includes('Anonymous Storage canonical route'));
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
    ['/v1', undefined],
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
  assert.ok(wrapper.includes('Общий доступ появится'));
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
  assert.ok(shell.includes('StorageTrash v-else-if="legacyFallback && activeSection === \'trash\'"'));
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
  assert.ok(router.includes("path: '/v1', redirect: '/'"));
  assert.ok(app.includes('<StorageV1 v-else-if="[\'files\', \'available\', \'trash\'].includes(activeSection)"'));
  assert.ok(screen.includes('UiSearchSelect v-model="shareRecipients"'));
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
  // Chrome's built-in PDF viewer is blocked by an empty iframe sandbox.
  // The backend checks actor ACL, PDF magic bytes and sends nosniff.
  assert.ok(v1.includes('previewMime === \'pdf\''));
  assert.doesNotMatch(v1, /<iframe[^>]*sandbox=/);
  assert.ok(compose.includes('STORAGE_V1_QUOTA_BYTES:'));
  assert.ok(versions.includes('storage.file_versions'));
  assert.ok(versions.includes('storage.reject_version_mutation'));
  assert.ok(!server.includes('delete_object('));
});

test('Storage V1 is the canonical three-section Drive, gated independently from legacy admin data', () => {
  const app = text('../src/StorageApp.vue');
  const screen = text('../src/StorageV1.vue');
  const routes = text('../src/routes.js');
  assert.ok(app.includes('const legacyFallback = ref(false)'));
  assert.ok(app.includes("probe.status === 503 && isPlatformAdministratorAccess(result)"));
  assert.ok(app.includes("'/api/storage/v1/items?scope=mine'"));
  assert.ok(app.includes("id: 'files', label: 'Мои файлы'"));
  assert.ok(app.includes("id: 'available', label: 'Доступные мне'"));
  assert.ok(app.includes("id: 'trash', label: 'Корзина'"));
  assert.ok(!app.includes('Новый диск (пилот)'));
  assert.ok(routes.includes("path: '/v1', redirect: '/'"));
  assert.ok(screen.includes("props.section === 'trash'"));
  assert.ok(screen.includes("props.section === 'available'"));
  assert.ok(screen.includes("router.push({ name: route.name, query: { folder_id: item.id } })"));
  assert.ok(screen.includes('class="storage-switch"'));
  assert.ok(screen.includes("layout === 'list'"));
  assert.ok(screen.includes("layout === 'grid'"));
  assert.ok(screen.includes('class="storage-table storage-v1-table"'));
  assert.ok(screen.includes('class="storage-tile-open" :title="item.name"'));
  assert.ok(screen.includes('refreshSerial'));
  assert.ok(!screen.includes('storage-v1-sections" role="group"'));
});

test('Storage V1 special-role limit is enforced at session, gate and Employees access matrix', () => {
  const matrix = text('../../../services/employees/app/Support/EmployeesAccess.php');
  const gate = text('../../../services/employees/app/Http/Controllers/StorageV1GateController.php');
  const session = text('../../../services/employees/app/Http/Controllers/StorageV1SessionController.php');
  const directory = text('../../../services/employees/app/Http/Controllers/StorageV1DirectoryController.php');
  for (const source of [matrix, gate, session, directory]) {
    assert.ok(source.includes('SpecialRoles::TopManagement'));
    assert.ok(source.includes('STORAGE_V1_ACCESS_ENABLED'));
  }
  for (const source of [gate, session, directory]) {
    assert.ok(source.includes('SpecialRoles::PlatformAdmin'));
  }
  assert.ok(matrix.includes("&& in_array(SpecialRoles::TopManagement, $roles, true)"));
  assert.ok(gate.includes('if (!$admitted ||'));
  assert.ok(session.includes('if (!$admitted ||'));
});


test('Storage favicon resolves under the canonical /storage/ URL', () => {
  const index = text('../index.html');
  const dockerfile = text('../Dockerfile');
  const storageNginx = text('../nginx.conf');
  const favicon = text('../../portal/favicon.svg');
  assert.ok(index.includes('<link rel="icon" type="image/svg+xml" href="/storage/favicon.svg" />'));
  assert.ok(dockerfile.includes('COPY apps/portal/favicon.svg /usr/share/nginx/html/favicon.svg'));
  assert.ok(storageNginx.includes('try_files'));
  assert.ok(favicon.includes('<svg'));
});

test('Storage V1 tiles use a resized and ACL-checked thumbnail, not original preview bytes', () => {
  const screen = text('../src/StorageV1.vue');
  const server = text('../../../services/storage-api/app/v1_api.py');
  assert.ok(screen.includes("'/api/storage/v1/thumbnail/' + encodeURIComponent(item.id)"));
  assert.ok(screen.includes(':src="thumbnailUrl(item)" alt="" loading="lazy" decoding="async"'));
  assert.ok(screen.includes("await authenticatedFetch('/preview/' + encodeURIComponent(item.id))"));
  assert.ok(server.includes('@router.get("/thumbnail/{object_id}")'));
  assert.ok(server.includes('preview = preview_safe(request, object_id)'));
  assert.ok(server.includes('picture.thumbnail((320, 240), Image.Resampling.LANCZOS)'));
  assert.ok(server.includes('rgb.save(output, format="JPEG", quality=78, optimize=True)'));
  assert.ok(server.includes('"Cache-Control": "private, no-store"'));
  assert.ok(!screen.includes("'/api/storage/v1/preview/' + encodeURIComponent(item.id)"));
});

test('V1 PDF uses native Chrome PDF viewer without restrictive iframe sandbox', () => {
  const v1 = text('../src/StorageV1.vue');
  const pilot = text('../src/StorageDrive.vue');
  const backend = text('../../../services/storage-api/app/v1_api.py');
  assert.match(v1, /<iframe v-else-if="previewUrl && previewMime === 'pdf'" :src="previewUrl" :title="previewItem.name"\s*\/>/);
  assert.doesNotMatch(v1, /<iframe[^>]*sandbox=/);
  assert.ok(pilot.includes('<iframe v-else-if="typeOf(preview.name)===\'pdf\'"'));
  assert.ok(v1.includes("await authenticatedFetch('/preview/' + encodeURIComponent(item.id))"));
  assert.ok(v1.includes('URL.createObjectURL(bytes)'));
  assert.ok(backend.includes('".pdf": raw.startswith(b"%PDF-")'));
  assert.ok(backend.includes('"Content-Disposition": "inline"'));
  assert.ok(backend.includes('"X-Content-Type-Options": "nosniff"'));
});


test('V1 toolbar follows compact shared layout without nested top padding', () => {
  const vue = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  assert.ok(vue.includes('class="storage-toolbar storage-v1-toolbar"'));
  assert.ok(vue.includes('class="storage-sort"'));
  assert.ok(css.includes('.storage-v1 .storage-v1-toolbar{'));
  assert.ok(css.includes('margin:0;padding:12px 20px'));
  assert.ok(css.includes('.storage-v1 .storage-switch{height:36px'));
});

test('V1 uses distinctive file type icons in both list and grid, and marks shared files', () => {
  const vue = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  for (const type of ['pdf', 'image', 'sheet', 'document', 'archive', 'video', 'audio', 'code']) {
    assert.ok(vue.includes("'" + type + "'") || vue.includes(type + ":"));
    assert.ok(css.includes('.storage-v1 .storage-type--' + type));
  }
  assert.ok(vue.includes('class="storage-file-icon" :class="\'storage-type--\' + typeOf(item)"'));
  assert.ok(vue.includes('class="storage-type-large"'));
  assert.ok(vue.includes('item.shared_count > 0'));
  assert.ok(vue.includes('class="storage-shared-badge"'));
  assert.ok(vue.includes('class="storage-shared-indicator"'));
  assert.ok(vue.includes('@click.stop="openSharing(item)"'));
});

test('V1 sharing dialog loads and shows named grants, supports revocation and scoped errors', () => {
  const vue = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  const server = text('../../../services/storage-api/app/v1_api.py');
  assert.ok(vue.includes('recipientName(grantRow.employee_id)'));
  assert.ok(vue.includes('grantLabel(grantRow.role)'));
  assert.ok(vue.includes('v-for="grantRow in grants"'));
  assert.ok(vue.includes('@click="revoke(grantRow.employee_id)"'));
  assert.ok(vue.includes("await Promise.all([loadDirectory(), loadGrants(item)])"));
  assert.ok(vue.includes('await loadGrants(shareItem.value)'));
  assert.ok(vue.includes('shareError.value = err.message'));
  assert.ok(vue.includes('shareLoading.value = true'));
  assert.ok(vue.includes('role="alert">{{ shareError }}'));
  assert.ok(css.includes('.storage-v1 .storage-v1-share{'));
  assert.ok(css.includes('.storage-v1 .storage-grant-person'));
  assert.ok(server.includes('"shared_count": int(shared_count)'));
  assert.ok(server.includes('"WHERE g.object_id=o.id AND g.revoked_at IS NULL) "'));
  assert.ok(server.includes('@router.delete("/objects/{object_id}/grants/{employee_id}")'));
});

test('V1 preview and all modals inherit compact design system typography', () => {
  const vue = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  assert.ok(vue.includes('class="storage-preview-download"'));
  assert.ok(vue.includes('id="storage-share-title"'));
  assert.ok(css.includes('.storage-v1 .storage-modal-head h2{'));
  assert.ok(css.includes('font-size:14px'));
  assert.ok(css.includes('.storage-v1 .storage-preview-modal{'));
  assert.ok(css.includes('padding:10px'));
});


test('Storage V1 employee selector supports compact multi-select and explicit chosen names', () => {
  const screen = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  const ui = text('../../../packages/ui/src/components/UiSearchSelect.vue');
  assert.ok(screen.includes('const shareRecipients = ref([])'));
  assert.ok(screen.includes('UiSearchSelect v-model="shareRecipients"'));
  assert.ok(screen.includes(':multiple="true" :disabled="busy || shareLoading" :teleport="true"'));
  assert.ok(screen.includes('v-for="employeeId in shareRecipients"'));
  assert.ok(screen.includes('removeRecipient(employeeId)'));
  assert.ok(screen.includes(':disabled="busy || shareLoading || !shareRecipients.length"'));
  assert.ok(ui.includes("const selectedValues = computed(() => props.multiple"));
  assert.ok(ui.includes("role=\"listbox\" :aria-multiselectable=\"multiple || undefined\""));
  assert.ok(css.includes('body:has(.storage-v1-share) > .ui-search-select__menu--teleported'));
  assert.ok(css.includes('.ui-search-select__option{'));
  assert.ok(css.includes('font-size:12px'));
  assert.ok(css.includes('.storage-v1 .storage-recipient-chips'));
});

test('Storage V1 multiple grants reuse individually audited API and retain failures for retry', () => {
  const screen = text('../src/StorageV1.vue');
  const api = text('../../../services/storage-api/app/v1_api.py');
  const segment = screen.slice(screen.indexOf('async function grant()'), screen.indexOf('async function revoke('));
  assert.ok(segment.includes('new Set(shareRecipients.value.map(Number))'));
  assert.ok(segment.includes('for (const employeeId of targetIds)'));
  assert.ok(segment.includes('employee_id: employeeId, role: shareRole.value'));
  assert.ok(segment.includes('failures.push({ id: employeeId, message: err.message })'));
  assert.ok(segment.includes('shareRecipients.value = failures.map(entry => entry.id)'));
  assert.ok(segment.includes('await loadGrants(item)'));
  assert.ok(api.includes('@router.post("/objects/{object_id}/grants")'));
});

test('Storage V1 tile isolates thumbnail and long title in separate bounded rows', () => {
  const screen = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  assert.ok(screen.includes('class="storage-tile-media"'));
  assert.ok(screen.includes('class="storage-tile-title"'));
  assert.ok(screen.includes('class="storage-tile-size"'));
  assert.ok(screen.includes('class="storage-tile-sharing"'));
  assert.ok(css.includes('grid-template-rows:126px 38px 17px 18px'));
  assert.ok(css.includes('.storage-v1 .storage-tile-media{'));
  assert.ok(css.includes('height:126px;overflow:hidden;'));
  assert.ok(css.includes('object-fit:contain;border-radius:0;'));
  assert.ok(css.includes('-webkit-line-clamp:2'));
  assert.ok(css.includes('overflow-wrap:anywhere;word-break:break-word;'));
});


test('V1 drag-drop uploads to current directory and to folder rows or tiles', () => {
  const screen = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  assert.ok(screen.includes('class="storage-content storage-v1-drop-area"'));
  assert.ok(screen.includes('@dragover="onAreaDragOver" @dragleave="onAreaDragLeave" @drop="onAreaDrop"'));
  assert.ok(screen.includes('const destination = parentId.value;'));
  assert.ok(screen.includes('void uploadFiles(files, destination);'));
  assert.ok(screen.includes('void uploadFiles(files, item.id);'));
  assert.ok(screen.includes('async function uploadFiles(chosen, destinationId = parentId.value)'));
  assert.ok(screen.includes("data.append('parent_id', destinationId)"));
  assert.ok(screen.includes('const canDropIntoCurrent = computed(() => canCreate.value'));
  assert.ok(screen.includes("item.kind === 'folder' && mode.value !== 'trash'"));
  assert.ok(screen.includes('&& !busy.value && !loading.value && canEdit(item)'));
  assert.ok(screen.includes("const isFileDrag = event => Array.from(event.dataTransfer?.types || []).includes('Files')"));
  assert.ok(screen.includes('event.dataTransfer.dropEffect = canDropIntoCurrent.value'));
  assert.ok(screen.includes("event.dataTransfer.dropEffect = 'none'"));
  assert.ok(screen.includes('event.preventDefault(); // Avoid the browser opening the file in this tab.'));
  assert.equal((screen.match(/@dragover="onFolderDragOver\(\$event, item\)"/g) || []).length, 2,
    'Both list rows and tiles must target writable folders');
  assert.equal((screen.match(/@drop="onFolderDrop\(\$event, item\)"/g) || []).length, 2);
  assert.ok(screen.includes('onAreaDrop(event);'), 'dropping on a file must upload to its containing folder');
  assert.ok(screen.includes('clearDropState();'));
  assert.ok(css.includes('.storage-v1 .storage-v1-drop-hint'));
  assert.ok(css.includes('pointer-events:none;'));
  assert.ok(css.includes('.storage-v1 .storage-v1-table tr.storage-v1-folder-drop-target'));
  assert.ok(css.includes('.storage-v1 .storage-grid .storage-v1-folder-drop-target'));
});

test('V1 drag-drop batch failures are reported without retrying successful files', () => {
  const screen = text('../src/StorageV1.vue');
  const upload = screen.slice(screen.indexOf('async function uploadFiles('), screen.indexOf('async function mutate('));
  const api = text('../../../services/storage-api/app/v1_api.py');
  assert.ok(upload.includes('for (const file of files)'));
  assert.ok(upload.includes('failed.push(file.name +'));
  assert.ok(upload.includes("notice.value = 'Загружено файлов: ' + uploaded"));
  assert.ok(upload.includes("error.value = 'Не удалось загрузить: ' + failed.join('; ')"));
  assert.ok(upload.includes('if (uploaded)'));
  assert.ok(screen.includes("item.webkitGetAsEntry?.()?.isDirectory"));
  assert.ok(screen.includes("mode.value !== 'trash'"));
  assert.ok(api.includes('parent, owner = _parent_for_create('));
  assert.ok(api.includes('_require_capacity(repo, owner, len(data))'));
  assert.ok(api.includes('raise HTTPException(413, "Файл больше 10 МБ")'));
});


test('Storage V1 opens an authenticated in-app image viewer with controls', () => {
  const viewer = text('../src/StorageV1.vue');
  const css = text('../src/storage.css');
  const api = text('../../../services/storage-api/app/v1_api.py');
  assert.match(viewer, /\{ id: 'preview', label: 'Просмотреть' \}/);
  assert.match(viewer, /if \(action === 'preview'\) return openPreview\(item\)/);
  assert.ok(viewer.includes('async function openPreview(item)'));
  assert.ok(viewer.includes("authenticatedFetch('/preview/' + encodeURIComponent(item.id))"));
  assert.ok(viewer.includes("previewMime === 'image'"));
  assert.ok(viewer.includes('URL.createObjectURL(bytes)'));
  assert.ok(viewer.includes('URL.revokeObjectURL(previewUrl.value)'));
  assert.ok(viewer.includes('if (serial !== previewSerial) return'));
  assert.ok(viewer.includes("content-type"));
  assert.ok(viewer.includes("previewLoading.value = true"));
  assert.ok(viewer.includes('previewError.value ='));
  assert.ok(viewer.includes('navigatePreview(direction)'));
  assert.ok(viewer.includes("event.key === 'Escape'"));
  assert.ok(viewer.includes("event.key === 'ArrowLeft'"));
  assert.ok(viewer.includes("event.key === 'ArrowRight'"));
  assert.ok(viewer.includes('document.removeEventListener'));
  assert.ok(viewer.includes('aria-label="Увеличить изображение"'));
  assert.ok(viewer.includes('aria-label="Следующее изображение"'));
  assert.ok(viewer.includes('class="storage-preview-state" role="alert"'));
  assert.ok(css.includes('.storage-v1 .storage-preview-modal .storage-image-preview-content{overflow:auto'));
  assert.ok(css.includes('.storage-v1 .storage-image-controls{'));
  assert.ok(api.includes('_authorize(repo, actor, object_id, Permission.READ)'));
  assert.ok(api.includes('"Content-Security-Policy": "sandbox; default-src'));
  assert.ok(!viewer.includes('storage-s3:8333'));
});
