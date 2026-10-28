<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { UiButton, UiActionMenu, UiSearchSelect } from '@irlix/ui';

const props = defineProps({
  access: { type: Object, default: null },
  directoryFetch: { type: Function, required: true },
});
const route = useRoute();
const router = useRouter();
const parentId = computed(() => typeof route.query.folder_id === 'string' ? route.query.folder_id : '');
const mode = ref('mine');
const entries = ref([]);
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
    const url = mode.value === 'trash' ? '/trash'
      : mode.value === 'shared' && !parentId.value ? '/available'
      : '/items' + (parentId.value ? '?parent_id=' + encodeURIComponent(parentId.value) : '');
    const result = await request(url);
    entries.value = Array.isArray(result.entries) ? result.entries : [];
  } catch (err) { entries.value = []; error.value = err.message; }
  finally { loading.value = false; }
}
function setMode(value) {
  mode.value = value;
  if (parentId.value) router.push({ name: 'v1', query: {} });
  else refresh();
}
function open(item) {
  if (item.kind === 'folder' && mode.value !== 'trash') {
    router.push({ name: 'v1', query: { folder_id: item.id } });
  } else if (item.kind === 'file' && mode.value !== 'trash') {
    download(item);
  }
}
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
watch(parentId, () => refresh());
onMounted(() => refresh());
</script>

<template>
  <section class="storage-v1">
    <div class="storage-v1-toolbar">
      <div class="storage-v1-sections" role="group" aria-label="Раздел">
        <UiButton v-for="part in [{id:'mine',label:'Мои файлы'},{id:'shared',label:'Доступные мне'},{id:'trash',label:'Корзина'}]"
          :key="part.id" type="button" :variant="mode === part.id ? 'primary' : 'secondary'" @click="setMode(part.id)">{{ part.label }}</UiButton>
        <UiButton v-if="parentId" type="button" variant="secondary" @click="router.push({ name: 'v1' })">← К корню</UiButton>
      </div>
      <div class="storage-v1-actions" v-if="canCreate">
        <UiButton type="button" variant="secondary" :disabled="busy" @click="createFolderOpen = true">+ Папка</UiButton>
        <UiButton type="button" :disabled="busy" @click="uploadInput?.click()">↑ Загрузить</UiButton>
        <input ref="uploadInput" type="file" hidden multiple @change="uploadFiles($event.target.files)" />
      </div>
    </div>
    <p class="storage-v1-notice">Отдельное файловое пространство V1. Ранее загруженные пилотные документы сюда не переносились и не открываются другим сотрудникам автоматически.</p>
    <p v-if="error" class="storage-v1-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="storage-v1-notice" role="status">{{ notice }}</p>
    <div class="storage-content">
      <div v-if="loading" class="storage-empty" role="status">Загружаем файлы…</div>
      <div v-else-if="!entries.length" class="storage-empty">Здесь пока нет файлов</div>
      <table v-else class="storage-table">
        <thead><tr><th>Название</th><th>Тип</th><th>Владелец</th><th>Действия</th></tr></thead>
        <tbody>
          <tr v-for="item in entries" :key="item.id">
            <td><button type="button" class="storage-v1-item" @click="open(item)">{{ item.kind === 'folder' ? '📁' : '📄' }} {{ item.name }}</button></td>
            <td>{{ item.kind === 'folder' ? 'Папка' : 'Файл' }}</td>
            <td>{{ item.owner_employee_id || '—' }}</td>
            <td><UiActionMenu :items="actionsFor(item)" :aria-label="'Действия: ' + item.name" @select="mutate(item, $event)" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="createFolderOpen" class="storage-overlay" @click.self="createFolderOpen=false">
      <form class="storage-modal storage-small-modal" @submit.prevent="createFolder">
        <div class="storage-modal-head"><h2>Новая папка</h2><button type="button" @click="createFolderOpen=false">×</button></div>
        <label>Название<input v-model="createName" maxlength="160" required /></label>
        <div class="storage-modal-actions"><UiButton type="button" variant="secondary" @click="createFolderOpen=false">Отмена</UiButton><UiButton :disabled="busy || !createName.trim()">Создать</UiButton></div>
      </form>
    </div>

    <div v-if="operationItem" class="storage-overlay" @click.self="!busy && (operationItem=null)">
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
