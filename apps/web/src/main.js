import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './organization.css';
import './employee-card.css';
import './identity.css';
import './sidebar.css';

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    createApp(App).mount('#app');
  } catch (error) {
    console.error('OIDC initialization failed', error);
    const target = document.querySelector('#app');
    const detail = error instanceof Error ? error.message : String(error || 'Unknown authentication error');
    if (target) target.innerHTML = `<div style="padding:24px;font-family:Arial,sans-serif"><strong>Не удалось подключиться к сервису авторизации.</strong><br><span style="color:#667085">${detail.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))}</span></div>`;
  }
};

start();
