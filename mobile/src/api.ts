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

/** Someone this login looks after: the person themself, or a family member. */
export type Person = { patient_id: number; name: string; relation: string; uhid: string; gender: string; age_label: string }
export type Account = { id: number; name: string; mobile: string; verified: boolean; patient: { id: number; uhid: string; gender: string; age_label: string }; people: Person[]; hospital: { name: string; phone: string } }
export type Doctor = { id: number; name: string; department: string | null }
export type Slot = { time: string; label: string; available: boolean }
export type Appointment = { id: number; scheduled_at: string; status: 'booked' | 'arrived' | 'ongoing' | 'reviewed' | 'cancelled'; token_no: number | null; notes: string | null; doctor: Doctor | null; patient?: { id: number; name: string } | null; can_cancel: boolean }
export type MedicineName = { id: number; name: string; type: string | null }
export type SubscriptionItem = { medicine_id: number | null; name: string; quantity: number }
export type Subscription = { id: number; title: string; items: SubscriptionItem[]; day_of_month: number; status: 'active' | 'paused' | 'cancelled'; next_due_on: string; last_prepared_on: string | null; notes: string | null }

export type StaffUser = { id: number; name: string; role: string; role_label: string; can_consult: boolean; can_view_reports?: boolean; can_run_pharmacy?: boolean; can_ipd?: boolean; branch: { name: string } | null }
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

// ---- Records, hospital details, and what the doctor sees of a patient ----

export type Hospital = { name: string; tagline: string | null; address: string | null; phone: string | null; phone_alt: string | null; email: string | null; doctors?: Doctor[] }
export type RecordVisit = { id: number; date: string; doctor: { name: string; department: string | null } | null; diagnosis: string | null; advice: string | null; tests_advised: string | null; follow_up_on: string | null; medicines: { medicine: string; directions: string }[] }
export type LabResultRow = { parameter: string; value: string; unit: string | null; range?: string | null; flag: string | null }
export type RecordLab = { id: number; order_no: string; date: string; tests: { name: string; report: string | null; remarks: string | null; results: LabResultRow[] }[] }
export type Records = { verified: boolean; visits: RecordVisit[]; lab: RecordLab[] }

export type HealthSummary = { allergies: string | null; sections: { kind: string; label: string; items: { id: number; title: string; detail: string | null; since: string | null; is_active: boolean }[] }[] }
export type PastVisit = { id: number; completed_at: string | null; diagnosis: string | null; complaints: string | null; advice: string | null; bp_systolic: number | null; bp_diastolic: number | null; weight_kg: number | null; medicines: { id: number; medicine: string; dose: string | null; frequency: string | null; duration: string | null }[]; doctor?: { name: string } }
export type Trends = { tests: { test: string; parameter: string; unit: string | null; reference_range: string | null; points: { date: string; value: string; flag: string | null }[] }[] }
export type Inpatient = { id: number; ipd_no: string; admitted_at: string; reason: string | null; patient: { id: number; uhid: string; name: string; title?: string | null; gender: string; age_label: string }; doctor: { id: number; name: string }; bed: { ward: string; bed_no: string } | null }
export type BedsideVital = { id: number; recorded_at: string; temperature_f: number | null; pulse: number | null; bp_systolic: number | null; bp_diastolic: number | null; resp_rate: number | null; spo2: number | null; blood_sugar: number | null }

// ---- Family, bills and today's notices ----

export type BillRow = { kind: string; bill_no: string; date: string; total: number; paid: number; due: number; patient_id: number }
export type Bills = { verified: boolean; bills: BillRow[]; due: number }
export type HomeNotice = { kind: string; tone: 'info' | 'ok' | 'warn' | 'note'; tab: 'appointments' | 'medicines' | 'records' | 'bills' | 'account'; title: string; text: string; patient_id?: number }
export type HomeData = { verified: boolean; notices: HomeNotice[]; due: number }

/** Rupees the way a bill prints them: ₹1,25,000 or ₹45.50. */
export const rupees = (amount: number) => {
  const [whole, paise] = Math.abs(amount).toFixed(2).split('.')
  const last3 = whole.slice(-3)
  const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',')
  return `${amount < 0 ? '-' : ''}₹${rest ? `${rest},` : ''}${last3}${paise === '00' ? '' : `.${paise}`}`
}
/** Now, as the server writes times: "2026-11-17 14:05". */
export const nowStamp = () => {
  const d = new Date()
  return `${isoDate(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

// ---- What staff see ----

export type AbnormalOrder = { order_id: number; order_no: string; reported_at: string; patient: { id: number; uhid: string; title?: string | null; name: string; gender: string; age_label: string }; results: { test: string; parameter: string; value: string; unit: string | null; range: string | null; flag: 'high' | 'low' }[] }
export type FoundPatient = { id: number; uhid: string; title?: string | null; name: string; gender: string; age_label: string; mobile: string | null }
export type WardNote = { id: number; kind: string; noted_at: string; body: string | null; author?: { name: string } | null }
export type Department = { label: string; billed: number; collected: Record<string, number>; collected_total: number; refunded?: number; due?: number }
export type Summary = { from: string; to: string; modes: string[]; departments: Record<string, Department>; counts: Record<string, number> }
export type DayCheck = { key: string; label: string; blocking: boolean; count: number }
export type DayBook = {
  day: string
  today: string
  sections: { key: string; label: string; checks: DayCheck[] }[]
  expected_cash: number
  blocking: number
  notes: number
  closed: { closed_by_name: string; closed_at: string; counted_cash: number; variance: number } | null
  recent: { day: string; closed: boolean }[]
}
export type PharmacyDesk = { actions: { key: string; tone: 'danger' | 'warn' | 'note'; count: number; text: string }[] }
