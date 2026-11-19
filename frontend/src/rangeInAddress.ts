import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { monthStart, today } from './format'

const DAY = /^\d{4}-\d{2}-\d{2}$/

/**
 * The from/to dates of a list or report, kept in the page address (`?from=…&to=…`) instead of only in memory.
 * So refreshing the page keeps the period, and the address can be bookmarked or sent to someone.
 *
 * "This month" is written as `?period=month`, which stays this month when the link is opened later.
 * The screen's usual period is not written at all, so a plain address still means "the usual".
 *
 * `usual` is the period the screen opens on: today alone, or the month so far.
 */
export function useRangeInAddress(usual: 'today' | 'month' = 'today'): [from: string, to: string, setRange: (from: string, to: string) => void] {
  const [params, setParams] = useSearchParams()
  const now = today()

  let from = usual === 'month' ? monthStart() : now
  let to = now
  if (params.get('period') === 'month') from = monthStart()
  else if (params.get('period') === 'today') from = now
  else {
    const f = params.get('from')
    const t = params.get('to')
    if (f && DAY.test(f) && t && DAY.test(t)) {
      // A hand-edited or old address is brought back into what the screens accept.
      to = t > now ? now : t
      from = f > to ? to : f
    }
  }

  const setRange = useCallback(
    (nextFrom: string, nextTo: string) => {
      setParams(
        (old) => {
          const next = new URLSearchParams(old)
          for (const key of ['from', 'to', 'period']) next.delete(key)
          const day = today()
          const period = nextTo !== day ? null : nextFrom === day ? 'today' : nextFrom === monthStart() ? 'month' : null
          if (period === null) {
            next.set('from', nextFrom)
            next.set('to', nextTo)
          } else if (period !== usual) {
            next.set('period', period)
          }
          return next
        },
        { replace: true },
      )
    },
    [setParams, usual],
  )

  return [from, to, setRange]
}

/** One more choice kept in the address beside the dates, such as which tab or which report is showing. */
export function useChoiceInAddress<T extends string>(key: string, usual: T, allowed?: readonly T[]): [T, (value: T) => void] {
  const [params, setParams] = useSearchParams()
  const typed = params.get(key) as T | null
  const value = typed && (!allowed || allowed.includes(typed)) ? typed : usual

  const setValue = useCallback(
    (next: T) => {
      setParams(
        (old) => {
          const kept = new URLSearchParams(old)
          if (next === usual) kept.delete(key)
          else kept.set(key, next)
          return kept
        },
        { replace: true },
      )
    },
    [setParams, key, usual],
  )

  return [value, setValue]
}
