import { useEffect, useRef } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { canLeave } from './unsaved'
import type { User } from './types'

/**
 * The notices the hospital sends to a person's phone or computer, and what the open screens do when one arrives.
 * The server decides who may be sent what; the screens only say so in words and load again when something comes.
 */
export type NoticeKind = 'appointment' | 'lab' | 'rep' | 'task' | 'leave' | 'expense' | 'stock_count' | 'summary' | 'test'

/** Something has just arrived on this device while the app is open: the screen it concerns may want to load again. */
export const ARRIVED = 'hms:push-arrived'
/** A notice was tapped while the app was already open: the page it points to. */
export const TAPPED = 'hms:push-open'

/** What a doctor was sent before the server listed it for each person. Used only until the person's details are read again. */
const DOCTOR_BEFORE: NoticeKind[] = ['appointment', 'lab', 'rep', 'task', 'test']

/** The kinds of notice this person can be sent. Details saved on the device before the server listed them count a doctor in, and nobody else. */
export function noticeKinds(user: User | null | undefined): string[] {
  if (!user) return []
  return user.notices ?? (user.role === 'doctor' ? DOCTOR_BEFORE : [])
}

/** Whether this login is offered notices at all. Nobody else is ever asked. */
export const getsNotices = (user: User | null | undefined) => noticeKinds(user).length > 0

/** Each kind in words, to follow "notified of". The test notice is left out: it only shows that notices arrive. */
const WORDS: Partial<Record<string, string>> = {
  appointment: 'new appointments and patients arriving',
  lab: 'lab results ready to see',
  rep: 'medical representatives waiting',
  task: 'tasks given to you',
  leave: 'your leave being decided',
  expense: 'your expenses being approved or turned down',
  stock_count: 'stock counts handed in for approval',
  summary: 'discharge summaries ready for your approval',
}

/** What this person will be told of, in words, so the strip on the first screen and My profile always say the same. */
export const noticesFor = (user: User | null | undefined): string[] => noticeKinds(user).flatMap((kind) => WORDS[kind] ?? [])

/** "a", "a and b", "a, b and c". */
export function inWords(parts: string[]): string {
  if (parts.length < 2) return parts[0] ?? ''
  return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`
}

/** Notices that come close together are gathered, so a list is loaded once for them all. */
const GATHER_MS = 500

/**
 * Calls `then` when a notice of one of these kinds (or of any kind) reaches this device while the screen is open,
 * so the list it concerns loads again at once instead of at the next timed look.
 */
export function useNotice(kinds: readonly NoticeKind[] | 'any', then: () => void): void {
  const latest = useRef(then)
  useEffect(() => {
    latest.current = then
  })
  const wanted = kinds === 'any' ? 'any' : kinds.join(' ')
  useEffect(() => {
    const listed = wanted.split(' ')
    let waiting: number | undefined
    const heard = (event: Event) => {
      const kind = (event as CustomEvent<unknown>).detail
      if (wanted !== 'any' && (typeof kind !== 'string' || !listed.includes(kind))) return
      waiting ??= window.setTimeout(() => {
        waiting = undefined
        latest.current()
      }, GATHER_MS)
    }
    window.addEventListener(ARRIVED, heard)
    return () => {
      window.removeEventListener(ARRIVED, heard)
      window.clearTimeout(waiting)
    }
  }, [wanted])
}

/** An address inside this app, such as "/todo". Anything that could lead to another site is not one. */
export function ownPage(url: unknown): url is string {
  if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//') || url.startsWith('/\\')) return false
  try {
    return new URL(url, window.location.origin).origin === window.location.origin
  } catch {
    return false
  }
}

/**
 * A notice tapped while the app is already open goes to the page it is about, unless that would lose writing not yet
 * saved: then the person is asked first, as for any link, and staying puts them back where they were.
 */
export function useOpenFromNotice(): void {
  const navigate = useNavigate()
  const { pathname, search } = useLocation()
  const here = useRef('')
  useEffect(() => {
    here.current = pathname + search
  })
  useEffect(() => {
    const open = (event: Event) => {
      const url = (event as CustomEvent<unknown>).detail
      if (!ownPage(url) || url === here.current || !canLeave()) return
      navigate(url)
    }
    window.addEventListener(TAPPED, open)
    return () => window.removeEventListener(TAPPED, open)
  }, [navigate])
}
