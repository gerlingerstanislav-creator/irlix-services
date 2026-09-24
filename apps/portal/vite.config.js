import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
  resolve: {
    alias: {
      '@irlix/auth': fileURLToPath(new URL('../../packages/auth/src/index.js', import.meta.url)),
    },
  },
});
