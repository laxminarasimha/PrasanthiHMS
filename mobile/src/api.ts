/** Where the hospital's server is. Set EXPO_PUBLIC_API_URL to point a test build somewhere else. */
export const API_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'https://hms.prasanthihospitals.com/api').replace(/\/$/, '')

export class ApiError extends Error {
  status: number
  fields: Record<string, string>

  constructor(message: string, status: number, fields: Record<string, string> = {}) {
    super(message)
    this.status = status
    this.fields = fields
  }
}

let token: string | null = null
let onSignedOut: (() => void) | null = null

export const setToken = (value: string | null) => {
  token = value
}
/** Called when the server no longer accepts the sign-in, so the app can go back to the first screen. */
export const whenSignedOut = (handler: () => void) => {
  onSignedOut = handler
}

async function request<T>(method: string, path: string, body?: unknown, query?: Record<string, string | undefined>): Promise<T> {
  const search = Object.entries(query ?? {})
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value as string)}`)
    .join('&')

  let response: Response
  try {
    response = await fetch(`${API_URL}${path}${search ? `?${search}` : ''}`, {
      method,
      headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    })
  } catch {
    throw new ApiError('Could not reach the hospital. Check your internet connection and try again.', 0)
  }

  const data = response.status === 204 ? null : await response.json().catch(() => null)
  if (response.ok) return data as T

  if (response.status === 401 && token) onSignedOut?.()
  const fields: Record<string, string> = {}
  for (const [key, messages] of Object.entries((data?.errors ?? {}) as Record<string, string[]>)) fields[key] = messages[0]
  const first = Object.values(fields)[0]
  const message = first ?? data?.message ?? (response.status === 429 ? 'Too many tries. Wait a minute and try again.' : 'Something went wrong. Please try again.')
  throw new ApiError(message, response.status, fields)
}

export const api = {
  get: <T>(path: string, query?: Record<string, string | undefined>) => request<T>('GET', path, undefined, query),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body ?? {}),
  delete: <T>(path: string) => request<T>('DELETE', path),
}

// ---- What the server sends ----

export type Account = { id: number; name: string; mobile: string; verified: boolean; patient: { uhid: string; gender: string; age_label: string }; hospital: { name: string; phone: string } }
export type Doctor = { id: number; name: string; department: string | null }
export type Slot = { time: string; label: string; available: boolean }
export type Appointment = { id: number; scheduled_at: string; status: 'booked' | 'arrived' | 'ongoing' | 'reviewed' | 'cancelled'; token_no: number | null; notes: string | null; doctor: Doctor | null; can_cancel: boolean }
export type MedicineName = { id: number; name: string; type: string | null }
export type SubscriptionItem = { medicine_id: number | null; name: string; quantity: number }
export type Subscription = { id: number; title: string; items: SubscriptionItem[]; day_of_month: number; status: 'active' | 'paused' | 'cancelled'; next_due_on: string; last_prepared_on: string | null; notes: string | null }

export type StaffUser = { id: number; name: string; role: string; role_label: string; can_consult: boolean; branch: { name: string } | null }
export type QueueEntry = {
  id: number
  scheduled_at: string
  status: Appointment['status']
  token_no: number | null
  arrived_at: string | null
  visits: number
  last_visit: string | null
  vitals_ready: boolean
  booked_via?: string | null
  patient: { id: number; uhid: string; name: string; title?: string | null; gender: string; age_label: string; mobile: string }
  service: { name: string } | null
}

// ---- Dates, shown the way the hospital writes them ----

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

const parse = (value: string) => new Date(value.replace(' ', 'T').length === 10 ? `${value}T00:00:00` : value.replace(' ', 'T'))
const pad = (n: number) => String(n).padStart(2, '0')

export const isoDate = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
export const todayIso = () => isoDate(new Date())
export const addDays = (iso: string, days: number) => {
  const d = parse(iso)
  d.setDate(d.getDate() + days)
  return isoDate(d)
}
export const niceDate = (value: string) => {
  const d = parse(value)
  return `${DAYS[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`
}
export const shortDay = (value: string) => {
  const d = parse(value)
  return { weekday: DAYS[d.getDay()], day: d.getDate(), month: MONTHS[d.getMonth()] }
}
export const niceTime = (value: string) => {
  const d = parse(value)
  const hour = d.getHours() % 12 || 12
  return `${hour}:${pad(d.getMinutes())} ${d.getHours() < 12 ? 'AM' : 'PM'}`
}
