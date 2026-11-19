import axios, { AxiosError, type AxiosResponse } from 'axios'
import { loadEnded, loadStarted } from './loading'

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
  // A request that gets no answer gives up after a minute, so a screen is never left on "Saving…" for good.
  timeout: 60_000,
})

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  // Sending a scanned paper or writing a backup can honestly take longer on a slow line.
  if (config.data instanceof FormData || config.url?.startsWith('/backups')) config.timeout = 300_000
  return config
})

// A button pressed twice sends the same thing twice. While the first is still on its way, the second is not sent:
// it waits for the first one's answer and gets that. So a double click on "Record" or "Save" can never enter anything twice,
// on any screen, whether or not the button greys out.
const send = axios.getAdapter(api.defaults.adapter)
const onTheWay = new Map<string, Promise<AxiosResponse>>()
api.defaults.adapter = (config) => {
  const method = (config.method ?? 'get').toLowerCase()
  if (method === 'get') {
    // A screen is still loading while something it asked for is on its way. Writing a backup takes minutes and
    // is not part of opening any screen.
    if (config.url?.startsWith('/backups')) return send(config)
    const ask = loadStarted()
    return send(config).finally(() => loadEnded(ask))
  }
  if (typeof config.data !== 'string') return send(config)
  const key = `${method} ${config.url} ${config.data}`
  const first = onTheWay.get(key)
  if (first) return first
  const sent = send(config).finally(() => onTheWay.delete(key))
  onTheWay.set(key, sent)
  return sent
}

// Most screens simply stay empty when the list they asked for does not arrive, which looks the same as "nothing here".
// So every failed load is also reported here, and the app says so in one place until a load works again.
type TroubleListener = (failing: boolean) => void
const troubleListeners = new Set<TroubleListener>()
export const onLoadTrouble = (listener: TroubleListener) => {
  troubleListeners.add(listener)
  return () => {
    troubleListeners.delete(listener)
  }
}
const isRead = (method?: string) => (method ?? 'get').toLowerCase() === 'get'

api.interceptors.response.use(
  (response) => {
    if (isRead(response.config.method)) troubleListeners.forEach((tell) => tell(false))
    return response
  },
  (error: AxiosError) => {
    // An expired or revoked token sends the user back to the sign-in page.
    if (error.response?.status === 401 && getToken()) {
      setToken(null)
      window.location.assign('/login')
    }
    // No answer at all, or the server itself failing. A refusal (not allowed, not found) is the screen's own business.
    if (!axios.isCancel(error) && isRead(error.config?.method) && (!error.response || error.response.status >= 500)) {
      troubleListeners.forEach((tell) => tell(true))
    }
    return Promise.reject(error)
  },
)

export type FieldErrors = Record<string, string>

/** Turns a Laravel validation response into one message per field. */
export function fieldErrors(error: unknown): FieldErrors {
  const data = (error as AxiosError<{ errors?: Record<string, string[]>; message?: string }>).response?.data
  if (data?.errors) {
    return Object.fromEntries(Object.entries(data.errors).map(([field, messages]) => [field, messages[0]]))
  }
  // No reply at all: the internet or the server is down, or the answer was lost on the way back.
  // For something being saved that is not the same as "not saved", so the message does not claim it.
  if (!(error as AxiosError).response) {
    const reading = ((error as AxiosError).config?.method ?? 'get').toLowerCase() === 'get'
    return {
      _: reading
        ? 'Could not reach the server. Check the internet connection and try again.'
        : 'No reply came back from the server, so this may or may not have been saved. Check the internet connection, then look whether it is there before entering it again.',
    }
  }
  return { _: data?.message ?? 'Something went wrong. Please try again.' }
}
