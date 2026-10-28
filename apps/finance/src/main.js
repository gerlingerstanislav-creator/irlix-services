import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import { renderNavigationError } from '@irlix/ui';
import App from './App.vue';
import { router } from './router';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';

const errorPage = (text) => {
  const root = document.querySelector('#app');
  root.replaceChildren();
  const message = document.createElement('div');
  message.style.padding = '24px';
  message.setAttribute('role', 'alert');
  message.textContent = text;
  root.appendChild(message);
};

const start = async () => {
  try {
    if (!await auth.init()) return;
    // Finance backend owns admission and effective permissions. It keeps
    // historical Employees-derived grants only until its local cutover.
    const response = await auth.fetch('/api/finance/me', { cache: 'no-store' });
    if (!response.ok) {
      if (response.status === 403) {
        errorPage('Доступ к сервису «Финансы» запрещён.');
        return;
      }
      throw new Error('Finance access lookup failed');
    }
    const payload = await response.json();
    if (payload?.data?.permissions?.['finance.view'] !== true) {
      errorPage('Доступ к сервису «Финансы» запрещён.');
      return;
    }
    const app = createApp(App);
    app.use(router);
    await router.isReady();
    // Preserve the existing accountant-specific landing route.
    if (router.currentRoute.value.name === 'root') {
      const permissions = payload?.data?.permissions || {};
      const accountingStart = permissions['finance.invoices.manage'] === true
        && permissions['finance.planning.approve'] !== true;
      await router.replace({ name: accountingStart ? 'invoices' : 'overview' });
    }
    app.mount('#app');
  } catch (error) {
    console.error('finance startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};
start();
