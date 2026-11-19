import { useCallback, useState } from 'react'
import { api } from './api'
import { clock, longDate, today } from './format'
import type { Patient } from './types'

/** A patient who is in the hospital or was here in the last few days, with what brought them in. */
export type RecentPatient = Patient & { recent: { at: string; why: string; here: boolean } }

// Kept for a minute between the boxes that offer it, so opening one does not ask the server each time.
let kept: { at: number; list: RecentPatient[] } | null = null

/**
 * The patients recently in the hospital, for a search box to offer before anything is typed.
 * `ask()` is called when the box is opened; the list is refreshed when it is more than a minute old.
 */
export function useRecentPatients() {
  const [list, setList] = useState<RecentPatient[]>(kept?.list ?? [])
  const ask = useCallback(() => {
    if (kept && Date.now() - kept.at < 60000) return setList(kept.list)
    api
      .get<RecentPatient[]>('/patients/recent')
      .then((res) => {
        kept = { at: Date.now(), list: res.data }
        setList(res.data)
      })
      .catch(() => undefined)
  }, [])
  return { recent: list, ask }
}

/** Why a patient is offered, in one line: "Seen · Dr Rao · today 10:30 AM", "Admitted · IP00012 · since 12 Nov 2026". */
export const recentLine = (recent: RecentPatient['recent']) => `${recent.why} · ${recent.why.startsWith('Admitted') ? 'since ' : ''}${recentWhen(recent.at)}`

/** When they came, said shortly: the time for today, "yesterday", or the date. */
export const recentWhen = (at: string) => {
  const day = at.slice(0, 10)
  if (day === today()) return `today ${clock(at)}`
  const yesterday = new Date()
  yesterday.setDate(yesterday.getDate() - 1)
  const y = `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`
  return day === y ? 'yesterday' : longDate(at)
}
