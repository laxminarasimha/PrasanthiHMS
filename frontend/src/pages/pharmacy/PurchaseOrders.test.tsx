import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PurchaseOrder } from '../../types'
import { PharmacyPurchaseOrders } from './PurchaseOrders'

const get = vi.fn()
vi.mock('../../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), put: vi.fn() },
  fieldErrors: () => ({}),
}))
vi.mock('../../auth', () => ({ useAuth: () => ({ user: { can_run_pharmacy: true, can_view_pharmacy: true } }) }))

const order = (over: Partial<PurchaseOrder> = {}): PurchaseOrder => ({
  id: 7, order_no: 'PO00007', order_date: '2026-11-24', status: 'open', expected_on: '2026-11-26', remarks: null, stockist: { id: 3, name: 'Sample Pharma' }, items_count: 1, ...over,
})

// Shows what is left in the address, so the test can see the order asked for is not opened again on the next visit.
function Address() {
  return <output data-testid="address">{useLocation().search}</output>
}

const show = (address: string) =>
  render(
    <MemoryRouter initialEntries={[address]}>
      <PharmacyPurchaseOrders />
      <Address />
    </MemoryRouter>,
  )

afterEach(() => {
  cleanup()
  get.mockReset()
})

describe('the purchase orders page', () => {
  it('opens the order named in the address, as the To do list links to it, and then clears the address', async () => {
    get.mockImplementation((url: string) =>
      Promise.resolve({ data: url === '/pharmacy/purchase-orders' ? [order()] : order({ items: [{ id: 1, medicine_name: 'Sample Tablet 500', units_per_pack: 10, packs: 5 }] }) }),
    )
    show('/pharmacy/purchase-orders?open=7')

    await waitFor(() => expect(screen.getByText('Purchase order PO00007')).toBeTruthy())
    expect(get).toHaveBeenCalledWith('/pharmacy/purchase-orders/7')
    expect(screen.getByText('Sample Tablet 500')).toBeTruthy()
    expect(screen.getByTestId('address').textContent).toBe('')
  })

  it('opens no order by itself when the address names none', async () => {
    get.mockResolvedValue({ data: [order()] })
    show('/pharmacy/purchase-orders')

    await waitFor(() => expect(screen.getByText('PO00007')).toBeTruthy())
    expect(screen.queryByText('Purchase order PO00007')).toBeNull()
    expect(get).toHaveBeenCalledTimes(1)
  })
})
