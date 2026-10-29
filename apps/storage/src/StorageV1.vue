<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { UiButton, UiActionMenu, UiSearchSelect, UiCheckbox, UiIcon } from '@irlix/ui';

const props = defineProps({
  access: { type: Object, default: null },
  section: { type: String, default: 'files' },
  directoryFetch: { type: Function, required: true },
});
const emit = defineEmits(['folder-open']);
const route = useRoute();
const router = useRouter();
const parentId = computed(() => typeof route.query.folder_id === 'string' ? route.query.folder_id : '');
const mode = computed(() => props.section === 'trash' ? 'trash' : (props.section === 'available' ? 'shared' : 'mine'));
const layout = ref('list');
const sortBy = ref('name');
const entries = ref([]);
const searchQuery = ref('');
const searchActive = ref(false);
const stats = ref(null);
const selected = ref(new Set());
const bulkMoving = ref(false);
const bulkTarget = ref('');
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const createName = ref('');
const createFolderOpen = ref(false);
const uploadInput = ref(null);
const shareItem = ref(null);
const shareRecipient = ref('');
const shareRole = ref('reader');
const grants = ref([]);
const historyItem = ref(null);
const historyEvents = ref([]);
const versionsItem = ref(null);
const previewItem = ref(null);
const previewUrl = ref('');
const previewMime = ref('');
const previewText = ref('');
const fileVersions = ref([]);
const newVersionInput = ref(null);
const operationItem = ref(null);
const operationKind = ref('');
const operationName = ref('');
const operationParent = ref('');
const targetFolders = ref([]);
const directoryOptions = ref([]);
const currentEmployeeId = computed(() => Number(props.access?.employee_id || 0));
const isAdmin = computed(() => Array.isArray(props.access?.roles) && props.access.roles.includes('platform-admin'));
const canManage = item => isAdmin.value || item.owner_employee_id === currentEmployeeId.value;
const canEdit = item => canManage(item) || item.can_edit === true;
const selectedItems = computed(() => entries.value.filter(item => selected.value.has(item.id)));
const visibleEntries = computed(() => [...entries.value].sort((a, b) => {
  if (a.kind !== b.kind) return a.kind === 'folder' ? -1 : 1;
  if (sortBy.value === 'size') return Number(b.size || 0) - Number(a.size || 0);
  if (sortBy.value === 'date') return String(b.modified || '').localeCompare(String(a.modified || ''));
  return a.name.localeCompare(b.name, 'ru', { sensitivity: 'base' });
}));
const dateText = value => value ? new Date(value).toLocaleDateString('ru-RU') : '—';
const iconOf = item => item.kind === 'folder' ? '📁' : '📄';
const isImage = item => item.kind === 'file' && /\.(png|jpe?g|gif|webp)$/i.test(item.name);
const thumbnailUrl = item => '/api/storage/v1/preview/' + encodeURIComponent(item.id);
const displayedSize = item => item.kind === 'folder' ? 'Папка' : (item.size == null ? '—' : readableSize(item.size));
function toggleSelected(item, checked) {
  const next = new Set(selected.value);
  if (checked) next.add(item.id);
  else next.delete(item.id);
  selected.value = next;
}
function clearSelection() { selected.value = new Set(); }
const readableSize = value => {
  const bytes = Number(value || 0);
  if (!bytes) return '0 Б';
  const unit = Math.min(3, Math.floor(Math.log(bytes) / Math.log(1024)));
  return (bytes / (1024 ** unit)).toFixed(unit ? 1 : 0).replace('.', ',')
    + ' ' + ['Б','КБ','МБ','ГБ'][unit];
};
const targets = computed(() => [
  { value: '', label: 'Корневая папка' },
  ...targetFolders.value.filter(folder => operationKind.value !== 'move'
    || folder.owner_employee_id === operationItem.value?.owner_employee_id)
    .map(folder => ({ value: folder.id, label: folder.name,
                     meta: 'Владелец #' + folder.owner_employee_id })),
]);
const actionsFor = item => [
  ...(item.kind === 'file' && mode.value !== 'trash'
    ? [{ id: 'download', label: 'Скачать' }, { id: 'copy', label: 'Создать копию' }] : []),
  ...(canEdit(item) && mode.value !== 'trash'
    ? [{ id: 'rename', label: 'Переименовать' },
       ...(item.kind === 'file' ? [{ id: 'versions', label: 'Версии файла' }] : []) ] : []),
  ...(canManage(item) && mode.value !== 'trash' ? [
    { id: 'move', label: 'Переместить' }, { id: 'share', label: 'Настроить доступ' },
    { id: 'history', label: 'История действий' }, { id: 'trash', label: 'В корзину', danger: true },
  ] : []),
  ...(mode.value === 'trash' && canManage(item) ? [{ id: 'restore', label: 'Восстановить' }] : []),
];
const showingNormal = computed(() => mode.value !== 'trash');
const canCreate = computed(() => mode.value === 'mine' || (mode.value === 'shared' && Boolean(parentId.value)));

