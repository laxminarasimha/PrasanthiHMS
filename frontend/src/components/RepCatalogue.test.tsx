import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RepCatalogue } from './RepCatalogue'

const get = vi.fn()
const post = vi.fn()
const del = vi.fn()
vi.mock('../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), put: vi.fn(), delete: (...args: unknown[]) => del(...args) },
  fieldErrors: (err: { response?: { data?: { errors?: Record<string, string[]> } } }) => Object.fromEntries(Object.entries(err.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]])),
}))
vi.mock('../auth', () => ({ useAuth: () => ({ user: { can_run_pharmacy: true, can_view_pharmacy: true } }) }))

const company = { id: 5, name: 'Sample Pharma', stockist_id: 3, stockist: { id: 3, name: 'City Sample Agencies' } }
const brand = (over: Record<string, unknown>) => ({
  id: 1, rep_company_id: 5, name: 'Samplepan 40', composition: 'Pantoprazole 40 mg', form: 'Tablet', pack: 'Strip of 10', mrp: 120, rate: 84.5, scheme: '10 + 1', notes: null,
  medicine_id: 21, company, medicine: { id: 21, name: 'Samplepan 40', units_per_pack: 15, is_active: true, preferred_stockist_id: 4 }, on_order: 30, in_pharmacy: [], also_from: [], ...over,
})
const products = [
  brand({}),
  brand({ id: 2, name: 'Samplecef 200', medicine_id: null, medicine: null, on_order: 0 }),
  brand({ id: 3, name: 'Sampleold 5', medicine_id: 22, medicine: { id: 22, name: 'Sampleold 5', units_per_pack: 10, is_active: false, preferred_stockist_id: null }, on_order: 0 }),
  brand({ id: 4, rep_company_id: 6, name: 'Otherpan 40', medicine_id: 23, company: { id: 6, name: 'Other Labs', stockist_id: null, stockist: null }, medicine: { id: 23, name: 'Otherpan 40', units_per_pack: 10, is_active: true, preferred_stockist_id: null }, on_order: 0 }),
]
const stockists = [
  { id: 3, name: 'City Sample Agencies' },
  { id: 4, name: 'Far Sample Distributors' },
]
/** The catalogue as the server tells it: to the pharmacy with what is on order, to anyone else without. */
function answer(catalogue: Record<string, unknown>) {
  get.mockImplementation((url: string) =>
    url === '/reps/products' ? Promise.resolve({ data: { can_change: true, companies: [{ id: 5, name: 'Sample Pharma' }, { id: 6, name: 'Other Labs' }], ...catalogue } }) : url === '/pharmacy/stockists' ? Promise.resolve({ data: stockists }) : Promise.reject(new Error(url)),
  )
}
const show = () =>
  render(
    <MemoryRouter>
      <RepCatalogue />
    </MemoryRouter>,
  )
const row = (name: string) => screen.getByText(name, { selector: 'strong' }).closest('tr') as HTMLTableRowElement
/** A brand as the order bar lists it. */
const inBar = (name: string) => screen.getByText(name, { selector: '.order-bar b' }).closest('li') as HTMLLIElement
/** The catalogue as the server tells it to the pharmacy, found by a search the way the server finds it. */
function answerSearch(list: Record<string, unknown>[]) {
  get.mockImplementation((url: string, config?: { params?: { q?: string } }) => {
    const q = (config?.params?.q ?? '').toLowerCase()
    if (url === '/reps/products') return Promise.resolve({ data: { can_change: true, can_order: true, companies: [{ id: 5, name: 'Sample Pharma' }, { id: 6, name: 'Other Labs' }], products: list.filter((p) => String(p.name).toLowerCase().includes(q)) } })
    return url === '/pharmacy/stockists' ? Promise.resolve({ data: stockists }) : Promise.reject(new Error(url))
  })
}

afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
  del.mockReset()
})

