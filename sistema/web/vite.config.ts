import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Em dev a API roda em http://localhost:8099 (php -S, com API_BASE_PATH=/api) e é acessada via /api,
// igual à produção, onde a API fica em /api no mesmo domínio.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8099', // API com API_BASE_PATH=/api no .env
        changeOrigin: false,
      },
    },
  },
  build: {
    outDir: '../public_html',
    emptyOutDir: false, // preserva public_html/api e web.config
    sourcemap: false,
  },
});
