import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './settings.css';

const normalizeRole = role => String(role || '').trim().toLowerCase().replaceAll('_', '-');

const hasPlatformAdminAccess = async () => {
  const response = await auth.fetch('/api/employees/access/me', {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  });
  if (!response.ok) return false;
  const payload = await response.json();
  const roles = Array.isArray(payload?.data?.roles) ? payload.data.roles.map(normalizeRole) : [];
  return roles.includes('platform-admin');
};

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    if (!(await hasPlatformAdminAccess())) {
      window.location.replace('/');
      return;
    }
    createApp(App).mount('#app');
  } catch (error) {
    console.error('CV OIDC initialization failed', error);
    window.location.replace('/');
  }
};

start();