import { useCallback, useEffect, useState } from 'react'
import type { AxiosError } from 'axios'
import { api } from './api'
import { onIphone, runningInstalled } from './install'
import { ARRIVED, TAPPED, getsNotices } from './notices'
import type { User } from './types'

/**
 * Notifications on the phone or computer in hand, which arrive even when the app is closed.
 *
 * A device is asked once, by a tap, whether it may show them, and each person switches them on for themselves. The
 * device remembers who did. When one of them opens the app, the device is noted on the server as theirs. When anyone
 * else opens it, the device is taken away from whoever held it, so a shared phone or desk computer never shows one
 * person's notifications to the next, and nobody is signed up by someone else's yes. Signing out lets go of the
 * device too. Nothing of a notification is kept on the phone by this: it is shown and that is all.
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

/** The people who switched notifications on with this device in their hand, by login. Kept on the device only. */
const AGREED = 'hms_push_people'
/** From before each person was asked for themselves: someone had switched them off on this device. */
const OFF = 'hms_push_off'
const CHANGED = 'hms:push'

const able = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

/** Who switched them on here, or null on a device where nobody has been asked for themselves yet. */
function agreed(): number[] | null {
  let kept: string | null
  try {
    kept = localStorage.getItem(AGREED)
  } catch {
    return null
  }
  if (kept === null) return null
  try {
    const people: unknown = JSON.parse(kept)
    return Array.isArray(people) ? people.filter((id): id is number => typeof id === 'number') : []
  } catch {
    return []
  }
}

function agree(id: number, yes: boolean) {
  const others = (agreed() ?? []).filter((one) => one !== id)
  try {
    localStorage.setItem(AGREED, JSON.stringify(yes ? [...others, id] : others))
    localStorage.removeItem(OFF)
  } catch {
    // Without storage the device cannot remember the choice, and asks again the next time the app is opened.
  }
}

const switchedOffHere = () => {
  try {
    return localStorage.getItem(OFF) === '1'
  } catch {
    return false
  }
}

/**
 * Before each person was asked for themselves, only a doctor could switch notifications on, and a device that was on
 * followed whoever signed in. A doctor who had them on keeps them, without being asked again.
 */
const carriedOver = (user: User) => user.role === 'doctor' && getsNotices(user)

/** Whether this person switched them on on this device. `holding`: the device still has its arrangement from before. */
function agreedHere(id: number, carried: boolean, holding: boolean): boolean {
  const people = agreed()
  return people === null ? carried && holding && !switchedOffHere() : people.includes(id)
}

/** The part of the app that stays awake to show a notification. It is started as the page loads, so it may not be there yet. */
async function worker(): Promise<ServiceWorkerRegistration | null> {
  if (!able()) return null
  const waited = new Promise<null>((done) => window.setTimeout(() => done(null), 5000))
  return Promise.race([navigator.serviceWorker.ready, waited]).catch(() => null)
}

const held = async (reg: ServiceWorkerRegistration | null) => (await reg?.pushManager.getSubscription().catch(() => null)) ?? null

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

async function stateOf(id: number | null, carried: boolean): Promise<PushState> {
  if (!able()) return onIphone() && !runningInstalled() ? 'install-first' : 'unsupported'
  if (Notification.permission === 'denied') return 'blocked'
  if (Notification.permission !== 'granted') return 'off'
  const sub = await held(await worker())
  // The device may be on for someone else who uses it: for this person it is on only if they said so here.
  return sub && id !== null && agreedHere(id, carried, true) ? 'on' : 'off'
}

/** Where things stand on this device for this person. */
export const pushState = (user: User | null | undefined): Promise<PushState> => stateOf(user?.id ?? null, user ? carriedOver(user) : false)

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

/**
 * Takes the device away from whoever holds it on the server, so their notifications stop coming here. The device keeps
 * its arrangement, for when someone who switched them on here signs in again. Having the device in hand is the proof.
 */
async function release(sub: PushSubscription): Promise<void> {
  await api.post('/me/push/release', { endpoint: sub.endpoint }).catch(() => undefined)
}

