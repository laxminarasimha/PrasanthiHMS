import { describe, expect, it } from 'vitest'
import { discountRupees } from './billDiscount'

describe('a discount on a whole bill', () => {
  it('in rupees is taken as typed, never more than the bill', () => {
    expect(discountRupees({ mode: 'amount', typed: '50' }, 500)).toBe(50)
    expect(discountRupees({ mode: 'amount', typed: '900' }, 500)).toBe(500)
  })
  it('as a percentage is of the bill, to the paisa, never above 100%', () => {
    expect(discountRupees({ mode: 'percent', typed: '10' }, 500)).toBe(50)
    expect(discountRupees({ mode: 'percent', typed: '12.5' }, 333.33)).toBe(41.67)
    expect(discountRupees({ mode: 'percent', typed: '150' }, 200)).toBe(200)
  })
  it('is nothing when nothing, or nonsense, is typed, or the bill is empty', () => {
    expect(discountRupees({ mode: 'percent', typed: '' }, 500)).toBe(0)
    expect(discountRupees({ mode: 'amount', typed: '-5' }, 500)).toBe(0)
    expect(discountRupees({ mode: 'percent', typed: '10' }, 0)).toBe(0)
  })
})
