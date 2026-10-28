<script setup>
import { onMounted, ref } from 'vue';
import { UiButton } from '@irlix/ui';

const entries = ref([]);
const next = ref(null);
const loading = ref(false);
const busy = ref(null);
const error = ref('');
const notice = ref('');
async function request(url, options = {}) {
  const response = await fetch('/api/storage' + url, {
    credentials: 'same-origin',
    cache: 'no-store',
    ...options,
    headers: { ...(options.method ? { 'X-Irlix-Storage-Request': '1' } : {}), ...(options.headers || {}) },
  });
  if (!response.ok) {
    let detail = 'Не удалось выполнить операцию';
    try { detail = (await response.json()).detail || detail; } catch { /* no JSON */ }
    throw new Error(detail);
  }
  return response.json();
}
async function load(more = false) {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const params = next.value && more ? '?continuation=' + encodeURIComponent(next.value) : '';
    const result = await request('/trash' + params);
    entries.value = more ? [...entries.value, ...result.entries] : result.entries;
    next.value = result.next || null;
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
async function restore(entry) {
  if (busy.value) return;
  busy.value = entry.identity;
  error.value = '';
  try {
    const form = new FormData();
    form.append('kind', entry.kind);
    form.append(entry.kind === 'file' ? 'key' : 'path', entry.identity);
    await request('/restore', { method: 'POST', body: form });
    entries.value = entries.value.filter(item => item.identity !== entry.identity);
    notice.value = 'Восстановлено: ' + entry.name;
    // Refresh the current page so the list never shows stale restored objects.
    await load();
  } catch (e) { error.value = e.message; }
  finally { busy.value = null; }
}
function date(value) {
  const dt = new Date(value);
  return Number.isNaN(dt.getTime()) ? '—' : dt.toLocaleString('ru-RU');
}
onMounted(() => load());
</script>

<template>
  <section class="storage-trash">
    <div class="storage-trash-header">
      <p>Удалённые файлы и папки можно восстановить. Содержимое не удаляется из хранилища.</p>
      <UiButton variant="secondary" type="button" :disabled="loading" @click="load()">Обновить</UiButton>
    </div>
    <div v-if="error" class="storage-trash-message" role="alert">{{ error }}</div>
    <div v-if="notice" class="storage-trash-message" role="status">{{ notice }}</div>
    <div class="storage-content">
      <table v-if="entries.length" class="storage-table">
        <thead><tr><th>Название</th><th>Тип</th><th>Удалён</th><th>Действия</th></tr></thead>
        <tbody>
          <tr v-for="entry in entries" :key="entry.kind + ':' + entry.identity">
            <td>{{ entry.name }}</td>
            <td>{{ entry.kind === 'folder' ? 'Папка' : 'Файл' }}</td>
            <td>{{ date(entry.deleted_at) }}</td>
            <td><UiButton variant="secondary" type="button" :disabled="Boolean(busy)" @click="restore(entry)">Восстановить</UiButton></td>
          </tr>
        </tbody>
      </table>
      <div v-else class="storage-empty" role="status">{{ loading ? 'Загружаем корзину…' : 'Корзина пуста' }}</div>
      <div v-if="next" class="storage-load-more"><UiButton variant="secondary" :disabled="loading" @click="load(true)">Показать ещё</UiButton></div>
    </div>
  </section>
</template>
