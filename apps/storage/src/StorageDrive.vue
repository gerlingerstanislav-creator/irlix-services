<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { UiActionMenu, UiButton, UiIcon } from '@irlix/ui';

const emit = defineEmits(['breadcrumbs']);

const route = useRoute();
const router = useRouter();
const folder = computed(() => typeof route.query.folder === 'string' ? route.query.folder : '');
const folders = ref([]);
const files = ref([]);
const next = ref(null);
const loading = ref(false);
const busy = ref(false);
const toast = ref(null);
let toastTimer = null;
function notify(message, kind = 'success') {
  if (toastTimer !== null) window.clearTimeout(toastTimer);
  toast.value = { message, kind };
  toastTimer = window.setTimeout(() => { toast.value = null; toastTimer = null; }, 5000);
}
onBeforeUnmount(() => {
  if (toastTimer !== null) window.clearTimeout(toastTimer);
});
const search = ref('');
const layout = ref('list');
const sortBy = ref('name');
const createFolder = ref(false);
const folderName = ref('');
const selected = ref(null);
const preview = ref(null);
const textPreview = ref('');
const showDrop = ref(false);
const input = ref(null);
const editor = ref(null);
const editedName = ref('');
const properties = ref(null);
const failedThumbs = ref(new Set());
let requestSerial = 0;
const actionsFor = item => item.kind === 'file'
  ? [{ id: 'rename', label: 'Переименовать' }, { id: 'details', label: 'Свойства' }, { id: 'download', label: 'Скачать' }]
  : [{ id: 'rename', label: 'Переименовать' }, { id: 'details', label: 'Свойства' }];
function action(item, id) {
  if (id === 'download') return download(item);
  if (id === 'details') { properties.value = item; return; }
  if (id === 'rename') {
    editor.value = item;
    editedName.value = item.name;
  }
}
const thumbnailUrl = item => '/api/storage/thumbnails?key=' + encodeURIComponent(item.key);
async function renameItem() {
  if (!editor.value || !editedName.value.trim() || busy.value) return;
  const item = editor.value;
  busy.value = true;
  try {
    const body = new FormData();
    body.append('kind', item.kind);
    body.append(item.kind === 'folder' ? 'path' : 'key', item.kind === 'folder' ? item.path : item.key);
    body.append('new_name', editedName.value.trim());
    await api('/rename', { method: 'POST', body });
    editor.value = null;
    notify('Название изменено');
    await refresh();
  } catch (err) { notify(err.message, 'error'); }
  finally { busy.value = false; }
}


const visible = computed(() => {
  const term = search.value.trim().toLocaleLowerCase();
  const items = [
    ...folders.value.map(entry => ({ ...entry, kind: 'folder' })),
    ...files.value.map(entry => ({ ...entry, kind: 'file' })),
  ].filter(item => !term || item.name.toLocaleLowerCase().includes(term));
  return items.sort((a, b) => {
    if (a.kind !== b.kind) return a.kind === 'folder' ? -1 : 1;
    if (sortBy.value === 'size') return (b.size || 0) - (a.size || 0);
    if (sortBy.value === 'date') return (b.modified || '').localeCompare(a.modified || '');
    return a.name.localeCompare(b.name, 'ru', { numeric: true });
  });
});
const totalSize = computed(() => files.value.reduce((sum, item) => sum + (item.size || 0), 0));
const typeOf = filename => {
  const extension = filename.toLocaleLowerCase().split('.').pop();
  if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(extension)) return 'image';
  if (extension === 'pdf') return 'pdf';
  if (['txt', 'csv', 'json', 'xml', 'log'].includes(extension)) return 'text';
  if (['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(extension)) return 'office';
  return 'other';
};
const iconOf = item => item.kind === 'folder' ? '📁' :
  ({ pdf: '📕', image: '🖼️', text: '📄', office: '📘', other: '📄' })[typeOf(item.name)];
const readableSize = size => {
  if (!size) return '0 Б';
  const unit = Math.floor(Math.log(size) / Math.log(1024));
  return (size / 1024 ** unit).toFixed(unit ? 1 : 0).replace('.', ',') + ' ' + ['Б', 'КБ', 'МБ', 'ГБ'][Math.min(unit, 3)];
};
const dateText = date => {
  if (!date) return '—';
  const parsed = new Date(date);
  return Number.isNaN(parsed.valueOf()) ? '—' : new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  }).format(parsed);
};
const urlFor = (item, inline = false) =>
  '/api/storage/files?key=' + encodeURIComponent(item.key) + (inline ? '&preview=true' : '');
