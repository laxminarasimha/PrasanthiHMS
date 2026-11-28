import { describe, expect, it } from 'vitest'
import { matchAdvised, namesOf } from './LabOrderForm'
import type { LabCatalogueTest } from '../types'

// Test names written the way the lab's own list writes them; ids and prices are made up.
const names = ['Antistreptolysin O - ASO', 'Complete Blood Picture - CBP', 'Random Blood Sugar - RBS', 'Serum Uric Acid', 'Adenosine Deaminase (ADA) - Serum', 'SGOT (AST)', 'Lipid Profile']
const tests = names.map((name, i) => ({ id: i + 1, code: null, name, kind: 'values', default_comment: null, department: null, sample_type: null, price: 100, print_order: i, is_active: true, version: 1 }) as LabCatalogueTest)

/** What the desk's list ticks for what the doctor wrote, by the lab's own names, and what it says is not in the list. */
const ticks = (advised: string) => {
  const { ids, missing } = matchAdvised(advised, tests)
  return { ticked: ids.map((id) => tests.find((t) => t.id === id)!.name), missing }
}

describe('ticking the tests a doctor advised', () => {
  it('finds a test by either side of a dash in its name, and by the other names of the same test', () => {
    expect(ticks('CBP')).toEqual({ ticked: ['Complete Blood Picture - CBP'], missing: [] })
    expect(ticks('CBC')).toEqual({ ticked: ['Complete Blood Picture - CBP'], missing: [] })
    expect(ticks('Hemogram')).toEqual({ ticked: ['Complete Blood Picture - CBP'], missing: [] })
    expect(ticks('RBS')).toEqual({ ticked: ['Random Blood Sugar - RBS'], missing: [] })
    expect(ticks('ASO, random blood sugar')).toEqual({ ticked: ['Antistreptolysin O - ASO', 'Random Blood Sugar - RBS'], missing: [] })
  })

  it('never ticks a test because a short form or one side of its name sits inside other words', () => {
    expect(ticks('Ultrasound abdomen\nVisual acuity; Serum sodium')).toEqual({ ticked: [], missing: ['Ultrasound abdomen', 'Visual acuity', 'Serum sodium'] })
  })

  it('still finds a short form in brackets, and a longer line that holds a test’s whole name', () => {
    expect(ticks('AST')).toEqual({ ticked: ['SGOT (AST)'], missing: [] })
    expect(ticks('Lipid profile fasting')).toEqual({ ticked: ['Lipid Profile'], missing: [] })
  })

  it('lists each side of a dash among the names a test is searched by', () => {
    const cbp = namesOf(tests[1])
    for (const name of ['complete blood picture - cbp', 'complete blood picture', 'cbp', 'cbc', 'hemogram']) expect(cbp.has(name)).toBe(true)
    expect(namesOf(tests[0]).has('aso')).toBe(true)
  })
})
