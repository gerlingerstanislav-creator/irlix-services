<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import UiIcon from './UiIcon.vue';
import { serviceGroups as defaultServiceGroups } from '../serviceCatalog';

const props = defineProps({
  section: { type: String, required: true },
  items: { type: Array, default: () => [] },
  currentService: { type: String, required: true },
  currentUser: { type: Object, default: () => ({}) },
  bottomItems: { type: Array, default: () => [] },
  serviceGroups: { type: Array, default: () => defaultServiceGroups },
  ariaLabel: { type: String, default: 'Навигация сервиса' },
});

const emit = defineEmits(['update:section', 'logout']);
const showServices = ref(false);
const servicesLogo = ref(null);
const servicesPopover = ref(null);
const navScrollTop = ref(0);

const go = (key) => {
  showServices.value = false;
  emit('update:section', key);
};

const openService = (service) => {
  if (!service.available || !service.href) return;
  window.location.assign(service.href);
};

const handleDocumentPointer = (event) => {
  if (!showServices.value) return;
  if (servicesPopover.value?.contains(event.target) || servicesLogo.value?.contains(event.target)) return;
  showServices.value = false;
};

const handleNavScroll = (event) => {
  navScrollTop.value = event.currentTarget.scrollTop;
};

onMounted(() => document.addEventListener('pointerdown', handleDocumentPointer));
onBeforeUnmount(() => document.removeEventListener('pointerdown', handleDocumentPointer));
</script>

