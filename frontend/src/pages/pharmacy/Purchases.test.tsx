import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PurchaseOrder } from '../../types'
import { PharmacyPurchases } from './Purchases'

const get = vi.fn()
const post = vi.fn()
vi.mock('../../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), put: vi.fn() },
  fieldErrors: () => ({}),
}))
vi.mock('../../auth', () => ({ useAuth: () => ({ user: { can_run_pharmacy: true, can_view_pharmacy: true } }) }))

type StillToCome = NonNullable<PurchaseOrder['still_to_come']>[number]
const stockists = [
  { id: 3, name: 'Sample Distributors' },
  { id: 4, name: 'Other Agencies' },
]
const medicines = [
  { id: 11, name: 'Sampleatorva 10', units_per_pack: 10, gst_percent: 12, hsn_code: '3004', rack_no: 'A', box_no: '2' },
  { id: 12, name: 'Samplepara 500', units_per_pack: 15, gst_percent: 5, hsn_code: null, rack_no: null, box_no: null },
]
const still = (over: Partial<StillToCome> = {}): StillToCome => ({
  medicine_id: 11, medicine_name: 'Sampleatorva 10', units_per_pack: 10, packs: 4, ordered: 10, received: 6, gst_percent: 12, hsn_code: '3004', rack_no: 'A', box_no: '2',
  is_active: true, last_rate: 20, last_mrp: 30, last_on: '2026-11-01', last_from_this_stockist: true, ...over,
})
const order = (over: Partial<PurchaseOrder> = {}): PurchaseOrder => ({ id: 7, order_no: 'PO00007', order_date: '2026-11-24', status: 'open', remarks: null, stockist: { id: 3, name: 'Sample Distributors' }, ...over })
const toCome = [still(), still({ medicine_id: 12, medicine_name: 'Samplepara 500', units_per_pack: 15, packs: 5, ordered: 5, received: 0, gst_percent: 5, hsn_code: null, rack_no: null, box_no: null, last_rate: null, last_mrp: null, last_on: null, last_from_this_stockist: false })]

/** The server's answers, by address. */
function answer(shown: PurchaseOrder = order({ still_to_come: toCome })) {
  get.mockImplementation((url: string) => {
    const data: Record<string, unknown> = {
      '/pharmacy/purchases': [],
      '/pharmacy/stockists': stockists,
      '/pharmacy/purchase-orders': [order(), order({ id: 8, order_no: 'PO00008', stockist: { id: 4, name: 'Other Agencies' } })],
      '/pharmacy/medicines': medicines,
      '/pharmacy/purchase-orders/7': shown,
    }
    return url in data ? Promise.resolve({ data: data[url] }) : Promise.reject(new Error(url))
  })
}

// Shows what is left in the address, so the test can see the order asked for is not opened again on the next visit.
function Address() {
  return <output data-testid="address">{useLocation().search}</output>
}

const show = (address = '/pharmacy/purchases') =>
  render(
    <MemoryRouter initialEntries={[address]}>
      <PharmacyPurchases />
      <Address />
    </MemoryRouter>,
  )
const boxes = (label: string) => screen.getAllByLabelText(label) as HTMLInputElement[]
const chooseMedicine = (line: number, typed: string) => {
  fireEvent.change(screen.getByLabelText(`Medicine ${line}`), { target: { value: typed } })
  fireEvent.keyDown(screen.getByLabelText(`Medicine ${line}`), { key: 'Enter' })
}

/** Opens a new invoice for the stockist of order 7, once the lists are in. */
async function newInvoice() {
  show()
  fireEvent.click(await screen.findByText('+ Add invoice'))
  await waitFor(() => expect(screen.getByRole('option', { name: 'Sample Distributors' })).toBeTruthy())
  fireEvent.change(screen.getByLabelText(/^Stockist/), { target: { value: '3' } })
  await waitFor(() => expect(screen.getByRole('option', { name: /PO00007/ })).toBeTruthy())
}

afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
})

