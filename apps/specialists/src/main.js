import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    const admission = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!admission.ok) throw new Error('Service entitlement lookup failed');
    if ((await admission.json())?.data?.services?.['specialists'] !== true) {
      renderNavigationError(document.querySelector('#app'), 403);
      return;
    }
    createApp(App).mount('#app');
  } catch (error) {
    console.error('specialists startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};

start();
