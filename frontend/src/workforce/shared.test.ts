import { describe, expect, it } from 'vitest'
import { parseCsv } from '../format'
import { addMonths, days, hours, monthName, readAttendanceSheet } from './shared'

describe('reading an attendance sheet', () => {
  it('finds the columns by what they are called, in any order', () => {
    const sheet = parseCsv('Date,Check Out,Staff Name,Check In,Employee No\n2026-03-09,17:30,Asha Sample,09:00,SH-1\n10/03/2026,,Bhanu Sample,20:00,SH-2\n')
    expect(readAttendanceSheet(sheet)).toEqual({
      rows: [
        { employee_no: 'SH-1', name: 'Asha Sample', date: '2026-03-09', check_in: '09:00', check_out: '17:30' },
        { employee_no: 'SH-2', name: 'Bhanu Sample', date: '10/03/2026', check_in: '20:00', check_out: '' },
      ],
    })
  })

  it('accepts the names an attendance machine uses, and skips blank lines', () => {
    const sheet = parseCsv('Emp ID,Day,In Time,Out Time\nSH-1,2026-03-09,09:00,17:30\n,,,\n')
    const found = readAttendanceSheet(sheet)
    expect('rows' in found && found.rows).toEqual([{ employee_no: 'SH-1', name: '', date: '2026-03-09', check_in: '09:00', check_out: '17:30' }])
  })

  it('says which column is missing instead of guessing', () => {
    expect(readAttendanceSheet(parseCsv('Name,Date,Check in\nAsha,2026-03-09,09:00\n'))).toEqual({ problem: 'No column for the employee number was found. Name it "Employee no".' })
    expect(readAttendanceSheet(parseCsv('Employee no,Check in\nSH-1,09:00\n'))).toEqual({ problem: 'No column for the date was found. Name it "Date".' })
    expect(readAttendanceSheet(parseCsv('Employee no,Date\nSH-1,2026-03-09\n'))).toHaveProperty('problem')
    expect(readAttendanceSheet(parseCsv('Employee no,Date,Check in\n'))).toEqual({ problem: 'The sheet has no lines under its heading row.' })
  })
})

describe('how times and days are written', () => {
  it('writes minutes as hours and minutes, and nothing for nothing', () => {
    expect(hours(505)).toBe('8h 25m')
    expect(hours(480)).toBe('8h 00m')
    expect(hours(0)).toBe('')
    expect(hours(null)).toBe('')
  })

  it('writes one day and several days', () => {
    expect([days(1), days(2.5), days(0)]).toEqual(['1 day', '2.5 days', '0 days'])
  })

  it('steps a month across a year end', () => {
    expect([addMonths('2026-12', 1), addMonths('2026-01', -1), addMonths('2026-03', 0)]).toEqual(['2027-01', '2025-12', '2026-03'])
    expect(monthName('2026-03')).toBe('March 2026')
  })
})
