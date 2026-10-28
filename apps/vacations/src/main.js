import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { auth } from './auth';
import { canEnterVacations } from './authorization.js';
import '@irlix/ui/styles/base.css';
import './style.css';
import './layout-fixes.css';
import './manage-redesign.css';
import './create-flow.css';
import './compact-card.css';

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    const admission = await auth.fetch('/api/vacations/permissions/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (admission.status === 403 || admission.status === 401) {
      renderNavigationError(document.querySelector('#app'), 403);
      return;
    }
    if (!admission.ok) throw new Error('Vacations permission lookup failed');
    const effective = (await admission.json())?.data;
    if (!effective || !canEnterVacations(effective.mode, effective.permissions)) {
      renderNavigationError(document.querySelector('#app'), 403);
      return;
    }
    const app = createApp(App);
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('vacations startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};

start();
