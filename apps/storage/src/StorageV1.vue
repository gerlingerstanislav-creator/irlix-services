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
let refreshSerial = 0;
const busy = ref(false);
const error = ref('');
const notice = ref('');
const createName = ref('');
const createFolderOpen = ref(false);
const permanentDeleteEnabled = ref(false);
const purgeItem = ref(null);
const purgeConfirmName = ref('');
const purgeError = ref('');
const uploadInput = ref(null);
const dropActive = ref(false);
const dropTargetId = ref('');
const shareItem = ref(null);
const shareRecipients = ref([]);
const shareRole = ref('reader');
const grants = ref([]);
const shareLoading = ref(false);
const shareError = ref('');
const shareFeedback = ref('');
const recipientNames = computed(() => new Map(directoryOptions.value
  .filter(option => option.kind !== 'group')
  .map(option => [Number(option.value), option.label])));
const recipientName = id => recipientNames.value.get(Number(id)) || 'Сотрудник #' + id;
const grantLabel = role => role === 'editor' ? 'Редактирование и загрузка' : 'Просмотр и скачивание';
function removeRecipient(id) {
  shareRecipients.value = shareRecipients.value.filter(selectedId => Number(selectedId) !== Number(id));
}
function syncShareBadge(item) {
  const count = grants.value.length;
  entries.value = entries.value.map(entry => entry.id === item.id ? { ...entry, shared_count: count } : entry);
  if (shareItem.value?.id === item.id) shareItem.value = { ...shareItem.value, shared_count: count };
}
const historyItem = ref(null);
const historyEvents = ref([]);
const versionsItem = ref(null);
const previewItem = ref(null);
const previewUrl = ref('');
const previewMime = ref('');
const previewText = ref('');
const previewLoading = ref(false);
const previewError = ref('');
const previewZoom = ref(1);
let previewSerial = 0;
const previewImages = computed(() => visibleEntries.value.filter(isImage));
const previewIndex = computed(() => previewImages.value.findIndex(item => item.id === previewItem.value?.id));
function navigatePreview(direction) {
  if (!isImage(previewItem.value || {})) return;
  const images = previewImages.value;
  if (images.length < 2 || previewIndex.value < 0) return;
  void openPreview(images[(previewIndex.value + direction + images.length) % images.length]);
}
function onPreviewKeydown(event) {
  if (!previewItem.value || event.altKey || event.ctrlKey || event.metaKey) return;
  if (event.key === 'Escape') { event.preventDefault(); closePreview(); }
  if (event.key === 'ArrowLeft' && isImage(previewItem.value)) { event.preventDefault(); navigatePreview(-1); }
  if (event.key === 'ArrowRight' && isImage(previewItem.value)) { event.preventDefault(); navigatePreview(1); }
}
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
const typeOf = item => {
  if (item.kind === 'folder') return 'folder';
  const ext = String(item.name).split('.').pop().toLowerCase();
  if (ext === 'pdf') return 'pdf';
  if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp'].includes(ext)) return 'image';
  if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return 'sheet';
  if (['doc', 'docx', 'odt', 'rtf'].includes(ext)) return 'document';
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return 'archive';
  if (['mp4', 'webm', 'avi', 'mov'].includes(ext)) return 'video';
  if (['mp3', 'wav', 'ogg', 'flac'].includes(ext)) return 'audio';
  if (['js', 'ts', 'jsx', 'tsx', 'json', 'xml', 'py', 'html', 'css'].includes(ext)) return 'code';
  return 'file';
};
const typeLabel = item => ({
  pdf: 'PDF', image: 'IMG', sheet: 'XLS', document: 'DOC', archive: 'ZIP',
  video: 'VID', audio: 'MP3', code: 'CODE', file: 'FILE',
})[typeOf(item)] || '';
const typeIcon = item => item.kind === 'folder' ? 'folder' : 'file-text';
const isImage = item => item.kind === 'file' && /\.(png|jpe?g|gif|webp)$/i.test(item.name);
const thumbnailUrl = item => '/api/storage/v1/thumbnail/' + encodeURIComponent(item.id);
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
    ? [...(isImage(item) ? [{ id: 'preview', label: 'Просмотреть' }] : []),
       { id: 'download', label: 'Скачать' }, { id: 'copy', label: 'Создать копию' }] : []),
  ...(canEdit(item) && mode.value !== 'trash'
    ? [{ id: 'rename', label: 'Переименовать' },
       ...(item.kind === 'file' ? [{ id: 'versions', label: 'Версии файла' }] : []) ] : []),
  ...(canManage(item) && mode.value !== 'trash' ? [
    { id: 'move', label: 'Переместить' }, { id: 'share', label: 'Настроить доступ' },
    { id: 'history', label: 'История действий' }, { id: 'trash', label: 'В корзину', danger: true },
  ] : []),
  ...(mode.value === 'trash' && canManage(item) ? [
    ...(!item.purge_pending ? [{ id: 'restore', label: 'Восстановить' }] : []),
    ...(permanentDeleteEnabled.value && item.kind === 'file'
      ? [{ id: 'purge', label: item.purge_pending ? 'Повторить полное удаление' : 'Удалить навсегда', danger: true }] : []),
  ] : []),
];
const showingNormal = computed(() => mode.value !== 'trash');
const canCreate = computed(() => mode.value === 'mine' || (mode.value === 'shared' && Boolean(parentId.value)));
const canDropIntoCurrent = computed(() => canCreate.value && !searchActive.value && !busy.value && !loading.value);
const isFileDrag = event => Array.from(event.dataTransfer?.types || []).includes('Files');
const canDropIntoFolder = item => item.kind === 'folder' && mode.value !== 'trash'
  && !busy.value && !loading.value && canEdit(item);
