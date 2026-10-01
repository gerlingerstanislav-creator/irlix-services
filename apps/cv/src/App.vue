<script setup>
import { computed, nextTick, ref } from 'vue';
import { UiAppSidebar } from '@irlix/ui';
import { auth } from './auth';
import DOMPurify from 'dompurify';
import mammoth from 'mammoth';
import * as pdfjsLib from 'pdfjs-dist/build/pdf.mjs';
import PdfWorker from 'pdfjs-dist/build/pdf.worker.mjs?worker';
import html2pdf from 'html2pdf.js';
import { Document, HeadingLevel, Packer, Paragraph, TextRun } from 'docx';

pdfjsLib.GlobalWorkerOptions.workerPort = new PdfWorker();

const navItems = [{ id: 'convert', label: 'Конвертация', icon: 'document' }];
const fileInput = ref(null);
const sourceFile = ref(null);
const sourceUrl = ref('');
const sourceHtml = ref('');
const sourceText = ref('');
const processing = ref(false);
const error = ref('');
const isDragging = ref(false);
const resultRef = ref(null);
const selectedTemplate = ref('irlix-standard');

const templates = [{ id: 'irlix-standard', label: 'IRLIX Standard' }];

const normalized = computed(() => normalizeCv(sourceText.value));
const hasResult = computed(() => Boolean(sourceText.value.trim()));
const sourceKind = computed(() => sourceFile.value?.name?.toLowerCase().endsWith('.pdf') ? 'pdf' : 'text');

function revokeSourceUrl() {
  if (sourceUrl.value) URL.revokeObjectURL(sourceUrl.value);
  sourceUrl.value = '';
}

function reset() {
  revokeSourceUrl();
  sourceFile.value = null;
  sourceHtml.value = '';
  sourceText.value = '';
  error.value = '';
  if (fileInput.value) fileInput.value.value = '';
}

async function onFiles(files) {
  const file = files?.[0];
  if (!file) return;
  error.value = '';
  processing.value = true;
  reset();
  sourceFile.value = file;

  try {
    const name = file.name.toLowerCase();
    if (name.endsWith('.pdf')) await readPdf(file);
    else if (name.endsWith('.docx')) await readDocx(file);
    else if (name.endsWith('.doc')) throw new Error('Формат .doc пока не поддерживается в первой итерации. Сохраните файл как .docx и загрузите повторно.');
    else throw new Error('Поддерживаются файлы PDF и DOCX.');
  } catch (e) {
    reset();
    error.value = e?.message || 'Не удалось прочитать CV.';
  } finally {
    processing.value = false;
  }
}

async function readDocx(file) {
  const buffer = await file.arrayBuffer();
  const [html, text] = await Promise.all([
    mammoth.convertToHtml({ arrayBuffer: buffer }),
    mammoth.extractRawText({ arrayBuffer: buffer }),
  ]);
  sourceHtml.value = DOMPurify.sanitize(html.value || '<p>Документ не содержит текста.</p>');
  sourceText.value = text.value || '';
}

async function readPdf(file) {
  sourceUrl.value = URL.createObjectURL(file);
  const data = new Uint8Array(await file.arrayBuffer());
  const pdf = await pdfjsLib.getDocument({ data }).promise;
  const pages = [];
  for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
    const page = await pdf.getPage(pageNumber);
    const content = await page.getTextContent();
    const lines = [];
    let lastY = null;
    let current = '';
    for (const item of content.items) {
      const y = Math.round(item.transform?.[5] || 0);
      if (lastY !== null && Math.abs(y - lastY) > 3 && current.trim()) {
        lines.push(current.trim());
        current = '';
      }
      current += `${item.str || ''} `;
      lastY = y;
    }
    if (current.trim()) lines.push(current.trim());
    pages.push(lines.join('\n'));
  }
  sourceText.value = pages.join('\n\n');
  if (!sourceText.value.trim()) throw new Error('В PDF не найден текстовый слой. OCR будет добавлен на следующей итерации.');
}

function normalizeCv(text) {
  const lines = text.split(/\r?\n/).map(v => v.replace(/\s+/g, ' ').trim()).filter(Boolean);
  const headings = [
    ['summary', /^(о себе|профиль|summary|profile|about)$/i],
    ['skills', /^(навыки|ключевые навыки|skills|tech stack|технологии)$/i],
    ['experience', /^(опыт|опыт работы|experience|work experience|employment)$/i],
    ['education', /^(образование|education)$/i],
    ['languages', /^(языки|languages)$/i],
    ['projects', /^(проекты|projects)$/i],
  ];
  const result = { name: lines[0] || 'CV', title: lines[1] || '', intro: [], sections: [] };
  let current = { key: 'intro', title: '', lines: result.intro };

  for (const line of lines.slice(2)) {
    const match = headings.find(([, regex]) => regex.test(line.replace(/:$/, '')));
    if (match) {
      current = { key: match[0], title: line.replace(/:$/, ''), lines: [] };
      result.sections.push(current);
      continue;
    }
    current.lines.push(line);
  }

  if (!result.sections.length && result.intro.length) {
    result.sections.push({ key: 'details', title: 'Профиль', lines: [...result.intro] });
    result.intro = [];
  }
  return result;
}

