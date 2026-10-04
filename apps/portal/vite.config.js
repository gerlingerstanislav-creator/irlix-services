import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

const dashboardEnhancements = () => ({
  name: 'dashboard-enhancements',
  transformIndexHtml: {
    order: 'pre',
    handler: () => [
      {
        tag: 'script',
        attrs: { type: 'module', src: '/src/dashboard-enhancements.js' },
        injectTo: 'body',
      },
      {
        tag: 'script',
        attrs: { type: 'module', src: '/src/dashboard-ui-fixes.js' },
        injectTo: 'body',
      },
    ],
  },
});

export default defineConfig({
  plugins: [vue(), dashboardEnhancements()],
  resolve: {
    alias: {
      '@irlix/auth': fileURLToPath(new URL('../../packages/auth/src/index.js', import.meta.url)),
      '@irlix/ui': fileURLToPath(new URL('../../packages/ui/src', import.meta.url)),
    },
  },
});