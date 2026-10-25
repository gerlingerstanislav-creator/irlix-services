import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { renderNavigationError } from '@irlix/ui';
import { catalogAnchorIds } from './catalogSections.js';
import '@irlix/ui/styles/base.css';
import './style.css';

// The public catalog validates every path in its own Vue Router.
const app = createApp(App);
app.use(router);
router.isReady().then(() => app.mount('#app'));
