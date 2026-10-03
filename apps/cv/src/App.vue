<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { UiAppSidebar, UiAppTopbar } from '@irlix/ui';
import { auth } from './auth';
import SettingsView from './SettingsView.vue';
import DOMPurify from 'dompurify';
import mammoth from 'mammoth';

const navItems = [
  { id: 'convert', label: 'Конвертация', icon: 'document' },
  { id: 'settings', label: 'Настройки', icon: 'audit', groupStart: true },
];
const templates = [{ id: 'irlix-cv', label: 'Irlix CV' }];
const currentSection = ref(window.location.pathname.includes('/settings') ? 'settings' : 'convert');
const selectedTemplate = ref('irlix-cv');
const fileInput = ref(null);
const sourceFile = ref(null);
const sourceUrl = ref('');
const sourceHtml = ref('');
const resultUrl = ref('');
const canonical = ref(null);
const metrics = ref(null);
const processing = ref(false);
const rendering = ref(false);
const downloading = ref('');
const error = ref('');
const isDragging = ref(false);
const renderMs = ref(null);
const liveElapsedMs = ref(0);
let stageStartedAt = 0;
let stageTimer = null;

const sourceKind = computed(() => sourceFile.value?.name?.toLowerCase().endsWith('.pdf') ? 'pdf' : 'docx');
const hasResult = computed(() => Boolean(canonical.value && resultUrl.value));
const canConvert = computed(() => Boolean(sourceFile.value && !processing.value && !rendering.value));
const selectedTemplateLabel = computed(() => templates.find(item => item.id === selectedTemplate.value)?.label || 'Irlix CV');
const statusText = computed(() => {
  if (processing.value) return 'Анализируем CV…';
  if (rendering.value) return 'Формируем PDF…';
  if (hasResult.value) return 'Готово';
  if (sourceFile.value) return 'Готово к конвертации';
  return '';
});
const llmStageLabel = computed(() => {
  const provider = metrics.value?.provider;
  const base = provider === 'gigachat' ? 'GigaChat' : provider === 'local' ? 'Локальная LLM' : 'LLM';
  return (metrics.value?.llm_attempts || 1) > 1 ? `${base} (${metrics.value.llm_attempts} попытки)` : base;
});
const timingStages = computed(() => [
  { id: 'extract', label: 'Извлечение текста', value: metrics.value?.extraction_ms ?? null },
  { id: 'preparse', label: 'Структурный разбор', value: metrics.value?.preparse_ms ?? null },
  { id: 'llm', label: llmStageLabel.value, value: metrics.value?.llm_ms ?? null },
  { id: 'validation', label: 'Валидация ответа', value: metrics.value?.validation_ms ?? null },
  { id: 'post', label: 'Объединение данных', value: metrics.value?.postprocess_ms ?? null },
  { id: 'pdf', label: 'Генерация PDF', value: renderMs.value },
]);
const measuredTotal = computed(() => timingStages.value.reduce((sum, stage) => sum + (stage.value || 0), 0));

function goSection(section, replace = false) {
  currentSection.value = section;
  const path = section === 'settings' ? '/cv-converter/settings/' : '/cv-converter/convert/';
  if (window.location.pathname !== path) window.history[replace ? 'replaceState' : 'pushState']({}, '', path);
}

function onPopState() {
  currentSection.value = window.location.pathname.includes('/settings') ? 'settings' : 'convert';
}

function formatDuration(ms) {
  if (ms == null) return '—';
  if (ms < 1000) return `${Math.max(1, Math.round(ms))} мс`;
  return `${(ms / 1000).toFixed(ms >= 10000 ? 1 : 2)} сек`;
}

function timingWidth(value) {
  if (!value || !measuredTotal.value) return '0%';
  return `${Math.max(3, (value / measuredTotal.value) * 100)}%`;
}

function startTimer() {
  stageStartedAt = performance.now();
  liveElapsedMs.value = 0;
  if (stageTimer) clearInterval(stageTimer);
  stageTimer = setInterval(() => {
    liveElapsedMs.value = Math.round(performance.now() - stageStartedAt);
  }, 250);
}

function stopTimer() {
  if (stageTimer) clearInterval(stageTimer);
  stageTimer = null;
  liveElapsedMs.value = 0;
}

function revoke(urlRef) {
  if (urlRef.value) URL.revokeObjectURL(urlRef.value);
  urlRef.value = '';
}

function clearResult() {
  revoke(resultUrl);
  canonical.value = null;
  metrics.value = null;
  renderMs.value = null;
}

function reset() {
  stopTimer();
  revoke(sourceUrl);
  clearResult();
  sourceFile.value = null;
  sourceHtml.value = '';
  error.value = '';
  if (fileInput.value) fileInput.value.value = '';
}

