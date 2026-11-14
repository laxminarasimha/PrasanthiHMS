/**
 * Work typed but not yet saved, such as a doctor's visit notes. A screen says so with setUnsaved;
 * anything that would take the person away asks first with canLeave.
 */
let warning: string | null = null

export function setUnsaved(message: string | null) {
  warning = message
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
