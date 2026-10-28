import '@fontsource-variable/onest/wght.css';
import { createApp, h, ref, computed } from 'vue';
import { router } from './router';
import { UiAppSidebar, UiAppTopbar, UiServiceDashboard, UiErrorState, isPlatformAdministratorAccess, renderNavigationError } from '@irlix/ui';
import AttentionCenter from './AttentionCenter.vue';
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
  } catch (error) { console.error('Dashboard access lookup failed', error); throw error; }
};

const start = async () => {
  startup.stage?.('startup');
  if (window.location.pathname === '/auth/logout' || window.location.pathname === '/auth/logout/') { await platformLogout(); return; }
  const loading = document.getElementById('auth-loading');
  try {
    if (!await auth.init()) return;
    const access = await loadPlatformAccess();
    if (!access) throw new Error('Employees access response missing entitlement map');
    // The host proxy never serves /brandbook/* to unauthenticated requests.
    // Portal performs the ordinary corporate OIDC flow and requests a short
    // server-validated, HttpOnly navigation cookie for permitted employees.
    if (new URLSearchParams(window.location.search).get('brandbook') === '1') {
      startup.stage?.('brandbook-access-check');
      const response = await auth.fetch('/api/employees/brandbook/session', {
        method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!response.ok) {
        loading.hidden = false;
        loading.style.display = 'grid';
        renderNavigationError(loading, response.status === 403 ? 403 : 503);
        startup.done?.();
        return;
      }
      window.location.replace('/brandbook/');
      return;
    }

    // Design System is a private SSO entry point for every active employee.
    if (new URLSearchParams(window.location.search).get('design-system') === '1') {
      startup.stage?.('design-system-access-check');
      const response = await auth.fetch('/api/employees/design-system/session', {
        method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!response.ok) {
        loading.hidden = false;
        loading.style.display = 'grid';
        renderNavigationError(loading, response.status === 403 ? 403 : 503);
        startup.done?.();
        return;
      }
      const requested = new URLSearchParams(window.location.search).get('return') || '';
      // Accept internal catalog routes only, never arbitrary target origins.
      const next = /^\/design-system\/(?:[a-zA-Z0-9_\/-]*)$/.test(requested)
        ? requested : '/design-system/';
      window.location.replace(next);
      return;
    }

    // Storage Drive is a strictly-admin isolated pilot at /storage/.
    // The short-lived HttpOnly cookie is validated at nginx and Storage API.
    // Navigation stays on the canonical corporate origin, without :8110.
    if (new URLSearchParams(window.location.search).get('storage') === '1') {
      startup.stage?.('storage-access-check');
      const response = await auth.fetch('/api/employees/storage-pilot/session', {
        method:'POST',headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',
      });
      if (!response.ok) {
        loading.hidden = false; loading.style.display = 'grid';
        renderNavigationError(loading, response.status === 403 ? 403 : 503);
        startup.done?.();
        return;
      }
      window.location.replace('/storage/');
      return;
    }

    // Unknown paths must never be interpreted as the Dashboard homepage.
    if (router.resolve(window.location.pathname).name === 'not-found') {
      const shell = document.getElementById('portal-shell');
      shell.hidden = true;
      const errorRoot = document.createElement('div');
      shell.parentNode.insertBefore(errorRoot, shell);
      renderNavigationError(errorRoot, 404);
      loading.hidden = true;
      startup.done?.();
      return;
    }

    const platformAdmin = isPlatformAdministratorAccess(access);
    const section = computed(() => router.currentRoute.value.meta.section || 'not-found');
    const navigate = value => { if (router.hasRoute(value)) router.push({ name: value }); };
    const actionCount = ref(0);
    const navigation = [
      {id:'dashboard',label:'Дашборд',icon:'dashboard'},
      {id:'tasks',label:'Задачи',icon:'list'},
    ];
    const sidebarApp = createApp({ render: () => h(UiAppSidebar, {
      section: section.value, items: navigation.map(item => ({...item,badge:item.id==='tasks'?actionCount.value:0})),
      currentService: 'dashboard', currentUser: auth.user || {}, platformAdmin, serviceAccess: access, reportSubmit: (url, options) => auth.fetch(url, options),
      'onUpdate:section': navigate, onLogout: () => auth.logout(),
    }) });
    sidebarApp.use(router);
    await router.isReady();
    sidebarApp.mount('#portal-sidebar');
    createApp({ render: () => h(UiAppTopbar, {service:'dashboard',breadcrumbs:section.value === 'tasks' ? [{label:'Задачи'}] : []}) }).mount('#portal-topbar');
    createApp({
      setup() {
        return () => section.value === 'not-found'
          ? h(UiErrorState, { status:404,backHref:'/',backLabel:'На дашборд' })
          : h('div', {class: 'dashboard-content dashboard-home ' + (section.value === 'dashboard' ? 'dashboard-home--overview' : 'dashboard-home--tasks')}, [
          h(AttentionCenter, { auth, serviceAccess:access, full:section.value==='tasks', showAll:section.value==='dashboard', onOpenAll:()=>navigate('tasks'), onCount:value=>{actionCount.value=value} }),
          ...(section.value === 'dashboard' ? [h(UiServiceDashboard, {platformAdmin, serviceAccess:access, layout:'list', showToolbar:false})] : []),
        ]);
      },
    }).mount('#dashboard-services');
    document.getElementById('portal-shell').hidden=false; loading.hidden=true; startup.done?.();
  } catch (error) {
    console.error('Dashboard OIDC initialization failed', error);
    startup.fail?.(error instanceof Error ? error.message : String(error));
    loading.hidden = false;
    loading.style.display = 'block';
    renderNavigationError(loading, 503);
  }
};
start();
