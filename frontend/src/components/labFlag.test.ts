import { describe, expect, it } from 'vitest'
import { flagFor } from './labFlag'

describe('flagFor', () => {
  it('judges a plain range, with or without thousands commas', () => {
    expect(flagFor('65', '70 - 140')).toBe('low')
    expect(flagFor('70', '70 - 140')).toBeNull()
    expect(flagFor('141', '70–140')).toBe('high')
    expect(flagFor('12,500', '4,000 - 11,000')).toBe('high')
    expect(flagFor('3900', '4,000 - 11,000')).toBe('low')
    expect(flagFor('7500', '4,000 to 11,000')).toBeNull()
    expect(flagFor('0.4', '0.5 - 1.2')).toBe('low')
  })

  it('judges a range with only an upper limit', () => {
    expect(flagFor('199', '< 200')).toBeNull()
    expect(flagFor('200', '< 200')).toBe('high')
    expect(flagFor('200', '<= 200')).toBeNull()
    expect(flagFor('201', '≤200')).toBe('high')
    expect(flagFor('40', 'Up to 40')).toBeNull()
    expect(flagFor('41', 'upto 40')).toBe('high')
    expect(flagFor('5', 'Less than 5')).toBe('high')
    expect(flagFor('4.9', 'below 5')).toBeNull()
  })

  it('judges a range with only a lower limit', () => {
    expect(flagFor('61', '> 60')).toBeNull()
    expect(flagFor('60', '> 60')).toBe('low')
    expect(flagFor('60', '>= 60')).toBeNull()
    expect(flagFor('3.4', '≥ 3.5')).toBe('low')
    expect(flagFor('39', 'More than 40')).toBe('low')
    expect(flagFor('45', 'above 40')).toBeNull()
  })

  it('reads a range written for each sex when the sex is known', () => {
    const range = 'Male : 14.0 - 17.0; Female : 12.0 - 15.0'
    expect(flagFor('13', range, 'male')).toBe('low')
    expect(flagFor('13', range, 'female')).toBeNull()
    expect(flagFor('15.5', range, 'female')).toBe('high')
    expect(flagFor('13', range, 'other')).toBeNull()
    expect(flagFor('13', range)).toBeNull()
    expect(flagFor('45', 'M: > 40, F: > 50', 'female')).toBe('low')
    expect(flagFor('45', 'Men 13-17 / Women 12-15', 'male')).toBe('high')
  })

  it('gives no flag when the value or the range cannot be read for certain', () => {
    expect(flagFor('', '70 - 140')).toBeNull()
    expect(flagFor('trace', '70 - 140')).toBeNull()
    expect(flagFor('150', null)).toBeNull()
    expect(flagFor('150', 'Negative')).toBeNull()
    expect(flagFor('150', '70 - 140 (fasting)')).toBeNull()
    expect(flagFor('150', 'Desirable < 200; Borderline 200 - 239', 'male')).toBeNull()
    expect(flagFor('13', 'Male : 14 - 17; Female : 12 - 15; Child : 11 - 14', 'male')).toBeNull()
  })
})
