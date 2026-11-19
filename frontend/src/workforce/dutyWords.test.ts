import { describe, expect, it } from 'vitest'
import { dutyLabel, shortTime, weekStart } from './dutyWords'

describe('duties in words', () => {
  it('reads the hospital’s three duties the way staff say them', () => {
    expect(dutyLabel({ off: false }, { name: 'Morning duty', starts_at: '08:00', ends_at: '18:00' })).toBe('Morning duty 8 AM–6 PM')
    expect(dutyLabel({ off: false }, { name: 'Long day', starts_at: '10:00', ends_at: '21:00' })).toBe('Long day 10 AM–9 PM')
    expect(dutyLabel({ off: false }, { name: 'Night duty', starts_at: '20:00', ends_at: '08:00' })).toBe('Night duty 8 PM–8 AM')
  })
  it('says a day off is a day off, and owns up to a missing shift', () => {
    expect(dutyLabel({ off: true }, { name: 'Night duty', starts_at: '20:00', ends_at: '08:00' })).toBe('Day off')
    expect(dutyLabel({ off: false }, undefined)).toBe('No shift set')
  })
  it('writes times on the twelve-hour clock', () => {
    expect(['00:00', '07:30', '12:00', '13:15', '23:59'].map(shortTime)).toEqual(['12 AM', '7:30 AM', '12 PM', '1:15 PM', '11:59 PM'])
  })
})

describe('the week a roster shows', () => {
  it('starts on the Monday', () => {
    expect(weekStart('2026-06-01')).toBe('2026-06-01') // a Monday
    expect(weekStart('2026-06-07')).toBe('2026-06-01') // the Sunday after
    expect(weekStart('2026-06-03')).toBe('2026-06-01')
    expect(weekStart('2026-01-01')).toBe('2025-12-29') // across a year
  })
})
