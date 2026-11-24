import { describe, expect, it } from 'vitest'
import { offeredTo, usualService } from './doctorFee'
import type { Service } from './types'

const service = (id: number, more: Partial<Service>): Service => ({ id, code: null, name: `Service ${id}`, type: 'appointment', price: 100, gst_percent: 0, priority: 2, doctor_id: null, doctor: null, is_active: true, ...more })

describe('a doctor’s own consultation fee', () => {
  const list = [service(1, { name: 'General consultation', price: 400 }), service(2, { doctor_id: 7, price: 300, name: 'Review' }), service(3, { doctor_id: 7, price: 600, is_doctor_fee: true }), service(4, { doctor_id: 8, price: 500 }), service(5, { type: 'other' }), service(6, { doctor_id: 9, is_active: false })]

  it('offers a doctor their own services first, then those open to all, and never another doctor’s', () => {
    expect(offeredTo(list, '7').map((s) => s.id)).toEqual([2, 3, 1])
    expect(offeredTo(list, '8').map((s) => s.id)).toEqual([4, 1])
  })

  it('picks the marked one, else the first of the doctor’s own, else nothing', () => {
    expect(usualService(list, '7')?.price).toBe(600)
    expect(usualService(list, '8')?.price).toBe(500)
    expect(usualService(list, '9')).toBeUndefined()
    expect(usualService(list, '')).toBeUndefined()
  })
})
