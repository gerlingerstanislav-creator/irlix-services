import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { createBrowserAuth } from '@irlix/auth';
import { renderNavigationError } from '@irlix/ui';
import '@irlix/ui/styles/base.css';
import '@fontsource-variable/onest/wght.css'; // OFL-1.1, self-hosted by Vite.
import './style.css';

// Host nginx gates every Brandbook page and asset. The app independently
// loads real Employees entitlements so the shared service launcher does not
// mistake the Brandbook cookie for access to every other platform service.
const auth = createBrowserAuth({ storagePrefix: 'irlix.platform.auth', defaultReturnTo: '/' });

async function start() {
  try {
    if (!await auth.init()) return;
    const response = await auth.fetch('/api/employees/access/me', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) throw new Error(`Employees access failed (${response.status})`);
    const access = (await response.json())?.data;
    if (!access?.employee_id || access?.services?.brandbook !== true) {
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
    console.error('Brandbook authentication or access lookup failed', error);
    renderNavigationError(document.getElementById('app'), 503);
  }
}
start();
