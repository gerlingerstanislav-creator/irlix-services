<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import UiIcon from './UiIcon.vue';
import { getTheme, setTheme } from '../theme';
import { getVisibleServiceGroups, isPlatformAdminAccess } from '../serviceCatalog';

const props = defineProps({
  section: { type: String, required: true },
  items: { type: Array, default: () => [] },
  currentService: { type: String, required: true },
  currentUser: { type: Object, default: () => ({}) },
  bottomItems: { type: Array, default: () => [] },
  platformAdmin: { type: Boolean, default: false },
  platformAccess: { type: Function, default: null },
  serviceAccess: { type: Object, default: null },
  ariaLabel: { type: String, default: 'Навигация сервиса' },
});

const emit = defineEmits(['update:section', 'logout']);
const showServices = ref(false);
const showThemeMenu = ref(false);
const themeMode = ref(getTheme());
const themeOptions = [{ value: 'light', label: 'Светлая' }, { value: 'dark', label: 'Тёмная' }, { value: 'system', label: 'Системная' }];
function selectTheme(value) { setTheme(value); themeMode.value = value; showThemeMenu.value = false; }
function syncTheme(event) { themeMode.value = event.detail.mode; }
const servicesLogo = ref(null);
const servicesPopover = ref(null);
const navScrollTop = ref(0);
const hasMigrationAccess = ref(false);
const loadedServiceAccess = ref(null);
const visibleServiceGroups = computed(() => getVisibleServiceGroups(props.platformAdmin || hasMigrationAccess.value, props.serviceAccess || loadedServiceAccess.value));
let accessLoading = false;
let accessLoaded = false;
async function loadAccess() {
  if (!props.platformAccess || props.serviceAccess || accessLoading || accessLoaded) return;
  accessLoading = true;
  try {
    const response = await props.platformAccess();
    if (!response.ok) return;
    loadedServiceAccess.value = (await response.json())?.data || null;
    hasMigrationAccess.value = isPlatformAdminAccess(loadedServiceAccess.value);
    accessLoaded = true;
  } catch (_) { /* Retry on the next menu open after a transient request failure. */ }
  finally { accessLoading = false; }
}
watch(showServices, value => { if (value) loadAccess(); });

const go = (key, disabled = false) => {
  if (disabled) return;
  showServices.value = false;
  emit('update:section', key);
};

const openService = (service) => {
  if (!service.available || !service.href) return;
  window.location.assign(service.href);
};

const handleDocumentPointer = (event) => {
  if (!showServices.value && !showThemeMenu.value) return;
  if (event.target.closest?.('.sidebar-theme-control')) return;
  showThemeMenu.value = false;
  if (servicesPopover.value?.contains(event.target) || servicesLogo.value?.contains(event.target)) return;
  showServices.value = false;
};

const handleNavScroll = (event) => {
  navScrollTop.value = event.currentTarget.scrollTop;
};

onMounted(() => {
  document.addEventListener('pointerdown', handleDocumentPointer);
  document.addEventListener('irlix:theme-change', syncTheme);
  loadAccess();
});
onBeforeUnmount(() => { document.removeEventListener('pointerdown', handleDocumentPointer); document.removeEventListener('irlix:theme-change', syncTheme); });
</script>

