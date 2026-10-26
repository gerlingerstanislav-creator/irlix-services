import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { createBrowserAuth } from '@irlix/auth';
import { renderNavigationError } from '@irlix/ui';
import '@irlix/ui/styles/base.css';
import './style.css';

// Host/Portal nginx gate all files; the SPA independently verifies the current
// corporate token and passes real Employees entitlements to the shared sidebar.
const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/' });
async function start() {
  try {
    if (!await auth.init()) return;
    const response = await auth.fetch('/api/employees/access/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (!response.ok) throw new Error(`Employees access failed (${response.status})`);
    const access = (await response.json())?.data;
    if (!access?.employee_id || access?.services?.['design-system'] !== true) {
      renderNavigationError(document.getElementById('app'), 403);
      return;
    }
    const app = createApp(App, {
      serviceAccess: access,
      currentUser: auth.user,
      onLogout: () => auth.logout(),
    });
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('Design System authentication failed', error);
    renderNavigationError(document.getElementById('app'), 503);
  }
}
start();
