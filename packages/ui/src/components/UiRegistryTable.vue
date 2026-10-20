<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Explicit column widths make independent header/body tables use identical tracks.
// The header is OUTSIDE the vertical scroll viewport; the body owns both scrollbars.
const props = defineProps({
  columns: { type: Array, required: true, validator: value => value.length > 0 },
  minWidth: { type: String, default: '0px' },
  maxHeight: { type: String, default: '100%' },
  ariaLabel: { type: String, default: 'Реестр' },
  density: { type: String, default: 'normal', validator: value => ['normal','compact'].includes(value) },
});
const root = ref(null);
const viewport = ref(null);
const heading = ref(null);
const gutter = ref(0);
const vars = computed(() => ({
  '--irlix-registry-min-width': props.minWidth,
  '--irlix-registry-max-height': props.maxHeight,
  '--irlix-registry-gutter': gutter.value + 'px',
}));
const widths = computed(() => props.columns.map(column => typeof column === 'string' ? column : column.width));
let observer;
const measure = () => {
  const el = viewport.value;
  if (!el) return;
  const width = Math.max(0, el.offsetWidth - el.clientWidth);
  if (gutter.value !== width) gutter.value = width;
};
const onScroll = () => { if (heading.value && viewport.value) heading.value.scrollLeft = viewport.value.scrollLeft; };
onMounted(async () => {
  await nextTick();
  observer = new ResizeObserver(measure);
  if (root.value) observer.observe(root.value);
  if (viewport.value) observer.observe(viewport.value);
  measure();
});
onBeforeUnmount(() => observer?.disconnect());
watch(() => [props.columns, props.minWidth, props.maxHeight], async () => { await nextTick(); measure(); });
</script>

<template>
  <div ref="root" class="ui-registry-table" :class="{ 'ui-registry-table--compact': density === 'compact' }" :style="vars">
    <div class="ui-registry-table__header" aria-hidden="true">
      <div ref="heading" class="ui-registry-table__header-viewport">
        <table class="irlix-data-table ui-registry-table__table ui-registry-table__table--head">
          <colgroup><col v-for="(width, index) in widths" :key="index" :style="{ width }" /></colgroup>
          <thead><slot name="head" /></thead>
        </table>
      </div>
    </div>
    <div ref="viewport" class="ui-registry-table__scroll" role="region" :aria-label="ariaLabel" tabindex="0" @scroll="onScroll">
      <table class="irlix-data-table ui-registry-table__table ui-registry-table__table--body">
        <colgroup><col v-for="(width, index) in widths" :key="index" :style="{ width }" /></colgroup>
        <thead class="ui-registry-table__sr-head"><slot name="head" /></thead>
        <tbody><slot /></tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.ui-registry-table { display:flex; flex-direction:column; width:100%; height:100%; min-width:0; min-height:0; margin:0; padding:0; border:0; border-radius:0; box-shadow:none; background:var(--irlix-color-surface); }
.ui-registry-table__header { flex:0 0 auto; min-width:0; padding-right:var(--irlix-registry-gutter); background:var(--irlix-color-surface-muted); border-bottom:1px solid var(--irlix-color-border); }
.ui-registry-table__header-viewport { overflow:hidden; width:100%; }
.ui-registry-table__scroll { flex:1 1 auto; width:100%; min-width:0; min-height:0; max-height:var(--irlix-registry-max-height); overflow:auto; scrollbar-gutter:auto; overscroll-behavior:contain; }
.ui-registry-table__table { width:100%; min-width:var(--irlix-registry-min-width); table-layout:fixed; border-collapse:collapse; border-spacing:0; border-radius:0; }
.ui-registry-table__table--head :deep(th) { vertical-align:middle; text-align:left; white-space:nowrap; background:var(--irlix-color-surface-muted); }
.ui-registry-table__table--body :deep(td), .ui-registry-table__table--body :deep(th) { vertical-align:middle; }
.ui-registry-table__table--body :deep(tr) { background:var(--irlix-color-surface); }
.ui-registry-table--compact .ui-registry-table__table :deep(:is(th,td)) { padding:4px 9px; }
.ui-registry-table__sr-head { visibility:collapse; height:0; }
.ui-registry-table__sr-head :deep(tr), .ui-registry-table__sr-head :deep(th) { height:0; padding-top:0; padding-bottom:0; border:0; line-height:0; }
.ui-registry-table__scroll:focus-visible { outline:2px solid var(--irlix-color-primary-text); outline-offset:-2px; }
</style>