async function api(path, options = {}) {
  const response = await fetch('/api/storage' + path, {
    credentials: 'same-origin', cache: 'no-store',
    ...options,
    headers: { ...(options.method ? { 'X-Irlix-Storage-Request': '1' } : {}), ...(options.headers || {}) },
  });
  if (!response.ok) {
    if (response.status === 401 || response.status === 403) {
      throw new Error('Сессия истекла или доступ запрещён. Перейдите на дашборд и войдите повторно.');
    }
    let message = 'Не удалось выполнить операцию';
    try { message = (await response.json()).detail || message; } catch (_) { /* no JSON */ }
    throw new Error(message);
  }
  return response.json();
}
async function refresh(loadMore = false) {
  if (loadMore && loading.value) return;
  loading.value = true;
  const serial = ++requestSerial;
  try {
    const params = new URLSearchParams({ folder: folder.value });
    if (loadMore && next.value) params.set('continuation', next.value);
    const result = await api('/items?' + params);
    if (serial !== requestSerial) return;
    folders.value = loadMore ? [...folders.value, ...result.folders] : result.folders;
    files.value = loadMore ? [...files.value, ...result.files] : result.files;
    next.value = result.next;
    emit('breadcrumbs', result.breadcrumbs || []);
  } catch (err) {
    if (serial === requestSerial) notify(err.message, 'error');
  } finally {
    if (serial === requestSerial) loading.value = false;
  }
}
function go(path) {
  if (path !== folder.value) router.push({ name: 'files', query: path ? { folder: path } : {} });
}
watch(folder, () => {
  folders.value = []; files.value = []; next.value = null;
  selected.value = null; preview.value = null; search.value = '';
  failedThumbs.value = new Set();
  emit('breadcrumbs', []);
  refresh();
});
function open(item) {
  if (item.kind === 'folder') { go(item.path); return; }
  selected.value = item;
  preview.value = item;
  textPreview.value = '';
  if (typeOf(item.name) === 'text' && item.size <= 1024 * 1024) {
    fetch(urlFor(item, true), { credentials: 'same-origin', cache: 'no-store' })
      .then(response => {
        if (!response.ok) throw new Error('Не удалось открыть предпросмотр');
        return response.text();
      }).then(text => { if (preview.value?.key === item.key) textPreview.value = text; })
      .catch(() => { textPreview.value = 'Предпросмотр недоступен'; });
  }
}
function download(item) {
  // The server forces Content-Disposition: attachment and never reveals S3 credentials.
  const link = document.createElement('a');
  link.href = urlFor(item);
  link.download = item.name;
  document.body.appendChild(link); link.click(); link.remove();
}
async function addFolder() {
  if (!folderName.value.trim() || busy.value) return;
  busy.value = true;
  try {
    const body = new FormData();
    body.append('folder', folder.value);
    body.append('folder_name', folderName.value);
    await api('/folders', { method: 'POST', body });
    createFolder.value = false; folderName.value = '';
    notify('Папка создана');
    await refresh();
  } catch (err) { notify(err.message, 'error'); }
  finally { busy.value = false; }
}
async function upload(chosen) {
  const uploadFiles = Array.from(chosen || []);
  if (!uploadFiles.length || busy.value) return;
  if (uploadFiles.some(file => file.size > 10 * 1024 * 1024)) {
    notify('Максимальный размер одного файла — 10 МБ', 'error'); return;
  }
  busy.value = true;
  try {
    for (const file of uploadFiles) {
      const body = new FormData();
      body.append('folder', folder.value);
      body.append('file', file, file.name);
      await api('/files', { method: 'POST', body });
    }
    notify('Загружено файлов: ' + uploadFiles.length);
    await refresh();
  } catch (err) { notify(err.message, 'error'); }
  finally { busy.value = false; if (input.value) input.value.value = ''; }
}
function dropped(event) {
  showDrop.value = false;
  if (event.dataTransfer?.files?.length) upload(event.dataTransfer.files);
}
onMounted(() => refresh());
</script>

