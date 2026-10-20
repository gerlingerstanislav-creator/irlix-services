<script setup>
defineProps({
  label: { type: String, default: 'Загрузка…' },
  overlay: { type: Boolean, default: false },
  showLabel: { type: Boolean, default: false },
  size: { type: String, default: 'normal', validator: value => ['small', 'normal'].includes(value) },
});
</script>

<template>
  <div
    class="ui-loader"
    :class="{ 'ui-loader--overlay': overlay, 'ui-loader--small': size === 'small' }"
    role="status"
    aria-live="polite"
    :aria-label="label"
  >
    <span class="ui-loader__spinner" aria-hidden="true" />
    <span v-if="showLabel" class="ui-loader__label">{{ label }}</span>
  </div>
</template>

<style scoped>
.ui-loader {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  color: var(--irlix-color-text-muted);
  font-size: var(--irlix-font-size-caption);
}
.ui-loader--overlay {
  position: absolute;
  z-index: 3;
  inset: 0;
  width: 100%;
  height: 100%;
  background: color-mix(in srgb, var(--irlix-color-surface) 86%, transparent);
}
.ui-loader__spinner {
  display: block;
  flex: none;
  width: 26px;
  height: 26px;
  border: 2px solid color-mix(in srgb, var(--irlix-color-primary-text) 20%, transparent);
  border-top-color: var(--irlix-color-primary-text);
  border-radius: 50%;
  animation: irlix-ui-loader-spin .8s linear infinite;
}
.ui-loader--small .ui-loader__spinner { width: 18px; height: 18px; }
@keyframes irlix-ui-loader-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
  .ui-loader__spinner { animation: none; }
}
</style>
