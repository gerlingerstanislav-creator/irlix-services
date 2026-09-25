<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { auth } from '../auth';

const props = defineProps({ section: { type: String, required: true }, canReadAudit: { type: Boolean, default: false } });
const emit = defineEmits(['update:section']);
const showServices = ref(false);
const servicesLogo = ref(null);
const servicesPopover = ref(null);
const currentUser = auth.user;

const navItems = computed(() => [
  { key: 'employees', label: 'Сотрудники', icon: 'users' },
  { key: 'departments', label: 'Подразделения', icon: 'org' },
]);

const serviceItems = [
  { key: 'dashboard', label: 'Dashboard', available: true, href: '/' },
  { key: 'employees', label: 'Сотрудники', available: true, href: '/employees/' },
  { key: 'vacations', label: 'Отсутствия', available: true, href: '/vacations/' },
  { key: 'clients', label: 'Клиенты', available: false },
  { key: 'specialists', label: 'Специалисты', available: false },
  { key: 'timesheets', label: 'Учет времени', available: false },
  { key: 'design-system', label: 'Design System', available: true, href: '/design-system/' },
];

const go = (key) => { showServices.value = false; emit('update:section', key); };
const openService = (service) => {
  if (!service.available) return;
  if (service.href) window.location.assign(service.href);
};
const handleDocumentPointer = (event) => {
  if (!showServices.value) return;
  if (servicesPopover.value?.contains(event.target) || servicesLogo.value?.contains(event.target)) return;
  showServices.value = false;
};
onMounted(() => document.addEventListener('pointerdown', handleDocumentPointer));
onBeforeUnmount(() => document.removeEventListener('pointerdown', handleDocumentPointer));
</script>

