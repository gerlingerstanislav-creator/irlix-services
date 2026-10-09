import { createApp, h, ref } from 'vue';
import { UiAppShell, isPlatformAdministratorAccess } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';
import MigrationConsole from './MigrationConsole.vue';

const auth = createBrowserAuth({ storagePrefix: 'irlix.migration.auth', defaultReturnTo: '/migration/' });
const state = ref('loading');
const message = ref('Проверяем доступ…');

createApp({
  setup() {
    return () => {
      if (state.value === 'console') return h(MigrationConsole, { auth });
      const status = () => h('main', {
        id: state.value === 'forbidden' ? 'forbidden-page' : 'auth-loading',
        class: 'mc-empty', role: state.value === 'error' ? 'alert' : 'status',
      }, message.value);
      if (state.value === 'forbidden') return h(UiAppShell, {
        service: 'migration', currentUser: auth.user, breadcrumbs: [{ label: 'Нет доступа' }],
        onLogout: () => auth.logout(),
      }, { default: status });
      return status();
    };
  },
}).mount('#app');

async function start() {
  try {
    if (!await auth.init()) return;
    // OIDC must process the original callback URL before changing its pathname.
    const url = new URL(window.location.href);
    if (url.pathname === '/migration/console' || url.pathname === '/migration/console/' || url.pathname === '/migration') {
      url.pathname = '/migration/';
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    }
    const response = await auth.fetch('/api/employees/access/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (!response.ok) throw new Error(`Не удалось проверить права доступа (${response.status}).`);
    const payload = await response.json();
    if (isPlatformAdministratorAccess(payload?.data)) state.value = 'console';
    else {
      message.value = 'Требуется роль platform-admin';
      state.value = 'forbidden';
    }
  } catch (error) {
    console.error('Migration initialization failed', error);
    message.value = 'Не удалось открыть пульт переноса. ' + (error instanceof Error ? error.message : String(error));
    state.value = 'error';
  }
}
start();
