<script setup>
import { computed } from 'vue';
import { serviceGroups } from '../serviceCatalog';
const props = defineProps({
  service: { type: String, default: '' },
  serviceName: { type: String, default: '' },
  section: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  breadcrumbs: { type: Array, default: null },
  loading: { type: Boolean, default: false },
});
const serviceLabel = computed(() => props.serviceName || serviceGroups.flatMap(group => group.items).find(item => item.key === props.service)?.label || props.service);
const crumbs = computed(() => props.breadcrumbs ?? (props.items.find(item => item.id === props.section) ? [{ label: props.items.find(item => item.id === props.section).label }] : []));
</script>

<template>
  <header class="irlix-app-topbar irlix-ui" data-component="ui-app-topbar">
    <div class="irlix-app-topbar__viewport"><div class="irlix-app-topbar__row">
    <div class="irlix-app-topbar__context">
      <nav class="irlix-breadcrumbs" aria-label="Навигационная цепочка">
        <span class="irlix-breadcrumbs__service">{{ serviceLabel }}</span>
        <template v-for="(crumb, index) in crumbs" :key="`${index}:${crumb.label}`">
          <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
          <a v-if="crumb.href && index < crumbs.length - 1" class="irlix-breadcrumbs__link" :href="crumb.href">{{ crumb.label }}</a>
          <span v-else class="irlix-breadcrumbs__section" :aria-current="index === crumbs.length - 1 ? 'page' : undefined">{{ crumb.label }}</span>
        </template>
        <slot name="breadcrumb-extra" />
      </nav>
      <span v-if="loading" class="irlix-app-topbar__loading" role="status">Обновление…</span>
    </div>
    <div v-if="$slots.actions" class="irlix-app-topbar__actions topbar-actions"><slot name="actions" /></div>
    </div></div>
  </header>
</template>

<style>
.irlix-app-topbar { --irlix-button-compact-height:var(--irlix-control-height); position:sticky; top:0; z-index:30; flex:none; min-width:0; width:100%; margin:0; padding:0; border-bottom:1px solid var(--irlix-color-border); background:var(--irlix-color-surface); }
.irlix-app-topbar__viewport { width:100%; overflow-x:auto; scrollbar-width:none; }
.irlix-app-topbar__viewport::-webkit-scrollbar { display:none; }
.irlix-app-topbar__row { display:flex; align-items:center; justify-content:space-between; gap:var(--irlix-topbar-gap); width:max-content; min-width:100%; min-height:calc(var(--irlix-topbar-height) - 1px); padding:6px var(--irlix-topbar-padding-right) 6px var(--irlix-topbar-padding-left); box-sizing:border-box; }
.irlix-app-topbar__context,.irlix-app-topbar__actions { display:flex; align-items:center; gap:var(--irlix-topbar-actions-gap); flex:none; white-space:nowrap; }
.irlix-app-topbar__actions { margin-left:auto; }
.irlix-breadcrumbs,.irlix-breadcrumbs__extra { display:flex; align-items:center; gap:var(--irlix-topbar-breadcrumb-gap); flex:none; font-size:var(--irlix-topbar-font-size); white-space:nowrap; flex-wrap:nowrap; }
.irlix-breadcrumbs > * { flex:none; }
.irlix-breadcrumbs__service { color:var(--irlix-color-text-muted); }
.irlix-breadcrumbs__separator { color:var(--irlix-topbar-separator); }
.irlix-breadcrumbs__section { color:var(--irlix-color-text); font-weight:600; }
.irlix-breadcrumbs__link { color:var(--irlix-color-primary-text); text-decoration:none; }
.irlix-breadcrumbs__link:hover { text-decoration:underline; }
.irlix-breadcrumbs__link:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:2px; }
.irlix-app-topbar__loading { font-size:var(--irlix-font-size-caption); color:var(--irlix-color-text-muted); }
.irlix-app-topbar .ui-button { height:var(--irlix-control-height); min-height:var(--irlix-control-height); padding-block:0; border-radius:var(--irlix-control-radius); }
.irlix-app-topbar__actions > div:not(.ui-period-picker) { display:flex; align-items:center; gap:var(--irlix-topbar-actions-gap); flex:none; }
@media(max-width:720px) { .irlix-app-topbar { top:calc(var(--irlix-sidebar-item-height) + 13px); }.irlix-app-topbar__loading { display:none; } }
</style>
