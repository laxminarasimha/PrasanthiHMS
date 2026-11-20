import { describe, expect, it } from 'vitest'
import { matchMedicines } from './medicineMatch'

const list = [
  { id: 1, name: 'Paracetamol 650mg Tablet', composition: 'Paracetamol 650mg' },
  { id: 2, name: 'Dolo 650mg Tablet', composition: 'Paracetamol 650mg' },
  { id: 3, name: 'Calpol 500mg Tablet', composition: 'Paracetamol 500mg' },
  { id: 4, name: 'Pan 40mg Tablet', composition: 'Pantoprazole 40mg' },
  { id: 5, name: 'Amoxicillin 500mg Capsule', composition: null },
]
const names = (typed: string) => matchMedicines(list, typed).map((m) => m.name)

describe('finding a medicine while typing an invoice', () => {
  it('matches every word in any order, in the name or what it contains', () => {
    expect(names('para 650')).toEqual(['Paracetamol 650mg Tablet', 'Dolo 650mg Tablet'])
    expect(names('650 para')).toEqual(['Paracetamol 650mg Tablet', 'Dolo 650mg Tablet'])
    expect(names('PANTO')).toEqual(['Pan 40mg Tablet'])
  })
  it('puts names that start with what was typed first, then names containing it, then the rest', () => {
    expect(names('pa')).toEqual(['Pan 40mg Tablet', 'Paracetamol 650mg Tablet', 'Calpol 500mg Tablet', 'Dolo 650mg Tablet'])
    expect(names('500')).toEqual(['Amoxicillin 500mg Capsule', 'Calpol 500mg Tablet'])
  })
  it('offers nothing for nothing typed, and stops at a sensible number', () => {
    expect(names('   ')).toEqual([])
    expect(names('zzz')).toEqual([])
    const many = Array.from({ length: 80 }, (_, i) => ({ id: i, name: `Sample ${i}` }))
    expect(matchMedicines(many, 'sample')).toHaveLength(30)
  })
})
