import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';

const normalizeRole = role => String(role || '').trim().toLowerCase().replaceAll('_', '-');
const isPlatformAdmin = user => (user?.realm_access?.roles || []).map(normalizeRole).includes('platform-admin');

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    if (!isPlatformAdmin(auth.user)) {
      window.location.replace('/');
      return;
    }
    createApp(App).mount('#app');
  } catch (error) {
    console.error('CV OIDC initialization failed', error);
    const target = document.querySelector('#app');
    if (target) target.innerHTML = '<div style="padding:24px;font-family:Arial,sans-serif">Не удалось подключиться к сервису авторизации.</div>';
  }
};

start();