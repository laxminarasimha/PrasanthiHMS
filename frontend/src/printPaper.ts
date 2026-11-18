import { useEffect, useRef, useState } from 'react'
import { api } from './api'
import type { BillingPreferences } from './types'

const PREFS_KEY = 'hms_bill_print'
const CHOICE_KEY = 'hms_bill_paper'

type Prefs = Pick<BillingPreferences, 'print_on_letterhead' | 'letterhead_top_mm' | 'letterhead_bottom_mm'>
type Choice = 'plain' | 'letterhead' | null

export type Paper = {
  /** True when the bill goes on the hospital's own letterhead paper: no hospital details or logo are printed. */
  letterhead: boolean
  top: number
  bottom: number
  /** False only on the very first print on a computer, for the moment it takes to learn the hospital's setting. */
  ready: boolean
  choose: (letterhead: boolean) => void
}

const read = <T,>(key: string): T | null => {
  try {
    return JSON.parse(localStorage.getItem(key) ?? 'null')
  } catch {
    return null
  }
}

/**
 * Which paper a bill is printed on. The hospital sets its usual paper under Setup → Billing preferences; a counter
 * that uses the other kind changes it on the print page, and that computer remembers its own choice.
 */
export function usePrintPaper(): Paper {
  const [prefs, setPrefs] = useState<Prefs | null>(() => read<Prefs>(PREFS_KEY))
  const [asked, setAsked] = useState(false)
  const [choice, setChoice] = useState<Choice>(() => read<Choice>(CHOICE_KEY))

  useEffect(() => {
    api
      .get<BillingPreferences>('/billing-preferences')
      .then((res) => {
        const next = { print_on_letterhead: Boolean(res.data.print_on_letterhead), letterhead_top_mm: res.data.letterhead_top_mm ?? 40, letterhead_bottom_mm: res.data.letterhead_bottom_mm ?? 20 }
        setPrefs(next)
        try {
          localStorage.setItem(PREFS_KEY, JSON.stringify(next))
        } catch {
          // A full or blocked store only means the next print asks again.
        }
      })
      .catch(() => undefined)
      .finally(() => setAsked(true))
  }, [])

  return {
    letterhead: choice ? choice === 'letterhead' : Boolean(prefs?.print_on_letterhead),
    top: prefs?.letterhead_top_mm ?? 40,
    bottom: prefs?.letterhead_bottom_mm ?? 20,
    ready: prefs !== null || asked,
    choose: (letterhead) => {
      const next: Choice = letterhead ? 'letterhead' : 'plain'
      setChoice(next)
      try {
        localStorage.setItem(CHOICE_KEY, JSON.stringify(next))
      } catch {
        // Not remembered, then; it still applies to this print.
      }
    },
  }
}

/** Opens the print dialog once, when everything the page needs has arrived. */
export function useAutoPrint(ready: boolean, delay = 300) {
  const done = useRef(false)
  useEffect(() => {
    if (!ready || done.current) return
    done.current = true
    const timer = setTimeout(() => window.print(), delay)
    return () => clearTimeout(timer)
  }, [ready, delay])
}