async function responseError(response, fallback) {
  const status = response.status ? `HTTP ${response.status}` : '';
  const contentType = response.headers.get('content-type') || '';
  try {
    if (contentType.includes('application/json')) {
      const body = await response.json();
      return body?.detail || `${fallback}${status ? ` (${status})` : ''}`;
    }
    const body = await response.text();
    const plain = body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (plain) return `${fallback}${status ? ` (${status}: ${plain.slice(0, 180)})` : ` (${plain.slice(0, 180)})`}`;
  } catch (_) {}
  return `${fallback}${status ? ` (${status})` : ''}`;
}

async function prepareSourcePreview(file) {
  const name = file.name.toLowerCase();
  if (name.endsWith('.pdf')) {
    sourceUrl.value = URL.createObjectURL(file);
    return;
  }
  if (name.endsWith('.docx')) {
    const html = await mammoth.convertToHtml({ arrayBuffer: await file.arrayBuffer() });
    sourceHtml.value = DOMPurify.sanitize(html.value || '<p>Документ не содержит текста.</p>');
  }
}

async function parse(file) {
  const form = new FormData();
  form.append('file', file);
  const response = await auth.fetch('/api/cv-converter/parse', { method: 'POST', body: form });
  if (!response.ok) throw new Error(await responseError(response, 'Не удалось разобрать CV.'));
  const body = await response.json();
  canonical.value = body.cv;
  metrics.value = body.metrics;
}

async function renderPreview() {
  rendering.value = true;
  startTimer();
  const startedAt = performance.now();
  try {
    const response = await auth.fetch('/api/cv-converter/render/pdf', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(canonical.value),
    });
    if (!response.ok) throw new Error(await responseError(response, 'Не удалось сформировать итоговый PDF.'));
    const serverRenderMs = Number(response.headers.get('x-cv-render-ms'));
    revoke(resultUrl);
    resultUrl.value = URL.createObjectURL(await response.blob());
    renderMs.value = Number.isFinite(serverRenderMs) && serverRenderMs >= 0
      ? serverRenderMs
      : Math.round(performance.now() - startedAt);
  } finally {
    rendering.value = false;
    stopTimer();
  }
}

async function convert() {
  if (!canConvert.value) return;
  clearResult();
  error.value = '';
  processing.value = true;
  startTimer();
  try {
    await parse(sourceFile.value);
    processing.value = false;
    stopTimer();
    await renderPreview();
  } catch (e) {
    error.value = e?.message || 'Не удалось преобразовать CV.';
  } finally {
    processing.value = false;
    rendering.value = false;
    stopTimer();
  }
}

