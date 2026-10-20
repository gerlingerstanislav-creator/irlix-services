import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { createBrowserAuth } from '@irlix/auth';
import '@irlix/ui/styles/base.css';
import './style.css';

const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/design-system/' });
const hasPlatformAdminAccess = async () => {
  const response = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
  if (!response.ok) return false;
  const payload = await response.json();
  return payload?.data?.services?.['design-system'] === true;
};

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    if (!(await hasPlatformAdminAccess())) {
      window.location.replace('/');
      return;
    }

    createApp(App, { auth }).mount('#app');
  } catch (error) {
    console.error('Design System OIDC initialization failed', error);
    window.location.replace('/');
  }
};

start();