const changed = () => window.dispatchEvent(new Event(CHANGED))

/**
 * Called from a tap. The phone asks the person whether the app may notify them; with a yes, the device is noted as
 * theirs, and remembers that this person said yes here.
 * The question is put before anything else is waited for, because an iPhone only allows it straight from the tap.
 */
export async function turnOnPush(user: User): Promise<PushState> {
  if (!able()) return pushState(user)
  const answer = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission
  if (answer !== 'granted') return answer === 'denied' ? 'blocked' : 'off'
  const reg = await worker()
  if (!reg) return 'unsupported'
  await join(reg)
  agree(user.id, true)
  changed()
  return 'on'
}

/** Lets go of this device: on the server first, so nothing more is sent, then on the device itself. */
async function leave(): Promise<void> {
  const sub = await held(await worker())
  if (!sub) return
  await api.delete('/me/push', { data: { endpoint: sub.endpoint } }).catch(() => undefined)
  await sub.unsubscribe().catch(() => undefined)
}

/** The person switching them off on this device. It stays off here for them until they switch it on again; others who use it keep their own choice. */
export async function turnOffPush(user: User): Promise<void> {
  await leave()
  agree(user.id, false)
  changed()
}

/** Before signing out: the next person to use this device must not get this person's notifications. */
export async function forgetPushOnSignOut(): Promise<void> {
  if (!able() || Notification.permission !== 'granted') return
  // Never holds the sign-out up for long on a bad line.
  await Promise.race([leave(), new Promise((done) => window.setTimeout(done, 4000))])
}

/**
 * On opening the app, and again after the person changes their own password: the device is noted as the signed-in
 * person's, without asking, if they switched notifications on here themselves. For anyone else it is let go of on the
 * server, so the person who used it before stops being notified here. Nothing is asked, and nothing happens on a device
 * that was never switched on.
 */
export async function syncPush(user: User): Promise<void> {
  if (!able()) return
  const reg = await worker()
  if (!reg) return
  const sub = await held(reg)
  if (Notification.permission === 'granted' && getsNotices(user) && agreedHere(user.id, carriedOver(user), Boolean(sub))) {
    // A doctor who had them on before each person was asked: noted now as having said yes here.
    if (agreed() === null) agree(user.id, true)
    await join(reg).catch(() => undefined)
  } else if (sub) {
    await release(sub)
  }
  changed()
}

/** Asks the server to send one to this device now, and gives back what to tell the person. */
export async function sendTestPush(): Promise<string> {
  const sub = await held(await worker())
  if (!sub) return 'Notifications are not switched on for this device.'
  try {
    return (await api.post<{ message: string }>('/me/push/test', { endpoint: sub.endpoint })).data.message
  } catch (err) {
    return (err as AxiosError<{ message?: string }>).response?.data?.message ?? 'Could not send it. Check the internet connection and try again.'
  }
}

/** Where things stand on this device for this person, kept the same on every screen that shows it. `null` while it is being found out. */
export function usePush(user: User | null | undefined): { state: PushState | null; refresh: () => void } {
  const [state, setState] = useState<PushState | null>(null)
  const id = user?.id ?? null
  const carried = user ? carriedOver(user) : false
  const refresh = useCallback(() => {
    stateOf(id, carried)
      .then(setState)
      .catch(() => setState('unsupported'))
  }, [id, carried])
  useEffect(() => {
    refresh()
    window.addEventListener(CHANGED, refresh)
    return () => window.removeEventListener(CHANGED, refresh)
  }, [refresh])
  return { state, refresh }
}

// A notification that arrives while the app is open is also passed to the open screen, so a list can load again at once.
// One that is tapped while the app is open asks it to go to the page the notification is about.
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (event) => {
    const data = event.data as { type?: string; kind?: string; url?: string } | null
    if (data?.type === 'hms-push') window.dispatchEvent(new CustomEvent(ARRIVED, { detail: data.kind ?? '' }))
    if (data?.type === 'hms-open' && typeof data.url === 'string') window.dispatchEvent(new CustomEvent(TAPPED, { detail: data.url }))
  })
}
