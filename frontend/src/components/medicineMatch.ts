/** The few things a medicine list needs to search and show. */
export type Findable = { id: number; name: string; composition?: string | null; type?: string | null; manufacturer?: string | null; units_in_stock?: number }

/**
 * The medicines that match what was typed: every word typed must appear in the name or the composition, in any
 * order ("para 650" finds "Paracetamol 650mg Tablet"). Names that start with what was typed come first, then names
 * that contain it, then those found only by what they contain.
 */
export function matchMedicines<T extends Findable>(list: T[], typed: string, most = 30): T[] {
  const words = typed.toLowerCase().split(/\s+/).filter(Boolean)
  if (words.length === 0) return []
  const first = words[0]
  const scored: [number, T][] = []
  for (const m of list) {
    const name = m.name.toLowerCase()
    const all = `${name} ${(m.composition ?? '').toLowerCase()}`
    if (!words.every((w) => all.includes(w))) continue
    const rank = name.startsWith(first) ? 0 : words.every((w) => name.includes(w)) ? 1 : 2
    scored.push([rank, m])
  }
  return scored
    .sort((a, b) => a[0] - b[0] || a[1].name.localeCompare(b[1].name))
    .slice(0, most)
    .map(([, m]) => m)
}
