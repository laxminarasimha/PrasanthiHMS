import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'hms_token'

export const getToken = () => localStorage.getItem(TOKEN_KEY)
export const setToken = (token: string | null) => {
  if (token) return localStorage.setItem(TOKEN_KEY, token)
  localStorage.removeItem(TOKEN_KEY)
  // Whoever was remembered as signed in goes with the token.
  localStorage.removeItem(USER_KEY)
}

/** Where the signed-in person's details are kept, so the next page load need not wait to ask who they are. */
export const USER_KEY = 'hms_user'

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
  // No reply at all means the request never arrived: the internet or the server is down, not the entry.
  if (!(error as AxiosError).response) {
    return { _: 'Could not reach the server. Check the internet connection and try again; nothing was saved.' }
  }
  return { _: data?.message ?? 'Something went wrong. Please try again.' }
}
