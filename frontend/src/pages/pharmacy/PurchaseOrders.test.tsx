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

  it('offers, on an open order, to enter the stockist’s invoice for it, and only on an open one', async () => {
    get.mockImplementation((url: string) =>
      Promise.resolve({ data: url === '/pharmacy/purchase-orders' ? [order()] : order({ items: [{ id: 1, medicine_name: 'Sample Tablet 500', units_per_pack: 10, packs: 5 }] }) }),
    )
    show('/pharmacy/purchase-orders?open=7')

    const enter = await screen.findByText('Enter the stockist’s invoice')
    expect(enter.getAttribute('href')).toBe('/pharmacy/purchases?order=7')
    cleanup()

    get.mockImplementation((url: string) => Promise.resolve({ data: url === '/pharmacy/purchase-orders' ? [order({ status: 'received' })] : order({ status: 'received', items: [] }) }))
    show('/pharmacy/purchase-orders?open=7')
    await waitFor(() => expect(screen.getByText('Purchase order PO00007')).toBeTruthy())
    expect(screen.queryByText('Enter the stockist’s invoice')).toBeNull()
  })

  it('tells a shortfall in units when the stockist sent another pack size, and in packs when not', async () => {
    const line = (over: Partial<NonNullable<PurchaseOrder['match']>['lines'][number]>) => ({
      medicine_id: 1, medicine_name: 'Sample Tablet 500', units_per_pack: 10, ordered: 10, received: 0, free: 0, ordered_units: 100, received_units: 0, to_come: 10, state: 'none' as const, ...over,
    })
    const match = {
      invoices: [{ id: 5, invoice_no: 'INV-1', invoice_date: '2026-11-25', total: 100 }],
      extras: [],
      complete: false,
      lines: [
        // 5 packs of 25 against 10 packs of 10: 25 units more than ordered, never a negative number of packs.
        line({ received: 5, received_units: 125, to_come: 0, state: 'over' }),
        // 12 packs of 10 against 10 packs of 100: 880 units short.
        line({ medicine_id: 2, medicine_name: 'Sample Syrup', units_per_pack: 100, ordered_units: 1000, received: 12, received_units: 120, to_come: 9, state: 'short' }),
        // 6 packs of 10 against 10 of 10: 4 short, in packs.
        line({ medicine_id: 3, medicine_name: 'Sample Drops', received: 6, received_units: 60, to_come: 4, state: 'short' }),
      ],
    }
    get.mockImplementation((url: string) => Promise.resolve({ data: url === '/pharmacy/purchase-orders' ? [order()] : order({ items: [], match }) }))
    show('/pharmacy/purchase-orders?open=7')

    await waitFor(() => expect(screen.getByText('What came against this order')).toBeTruthy())
    expect(screen.getByText('25 units more than ordered')).toBeTruthy()
    expect(screen.getByText('880 units short')).toBeTruthy()
    expect(screen.getByText('4 short')).toBeTruthy()
    expect(screen.getByText('125 units, in another pack size')).toBeTruthy()
    expect(screen.queryByText(/^-\d/)).toBeNull()
  })

  it('opens no order by itself when the address names none', async () => {
    get.mockResolvedValue({ data: [order()] })
    show('/pharmacy/purchase-orders')

    await waitFor(() => expect(screen.getByText('PO00007')).toBeTruthy())
    expect(screen.queryByText('Purchase order PO00007')).toBeNull()
    expect(get).toHaveBeenCalledTimes(1)
  })
})
