<script setup>
import { computed, ref } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from './auth';
import DOMPurify from 'dompurify';
import mammoth from 'mammoth';

const navItems = [{ id: 'convert', label: 'Конвертация', icon: 'document' }];
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

const sourceKind = computed(() => sourceFile.value?.name?.toLowerCase().endsWith('.pdf') ? 'pdf' : 'docx');
const hasResult = computed(() => Boolean(canonical.value && resultUrl.value));
const statusText = computed(() => {
  if (processing.value) return 'Анализируем CV…';
  if (rendering.value) return 'Формируем IRLIX CV…';
  if (hasResult.value) return 'Готово';
  return '';
});

function revoke(urlRef) {
  if (urlRef.value) URL.revokeObjectURL(urlRef.value);
  urlRef.value = '';
}

function reset() {
  revoke(sourceUrl);
  revoke(resultUrl);
  sourceFile.value = null;
  sourceHtml.value = '';
  canonical.value = null;
  metrics.value = null;
  error.value = '';
  if (fileInput.value) fileInput.value.value = '';
}

async function responseError(response, fallback) {
  try {
    const body = await response.json();
    return body?.detail || fallback;
  } catch (_) {
    return fallback;
  }
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
  try {
    const response = await auth.fetch('/api/cv-converter/render/pdf', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(canonical.value),
    });
    if (!response.ok) throw new Error(await responseError(response, 'Не удалось сформировать итоговый PDF.'));
    revoke(resultUrl);
    resultUrl.value = URL.createObjectURL(await response.blob());
  } finally {
    rendering.value = false;
  }
}

async function onFiles(files) {
  const file = files?.[0];
  if (!file) return;
  reset();
  sourceFile.value = file;
  processing.value = true;
  try {
    await prepareSourcePreview(file);
    await parse(file);
    processing.value = false;
    await renderPreview();
  } catch (e) {
    error.value = e?.message || 'Не удалось преобразовать CV.';
  } finally {
    processing.value = false;
    rendering.value = false;
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
              <p>PDF или DOCX · после загрузки конвертация запускается автоматически</p>
              <button class="primary-btn" type="button">Выбрать файл</button>
              <input ref="fileInput" hidden type="file" accept=".pdf,.doc,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" @change="onFiles($event.target.files)" />
            </div>

            <div v-else class="document-frame source-frame" :class="{ 'pdf-frame': sourceKind === 'pdf' }">
              <iframe v-if="sourceKind === 'pdf'" :src="sourceUrl" title="Исходное CV" />
              <div v-else class="source-docx" v-html="sourceHtml" />
            </div>
          </article>

          <article class="workspace-column result-column">
            <div class="result-toolbar">
              <div>
                <span class="column-kicker">Результат</span>
                <b>IRLIX CV</b>
                <span v-if="metrics" class="metrics">{{ metrics.provider }} · {{ (metrics.total_ms / 1000).toFixed(1) }} сек.</span>
              </div>
              <div class="download-actions" :class="{ disabled: !hasResult }">
                <button class="secondary-btn" type="button" :disabled="!hasResult || downloading" @click="download('docx')">{{ downloading === 'docx' ? 'Готовим…' : 'DOCX' }}</button>
                <button class="primary-btn" type="button" :disabled="!hasResult || downloading" @click="download('pdf')">{{ downloading === 'pdf' ? 'Готовим…' : 'PDF' }}</button>
              </div>
            </div>

            <div v-if="!hasResult" class="result-empty">
              <div v-if="processing || rendering" class="loader" />
              <div v-else class="preview-placeholder" />
              <h2>{{ processing ? 'Разбираем структуру CV' : rendering ? 'Собираем документ' : 'Здесь появится IRLIX CV' }}</h2>
              <p>{{ processing ? 'Локальная модель извлекает навыки, опыт, проекты, образование и другие данные.' : 'Загрузите исходный PDF или DOCX слева.' }}</p>
            </div>

            <div v-else class="document-frame result-frame pdf-frame">
              <iframe :src="resultUrl" title="IRLIX CV" />
            </div>
          </article>
        </section>
      </div>
    </main>
  </div>
</template>
