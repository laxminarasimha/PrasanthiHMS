import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { IpdSummary } from './IpdSummary'
import { setUnsaved } from '../../unsaved'
import type { DischargeSummary } from '../../types'

const get = vi.fn()
const post = vi.fn()
vi.mock('../../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), put: vi.fn() },
  fieldErrors: () => ({ _: 'That did not save.' }),
}))

const doctor = { id: 2, name: 'Dr. Asha Sample' }
const nurse = { id: 9, name: 'Sample Nurse' }
const saved = (over: Partial<DischargeSummary> = {}): DischargeSummary => ({
  id: 3, diagnosis: 'Sample fever', course: 'Settled with fluids', condition_at_discharge: 'Stable', advice: 'Rest', medicines: null, follow_up_on: null, approved_at: null, ready_at: null, ready_by: null, preparer: nurse, ...over,
})
const stay = { investigations: '', treatment_given: '', procedures: '', operative_findings: '', medicines: [] }
const answers = (summary: DischargeSummary) => get.mockImplementation((url: string) => Promise.resolve({ data: url.endsWith('/from-stay') ? stay : summary }))
const open = (userId: number, canApprove = false) => render(<IpdSummary admissionId={5} canEdit canApprove={canApprove} doctor={doctor} userId={userId} />)
const sendButton = () => screen.queryByRole('button', { name: 'Ready — send to Dr. Asha Sample for approval' }) as HTMLButtonElement | null

afterEach(() => {
  cleanup()
  setUnsaved(null)
  get.mockReset()
  post.mockReset()
})

describe('sending a discharge summary to the doctor for approval', () => {
  it('lets the person who wrote it send it once saved, and then says who it went to, when and by whom', async () => {
    answers(saved())
    post.mockResolvedValue({ data: saved({ ready_at: '2026-11-28 10:30', ready_by: nurse }) })
    open(nurse.id)
    await waitFor(() => expect(sendButton()).toBeTruthy())
    expect(sendButton()!.disabled).toBe(false)

    fireEvent.click(sendButton()!)
    await waitFor(() => expect(post).toHaveBeenCalledWith('/ipd/admissions/5/summary/ready'))
    expect(await screen.findByText(/Sent to Dr\. Asha Sample for approval on .+ 10:30 AM, by Sample Nurse\./)).toBeTruthy()
    expect(screen.getByText('Sent for approval')).toBeTruthy()
    expect(sendButton()).toBeNull()
  })

  it('wants changes saved first', async () => {
    answers(saved())
    open(nurse.id)
    await waitFor(() => expect(sendButton()).toBeTruthy())
    fireEvent.change(screen.getByLabelText('Final diagnosis'), { target: { value: 'Sample fever, settled' } })
    expect(sendButton()!.disabled).toBe(true)
    expect(screen.getByText('Save the changes first.')).toBeTruthy()
  })

  it('is not offered before the summary is saved, nor to the admission’s own doctor, who is told it was sent to them', async () => {
    answers(saved({ id: null }))
    const unsaved = open(nurse.id)
    await screen.findByText(/Not written yet\. The diagnosis below/)
    expect(sendButton()).toBeNull()
    unsaved.unmount()

    answers(saved({ ready_at: '2026-11-28 10:30', ready_by: nurse }))
    open(doctor.id, true)
    expect(await screen.findByText(/Sent to you for approval on .+, by Sample Nurse\./)).toBeTruthy()
    expect(sendButton()).toBeNull()
    expect(screen.getByRole('button', { name: 'Approve' })).toBeTruthy()
  })

  it('reads the summary again when the answer does not carry it', async () => {
    answers(saved())
    post.mockResolvedValue({ data: '' })
    open(nurse.id)
    await waitFor(() => expect(sendButton()).toBeTruthy())
    answers(saved({ ready_at: '2026-11-28 10:30', ready_by: nurse }))
    fireEvent.click(sendButton()!)
    expect(await screen.findByText(/Sent to Dr\. Asha Sample for approval on .+, by Sample Nurse\./)).toBeTruthy()
    expect(get).toHaveBeenCalledTimes(3)
  })
})
