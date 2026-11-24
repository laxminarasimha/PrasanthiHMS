import { useEffect, useRef, useState } from 'react'
import { api } from './api'
import type { BillingPreferences } from './types'

const CHOICE_KEY = 'hms_bill_paper'

type Prefs = Pick<BillingPreferences, 'print_on_letterhead' | 'letterhead_top_mm' | 'letterhead_bottom_mm' | 'bill_heading' | 'bill_heading_size' | 'bill_logo_size' | 'ipd_print_medicines' | 'bill_paper_size' | 'print_gstin'>

/** The paper a receipt can be printed on: a full sheet, a half sheet, or an 80 mm thermal roll. */
export type PaperSize = 'a4' | 'a5' | 'thermal'
export const SIZE_LABEL: Record<PaperSize, string> = { a4: 'A4', a5: 'A5', thermal: 'Thermal roll (80 mm)' }
const SIZE_KEY = 'hms_bill_size'

/** What the print pages keep of the billing preferences, with the usual values filled in. Also written by Setup when they are saved. */
export const printPrefs = (from: BillingPreferences): Required<Prefs> => ({
  print_on_letterhead: Boolean(from.print_on_letterhead),
  letterhead_top_mm: from.letterhead_top_mm ?? 40,
  letterhead_bottom_mm: from.letterhead_bottom_mm ?? 20,
  bill_heading: from.bill_heading ?? 'side',
  bill_heading_size: from.bill_heading_size ?? 'small',
  bill_logo_size: from.bill_logo_size ?? 'medium',
  ipd_print_medicines: from.ipd_print_medicines ?? true,
  bill_paper_size: from.bill_paper_size ?? 'a4',
  print_gstin: from.print_gstin ?? true,
})
export const PRINT_PREFS_KEY = 'hms_bill_print'
type Choice = 'plain' | 'letterhead' | null

export type Paper = {
  /** True when the bill goes on the hospital's own letterhead paper: no hospital details or logo are printed. */
  letterhead: boolean
  top: number
  bottom: number
  /** False only on the very first print on a computer, for the moment it takes to learn the hospital's setting. */
  ready: boolean
  choose: (letterhead: boolean) => void
  /** The size of paper this page is being printed on, and the sizes it can be printed on (none: always A4). */
  size: PaperSize
  sizes: PaperSize[]
  chooseSize: (size: PaperSize) => void
  /** Whether the hospital's GSTIN goes on bills. */
  gstin: boolean
  /** On plain paper: how the hospital's heading is laid out and sized. `image` uses the letterhead pictures. */
  heading: 'side' | 'centre' | 'image'
  headingSize: 'small' | 'medium' | 'large'
  logoSize: 'small' | 'medium' | 'large'
  /** On the IPD bill: each medicine listed under the pharmacy bill it came on. */
  ipdMedicines: boolean
}

/** The class a printed bill wears for the size of paper it is on. */
export const paperClass = (paper: Paper) => `paper-${paper.size}`

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
export function usePrintPaper(sizes: PaperSize[] = []): Paper {
  const [prefs, setPrefs] = useState<Prefs | null>(() => read<Prefs>(PRINT_PREFS_KEY))
  const [asked, setAsked] = useState(false)
  const [choice, setChoice] = useState<Choice>(() => read<Choice>(CHOICE_KEY))
  const [sizeChoice, setSizeChoice] = useState<PaperSize | null>(() => read<PaperSize>(SIZE_KEY))
  // A page that cannot be printed on the size chosen (an inpatient bill on a thermal roll) falls back to A4.
  const wanted = sizeChoice ?? prefs?.bill_paper_size ?? 'a4'
  const size: PaperSize = sizes.includes(wanted) ? wanted : 'a4'

  useEffect(() => {
    api
      .get<BillingPreferences>('/billing-preferences')
      .then((res) => {
        const next = printPrefs(res.data)
        setPrefs(next)
        try {
          localStorage.setItem(PRINT_PREFS_KEY, JSON.stringify(next))
        } catch {
          // A full or blocked store only means the next print asks again.
        }
      })
      .catch(() => undefined)
      .finally(() => setAsked(true))
  }, [])

  return {
    // A thermal roll has no letterhead: the hospital's name is always printed on it.
    letterhead: size !== 'thermal' && (choice ? choice === 'letterhead' : Boolean(prefs?.print_on_letterhead)),
    size,
    sizes,
    chooseSize: (next) => {
      setSizeChoice(next)
      try {
        localStorage.setItem(SIZE_KEY, JSON.stringify(next))
      } catch {
        // Not remembered, then; it still applies to this print.
      }
    },
    gstin: prefs?.print_gstin ?? true,
    top: prefs?.letterhead_top_mm ?? 40,
    bottom: prefs?.letterhead_bottom_mm ?? 20,
    ready: prefs !== null || asked,
    heading: prefs?.bill_heading ?? 'side',
    headingSize: prefs?.bill_heading_size ?? 'small',
    logoSize: prefs?.bill_logo_size ?? 'medium',
    ipdMedicines: prefs?.ipd_print_medicines ?? true,
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

/**
 * How the hospital's heading is laid out and sized on every print: the last copy seen at once, then the saved one.
 * The app refreshes the copy each time it is opened (see keepPrintPrefsFresh), so a print page almost always has it already.
 */
export function useHeadingPrefs(): Pick<Paper, 'heading' | 'headingSize' | 'logoSize'> {
  const [prefs, setPrefs] = useState<Prefs | null>(() => read<Prefs>(PRINT_PREFS_KEY))

  useEffect(() => {
    let gone = false
    keepPrintPrefsFresh().then((next) => !gone && next && setPrefs(next))
    return () => {
      gone = true
    }
  }, [])

  return { heading: prefs?.bill_heading ?? 'side', headingSize: prefs?.bill_heading_size ?? 'small', logoSize: prefs?.bill_logo_size ?? 'medium' }
}

/** Fetches the print preferences and keeps a copy for the print pages, which open in their own tab and print at once. */
export function keepPrintPrefsFresh(): Promise<Required<Prefs> | null> {
  return api
    .get<BillingPreferences>('/billing-preferences')
    .then((res) => {
      const next = printPrefs(res.data)
      try {
        localStorage.setItem(PRINT_PREFS_KEY, JSON.stringify(next))
      } catch {
        // A full or blocked store only means the print page asks for itself.
      }
      return next
    })
    .catch(() => null)
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
