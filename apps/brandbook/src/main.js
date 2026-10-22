import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import '@irlix/ui/styles/base.css';
import './style.css';

// No authentication or service API calls: only already public, attributed observations.
// Private brand specifications remain in the private ideas repository.
createApp(App).mount('#app');
