<script setup>
defineProps({
  expanded: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  variant: { type: String, default: 'chevron' },
  label: { type: String, required: true },
  hover: { type: Boolean, default: true },
});
</script>

<template>
  <button type="button" class="ui-tree-toggle"
    :class="{ 'ui-tree-toggle--expanded': expanded, 'ui-tree-toggle--plus': variant === 'plus', 'ui-tree-toggle--hover': hover }"
    :disabled="disabled" :aria-expanded="expanded" :aria-label="label">
    <span v-if="variant === 'plus'" aria-hidden="true">{{ expanded ? '−' : '+' }}</span>
    <span v-else class="ui-tree-toggle__chevron" aria-hidden="true" />
  </button>
</template>

<style scoped>
.ui-tree-toggle { position:relative; z-index:1; flex:none; display:grid; place-items:center; width:22px; min-width:22px; height:22px; padding:0; border:0; border-radius:var(--irlix-radius-sm); background:transparent; cursor:pointer; }
.ui-tree-toggle--hover:hover { background:var(--irlix-tree-hover-bg); }
.ui-tree-toggle__chevron { width:7px; height:7px; border-right:1.5px solid var(--irlix-tree-icon); border-bottom:1.5px solid var(--irlix-tree-icon); transform:rotate(-45deg); transition:transform .14s ease; }
.ui-tree-toggle--expanded .ui-tree-toggle__chevron { transform:rotate(45deg); }
.ui-tree-toggle--plus { color:var(--irlix-color-primary); font-size:var(--irlix-font-size-section-title); font-weight:600; line-height:1; }
.ui-tree-toggle--plus:hover { color:var(--irlix-color-primary-text); }
.ui-tree-toggle:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:2px; }
.ui-tree-toggle:disabled { cursor:default; opacity:.45; }
@media (prefers-reduced-motion:reduce) { .ui-tree-toggle__chevron { transition:none; } }
</style>
