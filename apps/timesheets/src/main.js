import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './extra.css';
import './management-compact.css';
import './hatching.css';

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    const admission = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!admission.ok) throw new Error('Service entitlement lookup failed');
    if ((await admission.json())?.data?.services?.['timesheets'] !== true) {
      renderNavigationError(document.querySelector('#app'), 403);
      return;
    }
    const app = createApp(App);
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('timesheets startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};
start();

