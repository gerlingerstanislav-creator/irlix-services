<script setup>
import { ref, nextTick } from 'vue';
const props = defineProps({
  service: { type: String, required: true },
  submit: { type: Function, required: true },
});
const open = ref(false);
const description = ref('');
const sending = ref(false);
const error = ref('');
const sent = ref(false);
const input = ref(null);
async function show() { open.value = true; error.value = ''; sent.value = false; await nextTick(); input.value?.focus(); }
function close() { if (!sending.value) open.value = false; }
async function send() {
  if (sending.value || !description.value.trim()) return;
  sending.value = true; error.value = '';
  try {
    const response = await props.submit('/api/reports', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ service_key: props.service, page_path: window.location.pathname + window.location.search, description: description.value.trim() }) });
    if (!response?.ok) throw new Error('Не удалось отправить сообщение. Попробуйте ещё раз.');
    description.value = ''; sent.value = true; open.value = false;
  } catch (e) { error.value = e.message || 'Ошибка отправки'; }
  finally { sending.value = false; }
}
function onKeydown(event) {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); send(); }
  if (event.key === 'Escape') { event.stopPropagation(); close(); }
}
</script>

<template>
  <button class="bug-report-trigger" type="button" aria-label="Сообщить об ошибке" title="Сообщить об ошибке" @click="show">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 5 6 3m10 2 2-2M4 12H2m20 0h-2M6 18l-2 2m14-2 2 2M8 9h8v7a4 4 0 0 1-8 0V9Zm4-3v13"/><path d="M7 9a5 5 0 0 1 10 0"/></svg>
  </button>
  <div v-if="open" class="bug-report-backdrop" @click.self="close">
    <section class="bug-report-dialog" role="dialog" aria-modal="true" aria-label="Сообщить об ошибке" @keydown.esc.stop="close">
      <header><strong>Сообщить об ошибке</strong><button type="button" aria-label="Закрыть" @click="close">×</button></header>
      <textarea ref="input" v-model="description" rows="5" maxlength="10000" placeholder="Что работает некорректно?" aria-label="Описание ошибки" @keydown="onKeydown" />
      <div v-if="error" role="alert" class="bug-report-error">{{ error }}</div>
      <footer><small>Enter — отправить · Shift+Enter — новая строка</small><button type="button" :disabled="sending || !description.trim()" @click="send">{{ sending ? 'Отправляем…' : 'Отправить' }}</button></footer>
    </section>
  </div>
</template>

<style scoped>
.bug-report-trigger { width:var(--irlix-sidebar-item-width);height:var(--irlix-sidebar-item-height);display:grid;place-items:center;background:transparent;border:0;border-radius:var(--irlix-sidebar-item-radius);color:var(--irlix-sidebar-icon);cursor:pointer }
.bug-report-trigger:hover { background:var(--irlix-sidebar-logo-hover-bg) }
.bug-report-trigger svg { width:20px;height:20px }
.bug-report-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:2000;display:grid;place-items:center;padding:16px }
.bug-report-dialog { box-sizing:border-box;width:min(440px,100%);padding:16px;border-radius:12px;background:var(--irlix-color-surface,#fff);color:var(--irlix-color-text,#222);box-shadow:0 12px 40px rgba(0,0,0,.2);font:inherit }
.bug-report-dialog header,.bug-report-dialog footer { display:flex;justify-content:space-between;align-items:center;gap:12px }
.bug-report-dialog header button { border:0;background:transparent;color:inherit;font-size:24px;cursor:pointer }
.bug-report-dialog textarea {box-sizing:border-box;width:100%;min-height:126px;margin:12px 0;padding:10px;border:1px solid var(--irlix-color-border,#ccc);border-radius:7px;background:transparent;color:inherit;resize:vertical;font:inherit;font-size:14px}
.bug-report-dialog footer small {font-size:11px;opacity:.7}
.bug-report-dialog footer button {border:0;border-radius:7px;background:var(--irlix-color-primary,#187a63);color:white;padding:9px 12px;cursor:pointer}
.bug-report-dialog footer button:disabled {opacity:.5;cursor:default}
.bug-report-error {color:var(--irlix-color-danger,#b22);font-size:12px}
</style>
