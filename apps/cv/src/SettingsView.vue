<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { auth } from './auth';

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const error = ref('');
const success = ref('');
const connection = ref(null);

const form = reactive({
  provider: 'local',
  local: { base_url: '', model: '' },
  gigachat: { credentials: '', credentials_configured: false, scope: 'GIGACHAT_API_PERS', model: 'GigaChat-2-Pro', base_url: '', oauth_url: '' },
  yandex: { api_key: '', api_key_configured: false, folder_id: '', model: '', base_url: '' },
  openai_compatible: { api_key: '', api_key_configured: false, base_url: '', model: '' },
});

const providers = [
  { id: 'local', label: 'Локальная LLM', hint: 'llama.cpp на сервере IRLIX' },
  { id: 'gigachat', label: 'GigaChat', hint: 'Авторизация по credentials + scope' },
  { id: 'yandex', label: 'Yandex AI Studio', hint: 'API key + folder ID' },
  { id: 'openai_compatible', label: 'OpenAI-compatible', hint: 'MWS или другой совместимый endpoint' },
];

const gigachatScopes = [
  { id: 'GIGACHAT_API_PERS', label: 'Personal / Freemium (GIGACHAT_API_PERS)' },
  { id: 'GIGACHAT_API_B2B', label: 'Business prepaid (GIGACHAT_API_B2B)' },
  { id: 'GIGACHAT_API_CORP', label: 'Business postpaid (GIGACHAT_API_CORP)' },
];

const gigachatModels = [
  { id: 'GigaChat-2-Pro', label: 'GigaChat 2 Pro — рекомендуется для CV' },
  { id: 'GigaChat-3-Ultra', label: 'GigaChat 3 Ultra — Freemium' },
  { id: 'GigaChat-2-Max', label: 'GigaChat 2 Max' },
  { id: 'GigaChat-2', label: 'GigaChat 2 Lite' },
];

const activeProvider = computed(() => providers.find(item => item.id === form.provider));

async function responseError(response, fallback) {
  try {
    const body = await response.json();
    return body?.detail || fallback;
  } catch (_) {
    return fallback;
  }
}

function applySettings(data) {
  form.provider = data.provider || 'local';
  Object.assign(form.local, data.local || {});
  Object.assign(form.gigachat, data.gigachat || {});
  Object.assign(form.yandex, data.yandex || {});
  Object.assign(form.openai_compatible, data.openai_compatible || {});
  form.gigachat.credentials = '';
  form.yandex.api_key = '';
  form.openai_compatible.api_key = '';
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const response = await auth.fetch('/api/cv-converter/settings');
    if (!response.ok) throw new Error(await responseError(response, 'Не удалось загрузить настройки.'));
    applySettings(await response.json());
  } catch (e) {
    error.value = e?.message || 'Не удалось загрузить настройки.';
  } finally {
    loading.value = false;
  }
}

function payload() {
  return {
    provider: form.provider,
    local: { base_url: form.local.base_url, model: form.local.model },
    gigachat: {
      credentials: form.gigachat.credentials,
      scope: form.gigachat.scope,
      model: form.gigachat.model,
      base_url: form.gigachat.base_url,
      oauth_url: form.gigachat.oauth_url,
    },
    yandex: {
      api_key: form.yandex.api_key,
      folder_id: form.yandex.folder_id,
      model: form.yandex.model,
      base_url: form.yandex.base_url,
    },
    openai_compatible: {
      api_key: form.openai_compatible.api_key,
      base_url: form.openai_compatible.base_url,
      model: form.openai_compatible.model,
    },
  };
}

