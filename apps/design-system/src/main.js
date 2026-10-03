import { createApp } from 'vue';
import App from './App.vue';
import FilterRailShowcase from './FilterRailShowcase.vue';
import '@irlix/ui/styles/base.css';
import './style.css';

createApp(App).mount('#app');

const filterRailShowcase = document.createElement('div');
filterRailShowcase.id = 'filter-rail-showcase';
document.body.appendChild(filterRailShowcase);
createApp(FilterRailShowcase).mount(filterRailShowcase);