async function authenticatedFetch(path, options = {}) {
  const call = () => fetch('/api/storage/v1' + path, {
    credentials: 'same-origin',
    cache: 'no-store',
    ...options,
    headers: { ...(options.method ? { 'X-Irlix-Storage-Request': '1' } : {}), ...(options.headers || {}) },
  });
  let response = await call();
  if (response.status === 401) {
    // Tokens are short-lived; renew through Keycloak-authorized Employees,
    // never by trusting an employee ID supplied by the browser.
    const renewal = await props.directoryFetch('/api/employees/storage-v1/session', {
      method: 'POST', cache: 'no-store',
    });
    if (renewal.ok) response = await call();
  }
  return response;
}
async function request(path, options = {}) {
  const response = await authenticatedFetch(path, options);
  if (!response.ok) {
    let message = response.status === 503 ? 'Новое файловое пространство ещё не подключено к базе метаданных.' : 'Операция не выполнена';
    try { message = (await response.json()).detail || message; } catch { /* empty response */ }
    throw new Error(message);
  }
  return response.json();
}
async function refresh() {
  if (loading.value) return;
  loading.value = true; error.value = '';
  try {
    const url = searchActive.value ? '/search?q=' + encodeURIComponent(searchQuery.value.trim())
      : mode.value === 'trash' ? '/trash'
      : mode.value === 'shared' && !parentId.value ? '/available'
      : '/items' + (parentId.value ? '?parent_id=' + encodeURIComponent(parentId.value)
                                     : '?scope=mine');
    const result = await request(url);
    entries.value = Array.isArray(result.entries) ? result.entries : [];
    clearSelection();
    try { stats.value = await request('/stats'); } catch { stats.value = null; }
  } catch (err) { entries.value = []; error.value = err.message; }
  finally { loading.value = false; }
}
async function bulkAction(action) {
  const chosen = selectedItems.value.filter(item => action === 'restore' || canManage(item));
  if (!chosen.length || busy.value) return;
  if (action === 'trash' && !window.confirm('Переместить выбранные объекты в корзину?')) return;
  busy.value = true; error.value = '';
  const failures = [];
  let completed = 0;
  for (const item of chosen) {
    try {
      await request('/objects/' + item.id + '/' + action, { method: 'POST' });
      completed++;
    } catch (err) { failures.push(item.name + ': ' + err.message); }
  }
  busy.value = false;
  notice.value = 'Выполнено: ' + completed + ' из ' + chosen.length;
  if (failures.length) error.value = failures.slice(0, 5).join('; ');
  await refresh();
}
async function beginBulkMove() {
  const chosen = selectedItems.value.filter(canManage);
  if (!chosen.length || busy.value) return;
  const owners = new Set(chosen.map(item => item.owner_employee_id));
  if (owners.size > 1) {
    error.value = 'Для группового перемещения выберите объекты одного владельца';
    return;
  }
  operationItem.value = chosen[0]; operationKind.value = 'move';
  bulkMoving.value = true; bulkTarget.value = '';
  try {
    const data = await request('/folders/targets');
    targetFolders.value = data.entries || [];
  } catch (err) { error.value = err.message; bulkMoving.value = false; }
}
async function submitBulkMove() {
  const chosen = selectedItems.value.filter(canManage);
  if (!chosen.length || busy.value) return;
  busy.value = true; error.value = '';
  const failed = [];
  let done = 0;
  for (const item of chosen) {
    try {
      await request('/objects/' + item.id + '/move', { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ parent_id: bulkTarget.value || null }) });
      done++;
    } catch (err) { failed.push(item.name + ': ' + err.message); }
  }
  bulkMoving.value = false; operationItem.value = null; busy.value = false;
  notice.value = 'Перемещено: ' + done + ' из ' + chosen.length;
  if (failed.length) error.value = failed.slice(0, 5).join('; ');
  await refresh();
}
let searchTimer = null;
function queueSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applySearch, 300);
}
function applySearch() {
  if (searchQuery.value.trim().length < 2) {
    searchActive.value = false;
  } else {
    searchActive.value = true;
  }
  if (parentId.value) router.push({ name: route.name });
  else refresh();
}
function open(item) {
  if (item.kind === 'folder' && mode.value !== 'trash') {
    emit('folder-open', item);
    router.push({ name: route.name, query: { folder_id: item.id } });
  } else if (item.kind === 'file' && mode.value !== 'trash') {
    openPreview(item);
  }
}
function closePreview() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = '';
  previewItem.value = null;
  previewText.value = '';
  previewMime.value = '';
}
async function openPreview(item) {
  closePreview();
  previewItem.value = item;
  error.value = '';
  const extension = String(item.name).split('.').pop().toLowerCase();
  const kinds = {
    pdf: 'pdf', png: 'image', jpg: 'image', jpeg: 'image',
    gif: 'image', webp: 'image', txt: 'text', csv: 'text',
    log: 'text', mp4: 'video', webm: 'video',
  };
  if (!kinds[extension]) { previewMime.value = 'unsupported'; return; }
  try {
    const response = await authenticatedFetch('/preview/' + encodeURIComponent(item.id));
    if (!response.ok) throw new Error('Безопасный предпросмотр не доступен');
    const bytes = await response.blob();
    if (previewItem.value?.id !== item.id) return;
    previewMime.value = kinds[extension];
    if (kinds[extension] === 'text') {
      previewText.value = await bytes.text();
    } else {
      previewUrl.value = URL.createObjectURL(bytes);
    }
  } catch (err) { error.value = err.message; previewMime.value = 'unsupported'; }
}
onBeforeUnmount(() => { clearTimeout(searchTimer); closePreview(); });
async function download(item) {
  error.value = '';
  try {
    const response = await authenticatedFetch('/files/' + encodeURIComponent(item.id));
    if (!response.ok) throw new Error('Не удалось скачать файл');
    const file = await response.blob();
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = url; link.download = item.name;
    document.body.appendChild(link); link.click(); link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (err) { error.value = err.message; }
}
async function createFolder() {
  if (!createName.value.trim() || busy.value) return;
  busy.value = true; error.value = '';
  try {
    await request('/folders', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: createName.value, parent_id: parentId.value || null }) });
    createName.value = ''; createFolderOpen.value = false;
    notice.value = 'Папка создана'; await refresh();
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; }
}
async function uploadFiles(chosen) {
  const files = Array.from(chosen || []);
  if (!files.length || busy.value) return;
  busy.value = true; error.value = '';
  try {
    for (const file of files) {
      const data = new FormData();
      data.append('file', file); data.append('parent_id', parentId.value);
      await request('/files', { method: 'POST', body: data });
    }
    notice.value = 'Файлы загружены: ' + files.length;
    await refresh();
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; if (uploadInput.value) uploadInput.value.value = ''; }
}
async function mutate(item, action) {
  if (busy.value) return;
  if (action === 'download') return download(item);
  if (action === 'share') return openSharing(item);
  if (action === 'history') return openHistory(item);
  if (action === 'versions') return openVersions(item);
  if (['rename','move','copy'].includes(action)) return openOperation(item, action);
  if (!['trash', 'restore'].includes(action)) return;
  if (action === 'trash' && !window.confirm('Переместить «' + item.name + '» в корзину? Файл можно восстановить.')) return;
  busy.value = true; error.value = '';
  try {
    await request('/objects/' + encodeURIComponent(item.id) + '/' + action,
      { method: 'POST' });
    notice.value = action === 'trash' ? 'Перемещено в корзину' : 'Объект восстановлен';
    await refresh();
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; }
}
async function loadDirectory() {
  if (directoryOptions.value.length) return;
  try {
    const [peopleResponse, unitsResponse] = await Promise.all([
      props.directoryFetch('/api/employees/storage-v1-directory'),
      props.directoryFetch('/api/employees/storage-v1-departments'),
    ]);
    if (!peopleResponse.ok || !unitsResponse.ok) throw new Error('Каталог сотрудников недоступен');
    const people = (await peopleResponse.json()).data;
    const departments = (await unitsResponse.json()).data;
    if (!Array.isArray(people) || !Array.isArray(departments)) throw new Error('Некорректный каталог');
    const byDepartment = new Map();
    for (const person of people) {
      const id = Number(person.id);
      if (!Number.isSafeInteger(id) || id <= 0 || !person.full_name) continue;
      const department = Number(person.department_id || 0);
      if (!byDepartment.has(department)) byDepartment.set(department, []);
      byDepartment.get(department).push({ value: id, label: person.full_name, kind: 'option', depth: 1 });
    }
    const byParent = new Map();
    for (const unit of departments) {
      const id = Number(unit.id);
      const parent = Number(unit.parent_id || 0);
      if (!Number.isSafeInteger(id) || id <= 0) continue;
      if (!byParent.has(parent)) byParent.set(parent, []);
      byParent.get(parent).push(unit);
    }
    const options = [];
    const visited = new Set();
    const append = (parent, depth) => {
      for (const unit of byParent.get(parent) || []) {
        const id = Number(unit.id);
        if (visited.has(id)) continue;
        visited.add(id);
        options.push({ value: 'group-' + id, kind: 'group', label: unit.name, depth });
        for (const person of byDepartment.get(id) || []) options.push({ ...person, depth: depth + 1 });
        append(id, depth + 1);
      }
    };
    append(0, 0);
    const noDepartment = byDepartment.get(0) || [];
    if (noDepartment.length) options.push({ kind: 'group', value: 'group-none', label: 'Без подразделения', depth: 0 }, ...noDepartment);
    directoryOptions.value = options;
  } catch (err) { error.value = err.message; }
}
async function openSharing(item) {
  shareItem.value = item; grants.value = []; shareRecipient.value = '';
  await Promise.all([loadDirectory(), loadGrants(item)]);
}
async function openOperation(item, kind) {
  operationItem.value = item;
  operationKind.value = kind;
  operationName.value = kind === 'copy' ? 'Копия ' + item.name : item.name;
  operationParent.value = item.parent_id || '';
  targetFolders.value = [];
  error.value = '';
  if (kind === 'move' || kind === 'copy') {
    try {
      const data = await request('/folders/targets');
      targetFolders.value = data.entries || [];
    } catch (err) { error.value = err.message; }
  }
}
async function submitOperation() {
  const item = operationItem.value;
  if (!item || busy.value) return;
  busy.value = true; error.value = '';
  try {
    if (operationKind.value === 'rename') {
      await request('/objects/' + item.id, { method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: operationName.value }) });
    } else if (operationKind.value === 'move') {
      await request('/objects/' + item.id + '/move', { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ parent_id: operationParent.value || null }) });
    } else if (operationKind.value === 'copy') {
      await request('/objects/' + item.id + '/copy', { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ parent_id: operationParent.value || null,
                               name: operationName.value }) });
    }
    notice.value = 'Операция выполнена';
    operationItem.value = null;
    await refresh();
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; }
}
async function openVersions(item) {
  versionsItem.value = item; fileVersions.value = []; error.value = '';
  try {
    const result = await request('/objects/' + item.id + '/versions');
    fileVersions.value = result.versions || [];
  } catch (err) { error.value = err.message; }
}
async function replaceVersion(chosen) {
  if (!versionsItem.value || !chosen || busy.value) return;
  busy.value = true; error.value = '';
  try {
    const data = new FormData();
    data.append('file', chosen);
    await request('/objects/' + versionsItem.value.id + '/versions', { method: 'POST', body: data });
    notice.value = 'Новая версия сохранена';
    await openVersions(versionsItem.value);
    await refresh();
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; if (newVersionInput.value) newVersionInput.value.value = ''; }
}
async function restoreVersion(version) {
  if (!versionsItem.value || busy.value || !window.confirm('Восстановить выбранную версию файла? Текущая версия сохранится в истории.')) return;
  busy.value = true; error.value = '';
  try {
    await request('/objects/' + versionsItem.value.id + '/versions/' + version.id + '/restore', { method: 'POST' });
    notice.value = 'Версия восстановлена';
    await openVersions(versionsItem.value);
    await refresh();
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; }
}
async function openHistory(item) {
  historyItem.value = item; historyEvents.value = []; error.value = '';
  try {
    const result = await request('/objects/' + encodeURIComponent(item.id) + '/audit');
    historyEvents.value = result.events || [];
  } catch (err) { error.value = err.message; }
}
async function loadGrants(item) {
  try {
    const result = await request('/objects/' + encodeURIComponent(item.id) + '/grants');
    grants.value = result.entries || [];
  } catch (err) { error.value = err.message; }
}
async function grant() {
  if (!shareItem.value || !shareRecipient.value || busy.value) return;
  busy.value = true; error.value = '';
  try {
    await request('/objects/' + encodeURIComponent(shareItem.value.id) + '/grants', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ employee_id: Number(shareRecipient.value), role: shareRole.value }),
    });
    notice.value = 'Доступ предоставлен';
    await loadGrants(shareItem.value);
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; }
}
async function revoke(employeeId) {
  if (!shareItem.value || busy.value) return;
  busy.value = true; error.value = '';
  try {
    await request('/objects/' + encodeURIComponent(shareItem.value.id) + '/grants/' + employeeId,
      { method: 'DELETE' });
    notice.value = 'Доступ отозван';
    await loadGrants(shareItem.value);
  } catch (err) { error.value = err.message; }
  finally { busy.value = false; }
}
watch([parentId, mode], () => {
  searchActive.value = false;
  searchQuery.value = '';
  clearSelection();
  refresh();
});
onMounted(() => refresh());
</script>

