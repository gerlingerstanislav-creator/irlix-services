import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  base: '/design-system/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@irlix/ui': fileURLToPath(new URL('../../packages/ui/src', import.meta.url)),
    },
  },
});