function safeFileName(extension) {
  const base = (normalized.value.name || 'cv').replace(/[\\/:*?"<>|]+/g, ' ').trim().replace(/\s+/g, '_');
  return `${base || 'cv'}_IRLIX_Standard.${extension}`;
}

async function downloadPdf() {
  await nextTick();
  if (!resultRef.value) return;
  await html2pdf().set({
    margin: 0,
    filename: safeFileName('pdf'),
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2, useCORS: true },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
    pagebreak: { mode: ['css', 'legacy'] },
  }).from(resultRef.value).save();
}

async function downloadDocx() {
  const cv = normalized.value;
  const children = [
    new Paragraph({ children: [new TextRun({ text: cv.name, bold: true, size: 36 })] }),
    ...(cv.title ? [new Paragraph({ children: [new TextRun({ text: cv.title, size: 24, color: '666666' })] })] : []),
    ...cv.intro.map(text => new Paragraph({ text })),
  ];

  for (const section of cv.sections) {
    children.push(new Paragraph({ text: section.title || 'Раздел', heading: HeadingLevel.HEADING_2 }));
    for (const line of section.lines) children.push(new Paragraph({ text: line, spacing: { after: 120 } }));
  }

  const doc = new Document({ sections: [{ properties: {}, children }] });
  const blob = await Packer.toBlob(doc);
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = safeFileName('docx');
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
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
        <button v-if="sourceFile" class="secondary-btn" type="button" @click="reset">Новое CV</button>
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
              <span v-if="sourceFile" class="status-pill">Распознано</span>
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
              <h2>{{ processing ? 'Обрабатываем CV…' : 'Перетащите CV сюда' }}</h2>
              <p>PDF или DOCX · файл обрабатывается в браузере</p>
              <button class="primary-btn" type="button" :disabled="processing">Выбрать файл</button>
              <input ref="fileInput" hidden type="file" accept=".pdf,.doc,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" @change="onFiles($event.target.files)" />
            </div>

            <div v-else class="document-frame source-frame" :class="{ 'pdf-frame': sourceKind === 'pdf' }">
              <iframe v-if="sourceKind === 'pdf'" :src="sourceUrl" title="Исходное CV" />
              <div v-else class="source-docx" v-html="sourceHtml" />
            </div>
          </article>

          <article class="workspace-column result-column">
            <div class="result-toolbar">
              <label>
                <span>Формат CV</span>
                <select v-model="selectedTemplate">
                  <option v-for="item in templates" :key="item.id" :value="item.id">{{ item.label }}</option>
                </select>
              </label>
              <div class="download-actions" :class="{ disabled: !hasResult }">
                <button class="secondary-btn" type="button" :disabled="!hasResult" @click="downloadDocx">DOCX</button>
                <button class="primary-btn" type="button" :disabled="!hasResult" @click="downloadPdf">PDF</button>
              </div>
            </div>

            <div v-if="!hasResult" class="result-empty">
              <div class="preview-placeholder"></div>
              <h2>Здесь появится новое CV</h2>
              <p>После загрузки слева содержимое автоматически будет перенесено в выбранный шаблон.</p>
            </div>

            <div v-else class="document-frame result-frame">
              <div ref="resultRef" class="cv-sheet">
                <header class="cv-sheet-head">
                  <div class="brand-mark">IRLIX</div>
                  <div class="identity">
                    <h2>{{ normalized.name }}</h2>
                    <p v-if="normalized.title">{{ normalized.title }}</p>
                  </div>
                </header>

                <div v-if="normalized.intro.length" class="intro-block">
                  <p v-for="line in normalized.intro" :key="line">{{ line }}</p>
                </div>

                <section v-for="section in normalized.sections" :key="`${section.key}-${section.title}`" class="cv-section">
                  <h3>{{ section.title || 'Профиль' }}</h3>
                  <div class="section-rule"></div>
                  <p v-for="(line, index) in section.lines" :key="`${section.key}-${index}`">{{ line }}</p>
                </section>
              </div>
            </div>
          </article>
        </section>
      </div>
    </main>
  </div>
</template>
