import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './settings.css';

const readCvAccess = async () => {
  const response = await auth.fetch('/api/employees/access/me', {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  });
  if (!response.ok) return null;
  const payload = await response.json();
  return payload?.data || null;
};

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    const access = await readCvAccess();
    if (access?.services?.['cv-converter'] !== true) {
      renderNavigationError(document.querySelector('#app'), 403);
      return;
    }
    const canManageSettings = Array.isArray(access.roles)
      && access.roles.some(role => String(role).trim().toLowerCase() === 'platform-admin');
    const app = createApp(App, { canManageSettings });
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('cv startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};

start();