describe('ordering from the companies’ catalogues', () => {
  it('orders a brand the pharmacy keeps, from its company’s stockist first, as a draft asked for by the brand', async () => {
    answer({ can_order: true, products })
    post.mockResolvedValue({ data: { orders: [{ id: 10, order_no: 'PO00010', stockist: 'City Sample Agencies', items: 1 }, { id: 11, order_no: 'PO00011', stockist: 'Far Sample Distributors', items: 1 }] } })
    show()
    await waitFor(() => expect(row('Samplepan 40')).toBeTruthy())
    await waitFor(() => expect(get).toHaveBeenCalledWith('/pharmacy/stockists'))

    // Only brands linked to a medicine in use can be ordered; the others say why.
    expect(screen.getAllByText('+ Order')).toHaveLength(2)
    expect(within(row('Samplecef 200')).getByText('Not in the pharmacy’s medicines under this name, so it cannot be ordered here.')).toBeTruthy()
    expect(within(row('Sampleold 5')).getByText('Marked not in use in the pharmacy, so it cannot be ordered.')).toBeTruthy()
    expect(within(row('Samplepan 40')).getByText(/30 units already on order/)).toBeTruthy()

    // Choosing to order does not open the brand's own form, as a click on the row does.
    fireEvent.click(within(row('Samplepan 40')).getByText('+ Order'))
    expect(screen.queryByText('Change the brand')).toBeNull()
    expect(within(row('Samplepan 40')).getByText('packs of 15 from')).toBeTruthy()
    const supplier = screen.getByLabelText('Supplier for Samplepan 40') as HTMLSelectElement
    await waitFor(() => expect(supplier.value).toBe('3'))
    expect(within(supplier).getByText('City Sample Agencies (supplies Sample Pharma)')).toBeTruthy()
    fireEvent.change(screen.getByLabelText('Packs of Samplepan 40 to order'), { target: { value: '3' } })

    // A company with no stockist: nothing is chosen for it, and it says so until a supplier is picked.
    fireEvent.click(within(row('Otherpan 40')).getByText('+ Order'))
    expect((screen.getByLabelText('Supplier for Otherpan 40') as HTMLSelectElement).value).toBe('')
    expect(screen.getByText('Other Labs has no stockist set: choose a supplier.')).toBeTruthy()
    expect((screen.getByText('Make draft orders') as HTMLButtonElement).disabled).toBe(true)
    fireEvent.change(screen.getByLabelText('Supplier for Otherpan 40'), { target: { value: '4' } })
    fireEvent.click(screen.getByText('Make draft orders'))

    await waitFor(() => expect(screen.getByText('PO00010')).toBeTruthy())
    expect(post).toHaveBeenCalledWith('/pharmacy/manager/orders', {
      from: 'catalogue',
      lines: [
        { rep_product_id: 1, stockist_id: 3, packs: 3 },
        { rep_product_id: 4, stockist_id: 4, packs: 1 },
      ],
    })
    expect(screen.getByText('PO00011').getAttribute('href')).toBe('/pharmacy/purchase-orders?open=11')
    expect(screen.queryByText('Make draft orders')).toBeNull()
  })

  it('takes the medicine’s usual supplier when the company has no stockist, and shows a refusal in the bar', async () => {
    answer({ can_order: true, products: [brand({ company: { id: 5, name: 'Sample Pharma', stockist_id: null, stockist: null } })] })
    post.mockRejectedValue({ response: { status: 422, data: { errors: { 'lines.0.rep_product_id': ['Samplepan 40 is marked as not in use in the pharmacy, so it cannot be ordered.'] } } } })
    show()
    await waitFor(() => expect(row('Samplepan 40')).toBeTruthy())
    fireEvent.click(screen.getByText('+ Order'))
    const supplier = screen.getByLabelText('Supplier for Samplepan 40') as HTMLSelectElement
    await waitFor(() => expect(supplier.value).toBe('4'))
    expect(within(supplier).getByText('Far Sample Distributors (usual for this medicine)')).toBeTruthy()
    fireEvent.click(screen.getByText('Make draft orders'))
    await waitFor(() => expect(screen.getByText('Samplepan 40 is marked as not in use in the pharmacy, so it cannot be ordered.')).toBeTruthy())
  })

  it('shows a login that only looks at the pharmacy what is on order, without ordering', async () => {
    answer({ can_order: false, products })
    show()
    await waitFor(() => expect(row('Samplepan 40')).toBeTruthy())
    expect(within(row('Samplepan 40')).getByText(/30 units already on order/)).toBeTruthy()
    expect(screen.queryByText('+ Order')).toBeNull()
    expect(get).not.toHaveBeenCalledWith('/pharmacy/stockists')
  })

  it('lists each brand chosen by name in the bar, keeps one a search has hidden, and takes one off from there', async () => {
    answerSearch(products)
    post.mockResolvedValue({ data: { orders: [{ id: 10, order_no: 'PO00010', stockist: 'City Sample Agencies', items: 1 }] } })
    show()
    await waitFor(() => expect(row('Otherpan 40')).toBeTruthy())
    await waitFor(() => expect(get).toHaveBeenCalledWith('/pharmacy/stockists'))
    fireEvent.click(within(row('Samplepan 40')).getByText('+ Order'))
    fireEvent.click(within(row('Otherpan 40')).getByText('+ Order'))
    await waitFor(() => expect(inBar('Samplepan 40').textContent).toContain('1 pack of 15 from City Sample Agencies'))
    expect(inBar('Otherpan 40').textContent).toContain('choose a supplier')

    // A search hides Otherpan 40, which stays chosen: the bar still names it, and says what it still needs.
    fireEvent.change(screen.getByPlaceholderText('Find by brand, by a drug inside, or by company'), { target: { value: 'Samplepan' } })
    await waitFor(() => expect(screen.queryByText('Otherpan 40', { selector: 'strong' })).toBeNull())
    expect(screen.getByText(/brands chosen to order/).textContent).toContain('2 brands chosen to order')
    expect(inBar('Otherpan 40').textContent).toContain('choose a supplier')
    expect((screen.getByText('Make draft orders') as HTMLButtonElement).disabled).toBe(true)

    // Taken off from the bar, with its own “Not this one”: the rest can be ordered.
    fireEvent.click(within(inBar('Otherpan 40')).getByText('Not this one'))
    expect(screen.queryByText('Otherpan 40', { selector: '.order-bar b' })).toBeNull()
    expect(screen.getByText(/brand chosen to order/).textContent).toContain('1 brand chosen to order')
    fireEvent.click(screen.getByText('Make draft orders'))
    await waitFor(() => expect(screen.getByText('PO00010')).toBeTruthy())
    expect(post).toHaveBeenCalledWith('/pharmacy/manager/orders', { from: 'catalogue', lines: [{ rep_product_id: 1, stockist_id: 3, packs: 1 }] })
  })

  it('drops a brand from the basket when it is taken out of the catalogue', async () => {
    let gone = false
    get.mockImplementation((url: string) =>
      url === '/reps/products'
        ? Promise.resolve({ data: { can_change: true, can_order: true, companies: [{ id: 5, name: 'Sample Pharma' }], products: gone ? [] : [brand({})] } })
        : url === '/pharmacy/stockists'
          ? Promise.resolve({ data: stockists })
          : Promise.reject(new Error(url)),
    )
    del.mockImplementation(() => {
      gone = true
      return Promise.resolve({ data: null })
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    show()
    await waitFor(() => expect(row('Samplepan 40')).toBeTruthy())
    fireEvent.click(within(row('Samplepan 40')).getByText('+ Order'))
    expect(inBar('Samplepan 40')).toBeTruthy()

    fireEvent.click(row('Samplepan 40'))
    fireEvent.click(screen.getByText('Take this brand out of the catalogue'))
    await waitFor(() => expect(screen.queryByText('Samplepan 40', { selector: 'strong' })).toBeNull())
    expect(del).toHaveBeenCalledWith('/reps/products/1')
    // Nothing is left to order, so nothing can be sent for the brand that is gone.
    expect(screen.queryByText(/chosen to order/)).toBeNull()
    expect(screen.queryByText('Make draft orders')).toBeNull()
    expect(post).not.toHaveBeenCalled()
  })

  it('shows a refusal beside the brand it is about, such as one taken out of the catalogue on another screen', async () => {
    answerSearch(products)
    post.mockRejectedValue({ response: { status: 422, data: { errors: { 'lines.1.rep_product_id': ['That brand is no longer in the catalogue.'] } } } })
    show()
    await waitFor(() => expect(row('Otherpan 40')).toBeTruthy())
    fireEvent.click(within(row('Samplepan 40')).getByText('+ Order'))
    fireEvent.click(within(row('Otherpan 40')).getByText('+ Order'))
    fireEvent.change(screen.getByLabelText('Supplier for Otherpan 40'), { target: { value: '4' } })
    fireEvent.click(screen.getByText('Make draft orders'))

    // The second brand sent is the one refused.
    await waitFor(() => expect(within(inBar('Otherpan 40')).getByText('That brand is no longer in the catalogue.')).toBeTruthy())
    expect(within(inBar('Samplepan 40')).queryByText('That brand is no longer in the catalogue.')).toBeNull()
    fireEvent.click(within(inBar('Otherpan 40')).getByText('Not this one'))
    expect(screen.queryByText('That brand is no longer in the catalogue.')).toBeNull()
    expect(screen.getByText(/brand chosen to order/).textContent).toContain('1 brand chosen to order')
  })

  it('shows anyone outside the pharmacy nothing of ordering, and a row still opens the brand', async () => {
    answer({ products: products.map(({ on_order: _skip, ...p }) => ({ ...p, medicine: p.medicine && { id: p.medicine.id, name: p.medicine.name, units_per_pack: p.medicine.units_per_pack, is_active: p.medicine.is_active } })) })
    show()
    await waitFor(() => expect(row('Samplepan 40')).toBeTruthy())
    expect(screen.queryByText('+ Order')).toBeNull()
    expect(screen.queryByText(/cannot be ordered/)).toBeNull()
    expect(screen.queryByText(/already on order/)).toBeNull()
    expect(get).not.toHaveBeenCalledWith('/pharmacy/stockists')
    fireEvent.click(row('Samplepan 40'))
    expect(screen.getByText('Change the brand')).toBeTruthy()
  })
})
