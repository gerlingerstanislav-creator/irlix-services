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
  gap: 24px;
  margin-bottom: 24px;
}
.ui-page-header__eyebrow { color: var(--irlix-color-primary-text); font-size: 12px; font-weight: 700; letter-spacing: .12em; }
h1 { margin: 6px 0 4px; color: var(--irlix-color-text); font-size: clamp(30px, 3vw, 44px); line-height: 1.08; letter-spacing: -.03em; }
p { margin: 0; color: var(--irlix-color-text-muted); }
.ui-page-header__actions { display: flex; gap: 8px; }
@media (max-width: 720px) { .ui-page-header { align-items: flex-start; flex-direction: column; } }
</style>
