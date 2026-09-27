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
    await load();
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    saving.value = false;
  }
}

const formatDate = value => value ? new Date(value).toLocaleString('ru-RU') : '';

watch(() => props.memberId, load, { immediate: true });
</script>

<template>
  <section class="member-feedbacks">
    <h3>Фидбеки</h3>
    <form class="feedback-form" @submit.prevent="submit">
      <textarea v-model="text" rows="4" maxlength="10000" placeholder="Добавить фидбек по специалисту на этом проекте" required />
      <div v-if="error" class="feedback-error">{{ error }}</div>
      <UiButton type="submit" :disabled="saving || !text.trim()">{{ saving ? 'Сохраняю…' : 'Добавить фидбек' }}</UiButton>
    </form>

    <div v-if="loading" class="feedback-empty">Загрузка фидбеков…</div>
    <div v-else-if="!feedbacks.length" class="feedback-empty">Фидбеков пока нет.</div>
    <article v-for="feedback in feedbacks" :key="feedback.id" class="feedback-item">
      <div class="feedback-meta">
        <strong>{{ feedback.created_by_username || 'Пользователь' }}</strong>
        <span>{{ formatDate(feedback.created_at) }}</span>
      </div>
      <p>{{ feedback.text }}</p>
    </article>
  </section>
</template>

<style scoped>
.member-feedbacks{margin-top:28px;padding-top:20px;border-top:1px solid #e5e7eb}.member-feedbacks h3{margin:0 0 14px}.feedback-form{display:grid;gap:10px;margin-bottom:18px}.feedback-form textarea{width:100%;min-height:96px;resize:vertical;padding:10px 12px;border:1px solid #d8dde3;border-radius:8px;box-sizing:border-box;font:inherit}.feedback-error{padding:8px 10px;border-radius:8px;background:#fff2f2;color:#b42318;font-size:13px}.feedback-empty{padding:12px 0;color:#777}.feedback-item{padding:12px 0;border-top:1px solid #eceff2}.feedback-meta{display:flex;justify-content:space-between;gap:12px;color:#6b7280;font-size:12px}.feedback-meta strong{color:#42484f}.feedback-item p{margin:7px 0 0;white-space:pre-wrap;line-height:1.45}
</style>
