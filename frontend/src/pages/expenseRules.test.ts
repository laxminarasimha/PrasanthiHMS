import { describe, expect, it } from 'vitest'
import { approvalHint, ordinal } from './expenseRules'

const repairs = { name: 'Repairs', approval_above: '5000.00', approver_limit: '50000.00' }

describe('what a heading asks before an expense is paid', () => {
  it('asks nothing of a typed heading, or one with no approval amount, so the cashier’s entry is as before', () => {
    expect(approvalHint(undefined, 90000)).toBeNull()
    expect(approvalHint({ name: 'Tea', approval_above: null, approver_limit: null }, 90000)).toBeNull()
  })
  it('asks nothing up to the amount, and for approval above it', () => {
    expect(approvalHint(repairs, 5000)).toBeNull()
    expect(approvalHint(repairs, 5000.01)).toBe('Under Repairs, anything above ₹5,000 is approved before it is paid. Send it for approval.')
  })
  it('says when it needs the higher approval', () => {
    expect(approvalHint(repairs, 80000)).toBe('Under Repairs, anything above ₹5,000 is approved by someone allowed to approve large expenses before it is paid. Send it for approval.')
  })
  it('reads a heading where everything is approved, and describes a whole rule', () => {
    expect(approvalHint({ name: 'Rent', approval_above: 0, approver_limit: null }, 1)).toBe('Everything under Rent is approved before it is paid. Send it for approval.')
    expect(approvalHint(repairs, Infinity)).toBe('Approved before paying above ₹5,000; above ₹50,000 it needs “Approve large expenses”')
    expect(approvalHint({ name: 'Rent', approval_above: '0', approver_limit: null }, Infinity)).toBe('Every expense is approved before it is paid')
  })
})

describe('the day of the month in words', () => {
  it('says 1st, 2nd, 3rd, 4th, 11th, 21st and 22nd', () => {
    expect([1, 2, 3, 4, 11, 12, 13, 21, 22, 28].map(ordinal)).toEqual(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '28th'])
  })
})