async function onFiles(files) {
  const file = files?.[0];
  if (!file) return;
  reset();
  sourceFile.value = file;
  try {
    await prepareSourcePreview(file);
  } catch (e) {
    error.value = e?.message || 'Не удалось открыть исходный CV.';
  }
}

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function safeName(ext) {
  const raw = canonical.value?.full_name || sourceFile.value?.name?.replace(/\.[^.]+$/, '') || 'CV';
  const base = raw.replace(/[\\/:*?"<>|]+/g, ' ').trim().replace(/\s+/g, '_');
  return `${base || 'CV'}_IRLIX.${ext}`;
}

async function download(format) {
  if (!canonical.value || downloading.value) return;
  downloading.value = format;
  try {
    if (format === 'pdf' && resultUrl.value) {
      const blob = await fetch(resultUrl.value).then(response => response.blob());
      saveBlob(blob, safeName('pdf'));
      return;
    }
    const response = await auth.fetch(`/api/cv-converter/render/${format}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(canonical.value),
    });
    if (!response.ok) throw new Error(await responseError(response, `Не удалось сформировать ${format.toUpperCase()}.`));
    saveBlob(await response.blob(), safeName(format));
  } catch (e) {
    error.value = e?.message || 'Не удалось скачать файл.';
  } finally {
    downloading.value = '';
  }
}

function onDrop(event) {
  isDragging.value = false;
  onFiles(event.dataTransfer.files);
}

onMounted(() => {
  window.addEventListener('popstate', onPopState);
  if (!window.location.pathname.includes('/settings') && !window.location.pathname.includes('/convert')) goSection('convert', true);
});
onBeforeUnmount(() => {
  stopTimer();
  window.removeEventListener('popstate', onPopState);
});
</script>

<template>
  <div class="cv-app">
    <UiAppSidebar
      :section="currentSection"
      :items="navItems"
      current-service="cv-converter"
      :current-user="auth.user"
    :platform-access="() => auth.fetch('/api/employees/access/me')"
      @update:section="goSection"
      @logout="auth.logout()"
    />

    <main class="cv-content">
      <UiAppTopbar service="cv-converter" :section="currentSection" :items="navItems">
        <template v-if="currentSection === 'convert'" #actions>
          <div class="service-status">
            <span v-if="statusText" class="status-pill" :class="{ ready: hasResult }">{{ statusText }}</span>
            <button v-if="sourceFile" class="secondary-btn" type="button" :disabled="processing || rendering" @click="reset">Новое CV</button>
          </div>
        </template>
      </UiAppTopbar>

      <SettingsView v-if="currentSection === 'settings'" />

      <div v-else class="workspace-wrap">
        <div v-if="error" class="error-banner">{{ error }}</div>

        <section class="workspace">
          <article class="workspace-column">
            <div class="column-head">
              <div><span class="column-kicker">Исходник</span><b>{{ sourceFile?.name || 'CV не загружено' }}</b></div>
            </div>

            <div v-if="!sourceFile" class="drop-zone" :class="{ dragging: isDragging }" @dragenter.prevent="isDragging = true" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="onDrop" @click="fileInput?.click()">
              <div class="drop-icon">CV</div>
              <h2>Перетащите CV сюда</h2>
              <p>PDF или DOCX · после загрузки нажмите стрелку между окнами</p>
              <button class="primary-btn" type="button">Выбрать файл</button>
              <input ref="fileInput" hidden type="file" accept=".pdf,.doc,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" @change="onFiles($event.target.files)" />
            </div>

            <div v-else class="document-frame source-frame" :class="{ 'pdf-frame': sourceKind === 'pdf' }">
              <iframe v-if="sourceKind === 'pdf'" :src="sourceUrl" title="Исходное CV" />
              <div v-else class="source-docx" v-html="sourceHtml" />
            </div>
          </article>

          <div class="conversion-rail" aria-label="Запуск конвертации">
            <button class="convert-arrow" type="button" :disabled="!canConvert" :title="sourceFile ? 'Конвертировать CV' : 'Сначала загрузите CV'" @click="convert">
              <span v-if="processing || rendering" class="arrow-loader" />
              <span v-else aria-hidden="true">→</span>
            </button>
          </div>

          <article class="workspace-column result-column">
            <div class="result-toolbar">
              <div class="result-heading">
                <span class="column-kicker">Результат</span>
                <b>{{ selectedTemplateLabel }}</b>
                <span v-if="metrics" class="metrics">{{ metrics.provider }} · {{ metrics.model || 'model' }} · {{ formatDuration(metrics.total_ms) }}</span>
              </div>
              <label class="template-select-wrap"><span>Шаблон</span><select v-model="selectedTemplate" class="template-select" :disabled="processing || rendering"><option v-for="template in templates" :key="template.id" :value="template.id">{{ template.label }}</option></select></label>
              <div class="download-actions" :class="{ disabled: !hasResult }">
                <button class="secondary-btn" type="button" :disabled="!hasResult || downloading" @click="download('docx')">{{ downloading === 'docx' ? 'Готовим…' : 'DOCX' }}</button>
                <button class="primary-btn" type="button" :disabled="!hasResult || downloading" @click="download('pdf')">{{ downloading === 'pdf' ? 'Готовим…' : 'PDF' }}</button>
              </div>
            </div>

            <div v-if="!hasResult" class="result-empty">
              <div v-if="processing || rendering" class="loader" />
              <div v-else class="preview-placeholder" />
              <h2>{{ processing ? 'Разбираем структуру CV' : rendering ? 'Собираем документ' : 'Здесь появится Irlix CV' }}</h2>
              <p>{{ processing ? 'Сервер читает документ, структурирует данные и выполняет запрос к выбранной LLM.' : sourceFile ? 'Нажмите стрелку между окнами, чтобы запустить конвертацию.' : 'Загрузите исходный PDF или DOCX слева.' }}</p>
            </div>
            <div v-else class="document-frame result-frame pdf-frame"><iframe :src="resultUrl" title="Irlix CV" /></div>
          </article>
        </section>

        <section class="timing-panel" aria-label="Время обработки">
          <div v-if="processing || rendering" class="timing-live">
            <span class="stage-spinner" />
            <div><b>{{ processing ? 'Серверная обработка CV' : 'Генерация PDF' }}</b><span>{{ formatDuration(liveElapsedMs) }} · подробные времена появятся после завершения этапа</span></div>
          </div>
          <template v-else-if="metrics">
            <div class="timing-head"><b>Куда ушло время</b><span>Фактические измерения 6 этапов конвертации</span></div>
            <div class="timing-grid">
              <div v-for="stage in timingStages" :key="stage.id" class="timing-item">
                <div class="timing-label"><span>{{ stage.label }}</span><b>{{ formatDuration(stage.value) }}</b></div>
                <div class="timing-track"><span :style="{ width: timingWidth(stage.value) }" /></div>
              </div>
            </div>
          </template>
          <div v-else class="timing-idle">После конвертации здесь будет показано реальное время каждого этапа.</div>
        </section>
      </div>
    </main>
  </div>
</template>