<template>
  <section class="storage-v1">

    <div class="storage-toolbar storage-v1-toolbar">
      <div class="storage-toolbar-tools">
        <form class="storage-search" role="search" @submit.prevent="applySearch">
          <UiIcon name="search" />
          <input v-model="searchQuery" type="search" placeholder="Поиск по файлам"
            aria-label="Поиск по диску" @input="queueSearch" />
        </form>
        <select v-model="sortBy" aria-label="Сортировка">
          <option value="name">По названию</option>
          <option value="date">По дате</option>
          <option value="size">По размеру</option>
        </select>
        <div class="storage-switch" role="group" aria-label="Вид файлов">
          <button type="button" :class="{ active: layout === 'list' }"
            :aria-pressed="layout === 'list'" aria-label="Список" title="Список"
            @click="layout = 'list'"><UiIcon name="list" /></button>
          <button type="button" :class="{ active: layout === 'grid' }"
            :aria-pressed="layout === 'grid'" aria-label="Плитки" title="Плитки"
            @click="layout = 'grid'">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1"/>
              <rect x="13.5" y="3.5" width="7" height="7" rx="1"/>
              <rect x="3.5" y="13.5" width="7" height="7" rx="1"/>
              <rect x="13.5" y="13.5" width="7" height="7" rx="1"/></svg>
          </button>
        </div>
        <UiButton v-if="parentId" type="button" variant="secondary"
          @click="router.push({ name: route.name })">← К корню</UiButton>
      </div>
      <div class="storage-actions">
        <template v-if="selectedItems.length">
          <span class="storage-v1-count">{{ selectedItems.length }} выбрано</span>
          <UiButton v-if="mode === 'trash'" type="button" variant="secondary"
            :disabled="busy" @click="bulkAction('restore')">Восстановить</UiButton>
          <template v-else>
            <UiButton type="button" variant="secondary" :disabled="busy"
              @click="bulkAction('trash')">В корзину</UiButton>
            <UiButton type="button" variant="secondary" :disabled="busy"
              @click="beginBulkMove">Переместить</UiButton>
          </template>
          <UiButton type="button" variant="secondary" @click="clearSelection">Снять выделение</UiButton>
        </template>
        <template v-if="canCreate">
          <UiButton type="button" variant="secondary" :disabled="busy"
            @click="createFolderOpen = true">＋ Папка</UiButton>
          <UiButton type="button" :disabled="busy"
            @click="uploadInput?.click()">↑ Загрузить файлы</UiButton>
        </template>
        <input ref="uploadInput" type="file" hidden multiple @change="uploadFiles($event.target.files)" />
      </div>
    </div>
    <p v-if="error" class="storage-v1-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="storage-v1-notice" role="status">{{ notice }}</p>
    <div class="storage-content">
      <div v-if="loading" class="storage-empty" role="status">Загружаем файлы…</div>
      <div v-else-if="!visibleEntries.length" class="storage-empty">
        <div class="storage-empty-icon">▤</div>
        <h2>{{ searchActive ? 'Ничего не найдено' : mode === 'trash' ? 'Корзина пуста' : 'Папка пока пуста' }}</h2>
        <p>{{ searchActive ? 'Попробуйте изменить запрос' : 'Здесь пока нет файлов или папок' }}</p>
      </div>
      <div v-else-if="layout === 'list'" class="storage-table-wrap">
        <table class="storage-table storage-v1-table">
          <thead><tr><th>Название</th><th>Размер</th><th>Изменён</th><th class="storage-download-cell"></th></tr></thead>
          <tbody>
            <tr v-for="item in visibleEntries" :key="item.id" tabindex="0"
              @dblclick="open(item)" @keydown.enter="open(item)">
              <td>
                <UiCheckbox :model-value="selected.has(item.id)" :disabled="busy"
                  :aria-label="'Выбрать: ' + item.name"
                  @update:model-value="toggleSelected(item, $event)" />
                <span class="storage-file-icon">{{ iconOf(item) }}</span>
                <button type="button" class="storage-file-name" @click.stop="open(item)">{{ item.name }}</button>
              </td>
              <td>{{ displayedSize(item) }}</td>
              <td>{{ dateText(item.modified || item.deleted_at) }}</td>
              <td class="storage-download-cell" @click.stop @dblclick.stop>
                <UiActionMenu :items="actionsFor(item)"
                  :aria-label="'Действия: ' + item.name" @select="mutate(item, $event)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="storage-grid">
        <div v-for="item in visibleEntries" :key="item.id" class="storage-tile">
          <div class="storage-v1-tile-select">
            <UiCheckbox :model-value="selected.has(item.id)" :disabled="busy"
              :aria-label="'Выбрать: ' + item.name"
              @update:model-value="toggleSelected(item, $event)" />
          </div>
          <div class="storage-tile-actions">
            <UiActionMenu :items="actionsFor(item)"
              :aria-label="'Действия: ' + item.name" @select="mutate(item, $event)" />
          </div>
          <button type="button" class="storage-tile-open" @click="open(item)">
            <span class="storage-tile-icon">
              <img v-if="isImage(item) && mode !== 'trash'" class="storage-thumbnail"
                :src="thumbnailUrl(item)" alt="" loading="lazy" decoding="async" />
              <span v-else>{{ iconOf(item) }}</span>
            </span>
            <strong :title="item.name">{{ item.name }}</strong>
            <small>{{ displayedSize(item) }}</small>
          </button>
        </div>
      </div>
    </div>
    <div class="storage-footer">
      <span>{{ visibleEntries.filter(item => item.kind === 'folder').length }} папок ·
        {{ visibleEntries.filter(item => item.kind === 'file').length }} файлов</span>
      <span v-if="stats">Использовано с учётом версий: {{ readableSize(stats.bytes_with_versions) }}
        <template v-if="stats.quota_bytes"> из {{ readableSize(stats.quota_bytes) }}</template>
      </span>
    </div>

    <div v-if="createFolderOpen" class="storage-overlay" @click.self="createFolderOpen=false">
      <form class="storage-modal storage-small-modal" @submit.prevent="createFolder">
        <div class="storage-modal-head"><h2>Новая папка</h2><button type="button" @click="createFolderOpen=false">×</button></div>
        <label>Название<input v-model="createName" maxlength="160" required /></label>
        <div class="storage-modal-actions"><UiButton type="button" variant="secondary" @click="createFolderOpen=false">Отмена</UiButton><UiButton :disabled="busy || !createName.trim()">Создать</UiButton></div>
      </form>
    </div>

    <div v-if="bulkMoving" class="storage-overlay" @click.self="bulkMoving=false;operationItem=null">
      <form class="storage-modal storage-small-modal" @submit.prevent="submitBulkMove">
        <div class="storage-modal-head"><h2>Переместить выбранные объекты</h2><button type="button" @click="bulkMoving=false;operationItem=null">×</button></div>
        <label>Папка назначения
          <UiSearchSelect v-model="bulkTarget" :options="targets" placeholder="Корневая папка" :teleport="true" />
        </label>
        <div class="storage-modal-actions">
          <UiButton type="button" variant="secondary" @click="bulkMoving=false;operationItem=null">Отмена</UiButton>
          <UiButton :disabled="busy">Переместить</UiButton>
        </div>
      </form>
    </div>
    <div v-if="operationItem && !bulkMoving" class="storage-overlay" @click.self="!busy && (operationItem=null)">
      <form class="storage-modal storage-small-modal" @submit.prevent="submitOperation">
        <div class="storage-modal-head">
          <h2>{{ operationKind === 'rename' ? 'Переименовать' : operationKind === 'move' ? 'Переместить' : 'Создать копию' }}</h2>
          <button type="button" :disabled="busy" @click="operationItem=null">×</button>
        </div>
        <label v-if="operationKind !== 'move'">Название
          <input v-model="operationName" maxlength="160" required />
        </label>
        <label v-if="operationKind !== 'rename'">Папка назначения
          <UiSearchSelect v-model="operationParent" :options="targets" placeholder="Корневая папка" :clearable="true" :teleport="true" />
        </label>
        <p class="storage-rename-hint">Содержимое исходного файла не удаляется. Перенос между владельцами запрещён.</p>
        <div class="storage-modal-actions">
          <UiButton type="button" variant="secondary" :disabled="busy" @click="operationItem=null">Отмена</UiButton>
          <UiButton :disabled="busy || (operationKind !== 'move' && !operationName.trim())">Сохранить</UiButton>
        </div>
      </form>
    </div>
    <div v-if="previewItem" class="storage-overlay" @click.self="closePreview">
      <section class="storage-modal storage-preview-modal" role="dialog" aria-modal="true" aria-label="Просмотр файла">
        <div class="storage-modal-head">
          <h2>{{ previewItem.name }}</h2>
          <div><UiButton type="button" variant="secondary" @click="download(previewItem)">Скачать</UiButton>
            <button type="button" aria-label="Закрыть" @click="closePreview">×</button></div>
        </div>
        <div class="storage-preview-content">
          <img v-if="previewUrl && previewMime === 'image'" :src="previewUrl" :alt="previewItem.name" />
          <iframe v-else-if="previewUrl && previewMime === 'pdf'" :src="previewUrl" :title="previewItem.name" sandbox="" />
          <video v-else-if="previewUrl && previewMime === 'video'" :src="previewUrl" controls preload="metadata" class="storage-v1-video"></video>
          <pre v-else-if="previewMime === 'text'">{{ previewText }}</pre>
          <div v-else class="storage-preview-fallback">
            Просмотр этого формата не поддерживается. Файл можно безопасно скачать.
          </div>
        </div>
      </section>
    </div>
    <div v-if="versionsItem" class="storage-overlay" @click.self="versionsItem=null">
      <section class="storage-modal storage-small-modal storage-v1-history" role="dialog" aria-modal="true" aria-label="Версии файла">
        <div class="storage-modal-head"><h2>Версии: {{ versionsItem.name }}</h2><button type="button" @click="versionsItem=null">×</button></div>
        <div v-if="canEdit(versionsItem)" class="storage-v1-version-actions">
          <UiButton type="button" :disabled="busy" @click="newVersionInput?.click()">Загрузить новую версию</UiButton>
          <input ref="newVersionInput" type="file" hidden @change="replaceVersion($event.target.files?.[0])" />
        </div>
        <p v-if="!fileVersions.length">Версий пока нет</p>
        <div v-for="version in fileVersions" :key="version.id" class="storage-v1-grant">
          <span>{{ new Date(version.created_at).toLocaleString('ru-RU') }} · {{ version.size_bytes }} Б
            <strong v-if="version.current"> · Текущая</strong>
          </span>
          <UiButton v-if="!version.current && canEdit(versionsItem)" type="button" variant="secondary"
            :disabled="busy" @click="restoreVersion(version)">Восстановить</UiButton>
        </div>
      </section>
    </div>
    <div v-if="historyItem" class="storage-overlay" @click.self="historyItem=null">
      <section class="storage-modal storage-small-modal storage-v1-history" role="dialog" aria-modal="true" aria-label="История действий с файлом">
        <div class="storage-modal-head"><h2>История: {{ historyItem.name }}</h2><button type="button" @click="historyItem=null">×</button></div>
        <div v-if="!historyEvents.length" class="storage-empty">Записей пока нет</div>
        <table v-else class="storage-table">
          <thead><tr><th>Действие</th><th>Сотрудник</th><th>Дата</th></tr></thead>
          <tbody><tr v-for="(event, index) in historyEvents" :key="index">
            <td>{{ event.action }} · {{ event.outcome }}</td>
            <td>#{{ event.actor_employee_id }}</td>
            <td>{{ new Date(event.at).toLocaleString('ru-RU') }}</td>
          </tr></tbody>
        </table>
      </section>
    </div>
    <div v-if="shareItem" class="storage-overlay" @click.self="shareItem=null">
      <section class="storage-modal storage-v1-share" role="dialog" aria-modal="true" aria-label="Управление доступом">
        <div class="storage-modal-head"><h2>Доступ: {{ shareItem.name }}</h2><button type="button" @click="shareItem=null">×</button></div>
        <label>Сотрудник
          <UiSearchSelect v-model="shareRecipient" :options="directoryOptions" placeholder="Выберите сотрудника" aria-label="Получатель доступа" :teleport="true" />
        </label>
        <label>Право
          <select v-model="shareRole"><option value="reader">Просмотр и скачивание</option><option value="editor">Редактирование и загрузка</option></select>
        </label>
        <div class="storage-modal-actions"><UiButton type="button" :disabled="busy || !shareRecipient" @click="grant">Предоставить доступ</UiButton></div>
        <h3>Предоставленные доступы</h3>
        <p v-if="!grants.length">Пока никому не предоставлен доступ</p>
        <div v-for="grantRow in grants" :key="grantRow.employee_id" class="storage-v1-grant">
          <span>Сотрудник #{{ grantRow.employee_id }} · {{ grantRow.role === 'editor' ? 'Редактирование' : 'Просмотр' }}</span>
          <UiButton type="button" variant="secondary" :disabled="busy" @click="revoke(grantRow.employee_id)">Отозвать</UiButton>
        </div>
        <div class="storage-modal-actions"><UiButton type="button" variant="secondary" @click="shareItem=null">Закрыть</UiButton></div>
      </section>
    </div>
  </section>
</template>