async function save(runTest = false) {
  saving.value = true;
  error.value = '';
  success.value = '';
  connection.value = null;
  try {
    const response = await auth.fetch('/api/cv-converter/settings', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload()),
    });
    if (!response.ok) throw new Error(await responseError(response, 'Не удалось сохранить настройки.'));
    applySettings(await response.json());
    success.value = 'Настройки сохранены. Новые конвертации будут использовать выбранного провайдера.';
    if (runTest) await testConnection();
  } catch (e) {
    error.value = e?.message || 'Не удалось сохранить настройки.';
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  error.value = '';
  connection.value = null;
  try {
    const response = await auth.fetch('/api/cv-converter/settings/test', { method: 'POST' });
    if (!response.ok) throw new Error(await responseError(response, 'Проверка подключения не прошла.'));
    connection.value = await response.json();
  } catch (e) {
    error.value = e?.message || 'Проверка подключения не прошла.';
  } finally {
    testing.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="settings-page">
    <header class="settings-heading">
      <div>
        <h1>Настройки LLM</h1>
        <p>Выберите провайдера для новых конвертаций. Изменения применяются без перезапуска сервиса.</p>
      </div>
      <span v-if="activeProvider" class="provider-badge">{{ activeProvider.label }}</span>
    </header>

    <div v-if="error" class="error-banner">{{ error }}</div>
    <div v-if="success" class="success-banner">{{ success }}</div>

    <section v-if="!loading" class="settings-card">
      <div class="settings-section-title">
        <div>
          <b>Провайдер</b>
          <span>Активный провайдер используется для следующего запуска конвертации.</span>
        </div>
      </div>

      <div class="provider-grid">
        <label v-for="provider in providers" :key="provider.id" class="provider-option" :class="{ active: form.provider === provider.id }">
          <input v-model="form.provider" type="radio" name="provider" :value="provider.id" />
          <span>
            <b>{{ provider.label }}</b>
            <small>{{ provider.hint }}</small>
          </span>
        </label>
      </div>

      <div class="provider-form">
        <template v-if="form.provider === 'local'">
          <label><span>Base URL</span><input v-model.trim="form.local.base_url" type="text" /></label>
          <label><span>Модель</span><input v-model.trim="form.local.model" type="text" /></label>
        </template>

        <template v-else-if="form.provider === 'gigachat'">
          <label class="full"><span>Credentials</span><input v-model="form.gigachat.credentials" type="password" :placeholder="form.gigachat.credentials_configured ? 'Секрет уже сохранён · введите новый только для замены' : 'Вставьте credentials GigaChat'" autocomplete="new-password" /></label>
          <label><span>Scope</span><select v-model="form.gigachat.scope"><option v-for="scope in gigachatScopes" :key="scope.id" :value="scope.id">{{ scope.label }}</option></select></label>
          <label><span>Модель</span><select v-model="form.gigachat.model"><option v-for="model in gigachatModels" :key="model.id" :value="model.id">{{ model.label }}</option></select></label>
          <div class="full settings-hint">Для персонального Freemium используйте GIGACHAT_API_PERS. Для нормализации CV рекомендуем GigaChat 2 Pro: задача требует строгого следования инструкции, а не креативной генерации.</div>
          <label class="full"><span>API URL</span><input v-model.trim="form.gigachat.base_url" type="text" /></label>
          <label class="full"><span>OAuth URL</span><input v-model.trim="form.gigachat.oauth_url" type="text" /></label>
        </template>

        <template v-else-if="form.provider === 'yandex'">
          <label class="full"><span>API key</span><input v-model="form.yandex.api_key" type="password" :placeholder="form.yandex.api_key_configured ? 'Секрет уже сохранён · введите новый только для замены' : 'API key'" autocomplete="new-password" /></label>
          <label><span>Folder ID</span><input v-model.trim="form.yandex.folder_id" type="text" /></label>
          <label><span>Модель</span><input v-model.trim="form.yandex.model" type="text" placeholder="gpt://.../yandexgpt/latest" /></label>
          <label class="full"><span>Base URL</span><input v-model.trim="form.yandex.base_url" type="text" /></label>
        </template>

        <template v-else>
          <label class="full"><span>Base URL</span><input v-model.trim="form.openai_compatible.base_url" type="text" placeholder="https://.../v1" /></label>
          <label><span>Модель</span><input v-model.trim="form.openai_compatible.model" type="text" /></label>
          <label><span>API key</span><input v-model="form.openai_compatible.api_key" type="password" :placeholder="form.openai_compatible.api_key_configured ? 'Секрет уже сохранён' : 'Необязательно'" autocomplete="new-password" /></label>
        </template>
      </div>

      <div class="settings-actions">
        <button class="secondary-btn" type="button" :disabled="saving || testing" @click="testConnection">{{ testing ? 'Проверяем…' : 'Проверить текущие' }}</button>
        <button class="primary-btn" type="button" :disabled="saving || testing" @click="save(true)">{{ saving ? 'Сохраняем…' : 'Сохранить и проверить' }}</button>
      </div>

      <div v-if="connection" class="connection-result">
        <b>Подключение успешно</b>
        <span>{{ connection.provider }}<template v-if="connection.model"> · {{ connection.model }}</template></span>
      </div>
    </section>

    <div v-else class="settings-loading">Загружаем настройки…</div>
  </div>
</template>
