import '@fontsource-variable/onest/wght.css';
import { createApp, h, ref, watch } from 'vue';
import { router } from './router';
import { UiAppShell, UiLoader } from '@irlix/ui';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';
import MigrationWorkspace from './MigrationWorkspace.vue';

const auth = createBrowserAuth({ storagePrefix: 'irlix.migration.auth', defaultReturnTo: '/migration/' });
const state = ref('loading');
const message = ref('Проверяем доступ…');

const app = createApp({
  setup() {
    return () => {
      if (state.value === 'not-found') return h('main', { role: 'alert', class: 'mc-empty' }, [h('h1', '404 — Страница не найдена'), h('a', { href: '/migration/control/' }, 'К контролю переноса')]);
      if (state.value === 'console') return h(MigrationWorkspace, { auth });
      const status = () => h('main', {
        id: state.value === 'forbidden' ? 'forbidden-page' : 'auth-loading',
        class: 'mc-empty', role: ['error','forbidden'].includes(state.value) ? 'alert' : 'status',
        style: state.value === 'loading' ? { minHeight: '65vh', display: 'grid', placeItems: 'center' } : {},
      }, state.value === 'loading' ? h(UiLoader, { label: message.value, showLabel: true }) : message.value);
      if (state.value === 'forbidden') return h(UiAppShell, {
        service: 'migration', currentUser: auth.user, breadcrumbs: [{ label: 'Нет доступа' }],
        onLogout: () => auth.logout(),
      }, { default: status });
      return status();
    };
  },
});
app.use(router);


async function verifyAccess() {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 12000);
  try {
    const response = await auth.fetch('/api/employees/access/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal,
    });
    if (response.status === 401 || response.status === 403) {
      message.value = response.status === 403 ? '403 — Доступ запрещён' : '401 — Требуется повторный вход';
      state.value = response.status === 403 ? 'forbidden' : 'error';
      return;
    }
    if (!response.ok) throw new Error('Сервис авторизации временно недоступен (' + response.status + ').');
    const payload = await response.json();
    if (payload?.data?.services?.migration === true) state.value = 'console';
    else {
      message.value = '403 — Доступ запрещён. Требуется роль platform-admin';
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
    // Process the OIDC callback before canonicalizing the standalone route.
    const url = new URL(window.location.href);
    if (['/migration','/migration/','/migration/console','/migration/console/','/migration/control'].includes(url.pathname)
        || url.searchParams.get('view') === 'control') {
      const query = Object.fromEntries(url.searchParams);
      delete query.view;
      await router.replace({ name:'control',query,hash:url.hash });
    }
    if (router.currentRoute.value.name !== 'control') {
      state.value = 'not-found';
      return;
    }
    await verifyAccess();
  } catch (error) {
    console.error('Migration initialization failed', error);
    message.value = 'Не удалось открыть пульт переноса. ' + (error instanceof Error ? error.message : String(error));
    state.value = 'error';
  }
}
watch(() => router.currentRoute.value.name, name => {
  if (name === 'not-found') state.value = 'not-found';
  else if (name === 'control' && state.value === 'not-found') verifyAccess();
});
router.isReady().then(() => { app.mount('#app'); start(); });
