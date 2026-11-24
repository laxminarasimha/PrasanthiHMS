import { useEffect, useSyncExternalStore } from 'react'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import type { Spell } from './shared'

/** A person's own attendance for today, as the check-in card and the call-out on opening the app both show it. */
export type MyToday = {
  employee: { id: number; name: string } | null
  self_check_in?: boolean
  /** False when check-in is kept to the hospital's own internet and this phone is not on it. */
  here?: boolean
  /** The duty they are on now. A night duty that began yesterday evening is still this one, until it is over. */
  open?: Spell | null
  /** An earlier duty that was never checked out. It does not stop today's check-in; the office puts it right. */
  forgotten?: Spell | null
  done?: Spell[]
}

// One copy for the whole app, so the card and the call-out always say the same thing and ask the server once.
let state: { for: number | null; data: MyToday | null } = { for: null, data: null }
let asking: { for: number; promise: Promise<void> } | null = null
const listeners = new Set<() => void>()
const tell = () => listeners.forEach((listener) => listener())
const subscribe = (listener: () => void) => {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** Reads today's attendance afresh for the person signed in. Two askers at the same moment share one request. */
export function loadMyToday(userId: number): Promise<void> {
  if (state.for !== userId) {
    state = { for: userId, data: null }
    tell()
  }
  if (asking?.for === userId) return asking.promise
  const promise = api
    .get<MyToday>('/workforce/me/today')
    .then((res) => {
      if (state.for === userId) {
        state = { for: userId, data: res.data }
        tell()
      }
    })
    .catch(() => undefined)
    .finally(() => {
      if (asking?.promise === promise) asking = null
    })
  asking = { for: userId, promise }
  return promise
}

/** Checks the person in, or out if they are in. Gives back '' when it worked, or what went wrong in words for the screen. */
export async function punchMyToday(userId: number): Promise<string> {
  try {
    await api.post('/workforce/me/punch')
    await loadMyToday(userId)
    return ''
  } catch (err) {
    return Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.'
  }
}

/** Today's attendance of whoever is signed in; null until it arrives, and for someone with no record of their own. */
export function useMyToday(): MyToday | null {
  const { user } = useAuth()
  const id = user?.abilities?.['wf:self:view'] ? user.id : null
  const now = useSyncExternalStore(subscribe, () => state)
  useEffect(() => {
    if (id) void loadMyToday(id)
  }, [id])
  return id && now.for === id ? now.data : null
}
