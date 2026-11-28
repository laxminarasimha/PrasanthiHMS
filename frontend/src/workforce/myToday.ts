import { useEffect, useSyncExternalStore } from 'react'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { today } from '../format'
import type { Spell } from './shared'

/** A person's own attendance for today, as the check-in card and the call-out on opening the app both show it. */
export type MyToday = {
  employee: { id: number; name: string } | null
  self_check_in?: boolean
  /** False for a doctor or consultant, who is not in the daily attendance. */
  regular?: boolean
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

/**
 * Whether the call-out on opening the app asks this person to check in: someone in the daily attendance who marks
 * their own, is not in, and has done no duty today. Doctors and consultants are not in the daily attendance: they may
 * check in from the card, and are not asked to.
 */
export const checkInDue = (mine: MyToday | null) => Boolean(mine?.employee && mine.self_check_in && mine.regular !== false && !mine.open && (mine.done ?? []).length === 0)

// The call-out put away today, as "person:day", kept for as long as this tab is open. Shared, so that whatever waits
// while the call-out asks (the offer of notifications) comes as soon as it is put away.
const ASKED = 'hms_check_in_asked'
let putAway: string | null = null
const putAwayMark = () => {
  if (putAway === null) {
    try {
      putAway = sessionStorage.getItem(ASKED) ?? ''
    } catch {
      putAway = ''
    }
  }
  return putAway
}
const markFor = (userId: number) => `${userId}:${today()}`

/** The call-out is put away for today, by "Not now" or by checking in from it. It asks again when the app is next opened. */
export function putCheckInAway(userId: number): void {
  putAway = markFor(userId)
  try {
    sessionStorage.setItem(ASKED, putAway)
  } catch {
    // Without storage it is only put away until the page is loaded again.
  }
  tell()
}

/** Whether this person put the call-out away today. */
export function useCheckInPutAway(userId: number | null | undefined): boolean {
  const mark = useSyncExternalStore(subscribe, putAwayMark)
  return typeof userId === 'number' && mark === markFor(userId)
}