<template>
  <aside class="app-sidebar" :class="{ 'services-open': showServices }">
    <button ref="servicesLogo" class="services-logo" type="button" aria-label="Открыть список сервисов" :aria-expanded="showServices" @click="showServices = !showServices">
      <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path fill-rule="evenodd" clip-rule="evenodd" class="logo-one" d="M7.3125 7.67267H11.3897L24.6875 27.7584H20.6103L14.8396 19.0566L11.3897 24.2653H7.3125L12.801 15.9688L7.3125 7.67267Z" />
        <path fill-rule="evenodd" clip-rule="evenodd" class="logo-two" d="M20.6103 4.17932L16.1568 10.9162L18.1954 13.9727L24.6875 4.17932H20.6103Z" />
      </svg>
    </button>
    <div class="sidebar-divider" />

    <div class="nav-hover-zone">
      <nav class="icon-nav" aria-label="Навигация сервиса сотрудников">
        <button v-for="item in navItems" :key="item.key" type="button" :class="{ active: props.section === item.key }" :aria-label="item.label" @click="go(item.key)">
          <svg v-if="item.icon === 'users'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.4-4 2.4-6 5.5-6s5.1 2 5.5 6"/><circle cx="17" cy="9" r="2.3"/><path d="M15.5 14.2c3.3-.6 5 1.1 5.5 4.3"/></svg>
          <svg v-else-if="item.icon === 'org'" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M12 7.5v4M6 15.5v-3h12v3"/></svg>
        </button>
      </nav>
      <div class="nav-labels">
        <button v-for="item in navItems" :key="item.key" type="button" :class="{ active: props.section === item.key }" @click="go(item.key)">{{ item.label }}</button>
      </div>
    </div>

    <div class="sidebar-bottom" aria-label="Системные действия">
      <button v-if="props.canReadAudit" class="audit-link" type="button" :class="{ active: props.section === 'audit' }" aria-label="История действий" title="История действий" @click="go('audit')">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
      </button>
      <button class="user-chip" type="button" :title="currentUser.preferred_username || currentUser.email || 'Пользователь'">{{ (currentUser.preferred_username || currentUser.email || 'U').slice(0, 1).toUpperCase() }}</button>
      <button type="button" aria-label="Выйти" title="Выйти" @click="auth.logout">↪</button>
    </div>

    <div v-if="showServices" ref="servicesPopover" class="services-popover">
      <div class="services-popover__header"><strong>Сервисы</strong><button type="button" aria-label="Закрыть" @click="showServices = false">×</button></div>
      <div class="services-list">
        <button v-for="service in serviceItems" :key="service.key" type="button" :disabled="!service.available" :class="{ active: service.key === 'employees' }" @click="openService(service)">
          <span class="service-icon">{{ service.label.slice(0, 1) }}</span>
          <span class="service-copy"><strong>{{ service.label }}</strong><small>{{ service.available ? 'Открыть сервис' : 'Ещё не реализован' }}</small></span>
        </button>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.app-sidebar { position: sticky; top: 0; z-index: 40; width: 68px; height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 8px 0 10px; overflow: visible; background: #fff; border-right: 1px solid #e1e4e8; }
.services-logo { width: 52px; height: 44px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 8px; background: transparent; cursor: pointer; }
.services-logo:hover { background: #f5f7f7; }
.logo-one { fill: #15191f; } .logo-two { fill: var(--irlix-color-primary); }
.sidebar-divider { width: 100%; height: 1px; background: #eceef0; }
.nav-hover-zone { position: relative; width: 100%; padding-top: 12px; }
.icon-nav { width: 100%; display: grid; justify-items: center; gap: 7px; }
.icon-nav button, .sidebar-bottom button { width: 42px; height: 40px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 8px; background: transparent; color: #969da6; cursor: pointer; }
.icon-nav button:hover, .sidebar-bottom button:hover { background: #f3f5f5; color: #5f6770; }
.icon-nav button.active, .sidebar-bottom button.audit-link.active { color: var(--irlix-color-primary-text); background: var(--irlix-color-primary-soft); }
.icon-nav svg, .sidebar-bottom .audit-link svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.nav-labels { position: absolute; top: 12px; left: 76px; z-index: 45; display: grid; grid-auto-rows: 40px; align-items: center; gap: 7px; opacity: 0; visibility: hidden; transform: translateX(-4px); transition: opacity .12s ease, transform .12s ease, visibility .12s ease; }
.nav-hover-zone:hover .nav-labels, .nav-labels:hover { opacity: 1; visibility: visible; transform: translateX(0); }
.app-sidebar.services-open .nav-labels { opacity: 0; visibility: hidden; pointer-events: none; }
.nav-labels button { height: 30px; width: max-content; min-width: 92px; max-width: 122px; padding: 0 9px; border: 0; border-radius: 7px; background: rgba(67,69,72,.72); color: #fff; text-align: left; font-size: 12px; font-weight: 650; cursor: pointer; box-shadow: 0 3px 10px rgba(19,25,32,.10); white-space: nowrap; }
.nav-labels button:hover { background: rgba(45,47,50,.86); } .nav-labels button.active { background: var(--irlix-color-primary); }
.sidebar-bottom { margin-top: auto; display: grid; gap: 5px; justify-items: center; }
.sidebar-bottom button { font-size: 16px; color: #9aa0a8; }
.sidebar-bottom .audit-link { margin-bottom: 5px; }
.sidebar-bottom .user-chip { border-radius: 50%; background: var(--irlix-color-primary-soft); color: var(--irlix-color-primary-text); font-size: 12px; font-weight: 750; }
.services-popover { position: absolute; top: 8px; left: 76px; z-index: 70; width: 276px; padding: 8px; border: 1px solid #e3e5e8; border-radius: 12px; background: #fff; box-shadow: 0 12px 34px rgba(20,27,38,.14); }
.services-popover__header { display: flex; align-items: center; justify-content: space-between; padding: 5px 6px 8px 10px; }
.services-popover__header strong { font-size: 14px; } .services-popover__header button { width: 28px; height: 28px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: #858c95; font-size: 20px; cursor: pointer; }
.services-list { display: grid; gap: 3px; }
.services-list > button { display: grid; grid-template-columns: 36px 1fr; align-items: center; gap: 9px; width: 100%; min-height: 50px; padding: 6px 8px; border: 0; border-radius: 8px; background: transparent; color: #22272e; text-align: left; cursor: pointer; }
.services-list > button:hover:not(:disabled) { background: #f3f8f6; } .services-list > button.active { background: #eef9f5; } .services-list > button:disabled { cursor: default; opacity: .5; }
.service-icon { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 8px; background: #eef1f2; color: #53606a; font-weight: 700; }
.services-list > button.active .service-icon { background: var(--irlix-color-primary-soft); color: var(--irlix-color-primary-text); }
.service-copy { display: grid; gap: 2px; } .service-copy strong { font-size: 13px; } .service-copy small { color: #8b929b; font-size: 11px; font-weight: 400; }
@media (max-width: 720px) { .app-sidebar { position: static; width: 100%; height: auto; flex-direction: row; padding: 6px 8px; border-right: 0; border-bottom: 1px solid #e1e4e8; } .sidebar-divider { display: none; } .nav-hover-zone { width: auto; padding: 0; margin-left: 6px; } .icon-nav { width: auto; display: flex; gap: 3px; } .sidebar-bottom { display: none; } .nav-labels { display: none; } .services-popover { top: 58px; left: 8px; } }
</style>
