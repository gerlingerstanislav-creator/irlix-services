import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import '@irlix/ui/styles/base.css';
import '@fontsource-variable/onest/wght.css'; // OFL-1.1, self-hosted by Vite; Cyrillic and Latin unicode ranges.
import './style.css';

// Host nginx protects each page and asset. The isolated Vite preview cannot test production access.
// Digital v1.0 approved for this project;
// source facts remain attributed, private editorial drafts and rights-unverified files stay in ideas.
const app = createApp(App);
app.use(router);
router.isReady().then(() => app.mount('#app'));
