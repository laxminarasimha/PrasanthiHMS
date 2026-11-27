import { useCallback, useEffect, useState } from 'react'
import type { AxiosError } from 'axios'
import { api } from './api'
import { onIphone, runningInstalled } from './install'
import type { User } from './types'

/**
 * Notifications on the phone or computer in hand, which arrive even when the app is closed.
 *
 * A device is asked once, by a tap, whether it may show them. From then on it follows whoever is signed in on it:
 * opening the app notes the device as theirs, and signing out lets go of it, so a shared phone never shows one
 * person's notifications to the next. Nothing is kept on the phone by this: a notification is shown and that is all.
 */
export type PushState =
  /** This browser cannot show them at all. */
  | 'unsupported'
  /** An iPhone or iPad shows them only from the app on the home screen, not from a page in Safari. */
  | 'install-first'
  /** The person, or the phone's settings, said no. Only the phone's own settings can undo that. */
  | 'blocked'
  | 'off'
  | 'on'

/** Who is sent anything today: a doctor, when an appointment is added for them. */
export const getsNotices = (user: User | null | undefined) => user?.role === 'doctor'

const OFF = 'hms_push_off'
const CHANGED = 'hms:push'
/** Something has just arrived on this device while the app is open: the screen it concerns may want to load again. */
export const ARRIVED = 'hms:push-arrived'

const able = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

const switchedOffHere = () => {
  try {
    return localStorage.getItem(OFF) === '1'
  } catch {
    return false
  }
}
const rememberOff = (off: boolean) => {
  try {
    if (off) localStorage.setItem(OFF, '1')
    else localStorage.removeItem(OFF)
  } catch {
    // Without storage the choice only lasts until the app is opened again.
  }
}

/** The part of the app that stays awake to show a notification. It is started as the page loads, so it may not be there yet. */
async function worker(): Promise<ServiceWorkerRegistration | null> {
  if (!able()) return null
  const waited = new Promise<null>((done) => window.setTimeout(() => done(null), 5000))
  return Promise.race([navigator.serviceWorker.ready, waited]).catch(() => null)
}

function bytes(key: string): Uint8Array<ArrayBuffer> {
  const plain = atob(key.replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(plain.length))
  for (let i = 0; i < plain.length; i++) out[i] = plain.charCodeAt(i)
  return out
}

/** Whether the device's present arrangement was made with this site's present key. If not, it has to be made again. */
function madeWith(sub: PushSubscription, key: Uint8Array): boolean {
  const used = sub.options.applicationServerKey
  if (!used) return false
  const had = new Uint8Array(used)
  return had.length === key.length && had.every((b, i) => b === key[i])
}

export async function pushState(): Promise<PushState> {
  if (!able()) return onIphone() && !runningInstalled() ? 'install-first' : 'unsupported'
  if (Notification.permission === 'denied') return 'blocked'
  if (Notification.permission !== 'granted') return 'off'
  const sub = await (await worker())?.pushManager.getSubscription().catch(() => null)
  return sub ? 'on' : 'off'
}

/** Makes (or keeps) this device's arrangement with its notification service and tells the server it is the signed-in person's. */
async function join(reg: ServiceWorkerRegistration): Promise<void> {
  const key = bytes((await api.get<{ key: string }>('/me/push/key')).data.key)
  let sub = await reg.pushManager.getSubscription()
  if (sub && !madeWith(sub, key)) {
    await sub.unsubscribe()
    sub = null
  }
  sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
  await api.post('/me/push', sub.toJSON())
}

const changed = () => window.dispatchEvent(new Event(CHANGED))

/**
 * Called from a tap. The phone asks the person whether the app may notify them; with a yes, the device is noted.
 * The question is put before anything else is waited for, because an iPhone only allows it straight from the tap.
 */
export async function turnOnPush(): Promise<PushState> {
  if (!able()) return pushState()
  const answer = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission
  if (answer !== 'granted') return answer === 'denied' ? 'blocked' : 'off'
  const reg = await worker()
  if (!reg) return 'unsupported'
  await join(reg)
  rememberOff(false)
  changed()
  return 'on'
}

/** Lets go of this device: on the server first, so nothing more is sent, then on the device itself. */
async function leave(): Promise<void> {
  const sub = await (await worker())?.pushManager.getSubscription().catch(() => null)
  if (!sub) return
  await api.delete('/me/push', { data: { endpoint: sub.endpoint } }).catch(() => undefined)
  await sub.unsubscribe().catch(() => undefined)
}

/** The person switching them off on this device. It stays off here until they switch it on again. */
export async function turnOffPush(): Promise<void> {
  await leave()
  rememberOff(true)
  changed()
}

/** Before signing out: the next person to use this device must not get this person's notifications. */
export async function forgetPushOnSignOut(): Promise<void> {
  if (!able() || Notification.permission !== 'granted') return
  // Never holds the sign-out up for long on a bad line.
  await Promise.race([leave(), new Promise((done) => window.setTimeout(done, 4000))])
}

/**
 * On opening the app: a device that is allowed to notify is noted as the signed-in person's, without asking again.
 * Nothing happens on a device that was never asked, that said no, or where the person switched them off.
 */
export async function syncPush(user: User): Promise<void> {
  if (!getsNotices(user) || !able() || Notification.permission !== 'granted' || switchedOffHere()) return
  const reg = await worker()
  if (!reg) return
  await join(reg).catch(() => undefined)
  changed()
}

/** Asks the server to send one to this device now, and gives back what to tell the person. */
export async function sendTestPush(): Promise<string> {
  const sub = await (await worker())?.pushManager.getSubscription().catch(() => null)
  if (!sub) return 'Notifications are not switched on for this device.'
  try {
    return (await api.post<{ message: string }>('/me/push/test', { endpoint: sub.endpoint })).data.message
  } catch (err) {
    return (err as AxiosError<{ message?: string }>).response?.data?.message ?? 'Could not send it. Check the internet connection and try again.'
  }
}

/** Where things stand on this device, kept the same on every screen that shows it. `null` while it is being found out. */
export function usePush(): { state: PushState | null; refresh: () => void } {
  const [state, setState] = useState<PushState | null>(null)
  const refresh = useCallback(() => {
    pushState().then(setState).catch(() => setState('unsupported'))
  }, [])
  useEffect(() => {
    refresh()
    window.addEventListener(CHANGED, refresh)
    return () => window.removeEventListener(CHANGED, refresh)
  }, [refresh])
  return { state, refresh }
}

// A notification that arrives while the app is open is also passed to the open screen, so a list can load again at once.
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (event) => {
    const data = event.data as { type?: string; kind?: string } | null
    if (data?.type === 'hms-push') window.dispatchEvent(new CustomEvent(ARRIVED, { detail: data.kind ?? '' }))
  })
}
