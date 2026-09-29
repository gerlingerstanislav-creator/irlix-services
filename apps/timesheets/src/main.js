import { createApp } from 'vue';
import App from './App.vue';
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
    createApp(App).mount('#app');
  } catch (error) {
    console.error('Timesheets OIDC initialization failed', error);
    const target = document.querySelector('#app');
    if (target) target.innerHTML = '<div style="padding:24px;font-family:Arial,sans-serif">Не удалось подключиться к сервису авторизации.</div>';
  }
};
start();

