import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import '@irlix/ui/styles/base.css';
import StorageApp from './StorageApp.vue';
import { router } from './router.js';
import './storage.css';
createApp(StorageApp).use(router).mount('#app');
