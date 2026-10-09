import '@fontsource-variable/onest/wght.css';
import { createApp, h, ref, onMounted, onUnmounted } from 'vue';
import { UiAppSidebar, UiAppTopbar, UiServiceDashboard, isPlatformAdminAccess } from '@irlix/ui';
import ResourceMonitor from './ResourceMonitor.vue';
import { canViewResources, dashboardSection } from './resourceAccess.js';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';
const startup = window.__irlixStartup || { stage: () => {}, moduleStarted: () => {}, done: () => {}, fail: () => {} };
startup.moduleStarted?.('portal-main'); startup.stage?.('module-loaded');
const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/', onStage: ({stage,detail}) => startup.stage?.(stage,detail) });
const clearPlatformSessionStorage = () => {
  let idToken = null;
  for (let index = sessionStorage.length - 1; index >= 0; index -= 1) {
    const key = sessionStorage.key(index);
    if (!key || !key.startsWith('irlix.') || !key.includes('.auth.')) continue;
    if (!idToken && key.endsWith('.tokens')) { try { idToken = JSON.parse(sessionStorage.getItem(key) || 'null')?.id_token || null; } catch (_) { idToken = null; } }
    sessionStorage.removeItem(key);
  }
  return idToken;
};

const platformLogout = async () => {
  const loading = document.getElementById('auth-loading');
  loading.hidden = false; loading.style.display = 'grid'; loading.textContent = 'Сбрасываем авторизацию…';
  const idToken = clearPlatformSessionStorage();
  try {
    const response = await fetch('/keycloak/auth/realms/irlix/.well-known/openid-configuration', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error(`OIDC discovery failed (${response.status})`);
    const discovery = await response.json();
    if (!discovery.end_session_endpoint) throw new Error('OIDC logout endpoint is unavailable');
    const params = new URLSearchParams({ client_id: 'irlix-services-web', post_logout_redirect_uri: `${window.location.origin}/` });
    if (idToken) params.set('id_token_hint', idToken);
    window.location.replace(`${discovery.end_session_endpoint}?${params.toString()}`);
  } catch (error) {
    console.error('Platform logout failed', error);
    loading.textContent = 'Локальная авторизация сброшена. Возвращаемся на Dashboard…';
    window.setTimeout(() => window.location.replace('/'), 800);
  }
};

const loadPlatformAccess = async () => {
  startup.stage?.('access-check', '/api/employees/access/me');
  try {
    const response = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error(`Employees access lookup failed (${response.status})`);
    const payload = await response.json();
    startup.stage?.('access-check-complete');
    return payload?.data || null;
  } catch (error) { console.error('Dashboard access lookup failed', error); return null; }
};

const start = async () => {
  startup.stage?.('startup');
  if (window.location.pathname === '/auth/logout' || window.location.pathname === '/auth/logout/') { await platformLogout(); return; }
  const loading = document.getElementById('auth-loading');
  try {
    if (!await auth.init()) return;
    const access = await loadPlatformAccess();
    const platformAdmin = isPlatformAdminAccess(access);
    const resourceAdmin = canViewResources(access);
    const section = ref(dashboardSection(location.pathname));
    const navigate = value => {
      section.value = value;
      const path = value === 'resources' ? '/resources/' : '/';
      if (location.pathname !== path) history.pushState(null, '', path);
    };
    const navigation = [{id:'dashboard',label:'Дашборд',icon:'dashboard'},
      ...(resourceAdmin ? [{id:'resources',label:'Ресурсный монитор',icon:'chart'}] : [])];
    createApp({ render: () => h(UiAppSidebar, {
      section: section.value, items: navigation,
      currentService: 'dashboard', currentUser: auth.user || {}, platformAdmin, serviceAccess: access,
      'onUpdate:section': navigate, onLogout: () => auth.logout(),
    }) }).mount('#portal-sidebar');
    createApp({ render: () => h(UiAppTopbar, {service:'dashboard',breadcrumbs:section.value === 'resources' ? [{label:'Ресурсный монитор'}] : []}) }).mount('#portal-topbar');
    createApp({
      setup() {
        const back = () => { section.value = dashboardSection(location.pathname); };
        onMounted(() => window.addEventListener('popstate', back));
        onUnmounted(() => window.removeEventListener('popstate', back));
        return () => h('div', {class:'dashboard-content'}, [
          section.value === 'resources'
            ? (resourceAdmin ? h(ResourceMonitor, {auth}) : h('p', {class:'resource-denied',role:'alert'}, 'Доступно администратору платформы и системному администратору'))
            : h(UiServiceDashboard, {platformAdmin, serviceAccess: access}),
        ]);
      },
    }).mount('#dashboard-services');
    document.getElementById('portal-shell').hidden=false; loading.hidden=true; startup.done?.();
  } catch (error) {
    console.error('Dashboard OIDC initialization failed', error);
    startup.fail?.(error instanceof Error ? error.message : String(error));
    if (!window.__irlixStartup) { loading.hidden=false; loading.textContent='Не удалось завершить авторизацию. '+(error instanceof Error ? error.message : String(error)); }
  }
};
start();
