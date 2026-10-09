import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import FilterRailShowcase from './FilterRailShowcase.vue';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';
import './style.css';

const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/design-system/' });
const normalizeRole = role => String(role || '').trim().toLowerCase().replaceAll('_', '-');

const hasPlatformAdminAccess = async () => {
  const response = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
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
    const filterRailShowcase = document.createElement('div');
    filterRailShowcase.id = 'filter-rail-showcase';
    document.body.appendChild(filterRailShowcase);
    createApp(FilterRailShowcase).mount(filterRailShowcase);
  } catch (error) {
    console.error('Design System OIDC initialization failed', error);
    window.location.replace('/');
  }
};

start();
