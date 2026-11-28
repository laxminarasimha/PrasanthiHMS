/** One drug a representative spoke about, written the way a line of a prescription is, with what was on offer. */
export type RepDrug = {
  name: string
  composition: string
  uses: string
  dose: string
  frequency: string
  timing: string
  duration: string
  note: string
  mrp: string
  rate: string
  scheme: string
  keep: boolean
}
/** What the company's catalogue already says about a brand: offered as the brand is typed, and filled in when chosen. */
export type CatalogueBrand = { name: string; composition: string | null; uses?: string | null; mrp: number | null; rate: number | null; scheme: string | null }

export const emptyDrug = (): RepDrug => ({ name: '', composition: '', uses: '', dose: '', frequency: '', timing: '', duration: '', note: '', mrp: '', rate: '', scheme: '', keep: false })
/** Lines with no brand typed are left out when saving. */
export const filledDrugs = (rows: RepDrug[]) =>
  rows
    .filter((r) => r.name.trim().length > 0)
    .map((r) => ({ ...Object.fromEntries(Object.entries(r).map(([k, v]) => [k, typeof v === 'string' ? v.trim() || null : v])), name: r.name.trim() }))