<template>
  <div class="storage-shell" @dragenter.prevent="showDrop = true" @dragover.prevent @dragleave="showDrop = false" @drop.prevent="dropped">
      <main class="storage-main">
        <div class="storage-toolbar">
          <div class="storage-toolbar-tools">
            <label class="storage-search"><UiIcon name="search" /><input v-model="search" placeholder="Поиск в папке" aria-label="Поиск в папке" /></label>
            <select v-model="sortBy" aria-label="Сортировка"><option value="name">По названию</option><option value="date">По дате</option><option value="size">По размеру</option></select>
            <div class="storage-switch" role="group" aria-label="Вид файлов">
              <button type="button" :class="{ active: layout === 'list' }" :aria-pressed="layout === 'list'" title="Список" aria-label="Список" @click="layout = 'list'"><UiIcon name="list" /></button>
              <button type="button" :class="{ active: layout === 'grid' }" :aria-pressed="layout === 'grid'" title="Плитки" aria-label="Плитки" @click="layout = 'grid'"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1" /><rect x="13.5" y="3.5" width="7" height="7" rx="1" /><rect x="3.5" y="13.5" width="7" height="7" rx="1" /><rect x="13.5" y="13.5" width="7" height="7" rx="1" /></svg></button>
            </div>
          </div>
          <div class="storage-actions">
            <UiButton variant="secondary" type="button" @click="createFolder = true" :disabled="busy">＋ Папка</UiButton>
            <UiButton type="button" @click="input?.click()" :disabled="busy">{{ busy ? 'Загрузка…' : '↑ Загрузить файлы' }}</UiButton>
            <input ref="input" type="file" hidden multiple @change="upload($event.target.files)" />
          </div>
        </div>
        <div class="storage-content">
          <div v-if="loading && !visible.length" class="storage-empty">Загружаем файлы…</div>
          <div v-else-if="!visible.length" class="storage-empty">
            <div class="storage-empty-icon">▤</div>
            <h2>{{ search ? 'Ничего не найдено' : 'Папка пока пуста' }}</h2>
            <p>{{ search ? 'Попробуйте другой поисковый запрос' : 'Загрузите первые файлы или создайте папку' }}</p>
            <button v-if="!search" class="storage-button primary" @click="input?.click()">↑ Загрузить файлы</button>
          </div>
          <div v-else-if="layout==='list'" class="storage-table-wrap">
            <table class="storage-table">
              <thead><tr><th>Название</th><th>Размер</th><th>Изменён</th><th class="storage-download-cell"></th></tr></thead>
              <tbody><tr v-for="item in visible" :key="item.kind==='folder'?item.path:item.key" @dblclick="open(item)" @click="selected=item" :class="{ selected:selected===item }" tabindex="0" @keydown.enter="open(item)">
                <td><span class="storage-file-icon">{{ iconOf(item) }}</span><button class="storage-file-name" @click.stop="open(item)">{{ item.name }}</button></td>
                <td>{{ item.kind === 'folder' ? 'Папка' : readableSize(item.size) }}</td>
                <td>{{ dateText(item.modified) }}</td>
                <td class="storage-download-cell" @click.stop @dblclick.stop><UiActionMenu :items="actionsFor(item)" :aria-label="'Действия: ' + item.name" @select="action(item, $event)" /></td>
              </tr></tbody>
            </table>
          </div>
          <div v-else class="storage-grid">
            <div v-for="item in visible" :key="item.kind==='folder'?item.path:item.key" class="storage-tile">
              <div class="storage-tile-actions" @click.stop><UiActionMenu :items="actionsFor(item)" :aria-label="'Действия: ' + item.name" @select="action(item, $event)" /></div>
              <button type="button" class="storage-tile-open" @click="open(item)" :aria-label="'Открыть: ' + item.name">
                <span class="storage-tile-icon">
                  <img v-if="item.kind === 'file' && typeOf(item.name) === 'image' && !failedThumbs.has(item.key)"
                       class="storage-thumbnail" :src="thumbnailUrl(item)" :alt="''" loading="lazy" decoding="async"
                       @error="failedThumbs.add(item.key)" />
                  <span v-else>{{ iconOf(item) }}</span>
                </span>
                <strong :title="item.name">{{ item.name }}</strong>
                <small>{{ item.kind==='folder'?'Папка':readableSize(item.size) }}</small>
              </button>
            </div>
          </div>
          <div v-if="next" class="storage-load-more"><button class="storage-button secondary" :disabled="loading" @click="refresh(true)">Показать ещё</button></div>
        </div>
        <div class="storage-footer"><span>{{ folders.length }} папок · {{ files.length }} файлов</span><span>{{ readableSize(totalSize) }} · показана текущая папка</span></div>
      </main>
    <div v-if="createFolder" class="storage-overlay" @click.self="createFolder=false">
      <form class="storage-modal storage-small-modal" @submit.prevent="addFolder">
        <div class="storage-modal-head"><h2>Новая папка</h2><button type="button" @click="createFolder=false">×</button></div>
        <label>Название папки<input v-model="folderName" placeholder="Например, Документы" maxlength="80" autofocus required /></label>
        <div class="storage-modal-actions"><button type="button" class="storage-button secondary" @click="createFolder=false">Отмена</button><button class="storage-button primary" :disabled="busy||!folderName.trim()">Создать</button></div>
      </form>
    </div>
    <div v-if="editor" class="storage-overlay" @click.self="!busy && (editor=null)">
      <form class="storage-modal storage-small-modal" @submit.prevent="renameItem">
        <div class="storage-modal-head"><h2>Переименовать {{ editor.kind === 'folder' ? 'папку' : 'файл' }}</h2><button type="button" :disabled="busy" @click="editor=null">×</button></div>
        <label>Название<input v-model="editedName" maxlength="160" required autofocus /></label>
        <p v-if="editor.kind === 'file'" class="storage-rename-hint">Расширение файла менять нельзя: оно используется для безопасного предпросмотра.</p>
        <div class="storage-modal-actions"><UiButton type="button" variant="secondary" :disabled="busy" @click="editor=null">Отмена</UiButton><UiButton :disabled="busy || !editedName.trim() || editedName.trim() === editor.name">Сохранить</UiButton></div>
      </form>
    </div>
    <div v-if="properties" class="storage-overlay" @click.self="properties=null">
      <section class="storage-modal storage-small-modal" role="dialog" aria-modal="true" aria-label="Свойства объекта">
        <div class="storage-modal-head"><h2>Свойства</h2><button type="button" @click="properties=null">×</button></div>
        <dl class="storage-properties"><dt>Название</dt><dd>{{ properties.name }}</dd><dt>Тип</dt><dd>{{ properties.kind === 'folder' ? 'Папка' : 'Файл' }}</dd><dt v-if="properties.kind === 'file'">Размер</dt><dd v-if="properties.kind === 'file'">{{ readableSize(properties.size) }}</dd><dt v-if="properties.kind === 'file'">Изменён</dt><dd v-if="properties.kind === 'file'">{{ dateText(properties.modified) }}</dd></dl>
        <div class="storage-modal-actions"><UiButton type="button" variant="secondary" @click="properties=null">Закрыть</UiButton></div>
      </section>
    </div>
    <div v-if="preview" class="storage-overlay" @click.self="preview=null">
      <div class="storage-modal storage-preview-modal" role="dialog" aria-modal="true" aria-label="Просмотр файла">
        <div class="storage-modal-head"><div><strong>{{ preview.name }}</strong><small>{{ readableSize(preview.size) }} · {{ dateText(preview.modified) }}</small></div>
          <div><button class="storage-button secondary" @click="download(preview)">↓ Скачать</button><button title="Закрыть" @click="preview=null">×</button></div>
        </div>
        <div class="storage-preview-content">
          <img v-if="typeOf(preview.name)==='image'" :src="urlFor(preview,true)" :alt="preview.name" />
          <iframe v-else-if="typeOf(preview.name)==='pdf'" :src="urlFor(preview,true)" :title="preview.name" />
          <pre v-else-if="typeOf(preview.name)==='text' && preview.size<=1024*1024">{{ textPreview || 'Загрузка текста…' }}</pre>
          <div v-else class="storage-preview-fallback"><div class="storage-preview-symbol">{{ iconOf(preview) }}</div>
            <h3>Предпросмотр пока недоступен</h3><p>Этот формат можно скачать. Просмотр Word, Excel и презентаций добавим отдельно.</p>
            <button class="storage-button primary" @click="download(preview)">Скачать файл</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="toast" class="storage-toast" :class="`storage-toast--${toast.kind}`" :role="toast.kind === 'error' ? 'alert' : 'status'"><span>{{ toast.message }}</span><a v-if="toast.message.includes('Сессия')" href="/">На дашборд</a><button type="button" aria-label="Закрыть уведомление" @click="toast = null">×</button></div>
    <div v-if="showDrop" class="storage-dropzone">Отпустите файлы, чтобы загрузить их в папку</div>
  </div>
</template>
