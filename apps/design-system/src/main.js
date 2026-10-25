import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { renderNavigationError } from '@irlix/ui';
import { catalogAnchorIds } from './catalogSections.js';
import '@irlix/ui/styles/base.css';
import './style.css';

// Public, synthetic component catalog: no authenticated service requests.
const tail = location.pathname.replace(/^\/design-system\/?/, '').replace(/\/$/, '');
const legacy = new Set(['components','patterns','history','confirmations','service-dashboard']);
if (tail && (tail.includes('/') || (!catalogAnchorIds.includes(tail) && !legacy.has(tail)))) {
  renderNavigationError(document.querySelector('#app'), 404);
} else {
  createApp(App).mount('#app');
}
