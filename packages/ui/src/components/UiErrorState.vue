<script setup>
import { computed } from 'vue';

const props = defineProps({
  status: { type: Number, required: true },
  description: { type: String, default: '' },
  backHref: { type: String, default: '/' },
  backLabel: { type: String, default: 'На дашборд' },
  retryable: { type: Boolean, default: false },
});
const emit = defineEmits(['retry']);
const defaults = {
  401: ['Требуется вход', 'Войдите в систему, чтобы продолжить.'],
  403: ['Доступ запрещён', 'У вас нет прав для просмотра этого раздела.'],
  404: ['Страница не найдена', 'Проверьте адрес или вернитесь к списку.'],
  409: ['Конфликт изменений', 'Обновите данные и попробуйте ещё раз.'],
  500: ['Ошибка сервера', 'Не удалось загрузить страницу.'],
  503: ['Сервис временно недоступен', 'Повторите попытку позднее.'],
};
const message = computed(() => defaults[props.status] || ['Ошибка', 'Не удалось выполнить запрос.']);
</script>

<template>
  <section class="irlix-error-state" role="alert" :data-error-status="status">
    <div class="irlix-error-state__code">{{ status }}</div>
    <h1>{{ message[0] }}</h1>
    <p>{{ description || message[1] }}</p>
    <div class="irlix-error-state__actions">
      <a :href="backHref">{{ backLabel }}</a>
      <button v-if="retryable" type="button" @click="emit('retry')">Повторить</button>
    </div>
  </section>
</template>

<style scoped>
.irlix-error-state {
  box-sizing: border-box;
  width: min(100%, 640px);
  margin: 36px auto;
  padding: clamp(24px, 5vw, 48px);
  border: 1px solid var(--irlix-color-border, #dce5df);
  border-radius: 16px;
  background: var(--irlix-color-surface, #fff);
  color: var(--irlix-color-text, #172b22);
  text-align: center;
}
.irlix-error-state__code { font-size: clamp(48px, 9vw, 80px); font-weight: 700; line-height: 1; opacity: .55; }
.irlix-error-state h1 { margin: 16px 0 8px; font-size: 22px; }
.irlix-error-state p { margin: 0; line-height: 1.5; }
.irlix-error-state__actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 16px; margin-top: 24px; }
.irlix-error-state__actions a, .irlix-error-state__actions button { font: inherit; cursor: pointer; }
</style>
