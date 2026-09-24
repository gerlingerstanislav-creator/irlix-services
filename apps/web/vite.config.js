import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  base: '/employees/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@irlix/ui': fileURLToPath(new URL('../../packages/ui/src', import.meta.url)),
      '@irlix/auth': fileURLToPath(new URL('../../packages/auth/src/index.js', import.meta.url)),
    },
  },
});