<template>
  <aside class="irlix-app-sidebar" data-component="ui-app-sidebar" :class="{ 'services-open': showServices }">
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
          <div
            v-for="item in items"
            :key="item.id || item.key"
            class="nav-entry"
            :class="{ 'group-start': item.groupStart }"
          >
            <button
              type="button"
              :class="{ active: props.section === (item.id || item.key), disabled: item.disabled }"
              :disabled="item.disabled"
              :aria-label="item.label"
              @click="go(item.id || item.key,item.disabled)"
            >
              <UiIcon :name="item.icon || 'list'" />
            </button>
          </div>
        </nav>
      </div>

      <div class="nav-label-viewport">
        <div class="nav-labels" :style="{ transform: `translateY(${-navScrollTop}px)` }">
          <div
            v-for="item in items"
            :key="item.id || item.key"
            class="nav-label-entry"
            :class="{ 'group-start': item.groupStart }"
          >
            <button
              type="button"
              :class="{ active: props.section === (item.id || item.key), disabled: item.disabled }"
              :disabled="item.disabled"
              @click="go(item.id || item.key,item.disabled)"
            >{{ item.label }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="sidebar-theme-mobile sidebar-theme-control">
      <button type="button" class="theme-button" aria-label="Выбрать тему оформления" :aria-expanded="showThemeMenu" @click="showThemeMenu = !showThemeMenu; showServices = false">◐</button>
      <div v-if="showThemeMenu" class="sidebar-theme-popover" role="group" aria-label="Тема оформления">
        <button v-for="option in themeOptions" :key="option.value" type="button" :aria-pressed="themeMode === option.value" @click="selectTheme(option.value)">{{ option.label }}<span v-if="themeMode === option.value">✓</span></button>
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
      <div class="sidebar-theme-control">
        <button class="theme-button" type="button" :title="`Тема: ${themeOptions.find(option => option.value === themeMode)?.label}`" aria-label="Выбрать тему оформления" :aria-expanded="showThemeMenu" @click="showThemeMenu = !showThemeMenu; showServices = false"><span aria-hidden="true">{{ themeMode === 'dark' ? '☾' : themeMode === 'light' ? '☼' : '◐' }}</span></button>
        <div v-if="showThemeMenu" class="sidebar-theme-popover" role="group" aria-label="Тема оформления"><button v-for="option in themeOptions" :key="option.value" type="button" :aria-pressed="themeMode === option.value" @click="selectTheme(option.value)">{{ option.label }}<span v-if="themeMode === option.value">✓</span></button></div>
      </div>
      <button class="user-chip" type="button" :title="currentUser?.preferred_username || currentUser?.email || 'Пользователь'">
        {{ (currentUser?.preferred_username || currentUser?.email || 'U').slice(0, 1).toUpperCase() }}
      </button>
      <button class="logout-button" type="button" aria-label="Выйти" title="Выйти" @click="emit('logout')">↪</button>
    </div>

    <div v-if="showServices" ref="servicesPopover" class="services-popover">
      <div class="services-list">
        <section v-for="group in visibleServiceGroups" :key="group.label" class="services-group">
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
.sidebar-theme-control { position:relative; }
.sidebar-theme-mobile { display:none; }
.sidebar-theme-popover { position:absolute; bottom:0; left:calc(var(--irlix-sidebar-width) - 4px); z-index:90; width:160px; padding:5px; border:1px solid var(--irlix-sidebar-popover-border); border-radius:8px; background:var(--irlix-sidebar-popover-bg); box-shadow:var(--irlix-sidebar-popover-shadow); }
.sidebar-bottom .sidebar-theme-popover button { display:flex; width:100%; height:34px; align-items:center; justify-content:space-between; padding:0 10px; border:0; border-radius:5px; background:transparent; color:var(--irlix-color-text); text-align:left; cursor:pointer; font-family:var(--irlix-font-sans); font-size:var(--irlix-font-size-table); }
.sidebar-bottom .sidebar-theme-popover button:hover, .sidebar-bottom .sidebar-theme-popover button[aria-pressed="true"] { background:var(--irlix-color-primary-soft); color:var(--irlix-color-primary-text); }
.sidebar-bottom .theme-button { font-size:var(--irlix-font-size-page-title); line-height:1; }

.irlix-app-sidebar {
  position:fixed;
  top:0;
  left:0;
  z-index:40;
  width:var(--irlix-sidebar-width);
  height:100vh;
  display:flex;
  flex-direction:column;
  align-items:center;
  padding:var(--irlix-sidebar-logo-edge-space) 0 10px;
  overflow:visible;
  background:var(--irlix-sidebar-bg);
  border-right:1px solid var(--irlix-sidebar-border);
}
.services-logo {
  width:var(--irlix-sidebar-item-width);
  height:var(--irlix-sidebar-item-height);
  display:grid;
  place-items:center;
  flex:0 0 auto;
  margin-bottom:var(--irlix-sidebar-logo-edge-space);
  padding:0;
  border:0;
  border-radius:var(--irlix-sidebar-item-radius);
  background:transparent;
  cursor:pointer;
}
.services-logo:hover { background:var(--irlix-sidebar-logo-hover-bg); }
.logo-one { fill:var(--irlix-sidebar-logo-dark); }
.logo-two { fill:var(--irlix-color-primary); }
.sidebar-divider { width:100%; height:1px; flex:0 0 auto; background:var(--irlix-sidebar-divider); }
.nav-hover-zone { position:relative; width:100%; min-height:0; flex:1; overflow:visible; }
.nav-scroll { width:100%; height:100%; padding-top:12px; overflow-y:auto; overflow-x:hidden; scrollbar-width:thin; }
.icon-nav,
.nav-labels {
  display:flex;
  flex-direction:column;
  align-items:stretch;
  gap:7px;
}
.icon-nav { width:100%; padding-bottom:8px; }
.nav-entry,
.nav-label-entry {
  position:relative;
  flex:0 0 var(--irlix-sidebar-item-height);
  height:var(--irlix-sidebar-item-height);
}
.nav-entry { width:100%; display:grid; place-items:center; }
.nav-label-entry { display:flex; align-items:center; justify-content:flex-start; }
.nav-entry.group-start,
.nav-label-entry.group-start { margin-top:13px; }
.nav-entry.group-start::before {
  content:'';
  position:absolute;
  top:-7px;
  left:9px;
  right:9px;
  height:1px;
  background:var(--irlix-sidebar-divider);
}
.nav-entry button, .sidebar-bottom button {
  width:var(--irlix-sidebar-item-width);
  height:var(--irlix-sidebar-item-height);
  display:grid;
  place-items:center;
  padding:0;
  border:0;
  border-radius:var(--irlix-sidebar-item-radius);
  background:transparent;
  color:var(--irlix-sidebar-icon);
  cursor:pointer;
}
.nav-entry button:hover, .sidebar-bottom button:hover { background:var(--irlix-sidebar-hover-bg); color:var(--irlix-sidebar-icon-hover); }
.nav-entry button.active, .sidebar-bottom button.active { color:var(--irlix-color-primary-text); background:var(--irlix-color-primary-soft); }
.nav-entry button.disabled,.nav-entry button:disabled{color:var(--irlix-sidebar-icon);background:transparent;cursor:not-allowed;opacity:.62}
.nav-entry svg, .sidebar-bottom svg { width:20px; height:20px; fill:none; stroke:currentColor; stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round; }
.nav-label-viewport {
  position:absolute;
  top:12px;
  left:var(--irlix-sidebar-width);
  z-index:45;
  width:max-content;
  max-width:calc(100vw - var(--irlix-sidebar-width));
  height:calc(100% - 12px);
  padding-left:var(--irlix-sidebar-label-gap);
  overflow:hidden;
  opacity:0;
  visibility:hidden;
  pointer-events:none;
  transition:opacity .12s ease, visibility .12s ease;
}
.nav-hover-zone:hover .nav-label-viewport { opacity:1; visibility:visible; pointer-events:auto; }
.irlix-app-sidebar.services-open .nav-label-viewport { opacity:0; visibility:hidden; pointer-events:none; }
.nav-labels {
  position:relative;
  top:0;
  width:max-content;
  will-change:transform;
}
.nav-label-entry button {
  height:30px;
  width:max-content;
  max-width:calc(100vw - var(--irlix-sidebar-width) - 24px);
  padding:0 9px;
  border:0;
  border-radius:7px;
  background:var(--irlix-sidebar-label-bg);
  color:var(--irlix-sidebar-label-color);
  text-align:left;
  font-size:var(--irlix-font-size-caption);
  font-weight:650;
  cursor:pointer;
  box-shadow:var(--irlix-sidebar-label-shadow);
  white-space:nowrap;
}
.nav-label-entry button:hover { background:var(--irlix-sidebar-label-hover-bg); }
.nav-label-entry button.active { background:var(--irlix-color-primary); }
.nav-label-entry button.disabled,.nav-label-entry button:disabled{background:var(--irlix-color-surface-muted);color:var(--irlix-color-text-muted);cursor:not-allowed;box-shadow:none}
.sidebar-bottom {
  flex:0 0 auto;
  margin-top:auto;
  display:grid;
  gap:5px;
  justify-items:center;
  padding-top:6px;
  background:var(--irlix-sidebar-bg);
}
.sidebar-bottom .user-chip { border-radius:50%; background:var(--irlix-color-primary-soft); color:var(--irlix-color-primary-text); font-size:var(--irlix-font-size-caption); font-weight:750; }
.sidebar-bottom .logout-button { font-size:var(--irlix-font-size-section-title); }
.services-popover {
  position:absolute;
  top:var(--irlix-sidebar-logo-edge-space);
  left:var(--irlix-sidebar-width);
  z-index:70;
  width:var(--irlix-sidebar-popover-width);
  padding:0;
  border:1px solid var(--irlix-sidebar-popover-border);
  border-radius:8px;
  background:var(--irlix-sidebar-popover-bg);
  box-shadow:var(--irlix-sidebar-popover-shadow);
  max-height:calc(100dvh - 16px);
  overflow-y:auto;
  overscroll-behavior:contain;
}
.services-list { display:grid; }
.services-group { padding:9px 10px 8px; }
.services-group + .services-group { border-top:1px solid var(--irlix-sidebar-group-border); }
.services-group__title { padding:0 0 6px; color:var(--irlix-sidebar-group-title); font-size:var(--irlix-font-size-caption); }
.services-group > button {
  display:grid;
  grid-template-columns:28px minmax(0,1fr) auto;
  align-items:center;
  gap:8px;
  width:100%;
  min-height:36px;
  padding:4px 7px;
  border:0;
  border-radius:6px;
  background:transparent;
  color:var(--irlix-sidebar-service-text);
  text-align:left;
  cursor:pointer;
}
.services-group > button:hover:not(:disabled) { background:var(--irlix-sidebar-service-hover-bg); color:var(--irlix-sidebar-service-hover-text); }
.services-group > button.active { background:var(--irlix-color-primary-soft); color:var(--irlix-color-primary-text); }
.services-group > button:disabled { cursor:default; opacity:var(--irlix-sidebar-service-disabled-opacity); }
.service-icon { width:20px; height:20px; fill:none; stroke:currentColor; stroke-width:1.6; }
.service-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:var(--irlix-font-size-body); }
.service-current { padding:2px 5px; border-radius:4px; background:var(--irlix-sidebar-current-badge-bg); color:var(--irlix-sidebar-current-badge-text); font-size:var(--irlix-font-size-caption); font-weight:700; text-transform:uppercase; }
@media (max-width:720px) {
  .irlix-app-sidebar { position:sticky; left:auto; width:100%; height:auto; flex-direction:row; padding:6px 8px; border-right:0; border-bottom:1px solid var(--irlix-sidebar-border); }
  .services-logo { margin-bottom:0; }
  .sidebar-divider { display:none; }
  .nav-hover-zone { width:auto; padding:0; margin-left:6px; flex:1; overflow:hidden; }
  .nav-scroll { height:auto; padding:0; overflow-x:auto; overflow-y:hidden; }
  .icon-nav { width:max-content; flex-direction:row; gap:3px; padding:0; }
  .nav-entry { width:var(--irlix-sidebar-item-width); flex-basis:var(--irlix-sidebar-item-height); }
  .nav-entry.group-start { margin-top:0; margin-left:9px; }
  .nav-entry.group-start::before { display:none; }
  .nav-label-viewport, .sidebar-bottom { display:none; }
  .sidebar-theme-mobile { display:block; flex:0 0 auto; }
  .sidebar-theme-mobile .theme-button { width:42px; height:40px; border:0; background:transparent; color:var(--irlix-sidebar-icon); font-size:var(--irlix-font-size-page-title); cursor:pointer; }
  .sidebar-theme-mobile .sidebar-theme-popover { left:auto; right:0; bottom:auto; top:46px; }
  .sidebar-theme-mobile .sidebar-theme-popover button { display:flex; width:100%; height:34px; align-items:center; justify-content:space-between; padding:0 10px; color:var(--irlix-color-text); background:transparent; font-size:var(--irlix-font-size-table); }
  .sidebar-theme-mobile .sidebar-theme-popover button:hover { background:var(--irlix-color-primary-soft); color:var(--irlix-color-primary-text); }
  .services-popover { top:58px; left:8px; max-width:calc(100vw - 16px); max-height:calc(100dvh - 66px); }
}
</style>
