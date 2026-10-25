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
    const response = await auth.fetch('/api/employees/access/me', { cache: 'no-store' });
    if (!response.ok) throw new Error('Employees access lookup failed');
    const payload = await response.json();
    if (payload?.data?.services?.equipment !== true) {
      errorPage('Доступ к сервису «Учёт техники» запрещён.');
      return;
    }
    const app = createApp(App);
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('equipment startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};
start();
