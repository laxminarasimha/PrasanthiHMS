import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// Tests for the web app: `npm test`. They run in a pretend browser (jsdom), with the server's answers made up.
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.{ts,tsx}'],
    restoreMocks: true,
  },
})
