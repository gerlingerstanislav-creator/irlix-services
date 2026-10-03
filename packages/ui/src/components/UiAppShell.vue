<script setup>
import UiAppSidebar from './UiAppSidebar.vue';
import UiAppTopbar from './UiAppTopbar.vue';
const props = defineProps({
  service: { type: String, required: true },
  serviceName: { type: String, default: '' },
  section: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  bottomItems: { type: Array, default: () => [] },
  currentUser: { type: Object, default: null },
  platformAccess: { type: Function, default: null },
  breadcrumbs: { type: Array, default: null },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:section', 'logout']);
</script>

<template>
  <div class="irlix-app-shell irlix-ui">
    <slot name="sidebar"><UiAppSidebar :section="section" :items="items" :bottom-items="bottomItems" :current-service="service" :current-user="currentUser" :platform-access="platformAccess" @update:section="emit('update:section', $event)" @logout="emit('logout')" /></slot>
    <section class="irlix-app-shell__workspace">
      <UiAppTopbar :service="service" :service-name="serviceName" :section="section" :items="[...items, ...bottomItems]" :breadcrumbs="breadcrumbs" :loading="loading">
        <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        <template v-if="$slots['breadcrumb-extra']" #breadcrumb-extra><slot name="breadcrumb-extra" /></template>
      </UiAppTopbar>
      <slot />
    </section>
  </div>
</template>

<style>
.irlix-app-shell { display:grid; grid-template-columns:var(--irlix-sidebar-width) minmax(0,1fr); min-height:100vh; }.irlix-app-shell__workspace { grid-column:2; min-width:0; display:flex; flex-direction:column; }
@media(max-width:720px) { .irlix-app-shell { grid-template-columns:minmax(0,1fr); }.irlix-app-shell__workspace { grid-column:1; } }
</style>
