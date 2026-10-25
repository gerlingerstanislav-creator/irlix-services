import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
import './registry-overrides.css';
import './contact-overrides.css';
import './drawerResize';

const showForbidden = () => renderNavigationError(document.querySelector('#app'), 403);

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    const response = await auth.fetch('/api/clients/permissions/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) throw new Error(`Clients access lookup failed (${response.status})`);
    if ((await response.json())?.data?.clients_service_blocked) {
      showForbidden();
      return;
    }
    const admission = await auth.fetch('/api/employees/access/me', { headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!admission.ok) throw new Error('Service entitlement lookup failed');
    if ((await admission.json())?.data?.services?.['clients'] !== true) {
      showForbidden();
      return;
    }
    createApp(App).mount('#app');
  } catch (error) {
    console.error('clients startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};

start();
