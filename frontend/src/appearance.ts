/** A person's own look for the app: which colours, and how large the writing is. */
export type Appearance = { theme: string; text: 'small' | 'normal' | 'large' }

export const THEMES: { key: string; name: string; note: string; bar: string; brand: string; accent: string; bg: string; surface: string }[] = [
  { key: 'prasanthi', name: 'Prasanthi', note: 'The hospital colours', bar: '#6b2c91', brand: '#6b2c91', accent: '#e8862a', bg: '#f7f5f8', surface: '#ffffff' },
  { key: 'ocean', name: 'Ocean', note: 'Blue and warm', bar: '#1f5fa8', brand: '#1f5fa8', accent: '#e0862a', bg: '#f3f6fa', surface: '#ffffff' },
  { key: 'forest', name: 'Forest', note: 'Green and amber', bar: '#17695a', brand: '#17695a', accent: '#c97a1e', bg: '#f3f7f5', surface: '#ffffff' },
  { key: 'rose', name: 'Rose', note: 'Maroon and gold', bar: '#9b2c4d', brand: '#9b2c4d', accent: '#b8862b', bg: '#f9f5f6', surface: '#ffffff' },
  { key: 'slate', name: 'Slate', note: 'Quiet grey', bar: '#334155', brand: '#334155', accent: '#0d8f8c', bg: '#f3f4f6', surface: '#ffffff' },
  { key: 'night', name: 'Night', note: 'Dark, for dim rooms', bar: '#2a2136', brand: '#c39ae8', accent: '#f0a04b', bg: '#15131a', surface: '#1f1c26' },
]

const KEY = 'hms_appearance'
const DEFAULT: Appearance = { theme: 'prasanthi', text: 'normal' }

/** The look remembered on this computer, so the sign-in page and the first paint already wear it. */
export function remembered(): Appearance {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? 'null') as Partial<Appearance> | null
    return { theme: THEMES.some((t) => t.key === saved?.theme) ? saved!.theme! : DEFAULT.theme, text: saved?.text === 'large' || saved?.text === 'small' ? saved.text : 'normal' }
  } catch {
    return DEFAULT
  }
}

/** Puts a look on the page and remembers it here. Printed pages ignore it and always use the standard colours. */
export function applyAppearance(look: Partial<Appearance> | null | undefined) {
  const next: Appearance = { theme: THEMES.some((t) => t.key === look?.theme) ? look!.theme! : DEFAULT.theme, text: look?.text === 'large' || look?.text === 'small' ? look.text : 'normal' }
  const root = document.documentElement
  if (next.theme === DEFAULT.theme) delete root.dataset.theme
  else root.dataset.theme = next.theme
  if (next.text === 'normal') delete root.dataset.text
  else root.dataset.text = next.text
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEMES.find((t) => t.key === next.theme)?.bar ?? '#6b2c91')
  try {
    localStorage.setItem(KEY, JSON.stringify(next))
  } catch {
    // Private windows may refuse; the look still applies for this visit.
  }
  return next
}
