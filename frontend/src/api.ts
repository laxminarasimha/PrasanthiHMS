import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'hms_token'

export const getToken = () => localStorage.getItem(TOKEN_KEY)
export const setToken = (token: string | null) =>
  token ? localStorage.setItem(TOKEN_KEY, token) : localStorage.removeItem(TOKEN_KEY)

// All requests go to /api, which Vite proxies to Laravel in development
// and the web server routes to Laravel in production.
export const api = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(undefined, (error: AxiosError) => {
  // An expired or revoked token sends the user back to the sign-in page.
  if (error.response?.status === 401 && getToken()) {
    setToken(null)
    window.location.assign('/login')
  }
  return Promise.reject(error)
})

export type FieldErrors = Record<string, string>

/** Turns a Laravel validation response into one message per field. */
export function fieldErrors(error: unknown): FieldErrors {
  const data = (error as AxiosError<{ errors?: Record<string, string[]>; message?: string }>).response?.data
  if (data?.errors) {
    return Object.fromEntries(Object.entries(data.errors).map(([field, messages]) => [field, messages[0]]))
  }
  return { _: data?.message ?? 'Something went wrong. Please try again.' }
}
