import { describe, expect, it } from 'vitest'
import { fillFromBatches, shortOf, visitOf, type CardTag } from './rxFill'

const taken = (batches: { id: number; units_in_stock: number }[], wanted: number) => fillFromBatches(batches, wanted).map((t) => [t.batch.id, t.quantity])

describe('a prescribed number of tablets taken from the batches', () => {
  const batches = [
    { id: 1, units_in_stock: 15 },
    { id: 2, units_in_stock: 40 },
    { id: 3, units_in_stock: 10 },
  ]

  it('comes from the batch that expires first, then the next', () => {
    expect(taken(batches, 21)).toEqual([
      [1, 15],
      [2, 6],
    ])
  })

  it('all comes from the first batch when it has enough', () => {
    expect(taken(batches, 10)).toEqual([[1, 10]])
  })

  it('is never more than is in stock, and the counter says so', () => {
    expect(taken([{ id: 1, units_in_stock: 15 }], 21)).toEqual([[1, 15]])
    expect(shortOf(21, 15)).toBe('prescribed 21, 15 in stock')
  })

  it('passes over a batch with nothing left', () => {
    expect(
      taken(
        [
          { id: 1, units_in_stock: 0 },
          { id: 2, units_in_stock: 5 },
        ],
        3,
      ),
    ).toEqual([[2, 3]])
  })
})

describe('the visit a bill is made from', () => {
  const tag = (kind: CardTag['kind'], id: number, at: string, patientId = 7): CardTag => ({ kind, id, at, patientId })

  it('is the newest visit card that any medicine on the bill came from', () => {
    expect(visitOf([tag('visit', 3, '2026-11-20 10:00'), undefined, tag('visit', 9, '2026-11-24 11:00'), tag('visit', 4, '2026-11-22 09:00')], 7)).toBe(9)
  })

  it('is never a ward or a discharge card', () => {
    expect(visitOf([tag('ward', 3, '2026-11-24 10:00'), tag('discharge', 5, '2026-11-24 12:00')], 7)).toBeNull()
  })

  it('is never another patient’s card, and there is none for medicines picked by name', () => {
    expect(visitOf([tag('visit', 3, '2026-11-24 10:00', 8)], 7)).toBeNull()
    expect(visitOf([undefined, undefined], 7)).toBeNull()
  })
})
