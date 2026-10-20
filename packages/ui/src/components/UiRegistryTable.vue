<script setup>
import { computed } from 'vue';

const props = defineProps({
  minWidth: { type: String, default: '0px' },
  maxHeight: { type: String, default: '100%' },
  ariaLabel: { type: String, default: 'Реестр' },
  density: { type: String, default: 'normal', validator: value => ['normal', 'compact'].includes(value) },
});
const vars = computed(() => ({
  '--irlix-registry-min-width': props.minWidth,
  '--irlix-registry-max-height': props.maxHeight,
}));
</script>

<template>
  <div class="ui-registry-table" :class="{ 'ui-registry-table--compact': density === 'compact' }" :style="vars">
    <div class="ui-registry-table__scroll" role="region" :aria-label="ariaLabel" tabindex="0">
      <table class="irlix-data-table ui-registry-table__table">
        <thead><slot name="head" /></thead>
        <tbody><slot /></tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.ui-registry-table { display:flex; flex-direction:column; min-width:0; min-height:0; height:100%; }
.ui-registry-table__scroll { flex:1 1 auto; min-height:0; min-width:0; max-height:var(--irlix-registry-max-height); overflow:auto; scrollbar-gutter:auto; overscroll-behavior:contain; }
.ui-registry-table__table { min-width:var(--irlix-registry-min-width); width:100%; table-layout:auto; border-spacing:0; }
.ui-registry-table__table thead th { position:sticky; top:0; z-index:2; background:var(--irlix-color-surface-muted); }
.ui-registry-table__table tbody td,
.ui-registry-table__table tbody th { vertical-align:middle; }
.ui-registry-table--compact .ui-registry-table__table :is(th,td) { padding:4px 9px; }
.ui-registry-table__scroll:focus-visible { outline:2px solid var(--irlix-color-primary-text); outline-offset:-2px; }
</style>
