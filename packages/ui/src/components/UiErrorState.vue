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
    <div class="irlix-error-state__content">
      <div class="irlix-error-state__eyebrow">IRLIX SERVICES · ОШИБКА</div>
      <div class="irlix-error-state__code">{{ status }}</div>
      <h1>{{ message[0] }}</h1>
      <p>{{ description || message[1] }}</p>
      <div class="irlix-error-state__actions">
        <a :href="backHref">{{ backLabel }}</a>
        <button v-if="retryable" type="button" @click="emit('retry')">Повторить</button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.irlix-error-state {
  box-sizing: border-box;
  display: grid;
  place-items: center;
  width: 100%;
  min-height: max(360px, calc(100dvh - var(--irlix-topbar-height, 64px) - 72px));
  margin: 0 auto;
  padding: 24px;
  font-family: var(--irlix-font-sans, inherit);
  color: var(--irlix-color-text, #172b22);
  text-align: center;
}
.irlix-error-state__content {
  box-sizing: border-box;
  width: min(100%, 500px);
  padding: clamp(24px, 4vw, 44px);
  border: 1px solid var(--irlix-color-border, #dce5df);
  border-radius: var(--irlix-radius-lg, 16px);
  background: var(--irlix-color-surface, #fff);
  box-shadow: var(--irlix-shadow-sm, 0 8px 28px rgba(16, 45, 30, .05));
}
.irlix-error-state__eyebrow {
  color: var(--irlix-color-primary-text, #087b59);
  font-size: 11px;
  font-weight: 700;
  line-height: 1.4;
  letter-spacing: .13em;
}
.irlix-error-state__code {
  margin-top: 12px;
  color: var(--irlix-color-primary-text, #087b59);
  font-size: clamp(64px, 9vw, 100px);
  font-weight: 750;
  line-height: 1;
  letter-spacing: -.065em;
}
.irlix-error-state h1 {
  margin: 18px 0 10px;
  font-size: clamp(21px, 3vw, 26px);
  line-height: 1.25;
  font-weight: 700;
}
.irlix-error-state p {
  max-width: 36ch;
  margin: 0 auto;
  color: var(--irlix-color-text-muted, #66776e);
  font-size: 14px;
  line-height: 1.65;
}
.irlix-error-state__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-top: 28px;
}
.irlix-error-state__actions a,
.irlix-error-state__actions button {
  box-sizing: border-box;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 40px;
  padding: 9px 18px;
  border: 1px solid var(--irlix-color-border-strong, #c8d6ce);
  border-radius: var(--irlix-control-radius, 9px);
  background: var(--irlix-color-surface, #fff);
  color: var(--irlix-color-primary-text, #087b59);
  font: inherit;
  font-size: 13px;
  font-weight: 650;
  line-height: 1.4;
  text-decoration: none;
  cursor: pointer;
}
.irlix-error-state__actions a {
  border-color: var(--irlix-color-primary, #159c70);
  background: var(--irlix-color-primary, #159c70);
  color: var(--irlix-color-on-primary, #fff);
}
.irlix-error-state__actions a:hover,
.irlix-error-state__actions button:hover { filter: brightness(.96); }
.irlix-error-state__actions a:focus-visible,
.irlix-error-state__actions button:focus-visible {
  outline: 2px solid var(--irlix-color-primary, #159c70);
  outline-offset: 3px;
}
@media (max-width: 720px) {
  .irlix-error-state { min-height: max(300px, calc(100dvh - 128px)); padding: 14px; }
  .irlix-error-state__content { padding: 28px 20px; }
}
</style>
