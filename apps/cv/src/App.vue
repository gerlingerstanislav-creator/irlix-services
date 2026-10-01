<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from './auth';
import DOMPurify from 'dompurify';
import mammoth from 'mammoth';

const navItems = [{ id: 'convert', label: 'Конвертация', icon: 'document' }];
const templates = [{ id: 'irlix-cv', label: 'Irlix CV' }];
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
const activeStage = ref('');
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

const stages = computed(() => {
  const extractionMs = metrics.value?.extraction_ms ?? null;
  const llmMs = metrics.value?.llm_ms ?? null;
  return [
    {
      id: 'read',
      label: 'Чтение документа',
      value: extractionMs,
      state: extractionMs != null ? 'done' : activeStage.value === 'parse' ? 'active' : 'pending',
    },
    {
      id: 'llm',
      label: 'Структурирование и LLM',
      value: llmMs,
      state: llmMs != null ? 'done' : activeStage.value === 'parse' ? 'active' : 'pending',
    },
    {
      id: 'pdf',
      label: 'Генерация PDF',
      value: renderMs.value,
      state: renderMs.value != null ? 'done' : activeStage.value === 'render' ? 'active' : 'pending',
    },
  ];
});

function formatDuration(ms) {
  if (ms == null) return '—';
  if (ms < 1000) return `${Math.max(1, Math.round(ms))} мс`;
  return `${(ms / 1000).toFixed(ms >= 10000 ? 1 : 2)} сек`;
}

function stageDuration(stage) {
  if (stage.value != null) return formatDuration(stage.value);
  if (stage.state === 'active') return formatDuration(liveElapsedMs.value);
  return '—';
}

function startStage(stage) {
  activeStage.value = stage;
  stageStartedAt = performance.now();
  liveElapsedMs.value = 0;
  if (stageTimer) clearInterval(stageTimer);
  stageTimer = setInterval(() => {
    liveElapsedMs.value = Math.round(performance.now() - stageStartedAt);
  }, 250);
}

function stopStage() {
  if (stageTimer) clearInterval(stageTimer);
  stageTimer = null;
  liveElapsedMs.value = 0;
  activeStage.value = '';
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
  stopStage();
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
  } catch (_) {
  }
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
  startStage('render');
  const startedAt = performance.now();
  try {
    const response = await auth.fetch('/api/cv-converter/render/pdf', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(canonical.value),
    });
    if (!response.ok) throw new Error(await responseError(response, 'Не удалось сформировать итоговый PDF.'));
    revoke(resultUrl);
    resultUrl.value = URL.createObjectURL(await response.blob());
    renderMs.value = Math.round(performance.now() - startedAt);
  } finally {
    rendering.value = false;
    stopStage();
  }
}

async function convert() {
  if (!canConvert.value) return;
  clearResult();
  error.value = '';
  processing.value = true;
  startStage('parse');
  try {
    await parse(sourceFile.value);
    processing.value = false;
    stopStage();
    await renderPreview();
  } catch (e) {
    error.value = e?.message || 'Не удалось преобразовать CV.';
  } finally {
    processing.value = false;
    rendering.value = false;
    stopStage();
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

onBeforeUnmount(() => stopStage());
</script>

<template>
  <div class="cv-app">
    <UiAppSidebar
      section="convert"
      :items="navItems"
      current-service="cv-converter"
      :current-user="auth.user"
      @logout="auth.logout()"
    />

    <main class="cv-content">
      <header class="service-bar">
        <span class="service-name">CV конвертер</span>
        <div class="service-status">
          <span v-if="statusText" class="status-pill" :class="{ ready: hasResult }">{{ statusText }}</span>
          <button v-if="sourceFile" class="secondary-btn" type="button" :disabled="processing || rendering" @click="reset">Новое CV</button>
        </div>
      </header>

      <div class="workspace-wrap">
        <div v-if="error" class="error-banner">{{ error }}</div>

        <section class="workspace">
          <article class="workspace-column">
            <div class="column-head">
              <div>
                <span class="column-kicker">Исходник</span>
                <b>{{ sourceFile?.name || 'CV не загружено' }}</b>
              </div>
            </div>

            <div
              v-if="!sourceFile"
              class="drop-zone"
              :class="{ dragging: isDragging }"
              @dragenter.prevent="isDragging = true"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="onDrop"
              @click="fileInput?.click()"
            >
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
            <button
              class="convert-arrow"
              type="button"
              :disabled="!canConvert"
              :title="sourceFile ? 'Конвертировать CV' : 'Сначала загрузите CV'"
              @click="convert"
            >
              <span v-if="processing || rendering" class="arrow-loader" />
              <span v-else aria-hidden="true">→</span>
            </button>
          </div>

          <article class="workspace-column result-column">
            <div class="result-toolbar">
              <div class="result-heading">
                <span class="column-kicker">Результат</span>
                <b>{{ selectedTemplateLabel }}</b>
                <span v-if="metrics" class="metrics">{{ metrics.provider }} · {{ (metrics.total_ms / 1000).toFixed(1) }} сек.</span>
              </div>

              <label class="template-select-wrap">
                <span>Шаблон</span>
                <select v-model="selectedTemplate" class="template-select" :disabled="processing || rendering">
                  <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.label }}</option>
                </select>
              </label>

              <div class="download-actions" :class="{ disabled: !hasResult }">
                <button class="secondary-btn" type="button" :disabled="!hasResult || downloading" @click="download('docx')">{{ downloading === 'docx' ? 'Готовим…' : 'DOCX' }}</button>
                <button class="primary-btn" type="button" :disabled="!hasResult || downloading" @click="download('pdf')">{{ downloading === 'pdf' ? 'Готовим…' : 'PDF' }}</button>
              </div>
            </div>

            <div v-if="!hasResult" class="result-empty">
              <div v-if="processing || rendering" class="loader" />
              <div v-else class="preview-placeholder" />
              <h2>{{ processing ? 'Разбираем структуру CV' : rendering ? 'Собираем документ' : 'Здесь появится Irlix CV' }}</h2>
              <p>{{ processing ? 'Читаем документ, выделяем структуру и нормализуем данные с помощью LLM.' : sourceFile ? 'Нажмите стрелку между окнами, чтобы запустить конвертацию.' : 'Загрузите исходный PDF или DOCX слева.' }}</p>
            </div>

            <div v-else class="document-frame result-frame pdf-frame">
              <iframe :src="resultUrl" title="Irlix CV" />
            </div>
          </article>
        </section>

        <section class="process-line" aria-label="Этапы конвертации">
          <div v-for="(stage, index) in stages" :key="stage.id" class="process-stage" :class="stage.state">
            <div class="stage-marker">
              <span v-if="stage.state === 'done'">✓</span>
              <span v-else-if="stage.state === 'active'" class="stage-spinner" />
              <span v-else>{{ index + 1 }}</span>
            </div>
            <div class="stage-copy">
              <b>{{ stage.label }}</b>
              <span>{{ stageDuration(stage) }}</span>
            </div>
            <div v-if="index < stages.length - 1" class="stage-connector" />
          </div>
        </section>
      </div>
    </main>
  </div>
</template>
