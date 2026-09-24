<script setup>
defineProps({
  variant: { type: String, default: 'primary' },
  compact: { type: Boolean, default: false },
});
</script>

<template>
  <button class="ui-button" :class="[`ui-button--${variant}`, { 'ui-button--compact': compact }]">
    <slot />
  </button>
</template>

<style scoped>
.ui-button {
  min-height: var(--irlix-control-height);
  border: 1px solid transparent;
  border-radius: var(--irlix-radius-sm);
  padding: 6px 11px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.ui-button--primary { background: var(--irlix-color-primary); color: #fff; }
.ui-button--secondary { background: #fff; color: var(--irlix-color-text); border-color: var(--irlix-color-border-strong); }
.ui-button--ghost { background: transparent; color: var(--irlix-color-primary-text); }
.ui-button--danger { background: var(--irlix-color-danger); color: #fff; }
.ui-button--compact { min-height: 27px; padding: 3px 7px; font-size: 12px; }
.ui-button:disabled { cursor: not-allowed; opacity: .45; filter: none; }
</style>
