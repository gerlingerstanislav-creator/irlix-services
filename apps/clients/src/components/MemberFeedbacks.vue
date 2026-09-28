<script setup>
import { ref, watch } from 'vue';
import { UiButton } from '@irlix/ui';

const props = defineProps({
  memberId: { type: Number, required: true },
});

const feedbacks = ref([]);
const text = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const adding = ref(false);

async function api(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(', ') || `HTTP ${response.status}`);
  return body;
}

async function load() {
  if (!props.memberId) return;
  loading.value = true;
  error.value = '';
  try {
    feedbacks.value = (await api(`/api/clients/members/${props.memberId}/feedbacks`)).data || [];
  } catch (e) {
    feedbacks.value = [];
    error.value = e.message || String(e);
  } finally {
    loading.value = false;
  }
}

async function submit() {
  const value = text.value.trim();
  if (!value || saving.value) return;
  saving.value = true;
  error.value = '';
  try {
    await api(`/api/clients/members/${props.memberId}/feedbacks`, {
      method: 'POST',
      body: JSON.stringify({ text: value }),
    });
    text.value = '';
    adding.value = false;
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

const formatDate = value => value ? new Date(value).toLocaleDateString('ru-RU') : '';

watch(() => props.memberId, () => {
  adding.value = false;
  text.value = '';
  load();
}, { immediate: true });
</script>

<template>
  <section class="member-feedbacks">
    <div class="section-head">
      <strong>Фидбеки</strong>
      <button class="section-action" type="button" @click="adding = !adding">＋ фидбек</button>
    </div>

    <form v-if="adding" class="feedback-form" @submit.prevent="submit">
      <textarea v-model="text" rows="4" maxlength="10000" placeholder="Добавить фидбек по специалисту на этом проекте" required />
      <div v-if="error" class="feedback-error">{{ error }}</div>
      <div class="feedback-form__actions">
        <UiButton type="submit" :disabled="saving || !text.trim()">{{ saving ? 'Сохраняю…' : 'Сохранить' }}</UiButton>
        <UiButton type="button" variant="secondary" @click="adding=false;text=''">Отмена</UiButton>
      </div>
    </form>

    <div v-if="loading" class="feedback-empty">Загрузка фидбеков…</div>
    <div v-else-if="!feedbacks.length" class="feedback-empty">Фидбеков пока нет.</div>
    <article v-for="feedback in feedbacks" :key="feedback.id" class="feedback-item">
      <p>{{ feedback.text }}</p>
      <time>{{ formatDate(feedback.created_at) }}</time>
    </article>
  </section>
</template>

<style scoped>
.member-feedbacks{margin:22px 0 0}.section-head{min-height:34px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 0 8px;border-bottom:1px solid #dfe3e7}.section-head strong{font-size:14px;font-weight:600;color:#303841}.section-action{border:0;background:transparent;color:#078d6c;font-size:11px;font-weight:600;cursor:pointer}.feedback-form{display:grid;gap:10px;padding:12px 0;border-bottom:1px solid #eceff2}.feedback-form textarea{width:100%;min-height:96px;resize:vertical;padding:10px 12px;border:1px solid var(--irlix-control-border,#dde1e7);border-radius:var(--irlix-control-radius,10px);box-sizing:border-box;font:inherit;outline:0}.feedback-form textarea:focus{border-color:var(--irlix-color-primary);box-shadow:0 0 0 2px rgba(18,184,144,.1)}.feedback-form__actions{display:flex;gap:8px}.feedback-error{padding:8px 10px;border-radius:8px;background:#fff2f2;color:#b42318;font-size:13px}.feedback-empty{padding:12px 0;color:#777;font-size:12px}.feedback-item{padding:12px 0;border-bottom:1px solid #eceff2}.feedback-item p{margin:0;white-space:pre-wrap;line-height:1.45;font-size:13px;color:#303841}.feedback-item time{display:block;margin-top:6px;color:#8b929b;font-size:11px}
</style>
