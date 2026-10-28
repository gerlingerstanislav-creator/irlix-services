import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
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
    if (response.status === 401 || response.status === 403) {
      showForbidden();
      return;
    }
    if (!response.ok) throw new Error(`Clients access lookup failed (${response.status})`);
    const initialAccess = (await response.json())?.data;
    if (initialAccess?.clients_service_blocked) {
      showForbidden();
      return;
    }
    // Server-verified Clients permissions determine service admission.
    // Backend retains the old Employees entitlement until V2 cutover.
    const effective = initialAccess?.permissions || {};
    const hasClientPermission = Object.entries(effective).some(([name, grant]) =>
      !name.startsWith('timesheets.') && grant?.allowed === true);
    if (!hasClientPermission && !initialAccess?.platform_admin) {
      showForbidden();
      return;
    }
    const app = createApp(App);
    app.provide('clientsInitialAccess', initialAccess);
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('clients startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};

start();
