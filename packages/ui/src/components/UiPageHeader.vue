<script setup>
defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, required: true },
  description: { type: String, default: '' },
});
</script>

<template>
  <header class="ui-page-header">
    <div>
      <div v-if="eyebrow" class="ui-page-header__eyebrow">{{ eyebrow }}</div>
      <h1>{{ title }}</h1>
      <p v-if="description">{{ description }}</p>
    </div>
    <div class="ui-page-header__actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped>
.ui-page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  margin-bottom: 16px;
}
.ui-page-header__eyebrow { color: var(--irlix-color-primary-text); font-size: 10px; font-weight: 700; letter-spacing: .10em; }
h1 { margin: 4px 0 3px; color: var(--irlix-color-text); font-size: clamp(24px, 2.4vw, 32px); line-height: 1.08; letter-spacing: -.02em; }
p { margin: 0; color: var(--irlix-color-text-muted); font-size: 13px; }
.ui-page-header__actions { display: flex; gap: 6px; }
@media (max-width: 720px) { .ui-page-header { align-items: flex-start; flex-direction: column; } }
</style>
