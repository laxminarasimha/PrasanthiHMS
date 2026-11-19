import { useEffect, useRef, useState } from 'react'

// What the app has asked the server for and not yet heard back about, each with a number in the order it was
// asked. The page frame watches this to know when a screen that was just opened has everything it asked for.
let nextAsk = 1
const onTheWay = new Set<number>()
const watchers = new Set<() => void>()
const tell = () => watchers.forEach((w) => w())

/** Something has been asked for. Returns its number, to hand back to `loadEnded` when the answer (or a failure) comes. */
export const loadStarted = (): number => {
  const ask = nextAsk++
  onTheWay.add(ask)
  tell()
  return ask
}
export const loadEnded = (ask: number) => {
  onTheWay.delete(ask)
  tell()
}

/** A screen often asks for a second list once the first has come, so "nothing on its way" must hold this long. */
const QUIET_MS = 90
/** However the line behaves, a screen stops looking as if it is loading after this long; failed loads are reported separately. */
const GIVE_UP_MS = 15_000

/**
 * True from the moment a screen is opened until everything it first asked the server for has arrived.
 * `screen` names the screen (its address): when it changes, the wait starts again.
 *
 * Until then a count of 0 or "nothing here yet" is not yet true, so the page frame greys the figures and holds
 * the empty messages back (see `.first-load` in index.css).
 *
 * Only what was asked for since this screen opened counts: something the previous screen was still waiting for
 * does not keep the new one looking unfinished.
 */
export function useFirstLoad(screen: string): boolean {
  // Noted while drawing, before the new screen's own code has had a chance to ask for anything.
  const [opened, setOpened] = useState({ screen, from: nextAsk })
  if (opened.screen !== screen) setOpened({ screen, from: nextAsk })
  const [arrived, setArrived] = useState<string | null>(null)

  const from = opened.screen === screen ? opened.from : nextAsk
  useEffect(() => {
    let quiet: number | undefined
    const done = () => setArrived(screen)
    const check = () => {
      window.clearTimeout(quiet)
      for (const ask of onTheWay) if (ask >= from) return
      quiet = window.setTimeout(done, QUIET_MS)
    }
    watchers.add(check)
    check()
    const giveUp = window.setTimeout(done, GIVE_UP_MS)
    return () => {
      watchers.delete(check)
      window.clearTimeout(quiet)
      window.clearTimeout(giveUp)
    }
  }, [screen, from])

  return arrived !== screen
}

/**
 * The same for a part of a screen that is opened later: a side panel, a window, or the body of a tab. True from
 * when the part appears until what it asked for has arrived. Give `part` the tab's name where one body follows
 * another in the same place, so the wait starts again at each change of tab.
 *
 * Put `first-load` on the part's outer element while this is true to get the same treatment as a whole screen,
 * or use the answer directly to hold back a "nothing here" message.
 */
export const usePartLoading = (part = 'part'): boolean => useFirstLoad(part)

/**
 * Calls `load` as soon as the screen opens, and again whenever `load` changes (a new date, a filter, a search).
 * Those later calls wait `ms` first, so typing in a search box does not ask the server at every key.
 */
export function useLoadOnChange(load: () => void, ms = 250) {
  const ran = useRef(false)
  useEffect(() => {
    const timer = window.setTimeout(
      () => {
        ran.current = true
        load()
      },
      ran.current ? ms : 0,
    )
    return () => window.clearTimeout(timer)
  }, [load, ms])
}
