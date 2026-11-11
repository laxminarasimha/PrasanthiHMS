import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    // Forward API calls to the Laravel dev server (php artisan serve).
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
