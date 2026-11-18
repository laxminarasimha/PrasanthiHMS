import { useEffect } from 'react'

/**
 * Work typed but not yet saved, such as a doctor's visit notes or a half-made pharmacy bill. A screen says so
 * with useUnsaved (or setUnsaved); anything that would take the person away asks first with canLeave.
 *
 * Three ways of leaving are covered here for every screen at once: closing or reloading the tab, clicking any
 * link inside the app, and the browser's Back and Forward buttons. A screen that swaps what it shows without
 * changing the address (tabs, another row) calls canLeave() itself before doing so.
 */
let warning: string | null = null

export function setUnsaved(message: string | null) {
  warning = message
}

/** Says, for as long as `dirty` is true, that leaving this screen would lose something. */
export function useUnsaved(dirty: boolean, message: string) {
  useEffect(() => {
    if (!dirty) return
    warning = message
    return () => {
      if (warning === message) warning = null
    }
  }, [dirty, message])
}

/** The same, for a screen that would rather place it in its markup than call the hook. Draws nothing. */
export function UnsavedGuard({ on, message }: { on: boolean; message: string }) {
  useUnsaved(on, message)
  return null
}

/** True if there is nothing to lose, or the person agrees to lose it. */
export function canLeave(): boolean {
  if (!warning) return true
  if (window.confirm(warning)) {
    warning = null
    return true
  }
  return false
}

// Closing the tab or reloading the page: the browser shows its own "leave site?" question.
window.addEventListener('beforeunload', (e) => {
  if (warning) e.preventDefault()
})

// A link to another page of the app, clicked anywhere: asked on the way down, before the link itself acts.
document.addEventListener(
  'click',
  (e) => {
    if (!warning || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return
    const link = (e.target as HTMLElement | null)?.closest?.('a[href]') as HTMLAnchorElement | null
    // A print page opening in its own tab, or a file being saved, takes nobody away from this screen.
    if (!link || link.target === '_blank' || link.hasAttribute('download') || link.origin !== window.location.origin) return
    if (link.pathname === window.location.pathname && link.search === window.location.search) return
    if (!canLeave()) {
      e.preventDefault()
      e.stopPropagation()
    }
  },
  true,
)

// The browser's Back and Forward buttons. The router numbers each page in the history as it goes (state.idx), so when
// the person chooses to stay, the history is stepped back to the page they were on. This listener is added before the
// router's own, so the router never hears of a move that was refused, nor of the step that undoes it.
let here: number | null = typeof window.history.state?.idx === 'number' ? window.history.state.idx : null
let undoing = false
const remember = () => {
  if (typeof window.history.state?.idx === 'number') here = window.history.state.idx
}
for (const method of ['pushState', 'replaceState'] as const) {
  const original = window.history[method].bind(window.history)
  window.history[method] = (...args: Parameters<History['pushState']>) => {
    original(...args)
    remember()
  }
}
window.addEventListener('popstate', (e) => {
  if (undoing) {
    undoing = false
    e.stopImmediatePropagation()
    return
  }
  const to = typeof window.history.state?.idx === 'number' ? (window.history.state.idx as number) : null
  if (warning && here !== null && to !== null && to !== here && !canLeave()) {
    e.stopImmediatePropagation()
    undoing = true
    window.history.go(here - to)
    return
  }
  remember()
})