describe('entering a stockist’s invoice against a purchase order', () => {
  it('fills a line for each medicine still to come, keeps a line already typed, and shows the last rate only as a hint', async () => {
    answer()
    await newInvoice()
    // A line typed by hand first, once the medicine list is in.
    await waitFor(() => {
      chooseMedicine(1, 'Samplepara')
      expect(boxes('Medicine 1')[0].value).toBe('Samplepara 500')
    })

    fireEvent.change(screen.getByLabelText('Against purchase order'), { target: { value: '7' } })
    await waitFor(() => expect(boxes('Qty (packs)')).toHaveLength(2))
    expect(get).toHaveBeenCalledWith('/pharmacy/purchase-orders/7')
    // The line typed first stays as typed; the other medicine is added once, with the order's pack size and what is still to come.
    expect([boxes('Medicine 1')[0].value, boxes('Medicine 2')[0].value]).toEqual(['Samplepara 500', 'Sampleatorva 10'])
    expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['', '4'])
    expect(boxes('Units in a pack').map((b) => b.value)).toEqual(['15', '10'])
    expect(boxes('GST %')[1].value).toBe('12')
    expect(boxes('Rack')[1].value).toBe('A')
    // Batch, expiry, rate and MRP wait for the bill's own figures; the last rate and MRP are only greyed in.
    expect([boxes('Batch')[1].value, boxes('Expiry')[1].value, boxes('Purchase rate / pack ₹')[1].value, boxes('MRP / pack ₹')[1].value]).toEqual(['', '', '', ''])
    expect([boxes('Purchase rate / pack ₹')[1].placeholder, boxes('MRP / pack ₹')[1].placeholder]).toEqual(['last ₹20', 'last ₹30'])
    expect(screen.getByText('From PO00007 · 4 packs still to come')).toBeTruthy()
    expect(screen.getByText('From PO00007 · 5 packs still to come')).toBeTruthy()
    expect(screen.getByText(/The medicine still to come on PO00007 is filled in/)).toBeTruthy()
    expect(screen.getByText(/Already on this invoice, and left as typed: Samplepara 500/)).toBeTruthy()

    // Fewer packs came than are still to come: the rest stays on order.
    fireEvent.change(boxes('Qty (packs)')[1], { target: { value: '3' } })
    expect(screen.getByText('1 stays on order')).toBeTruthy()
    fireEvent.change(boxes('Qty (packs)')[1], { target: { value: '6' } })
    expect(screen.getByText('2 more than ordered')).toBeTruthy()
  })

  it('takes off, when the stockist changes, only the filled lines nobody has typed into, and says which', async () => {
    answer()
    await newInvoice()
    fireEvent.change(screen.getByLabelText('Against purchase order'), { target: { value: '7' } })
    await waitFor(() => expect(boxes('Qty (packs)')).toHaveLength(2))
    // Only the packs are changed on the first line: that is typing too.
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '3' } })

    fireEvent.change(screen.getByLabelText(/^Stockist/), { target: { value: '4' } })
    expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['3'])
    expect(boxes('Medicine 1')[0].value).toBe('Sampleatorva 10')
    expect(screen.getByText('Taken off, as nothing had been typed into it: Samplepara 500.')).toBeTruthy()
    // What stays is an ordinary line now, no longer marked as from the order.
    expect(screen.queryByText(/From PO00007/)).toBeNull()
  })

  it('opens from an order with the stockist the order is with, whatever else the address says, and then clears the address', async () => {
    answer()
    show('/pharmacy/purchases?order=7&add=4')

    await waitFor(() => expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['4', '5']))
    expect((screen.getByLabelText(/^Stockist/) as HTMLSelectElement).value).toBe('3')
    await waitFor(() => expect((screen.getByLabelText('Against purchase order') as HTMLSelectElement).value).toBe('7'))
    expect(screen.getByText(/The 2 medicines still to come on PO00007 are filled in/)).toBeTruthy()
    expect(screen.getByTestId('address').textContent).toBe('')
  })

  it('fills nothing from an order that is no longer open, and says so', async () => {
    answer(order({ status: 'received', still_to_come: [] }))
    show('/pharmacy/purchases?order=7')

    await waitFor(() => expect(screen.getByText('Order PO00007 is already received, so nothing was filled in.')).toBeTruthy())
    expect((screen.getByLabelText(/^Stockist/) as HTMLSelectElement).value).toBe('')
    expect(boxes('Qty (packs)').map((b) => b.value)).toEqual([''])
  })

  it('sends only the bill’s figures, and says after saving how much of the order is still to come', async () => {
    answer(order({ still_to_come: [still()] }))
    post.mockResolvedValue({ data: { id: 50, invoice_no: 'INV-9', batches: [{ id: 1 }], order: { id: 7, order_no: 'PO00007', status: 'open', packs_to_come: 2, medicines_to_come: 1 } } })
    await newInvoice()
    fireEvent.change(screen.getByLabelText('Against purchase order'), { target: { value: '7' } })
    await waitFor(() => expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['4']))
    fireEvent.change(screen.getByLabelText(/^Invoice number/), { target: { value: 'INV-9' } })
    fireEvent.change(boxes('Batch')[0], { target: { value: 'B77' } })
    fireEvent.change(boxes('Expiry')[0], { target: { value: '2027-06' } })
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '2' } })
    fireEvent.change(boxes('Purchase rate / pack ₹')[0], { target: { value: '21' } })
    fireEvent.change(boxes('MRP / pack ₹')[0], { target: { value: '32' } })
    fireEvent.click(screen.getByText('Save invoice and add to stock'))

    await waitFor(() => expect(screen.getByText('Invoice INV-9 saved. 1 batch(es) added to stock. Order PO00007 stays open: 2 packs of 1 medicine still to come.')).toBeTruthy())
    const [url, body] = post.mock.calls[0] as [string, { purchase_order_id: string; items: Record<string, unknown>[] }]
    expect(url).toBe('/pharmacy/purchases')
    expect(body.purchase_order_id).toBe('7')
    expect(body.items).toHaveLength(1)
    expect(body.items[0]).toMatchObject({ medicine_id: '11', batch_no: 'B77', expiry: '2027-06', units_per_pack: '10', packs: '2', pack_price: '21', pack_mrp: '32' })
    expect(body.items[0].from_order).toBeUndefined()
  })

  it('lets go of what the order said of a line once a new medicine is added on it', async () => {
    answer(order({ still_to_come: [still()] }))
    post.mockImplementation((url: string) =>
      url === '/pharmacy/medicines'
        ? Promise.resolve({ data: { id: 99, name: 'Samplenew 100', units_per_pack: 10, gst_percent: 12, hsn_code: null, rack_no: null, box_no: null, is_active: true } })
        : Promise.reject(new Error(url)),
    )
    show('/pharmacy/purchases?order=7')
    await waitFor(() => expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['4']))
    expect(screen.getByText('From PO00007 · 4 packs still to come')).toBeTruthy()

    // A medicine not in the list is typed on the line filled in from the order, and added there as a new one.
    chooseMedicine(1, 'Samplenew 100')
    fireEvent.click(await screen.findByText('Add and use it'))
    await waitFor(() => expect(boxes('Medicine 1')[0].value).toBe('Samplenew 100'))
    expect(post).toHaveBeenCalledWith('/pharmacy/medicines', expect.objectContaining({ name: 'Samplenew 100' }))
    // It is an ordinary line now: nothing from the order, and no last rate of the other medicine greyed in.
    expect(screen.queryByText(/From PO00007/)).toBeNull()
    expect(screen.queryByText(/Last bought/)).toBeNull()
    expect([boxes('Purchase rate / pack ₹')[0].placeholder, boxes('MRP / pack ₹')[0].placeholder]).toEqual(['', ''])
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '1' } })
    expect(screen.queryByText(/stays? on order|more than ordered/)).toBeNull()
  })

  it('sets every line of a medicine against the order, and says it once, on the line filled in from it', async () => {
    answer(order({ still_to_come: [still({ packs: 10, received: 0 })] }))
    show('/pharmacy/purchases?order=7')
    await waitFor(() => expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['10']))
    // Six of the ten came in one batch…
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '6' } })
    expect(screen.getByText('4 stay on order')).toBeTruthy()
    // …and four in another, typed on a line of its own: nothing stays on order.
    fireEvent.click(screen.getByText('+ Add another medicine'))
    await waitFor(() => {
      chooseMedicine(2, 'Sampleatorva')
      expect(boxes('Medicine 2')[0].value).toBe('Sampleatorva 10')
    })
    fireEvent.change(boxes('Qty (packs)')[1], { target: { value: '4' } })
    expect(screen.queryByText(/stays? on order|more than ordered/)).toBeNull()
    expect(screen.getAllByText(/From PO00007/)).toHaveLength(1)
    // One more than the order is told once, on the first line.
    fireEvent.change(boxes('Qty (packs)')[1], { target: { value: '5' } })
    expect(screen.getAllByText(/stays? on order|more than ordered/).map((e) => e.textContent)).toEqual(['1 more than ordered'])
  })

  it('gives the order’s note only to the first of two lines already typed for a medicine', async () => {
    answer(order({ still_to_come: [still({ packs: 10, received: 0 })] }))
    await newInvoice()
    await waitFor(() => {
      chooseMedicine(1, 'Sampleatorva')
      expect(boxes('Medicine 1')[0].value).toBe('Sampleatorva 10')
    })
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '6' } })
    fireEvent.click(screen.getByText('+ Add another medicine'))
    chooseMedicine(2, 'Sampleatorva')
    fireEvent.change(boxes('Qty (packs)')[1], { target: { value: '3' } })

    fireEvent.change(screen.getByLabelText('Against purchase order'), { target: { value: '7' } })
    await waitFor(() => expect(screen.getAllByText(/From PO00007/)).toHaveLength(1))
    expect(screen.getByText('From PO00007 · 10 packs still to come').closest('.purchase-line')).toBe(boxes('Medicine 1')[0].closest('.purchase-line'))
    expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['6', '3'])
    expect(screen.getByText(/Already on this invoice, and left as typed: Sampleatorva 10\./)).toBeTruthy()
    expect(screen.getAllByText(/stays? on order|more than ordered/).map((e) => e.textContent)).toEqual(['1 stays on order'])
  })

  it('counts in units when the stockist’s bill is in another pack size', async () => {
    answer(order({ still_to_come: [still({ packs: 10, received: 0 })] }))
    show('/pharmacy/purchases?order=7')
    await waitFor(() => expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['10']))
    // The order is for 10 packs of 10; the bill says packs of 25.
    fireEvent.change(boxes('Units in a pack')[0], { target: { value: '25' } })
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '3' } })
    expect(screen.getByText('25 units stay on order')).toBeTruthy()
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '4' } })
    expect(screen.queryByText(/stays? on order|more than ordered/)).toBeNull()
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '5' } })
    expect(screen.getByText('25 units more than ordered')).toBeTruthy()
  })

  it('works from exactly what is left when an earlier part came in a bigger pack', async () => {
    // 10 packs of 10 ordered; 3 packs of 25 came before, so 25 units are left: shown as 3 packs to come, rounded up.
    answer(order({ still_to_come: [still({ packs: 3, ordered: 10, received: 3, units_to_come: 25 })] }))
    show('/pharmacy/purchases?order=7')
    await waitFor(() => expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['3']))
    expect(screen.getByText('From PO00007 · 25 units still to come')).toBeTruthy()
    // The 3 packs of 10 filled in are 5 units more than is left…
    expect(screen.getByText('5 units more than ordered')).toBeTruthy()
    // …and one pack of 25 is just what is left.
    fireEvent.change(boxes('Units in a pack')[0], { target: { value: '25' } })
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '1' } })
    expect(screen.queryByText(/stays? on order|more than ordered/)).toBeNull()
    // Two packs of 10 leave 5 units on order, never half a pack.
    fireEvent.change(boxes('Units in a pack')[0], { target: { value: '10' } })
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '2' } })
    expect(screen.getByText('5 units stay on order')).toBeTruthy()
  })

  it('hands the order’s note to the medicine’s other line when the line holding it is removed', async () => {
    answer(order({ still_to_come: [still({ packs: 10, received: 0 })] }))
    await newInvoice()
    await waitFor(() => {
      chooseMedicine(1, 'Sampleatorva')
      expect(boxes('Medicine 1')[0].value).toBe('Sampleatorva 10')
    })
    fireEvent.change(boxes('Qty (packs)')[0], { target: { value: '6' } })
    fireEvent.click(screen.getByText('+ Add another medicine'))
    chooseMedicine(2, 'Sampleatorva')
    fireEvent.change(boxes('Qty (packs)')[1], { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Against purchase order'), { target: { value: '7' } })
    await waitFor(() => expect(screen.getAllByText(/From PO00007/)).toHaveLength(1))
    expect(screen.getByText('1 stays on order')).toBeTruthy()

    fireEvent.click(screen.getAllByText('Remove')[0])
    expect(boxes('Qty (packs)').map((b) => b.value)).toEqual(['3'])
    expect(screen.getAllByText(/From PO00007/)).toHaveLength(1)
    expect(screen.getByText('7 stay on order')).toBeTruthy()
  })
})
