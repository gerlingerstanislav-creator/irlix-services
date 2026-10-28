import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
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
    // The API is the access-policy authority. It relays Clients permissions
    // during compatibility and reads Timesheets-owned rules after cutover.
    const admission = await auth.fetch('/api/timesheets/permissions/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (!admission.ok) {
      renderNavigationError(document.querySelector('#app'), admission.status === 403 ? 403 : 503);
      return;
    }
    const permissions = (await admission.json())?.data?.permissions || {};
    if (!Object.values(permissions).some(permission => permission?.allowed === true)) {
      renderNavigationError(document.querySelector('#app'), 403);
      return;
    }
    const app = createApp(App);
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('timesheets startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};
start();

