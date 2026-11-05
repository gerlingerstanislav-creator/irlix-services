<script setup>
import { onMounted, ref } from 'vue';
import { auth } from './auth';

const rows = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const note = ref('');
const role = ref('');

async function request(url, options = {}) {
  const result = await auth.fetch(url, options);
  if (!result.ok) {
    let message = 'Операция с матрицей прав не выполнена.';
    try { message = (await result.json())?.detail || message; } catch (_) { /* non-JSON error */ }
    throw new Error(message);
  }
  return await result.json();
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await request('/api/cv-converter/permissions');
    rows.value = data.data?.matrix || [];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}

async function setRule(value, allowed) {
  if (saving.value) return;
  saving.value = true;
  error.value = '';
  note.value = '';
  try {
    const data = await request('/api/cv-converter/permissions', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role: value, allowed }),
    });
    rows.value = data.data?.matrix || [];
    note.value = 'Изменение права сохранено в журнале аудита.';
    role.value = '';
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
}

async function removeRule(value) {
  if (saving.value) return;
  saving.value = true;
  error.value = '';
  note.value = '';
  try {
    const data = await request('/api/cv-converter/permissions', {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role: value }),
    });
    rows.value = data.data?.matrix || [];
    note.value = 'Правило удалено. Отсутствие разрешения означает запрет.';
  } catch (e) { error.value = e.message; }
  finally { saving.value = false; }
}

onMounted(load);
</script>

<template>
  <section class="cv-authz-panel" aria-label="Управление доступом к CV">
    <h2>Разрешения CV Converter</h2>
    <p class="cv-authz-subtitle">Сервис проверяет подписанные роли из Employees/Keycloak и собственную матрицу. Для CV нет сохранённых объектов: разрешение <code>cv.convert</code> имеет область <code>all</code> либо <code>none</code>. Настройки LLM всегда доступны только администратору платформы.</p>
    <p v-if="error" role="alert" class="cv-authz-error">{{ error }}</p>
    <p v-if="note" role="status">{{ note }}</p>
    <p v-if="loading">Загрузка разрешений…</p>
    <template v-else>
      <table class="cv-authz-table">
        <thead><tr><th>Роль</th><th>Разрешение</th><th>Область</th><th>Действия</th></tr></thead>
        <tbody>
          <tr v-for="item in rows" :key="item.role + ':' + item.permission">
            <td><code>{{ item.role }}</code></td>
            <td>{{ item.permission }}</td>
            <td>{{ item.allowed ? 'all' : 'none (запрет)' }}</td>
            <td class="cv-authz-actions">
              <button type="button" :disabled="saving" @click="setRule(item.role, !item.allowed)">{{ item.allowed ? 'Запретить' : 'Разрешить' }}</button>
              <button type="button" :disabled="saving" @click="removeRule(item.role)">Удалить</button>
            </td>
          </tr>
          <tr v-if="rows.length === 0"><td colspan="4">Нет правил. Конвертация для обычных ролей запрещена.</td></tr>
        </tbody>
      </table>
      <form class="cv-authz-form" @submit.prevent="setRule(role.trim(), true)">
        <label for="cv-authz-role">Добавить разрешение для роли</label>
        <input id="cv-authz-role" v-model="role" type="text" required maxlength="120"
          placeholder="head:development или org:recruitment" autocomplete="off" />
        <button type="submit" :disabled="saving || !role.trim()">Разрешить cv.convert</button>
      </form>
      <p class="cv-authz-subtitle">Начальная запись <code>head:*</code> разрешает конвертацию назначенным руководителям производственных направлений при положительном общем допуске Employees. Точные роли могут иметь собственные запреты, которые имеют приоритет. <code>special:platform-admin</code> неизменяемый суперпользователь.</p>
    </template>
  </section>
</template>
