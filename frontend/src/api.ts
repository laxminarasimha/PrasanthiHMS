import axios from 'axios'

// All requests go to /api, which Vite proxies to Laravel in development
// and the web server routes to Laravel in production.
export const api = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})
