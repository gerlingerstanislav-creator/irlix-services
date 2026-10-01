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
  </header>
</template>

<style>
.irlix-app-topbar { position:sticky; top:0; z-index:30; flex:none; min-height:var(--irlix-topbar-height); display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 12px 6px 20px; border-bottom:1px solid var(--irlix-color-border); background:var(--irlix-color-surface); }
.irlix-app-topbar__context,.irlix-app-topbar__actions { display:flex; align-items:center; gap:10px; min-width:0; }.irlix-app-topbar__actions { margin-left:auto; flex:none; }
.irlix-breadcrumbs { display:flex; align-items:center; gap:8px; min-width:0; font-size:12px; white-space:nowrap; flex-wrap:wrap; }.irlix-breadcrumbs__service { color:var(--irlix-color-text-muted); }.irlix-breadcrumbs__separator { color:var(--irlix-topbar-separator); }.irlix-breadcrumbs__section { color:var(--irlix-color-text); font-weight:600; }.irlix-breadcrumbs__link { color:var(--irlix-color-primary-text); text-decoration:none; }.irlix-breadcrumbs__link:hover { text-decoration:underline; }.irlix-breadcrumbs__link:focus-visible { outline:2px solid var(--irlix-color-primary); outline-offset:2px; }.irlix-app-topbar__loading { font-size:11px; color:var(--irlix-color-text-muted); }
.irlix-app-topbar .breadcrumb-mode { width:auto; min-width:112px; max-width:190px; }.irlix-app-topbar .breadcrumb-mode .ui-search-select__trigger { width:auto; min-width:112px; height:28px; min-height:28px; padding:0 28px 0 0; border:0; background:transparent; color:var(--irlix-color-primary-text); font-weight:600; box-shadow:none; }.irlix-app-topbar .breadcrumb-mode .ui-search-select__actions { right:0; }.irlix-app-topbar .breadcrumb-mode .ui-search-select__menu { min-width:180px; }
@media(max-width:720px) { .irlix-app-topbar { top:calc(var(--irlix-sidebar-item-height) + 13px); padding:6px 8px; gap:8px; flex-wrap:wrap; }.irlix-breadcrumbs { gap:5px; font-size:11px; }.irlix-app-topbar__actions { flex-wrap:wrap; }.irlix-app-topbar__loading { display:none; } }
</style>
