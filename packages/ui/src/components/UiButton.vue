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
  min-height:var(--irlix-control-height);
  border:1px solid transparent;
  border-radius:var(--irlix-control-radius);
  padding:0 11px;
  height:var(--irlix-control-height);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:var(--irlix-space-1);
  white-space:nowrap;
  flex-shrink:0;
  font-size:13px;
  font-weight:600;
  cursor:pointer;
}
.ui-button--primary { background:var(--irlix-color-primary); color:var(--irlix-color-on-accent); }
.ui-button--secondary { background:var(--irlix-color-surface); color:var(--irlix-color-text); border-color:var(--irlix-color-border-strong); }
.ui-button--ghost { background:transparent; color:var(--irlix-color-primary-text); }
.ui-button--danger { background:var(--irlix-color-danger); color:var(--irlix-color-on-accent); }
.ui-button--compact { height:var(--irlix-button-compact-height,27px); min-height:var(--irlix-button-compact-height,27px); padding:0 7px; font-size:12px; }
.ui-button:disabled { cursor:not-allowed; opacity:.45; filter:none; }
</style>
