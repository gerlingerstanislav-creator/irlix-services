import '@fontsource-variable/onest/wght.css';
import { createApp, h, ref } from 'vue';
import { UiAppShell, UiLoader } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';
import MigrationWorkspace from './MigrationWorkspace.vue';

const auth = createBrowserAuth({ storagePrefix: 'irlix.migration.auth', defaultReturnTo: '/migration/' });
const state = ref('loading');
const message = ref('Проверяем доступ…');

createApp({
  setup() {
    return () => {
      if (state.value === 'console') return h(MigrationWorkspace, { auth });
      const status = () => h('main', {
        id: state.value === 'forbidden' ? 'forbidden-page' : 'auth-loading',
        class: 'mc-empty', role: state.value === 'error' ? 'alert' : 'status',
        style: state.value === 'loading' ? { minHeight: '65vh', display: 'grid', placeItems: 'center' } : {},
      }, state.value === 'loading' ? h(UiLoader, { label: message.value, showLabel: true }) : message.value);
      if (state.value === 'forbidden') return h(UiAppShell, {
        service: 'migration', currentUser: auth.user, breadcrumbs: [{ label: 'Нет доступа' }],
        onLogout: () => auth.logout(),
      }, { default: status });
      return status();
    };
  },
}).mount('#app');

async function verifyAccess() {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 12000);
  try {
    const response = await auth.fetch('/api/employees/access/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal,
    });
    if (response.status === 401 || response.status === 403) {
      message.value = 'Не удалось подтвердить права доступа (' + response.status + ').';
      state.value = 'error';
      return;
    }
    if (!response.ok) throw new Error('Сервис авторизации временно недоступен (' + response.status + ').');
    const payload = await response.json();
    if (payload?.data?.services?.migration === true) state.value = 'console';
    else {
      message.value = 'Требуется роль platform-admin';
      state.value = 'forbidden';
    }
  } catch (error) {
    // Network/5xx/timeout is not an access denial. Keep the loader and retry.
    console.warn('Migration access check unavailable; retrying', error instanceof Error ? error.message : String(error));
    message.value = 'Ожидаем восстановления проверки доступа…';
    state.value = 'loading';
    setTimeout(verifyAccess, 2000);
  } finally {
    clearTimeout(timeout);
  }
}

async function start() {
  try {
    if (!await auth.init()) return;
    // OIDC must process the original callback URL before changing its pathname.
    const url = new URL(window.location.href);
    if ((url.pathname === '/migration/' || url.pathname === '/migration') && url.searchParams.get('view') === 'control') {
      url.pathname = '/migration/control/';
      url.searchParams.delete('view');
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    } else if (url.pathname === '/migration/control') {
      url.pathname = '/migration/control/';
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    } else if (url.pathname === '/migration/console' || url.pathname === '/migration/console/' || url.pathname === '/migration') {
      url.pathname = '/migration/';
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    }
    await verifyAccess();
  } catch (error) {
    console.error('Migration initialization failed', error);
    message.value = 'Не удалось открыть пульт переноса. ' + (error instanceof Error ? error.message : String(error));
    state.value = 'error';
  }
}
start();