function clearDropState() {
  dropActive.value = false;
  dropTargetId.value = '';
}
function onAreaDragOver(event) {
  if (!isFileDrag(event)) return;
  event.preventDefault(); // Avoid the browser opening the file in this tab.
  event.dataTransfer.dropEffect = canDropIntoCurrent.value ? 'copy' : 'none';
  if (canDropIntoCurrent.value) {
    dropActive.value = true;
    dropTargetId.value = parentId.value;
  } else {
    clearDropState();
  }
}
function dragLeftElement(event) {
  if (event.relatedTarget && event.currentTarget.contains(event.relatedTarget)) return false;
  const box = event.currentTarget.getBoundingClientRect();
  // Chromium may report relatedTarget=null while traversing child elements.
  return event.clientX <= box.left || event.clientX >= box.right
    || event.clientY <= box.top || event.clientY >= box.bottom;
}
function onAreaDragLeave(event) {
  if (dragLeftElement(event)) clearDropState();
}
function onFolderDragOver(event, item) {
  if (!isFileDrag(event)) return;
  event.stopPropagation();
  if (item.kind !== 'folder') {
    onAreaDragOver(event); // Dropping over an existing file uploads beside it.
    return;
  }
  event.preventDefault();
  if (!canDropIntoFolder(item)) {
    event.dataTransfer.dropEffect = 'none';
    clearDropState();
    return;
  }
  event.dataTransfer.dropEffect = 'copy';
  dropActive.value = true;
  dropTargetId.value = item.id;
}
function onFolderDragLeave(event, item) {
  if (dropTargetId.value === item.id && dragLeftElement(event)) clearDropState();
}
function droppedFiles(event) {
  const items = Array.from(event.dataTransfer?.items || []);
  if (items.some(item => item.kind === 'file' && item.webkitGetAsEntry?.()?.isDirectory)) {
    error.value = 'Папки перетаскиванием пока не поддерживаются. Выберите файлы внутри папки.';
    return [];
  }
  return Array.from(event.dataTransfer?.files || []).filter(file => file instanceof File);
}
function onAreaDrop(event) {
  if (!isFileDrag(event)) return;
  event.preventDefault();
  event.stopPropagation();
  const allowed = canDropIntoCurrent.value;
  const destination = parentId.value;
  clearDropState();
  if (!allowed) {
    error.value = 'Загрузка здесь недоступна. Откройте папку с правом редактирования.';
    return;
  }
  const files = droppedFiles(event);
  if (files.length) void uploadFiles(files, destination);
}
function onFolderDrop(event, item) {
  if (!isFileDrag(event)) return;
  if (item.kind !== 'folder') {
    onAreaDrop(event);
    return;
  }
  event.preventDefault();
  event.stopPropagation();
  const allowed = canDropIntoFolder(item);
  clearDropState();
  if (!allowed) {
    error.value = 'Нет права загружать файлы в выбранную папку.';
    return;
  }
  const files = droppedFiles(event);
  if (files.length) void uploadFiles(files, item.id);
}

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
    let message = response.status === 507 ? 'На сервере недостаточно места для загрузки файла.'
      : response.status === 503 ? 'Файловое хранилище временно недоступно.'
      : response.status >= 500 ? 'Ошибка файлового сервера (HTTP ' + response.status + ').'
      : 'Операция не выполнена';
    try { message = (await response.json()).detail || message; } catch { /* empty response */ }
    throw new Error(message);
  }
  return response.json();
}
async function refresh() {
  const serial = ++refreshSerial;
  loading.value = true; error.value = '';
  try {
    const url = searchActive.value ? '/search?q=' + encodeURIComponent(searchQuery.value.trim())
      : mode.value === 'trash' ? '/trash'
      : mode.value === 'shared' && !parentId.value ? '/available'
      : '/items' + (parentId.value ? '?parent_id=' + encodeURIComponent(parentId.value)
                                     : '?scope=mine');
    const result = await request(url);
    if (serial !== refreshSerial) return;
    entries.value = Array.isArray(result.entries) ? result.entries : [];
    permanentDeleteEnabled.value = mode.value === 'trash' && result.permanent_delete_enabled === true;
    clearSelection();
    try { const data = await request('/stats'); if (serial === refreshSerial) stats.value = data; }
    catch { if (serial === refreshSerial) stats.value = null; }
  } catch (err) { if (serial === refreshSerial) { entries.value = []; error.value = err.message; } }
  finally { if (serial === refreshSerial) loading.value = false; }
}
async function bulkAction(action) {
  const chosen = selectedItems.value.filter(item => !item.purge_pending && (action === 'restore' || canManage(item)));
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
  // Invalidate any in-flight preview, including a slow request returning after close.
  previewSerial++;
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = '';
  previewItem.value = null;
  previewText.value = '';
  previewMime.value = '';
  previewError.value = '';
  previewLoading.value = false;
  previewZoom.value = 1;
}
async function openPreview(item) {
  closePreview();
  previewItem.value = item;
  const serial = previewSerial;
  const extension = String(item.name).split('.').pop().toLowerCase();
  const kinds = {
    pdf: 'pdf', png: 'image', jpg: 'image', jpeg: 'image',
    gif: 'image', webp: 'image', txt: 'text', csv: 'text',
    log: 'text', mp4: 'video', webm: 'video',
  };
  if (!kinds[extension]) { previewMime.value = 'unsupported'; return; }
  previewLoading.value = true;
  try {
    // A short-lived Employees session is renewed by authenticatedFetch; never
    // expose S3 URLs or use a permanent public link for private previews.
    const response = await authenticatedFetch('/preview/' + encodeURIComponent(item.id));
    if (serial !== previewSerial) return;
    if (!response.ok) throw new Error(response.status === 403
      ? 'Нет доступа к просмотру этого файла.'
      : 'Не удалось открыть файл (HTTP ' + response.status + ').');
    const contentType = (response.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
    if (kinds[extension] === 'image' && !['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(contentType)) {
      throw new Error('Сервер вернул неподдерживаемый формат изображения.');
    }
    const bytes = await response.blob();
    if (serial !== previewSerial) return;
    previewMime.value = kinds[extension];
    if (kinds[extension] === 'text') {
      const value = await bytes.text();
      if (serial === previewSerial) previewText.value = value;
    } else {
      previewUrl.value = URL.createObjectURL(bytes);
    }
  } catch (err) {
    if (serial !== previewSerial) return;
    previewError.value = err.message || 'Не удалось открыть предпросмотр.';
  } finally {
    if (serial === previewSerial) previewLoading.value = false;
  }
}
onMounted(() => document.addEventListener('keydown', onPreviewKeydown));
onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  document.removeEventListener('keydown', onPreviewKeydown);
  closePreview();
});
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
async function uploadFiles(chosen, destinationId = parentId.value) {
  const files = Array.from(chosen || []);
  if (!files.length || busy.value) return;
  busy.value = true; error.value = ''; notice.value = '';
  clearDropState();
  const failed = [];
  let uploaded = 0;
  for (const file of files) {
    try {
      const data = new FormData();
      data.append('file', file);
      data.append('parent_id', destinationId);
      await request('/files', { method: 'POST', body: data });
      uploaded++;
    } catch (err) {
      failed.push(file.name + ': ' + err.message);
    }
  }
  try {
    if (uploaded) {
      await refresh();
      notice.value = 'Загружено файлов: ' + uploaded + ' из ' + files.length;
    }
    if (failed.length) error.value = 'Не удалось загрузить: ' + failed.join('; ');
  } finally {
    busy.value = false;
    if (uploadInput.value) uploadInput.value.value = '';
  }
}
function openPurge(item) {
  if (!permanentDeleteEnabled.value || mode.value !== 'trash' || item.kind !== 'file' || !canManage(item)) return;
  purgeItem.value = item;
  purgeConfirmName.value = '';
  purgeError.value = '';
}
async function submitPurge() {
  const item = purgeItem.value;
  if (!item || busy.value || purgeConfirmName.value !== item.name) return;
  busy.value = true;
  purgeError.value = '';
  error.value = '';
  try {
    await request('/objects/' + encodeURIComponent(item.id) + '/purge', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ confirm_name: purgeConfirmName.value }),
    });
    purgeItem.value = null;
    purgeConfirmName.value = '';
    notice.value = 'Рабочая копия и все версии файла удалены навсегда';
    await refresh();
  } catch (err) {
    purgeError.value = err.message || 'Удаление не удалось. Можно повторить.';
    await refresh();
  } finally {
    busy.value = false;
  }
}
async function mutate(item, action) {
  if (busy.value) return;
  if (action === 'purge') return openPurge(item);
  if (action === 'preview') return openPreview(item);
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
  } catch (err) { throw err; }
}
async function openSharing(item) {
  shareItem.value = item; grants.value = []; shareRecipients.value = [];
  shareRole.value = 'reader'; shareError.value = ''; shareFeedback.value = '';
  shareLoading.value = true;
  try {
    await Promise.all([loadDirectory(), loadGrants(item)]);
  } catch (err) {
    shareError.value = err.message;
  } finally {
    shareLoading.value = false;
  }
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
  const result = await request('/objects/' + encodeURIComponent(item.id) + '/grants');
  if (shareItem.value?.id !== item.id) return;
  grants.value = Array.isArray(result.entries) ? result.entries : [];
  syncShareBadge(item);
}
async function grant() {
  if (!shareItem.value || !shareRecipients.value.length || busy.value) return;
  const item = shareItem.value;
  const targetIds = [...new Set(shareRecipients.value.map(Number))]
    .filter(id => Number.isSafeInteger(id) && id > 0);
  if (!targetIds.length) return;
  busy.value = true; shareError.value = ''; shareFeedback.value = '';
  const failures = [];
  let succeeded = 0;
  // A separate authorized, audited request for each recipient preserves the
  // existing V1 ACL contract. Failed recipients remain selected for retry.
  for (const employeeId of targetIds) {
    try {
      await request('/objects/' + encodeURIComponent(item.id) + '/grants', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: employeeId, role: shareRole.value }),
      });
      succeeded++;
    } catch (err) {
      failures.push({ id: employeeId, message: err.message });
    }
  }
  shareRecipients.value = failures.map(entry => entry.id);
  try {
    await loadGrants(item);
  } catch (err) {
    shareError.value = 'Не удалось обновить список доступов: ' + err.message;
  }
  if (succeeded) {
    shareFeedback.value = 'Доступ предоставлен: ' + succeeded + ' из ' + targetIds.length + '.';
    notice.value = 'Доступ предоставлен: ' + succeeded;
  }
  if (failures.length) {
    shareError.value = 'Не удалось предоставить доступ: '
      + failures.map(entry => recipientName(entry.id) + ' — ' + entry.message).join('; ');
  }
  busy.value = false;
}
async function revoke(employeeId) {
  if (!shareItem.value || busy.value) return;
  busy.value = true; shareError.value = ''; shareFeedback.value = '';
  try {
    await request('/objects/' + encodeURIComponent(shareItem.value.id) + '/grants/' + employeeId,
      { method: 'DELETE' });
    await loadGrants(shareItem.value);
    shareFeedback.value = 'Доступ отозван';
    notice.value = 'Доступ отозван';
  } catch (err) { shareError.value = err.message; }
  finally { busy.value = false; }
}
watch(mode, () => {
  clearDropState();
  searchActive.value = false;
  searchQuery.value = '';
  clearTimeout(searchTimer);
  clearSelection();
  refresh();
});
watch(parentId, () => {
  clearDropState();
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
        <div class="storage-sort">
          <select v-model="sortBy" aria-label="Сортировка">
            <option value="name">По названию</option>
            <option value="date">По дате</option>
            <option value="size">По размеру</option>
          </select>
        </div>
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
    <div class="storage-content storage-v1-drop-area" :class="{ 'storage-drop-active': dropActive && dropTargetId === parentId }"
      @dragover="onAreaDragOver" @dragleave="onAreaDragLeave" @drop="onAreaDrop">
      <div v-if="dropActive && dropTargetId === parentId && canDropIntoCurrent"
        class="storage-v1-drop-hint" role="status" aria-live="polite">
        <UiIcon name="upload-cloud" /> Отпустите файлы, чтобы загрузить в текущую папку
      </div>
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
              :class="{ 'storage-v1-folder-drop-target': dropActive && dropTargetId === item.id && item.kind === 'folder' }"
              @dragover="onFolderDragOver($event, item)"
              @dragleave="onFolderDragLeave($event, item)" @drop="onFolderDrop($event, item)"
              @dblclick="open(item)" @keydown.enter="open(item)">
              <td>
                <UiCheckbox :model-value="selected.has(item.id)" :disabled="busy"
                  :aria-label="'Выбрать: ' + item.name"
                  @update:model-value="toggleSelected(item, $event)" />
                <span class="storage-file-icon" :class="'storage-type--' + typeOf(item)" aria-hidden="true">
                  <span v-if="item.kind === 'folder'">📁</span>
                  <template v-else><UiIcon name="file-text" /><small>{{ typeLabel(item) }}</small></template>
                </span>
                <button type="button" class="storage-file-name" @click.stop="open(item)">{{ item.name }}</button>
                <button v-if="canManage(item) && item.shared_count > 0" type="button"
                  class="storage-shared-badge" :title="'Доступ предоставлен: ' + item.shared_count"
                  :aria-label="'Управлять доступом к файлу: ' + item.name"
                  @click.stop="openSharing(item)"><UiIcon name="users" />
                  <span>Доступ: {{ item.shared_count }}</span></button>
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
        <div v-for="item in visibleEntries" :key="item.id" class="storage-tile"
          :class="{ 'storage-v1-folder-drop-target': dropActive && dropTargetId === item.id && item.kind === 'folder' }"
          @dragover="onFolderDragOver($event, item)"
          @dragleave="onFolderDragLeave($event, item)" @drop="onFolderDrop($event, item)">
          <div class="storage-v1-tile-select">
            <UiCheckbox :model-value="selected.has(item.id)" :disabled="busy"
              :aria-label="'Выбрать: ' + item.name"
              @update:model-value="toggleSelected(item, $event)" />
          </div>
          <div class="storage-tile-actions">
            <UiActionMenu :items="actionsFor(item)"
              :aria-label="'Действия: ' + item.name" @select="mutate(item, $event)" />
          </div>
          <button type="button" class="storage-tile-open" :title="item.name" @click="open(item)">
            <span class="storage-tile-media">
              <span class="storage-tile-icon">
                <img v-if="isImage(item) && mode !== 'trash'" class="storage-thumbnail"
                  :src="thumbnailUrl(item)" alt="" loading="lazy" decoding="async" />
                <span v-else class="storage-type-large" :class="'storage-type--' + typeOf(item)" aria-hidden="true">
                  <span v-if="item.kind === 'folder'">📁</span>
                  <template v-else><UiIcon name="file-text" /><small>{{ typeLabel(item) }}</small></template>
                </span>
              </span>
            </span>
            <strong class="storage-tile-title">{{ item.name }}</strong>
            <small class="storage-tile-size">{{ displayedSize(item) }}</small>
            <span class="storage-tile-sharing">
              <span v-if="canManage(item) && item.shared_count > 0" class="storage-shared-indicator"
                :title="'Доступ предоставлен: ' + item.shared_count"><UiIcon name="users" />
                Доступ: {{ item.shared_count }}</span>
            </span>
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

    <div v-if="purgeItem" class="storage-overlay" @click.self="!busy && (purgeItem=null)">
      <form class="storage-modal storage-small-modal storage-v1-purge" @submit.prevent="submitPurge">
        <div class="storage-modal-head">
          <h2>Удалить файл навсегда?</h2>
          <button type="button" :disabled="busy" aria-label="Закрыть" @click="purgeItem=null">×</button>
        </div>
        <p>Из рабочего хранилища будут удалены файл <strong>{{ purgeItem.name }}</strong> и все его версии.
          Восстановить его через корзину нельзя. Резервные архивы сохраняются до истечения своего срока хранения.</p>
        <p v-if="purgeItem.purge_pending" role="status">Предыдущая попытка завершилась не полностью.
          Повторение продолжит удаление оставшихся версий.</p>
        <label>Для подтверждения введите точное название файла:
          <input v-model="purgeConfirmName" :disabled="busy" :placeholder="purgeItem.name"
            autocomplete="off" maxlength="160" required />
        </label>
        <p v-if="purgeError" class="storage-v1-purge-error" role="alert">{{ purgeError }}</p>
        <div class="storage-modal-actions">
          <UiButton type="button" variant="secondary" :disabled="busy" @click="purgeItem=null">Отмена</UiButton>
          <UiButton :disabled="busy || purgeConfirmName !== purgeItem.name">
            {{ busy ? 'Удаляем…' : 'Удалить навсегда' }}
          </UiButton>
        </div>
      </form>
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
          <h2 :title="previewItem.name">{{ previewItem.name }}</h2>
          <div><button type="button" class="storage-preview-download" @click="download(previewItem)">
              <span aria-hidden="true">↓</span> Скачать
            </button>
            <button type="button" aria-label="Закрыть" @click="closePreview">×</button></div>
        </div>
        <div v-if="isImage(previewItem)" class="storage-image-controls" aria-label="Управление просмотром изображений">
          <div class="storage-image-controls-group">
            <button type="button" :disabled="previewImages.length < 2" aria-label="Предыдущее изображение" @click="navigatePreview(-1)">‹</button>
            <span>{{ previewIndex >= 0 ? previewIndex + 1 : '—' }} из {{ previewImages.length }}</span>
            <button type="button" :disabled="previewImages.length < 2" aria-label="Следующее изображение" @click="navigatePreview(1)">›</button>
          </div>
          <div class="storage-image-controls-group">
            <button type="button" :disabled="previewZoom <= 1" aria-label="Уменьшить изображение" @click="previewZoom = Math.max(1, previewZoom - 0.5)">−</button>
            <button type="button" aria-label="Сбросить масштаб" @click="previewZoom = 1">{{ Math.round(previewZoom * 100) }}%</button>
            <button type="button" :disabled="previewZoom >= 3" aria-label="Увеличить изображение" @click="previewZoom = Math.min(3, previewZoom + 0.5)">+</button>
          </div>
        </div>
        <div class="storage-preview-content" :class="{ 'storage-image-preview-content': isImage(previewItem) }">
          <div v-if="previewLoading" class="storage-preview-state" role="status">Загружаем файл…</div>
          <div v-else-if="previewError" class="storage-preview-state" role="alert">{{ previewError }}</div>
          <img v-else-if="previewUrl && previewMime === 'image'" :src="previewUrl" :alt="previewItem.name"
            :style="previewZoom > 1 ? { width: (previewZoom * 100) + '%', maxWidth: 'none', maxHeight: 'none' } : {}"
            @error="previewError = 'Не удалось отобразить изображение.'" />
          <iframe v-else-if="previewUrl && previewMime === 'pdf'" :src="previewUrl" :title="previewItem.name" />
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
    <div v-if="shareItem" class="storage-overlay" @click.self="!busy && (shareItem=null)">
      <section class="storage-modal storage-v1-share" role="dialog" aria-modal="true" aria-labelledby="storage-share-title">
        <div class="storage-modal-head">
          <h2 id="storage-share-title">Управление доступом</h2>
          <button type="button" aria-label="Закрыть" :disabled="busy" @click="shareItem=null">×</button>
        </div>
        <p class="storage-share-filename" :title="shareItem.name">{{ shareItem.name }}</p>
        <div v-if="shareError" class="storage-share-error" role="alert">{{ shareError }}</div>
        <div v-if="shareFeedback" class="storage-share-feedback" role="status">{{ shareFeedback }}</div>
        <div class="storage-share-form">
          <label>Сотрудники
            <UiSearchSelect v-model="shareRecipients" :options="directoryOptions"
              placeholder="Выберите сотрудников" aria-label="Получатели доступа"
              :multiple="true" :disabled="busy || shareLoading" :teleport="true" />
            <div v-if="shareRecipients.length" class="storage-recipient-chips" aria-label="Выбранные сотрудники">
              <button v-for="employeeId in shareRecipients" :key="employeeId" type="button"
                :disabled="busy" class="storage-recipient-chip"
                :aria-label="'Убрать из выбора: ' + recipientName(employeeId)"
                @click.stop="removeRecipient(employeeId)">
                <span>{{ recipientName(employeeId) }}</span><span aria-hidden="true">×</span>
              </button>
            </div>
            <small class="storage-share-hint">Можно выбрать нескольких сотрудников. Всем будет назначено указанное ниже право.</small>
          </label>
          <label>Уровень доступа
            <select v-model="shareRole" :disabled="busy || shareLoading">
              <option value="reader">Просмотр и скачивание</option>
              <option value="editor">Редактирование и загрузка</option>
            </select>
          </label>
          <div class="storage-share-submit">
            <UiButton type="button" :disabled="busy || shareLoading || !shareRecipients.length"
              @click="grant">Предоставить доступ<span v-if="shareRecipients.length"> ({{ shareRecipients.length }})</span></UiButton>
          </div>
        </div>
        <div class="storage-share-list">
          <h3>Кому предоставлен доступ <span v-if="!shareLoading">({{ grants.length }})</span></h3>
          <p v-if="shareLoading" class="storage-share-empty" role="status">Загрузка разрешений…</p>
          <p v-else-if="!grants.length" class="storage-share-empty">Пока никому не предоставлен доступ</p>
          <div v-for="grantRow in grants" :key="grantRow.employee_id" class="storage-v1-grant">
            <div class="storage-grant-person">
              <strong>{{ recipientName(grantRow.employee_id) }}</strong>
              <small>{{ grantLabel(grantRow.role) }}</small>
            </div>
            <UiButton type="button" variant="secondary" :disabled="busy"
              :aria-label="'Отозвать доступ: ' + recipientName(grantRow.employee_id)"
              @click="revoke(grantRow.employee_id)">Отозвать</UiButton>
          </div>
        </div>
        <div class="storage-modal-actions"><UiButton type="button" variant="secondary"
          :disabled="busy" @click="shareItem=null">Закрыть</UiButton></div>
      </section>
    </div>
  </section>
</template>
