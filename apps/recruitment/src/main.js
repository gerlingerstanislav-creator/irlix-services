import { renderNavigationError } from '@irlix/ui';
import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './AppMvp.vue';
import { router } from './router';
import { auth } from './auth';
import { recruitmentAdmission } from './authorization.js';
import '@irlix/ui/styles/base.css';
import './style.css';

const start = async () => {
  try {
    const authenticated = await auth.init();
    if (!authenticated) return;
    // Domain backend validates signed bearer and owns Recruitment admission.
    // Legacy Employees entitlement remains enforced only within the backend
    // until V2 per-service effective grants are enabled.
    const admission = await auth.fetch('/api/recruitment/permissions/me', {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
    if (!admission.ok) {
      renderNavigationError(document.querySelector('#app'), admission.status === 403 ? 403 : 503);
      return;
    }
    const rights = (await admission.json())?.data;
    if (!rights || !recruitmentAdmission(rights.mode, rights.permissions)) {
      renderNavigationError(document.querySelector('#app'), 403);
      return;
    }
    const app = createApp(App);
    app.use(router);
    await router.isReady();
    app.mount('#app');
  } catch (error) {
    console.error('recruitment startup failed', error);
    renderNavigationError(document.querySelector('#app'), 503);
  }
};
start();
