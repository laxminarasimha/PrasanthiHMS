import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  build: {
    // Staff phones are not all up to date: a phone whose Chrome stopped updating years ago must still open the app.
    // Without this the app is written for browsers from 2023 onwards, and an older Chrome cannot read it at all and
    // shows a blank white page. The newer shorthand is written out the long way; the same app runs everywhere.
    // What the app itself may use is kept to what Chrome 73 (2019) has: see src/olderPhones.test.ts.
    target: ['chrome64', 'edge79', 'firefox67', 'safari12'],
  },
  server: {
    port: 5173,
    // Forward API calls to the Laravel dev server (php artisan serve).
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
