import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { NewMedicine } from './NewMedicine'

const get = vi.fn()
const post = vi.fn()
vi.mock('../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args) },
  fieldErrors: (err: { response?: { data?: { errors?: Record<string, string[]> } } }) => Object.fromEntries(Object.entries(err.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]])),
}))

afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
})

describe('adding a new medicine from an invoice line', () => {
  it('starts from what the line already says, creates the medicine, and hands it back without saving the invoice behind', async () => {
    get.mockResolvedValue({ data: [{ id: 1, name: 'Sample Pharma' }] })
    post.mockResolvedValue({ data: { id: 77, name: 'Sampleflam 50mg Tablet', units_per_pack: 15, gst_percent: 5, hsn_code: '3004' } })
    const onAdded = vi.fn()
    const invoiceSaved = vi.fn((e: Event) => e.preventDefault())
    render(
      <form onSubmit={(e) => invoiceSaved(e.nativeEvent)}>
        <NewMedicine start={{ units_per_pack: '15', gst_percent: '5', hsn_code: '3004' }} onClose={() => undefined} onAdded={onAdded} />
      </form>,
    )

    expect((screen.getByLabelText(/Units in a pack/) as HTMLInputElement).value).toBe('15')
    expect((screen.getByLabelText(/GST %/) as HTMLInputElement).value).toBe('5')
    const add = screen.getByText('Add and use it') as HTMLButtonElement
    expect(add.disabled).toBe(true) // no name yet
    fireEvent.change(screen.getByLabelText(/Name, as on the strip/), { target: { value: '  Sampleflam 50mg Tablet ' } })
    fireEvent.click(add)

    await waitFor(() => expect(onAdded).toHaveBeenCalledWith(expect.objectContaining({ id: 77 })))
    expect(post).toHaveBeenCalledWith('/pharmacy/medicines', expect.objectContaining({ name: 'Sampleflam 50mg Tablet', units_per_pack: '15', gst_percent: '5', hsn_code: '3004', composition: null, is_scheduled: false }))
    expect(invoiceSaved).not.toHaveBeenCalled()
  })

  it('says why when the name is already in the list, and adds nothing', async () => {
    get.mockResolvedValue({ data: [] })
    post.mockRejectedValue({ response: { status: 422, data: { errors: { name: ['A medicine with this name is already in the list.'] } } } })
    const onAdded = vi.fn()
    render(<NewMedicine onClose={() => undefined} onAdded={onAdded} />)
    fireEvent.change(screen.getByLabelText(/Name, as on the strip/), { target: { value: 'Paracetamol 650mg Tablet' } })
    fireEvent.click(screen.getByText('Add and use it'))
    await waitFor(() => expect(screen.getByText('A medicine with this name is already in the list.')).toBeTruthy())
    expect(onAdded).not.toHaveBeenCalled()
  })
})
