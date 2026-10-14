import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './layout-fixes.css';
import './manage-redesign.css';
import './create-flow.css';
import './compact-card.css';

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    const admission = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!admission.ok) throw new Error('Service entitlement lookup failed');
    if ((await admission.json())?.data?.services?.['vacations'] !== true) {
      window.location.replace('/');
      return;
    }
    createApp(App).mount('#app');
  } catch (error) {
    console.error('Vacations OIDC initialization failed', error);
    const target = document.querySelector('#app');
    if (target) target.innerHTML = '<div style="padding:24px;font-family:Arial,sans-serif">Не удалось подключиться к сервису авторизации.</div>';
  }
};

start();
