import { describe, expect, it } from 'vitest'
import { inr, monthlyPreview, ruleInWords, runActions, type Part, type RunCan } from './pay'

const nobody: RunCan = { create: false, edit: false, approve: false, finalise: false, reverse: false, export: false, see_pay: false }
const hr: RunCan = { ...nobody, create: true, edit: true, export: true, see_pay: true }
const accountant: RunCan = { ...nobody, approve: true, export: true, see_pay: true, approve_this: true }
const admin: RunCan = { create: true, edit: true, approve: true, finalise: true, reverse: true, export: true, see_pay: true, approve_this: true }

describe('money as read on a pay screen', () => {
  it('always shows paise and groups the Indian way, so a column lines up', () => {
    expect(inr('1234567.5')).toBe('₹12,34,567.50')
    expect(inr('30000.00')).toBe('₹30,000.00')
    expect(inr(0)).toBe('₹0.00')
    expect(inr('-250')).toBe('-₹250.00')
  })
  it('shows a dash, never a nought, for an amount the person may not see', () => {
    expect(inr(null)).toBe('—')
    expect(inr(undefined)).toBe('—')
  })
})

describe('what a person may do next with a payroll run', () => {
  it('lets whoever prepares it work it out and mark it reviewed, and nothing more', () => {
    expect(runActions({ status: 'draft', can: hr })).toEqual(['work_out', 'review'])
    expect(runActions({ status: 'reviewed', can: hr })).toEqual(['send_back'])
    expect(runActions({ status: 'approved', can: hr })).toEqual(['send_back'])
    expect(runActions({ status: 'finalised', can: hr })).toEqual(['export'])
  })
  it('lets the approver approve, record payment and export, never finalise or reverse', () => {
    expect(runActions({ status: 'draft', can: accountant })).toEqual([])
    expect(runActions({ status: 'reviewed', can: accountant })).toEqual(['approve', 'send_back'])
    expect(runActions({ status: 'approved', can: accountant })).toEqual(['send_back'])
    expect(runActions({ status: 'finalised', can: accountant })).toEqual(['pay', 'export'])
    expect(runActions({ status: 'paid', can: accountant })).toEqual(['export'])
  })
  it('does not offer approval to whoever prepared or reviewed the run, even if they hold the permission', () => {
    expect(runActions({ status: 'reviewed', can: { ...hr, approve: true, approve_this: false } })).toEqual(['send_back'])
  })
  it('offers finalising and reversal only to those who hold them, and nothing once reversed', () => {
    expect(runActions({ status: 'approved', can: admin })).toEqual(['finalise', 'send_back'])
    expect(runActions({ status: 'paid', can: admin })).toEqual(['export', 'reverse'])
    expect(runActions({ status: 'reversed', can: admin })).toEqual([])
    expect(runActions({ status: 'finalised', can: nobody })).toEqual([])
  })
  it('keeps the register export from someone who may not see pay', () => {
    expect(runActions({ status: 'finalised', can: { ...nobody, export: true } })).toEqual([])
  })
})

describe('a salary part’s rule in words', () => {
  const part = (over: Partial<Part>): Part => ({ id: 1, code: 'X', name: 'X', kind: 'deduction', basis: 'percent_of_basic', is_statutory: true, is_enabled: false, reduces_with_absence: false, rule: null, sort: 0, ...over })
  it('says plainly when a statutory part has no rate yet, and never shows a rate that was not entered', () => {
    expect(ruleInWords(part({}))).toBe('No rate entered yet')
    expect(ruleInWords(part({ rule: {} }))).toBe('No rate entered yet')
    expect(ruleInWords(part({ is_statutory: false }))).toBe('Set on each person’s salary')
  })
  it('reads back exactly what was entered', () => {
    expect(ruleInWords(part({ rule: { percent: '10', ceiling: 15000, round: 'rupee' } }))).toBe('10%, worked on no more than ₹15,000.00, rounded to the rupee')
    expect(ruleInWords(part({ basis: 'slab_of_gross', rule: { slabs: [{ up_to: 15000, amount: 0 }, { up_to: null, amount: 250 }] } }))).toBe('2 slabs')
    expect(ruleInWords(part({ basis: 'percent_of_gross', rule: { percent: 1.75, only_if_pay_up_to: 25000, round: 'rupee_up' } }))).toBe('1.75%, only for pay up to ₹25,000.00, rounded up to the rupee')
  })
})

describe('the preview of a salary for a full month', () => {
  const parts = [
    { id: 1, code: 'BASIC', kind: 'earning', basis: 'fixed' },
    { id: 2, code: 'HRA', kind: 'earning', basis: 'percent_of_basic' },
    { id: 3, code: 'ALLOW', kind: 'earning', basis: 'fixed' },
    { id: 4, code: 'WAGE', kind: 'earning', basis: 'per_day' },
    { id: 5, code: 'PF', kind: 'deduction', basis: 'percent_of_basic' },
  ] as Part[]
  it('adds the fixed earnings and the percentages of basic pay, and nothing else', () => {
    expect(monthlyPreview(parts, { 1: '20000', 2: '40', 3: '2000', 4: '800', 5: '12' })).toBe(30000)
    expect(monthlyPreview(parts, { 1: '', 2: '40', 3: '', 4: '', 5: '' })).toBe(0)
    expect(monthlyPreview(parts, {})).toBe(0)
  })
})