<template>
  <aside class="irlix-app-sidebar" :class="{ 'services-open': showServices }">
    <button
      ref="servicesLogo"
      class="services-logo"
      type="button"
      aria-label="Открыть список сервисов"
      :aria-expanded="showServices"
      @click="showServices = !showServices"
    >
      <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <path fill-rule="evenodd" clip-rule="evenodd" class="logo-one" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" />
        <path fill-rule="evenodd" clip-rule="evenodd" class="logo-two" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
      </svg>
    </button>
    <div class="sidebar-divider" />

    <div class="nav-hover-zone">
      <div class="nav-scroll" @scroll="handleNavScroll">
        <nav class="icon-nav" :aria-label="ariaLabel">
          <button
            v-for="item in items"
            :key="item.id || item.key"
            type="button"
            :class="{ active: props.section === (item.id || item.key) }"
            :aria-label="item.label"
            @click="go(item.id || item.key)"
          >
            <UiIcon :name="item.icon || 'list'" />
          </button>
        </nav>
      </div>

      <div class="nav-label-viewport">
        <div class="nav-labels" :style="{ transform: `translateY(${-navScrollTop}px)` }">
          <button
            v-for="item in items"
            :key="item.id || item.key"
            type="button"
            :class="{ active: props.section === (item.id || item.key) }"
            @click="go(item.id || item.key)"
          >{{ item.label }}</button>
        </div>
      </div>
    </div>

    <div class="sidebar-bottom" aria-label="Системные действия">
      <button
        v-for="item in bottomItems"
        :key="item.id || item.key"
        class="bottom-action"
        type="button"
        :class="{ active: props.section === (item.id || item.key) }"
        :aria-label="item.label"
        :title="item.label"
        @click="go(item.id || item.key)"
      ><UiIcon :name="item.icon || 'audit'" /></button>
      <button class="user-chip" type="button" :title="currentUser.preferred_username || currentUser.email || 'Пользователь'">
        {{ (currentUser.preferred_username || currentUser.email || 'U').slice(0, 1).toUpperCase() }}
      </button>
      <button class="logout-button" type="button" aria-label="Выйти" title="Выйти" @click="emit('logout')">↪</button>
    </div>

    <div v-if="showServices" ref="servicesPopover" class="services-popover">
      <div class="services-list">
        <section v-for="group in serviceGroups" :key="group.label" class="services-group">
          <div class="services-group__title">{{ group.label }}</div>
          <button
            v-for="service in group.items"
            :key="service.key"
            type="button"
            :disabled="!service.available || !service.href"
            :class="{ active: service.key === currentService }"
            @click="openService(service)"
          >
            <UiIcon class="service-icon" :name="service.icon || 'list'" />
            <span class="service-label">{{ service.label }}</span>
            <span v-if="service.key === currentService" class="service-current">Текущий</span>
          </button>
        </section>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.irlix-app-sidebar {
  position: sticky;
  top: 0;
  z-index: 40;
  width: var(--irlix-sidebar-width);
  height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: var(--irlix-sidebar-logo-edge-space) 0 10px;
  overflow: visible;
  background: var(--irlix-sidebar-bg);
  border-right: 1px solid var(--irlix-sidebar-border);
}
.services-logo {
  width: 52px;
  height: 44px;
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  margin-bottom: var(--irlix-sidebar-logo-edge-space);
  padding: 0;
  border: 0;
  border-radius: var(--irlix-sidebar-item-radius);
  background: transparent;
  cursor: pointer;
}
.services-logo:hover { background: var(--irlix-sidebar-logo-hover-bg); }
.logo-one { fill: var(--irlix-sidebar-logo-dark); }
.logo-two { fill: var(--irlix-color-primary); }
.sidebar-divider { width: 100%; height: 1px; flex: 0 0 auto; background: var(--irlix-sidebar-divider); }
.nav-hover-zone { position: relative; width: 100%; min-height: 0; flex: 1; overflow: visible; }
.nav-scroll { width: 100%; height: 100%; padding-top: 12px; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; }
.icon-nav { width: 100%; display: grid; justify-items: center; align-content: start; gap: 7px; padding-bottom: 8px; }
.icon-nav button, .sidebar-bottom button {
  width: var(--irlix-sidebar-item-width);
  height: var(--irlix-sidebar-item-height);
  display: grid;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: var(--irlix-sidebar-item-radius);
  background: transparent;
  color: var(--irlix-sidebar-icon);
  cursor: pointer;
}
.icon-nav button:hover, .sidebar-bottom button:hover { background: var(--irlix-sidebar-hover-bg); color: var(--irlix-sidebar-icon-hover); }
.icon-nav button.active, .sidebar-bottom button.active { color: var(--irlix-color-primary-text); background: var(--irlix-color-primary-soft); }
.icon-nav svg, .sidebar-bottom svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.nav-label-viewport {
  position: absolute;
  top: 12px;
  left: var(--irlix-sidebar-width);
  z-index: 45;
  width: max-content;
  max-width: calc(100vw - var(--irlix-sidebar-width));
  height: calc(100% - 12px);
  padding-left: var(--irlix-sidebar-label-gap);
  overflow: hidden;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity .12s ease, visibility .12s ease;
}
.nav-hover-zone:hover .nav-label-viewport { opacity: 1; visibility: visible; pointer-events: auto; }
.irlix-app-sidebar.services-open .nav-label-viewport { opacity: 0; visibility: hidden; pointer-events: none; }
.nav-labels {
  position: relative;
  top: 0;
  width: max-content;
  display: grid;
  grid-auto-rows: 40px;
  justify-items: start;
  align-items: center;
  gap: 7px;
  will-change: transform;
}
.nav-labels button {
  height: 30px;
  width: max-content;
  max-width: calc(100vw - var(--irlix-sidebar-width) - 24px);
  padding: 0 9px;
  border: 0;
  border-radius: 7px;
  background: var(--irlix-sidebar-label-bg);
  color: var(--irlix-sidebar-label-color);
  text-align: left;
  font-size: 12px;
  font-weight: 650;
  cursor: pointer;
  box-shadow: var(--irlix-sidebar-label-shadow);
  white-space: nowrap;
}
.nav-labels button:hover { background: var(--irlix-sidebar-label-hover-bg); }
.nav-labels button.active { background: var(--irlix-color-primary); }
.sidebar-bottom {
  flex: 0 0 auto;
  margin-top: auto;
  display: grid;
  gap: 5px;
  justify-items: center;
  padding-top: 6px;
  background: var(--irlix-sidebar-bg);
}
.sidebar-bottom .user-chip { border-radius: 50%; background: var(--irlix-color-primary-soft); color: var(--irlix-color-primary-text); font-size: 12px; font-weight: 750; }
.sidebar-bottom .logout-button { font-size: 16px; }
.services-popover {
  position: absolute;
  top: var(--irlix-sidebar-logo-edge-space);
  left: var(--irlix-sidebar-width);
  z-index: 70;
  width: var(--irlix-sidebar-popover-width);
  padding: 0;
  border: 1px solid var(--irlix-sidebar-popover-border);
  border-radius: 8px;
  background: var(--irlix-sidebar-popover-bg);
  box-shadow: var(--irlix-sidebar-popover-shadow);
  overflow: hidden;
}
.services-list { display: grid; }
.services-group { padding: 9px 10px 8px; }
.services-group + .services-group { border-top: 1px solid var(--irlix-sidebar-group-border); }
.services-group__title { padding: 0 0 6px; color: var(--irlix-sidebar-group-title); font-size: 12px; }
.services-group > button {
  display: grid;
  grid-template-columns: 28px minmax(0,1fr) auto;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-height: 36px;
  padding: 4px 7px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--irlix-sidebar-service-text);
  text-align: left;
  cursor: pointer;
}
.services-group > button:hover:not(:disabled) { background: var(--irlix-sidebar-service-hover-bg); color: var(--irlix-sidebar-service-hover-text); }
.services-group > button.active { background: var(--irlix-color-primary-soft); color: var(--irlix-color-primary-text); }
.services-group > button:disabled { cursor: default; opacity: var(--irlix-sidebar-service-disabled-opacity); }
.service-icon { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.6; }
.service-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; }
.service-current { padding: 2px 5px; border-radius: 4px; background: var(--irlix-sidebar-current-badge-bg); color: var(--irlix-sidebar-current-badge-text); font-size: 9px; font-weight: 700; text-transform: uppercase; }
@media (max-width: 720px) {
  .irlix-app-sidebar { position: sticky; width: 100%; height: auto; flex-direction: row; padding: 6px 8px; border-right: 0; border-bottom: 1px solid var(--irlix-sidebar-border); }
  .services-logo { margin-bottom: 0; }
  .sidebar-divider { display: none; }
  .nav-hover-zone { width: auto; padding: 0; margin-left: 6px; flex: 1; overflow: hidden; }
  .nav-scroll { height: auto; padding: 0; overflow-x: auto; overflow-y: hidden; }
  .icon-nav { width: max-content; display: flex; gap: 3px; padding: 0; }
  .nav-label-viewport, .sidebar-bottom { display: none; }
  .services-popover { top: 58px; left: 8px; }
}
</style>
