import { describe, expect, it } from 'vitest'
import { nth, testLabel } from './labName'

describe('a lab test done more than once on an order', () => {
  it('is numbered so the samples are told apart', () => {
    expect(testLabel({ test_name: 'CBP', repeat_no: 2 })).toBe('CBP (2nd)')
    expect([1, 2, 3, 4, 11, 12, 13, 21].map(nth)).toEqual(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st'])
  })

  it('keeps its plain name when it is there once', () => {
    expect(testLabel({ test_name: 'CBP' })).toBe('CBP')
    expect(testLabel({ test_name: 'CBP', repeat_no: null })).toBe('CBP')
  })
})
