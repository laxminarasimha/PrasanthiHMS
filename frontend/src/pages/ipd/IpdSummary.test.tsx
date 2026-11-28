import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { IpdSummary } from './IpdSummary'
import { ARRIVED } from '../../notices'
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
  id: 3, diagnosis: 'Sample fever', course: 'Settled with fluids', condition_at_discharge: 'Stable', advice: 'Rest', medicines: null, follow_up_on: null, approved_at: null, ready_at: null, ready_by: null, preparer: nurse, doctor_hears: true, ...over,
})
const stay = { investigations: '', treatment_given: '', procedures: '', operative_findings: '', medicines: [] }
const answers = (summary: DischargeSummary) => get.mockImplementation((url: string) => Promise.resolve({ data: url.endsWith('/from-stay') ? stay : summary }))
const open = (userId: number, canApprove = false) => render(<IpdSummary admissionId={5} canEdit canApprove={canApprove} doctor={doctor} userId={userId} />)
const sendButton = () => screen.queryByRole('button', { name: 'Ready — send to Dr. Asha Sample for approval' }) as HTMLButtonElement | null
const written = (label: string) => (screen.getByLabelText(label) as HTMLTextAreaElement).value
/** A notice of this kind reaching the device while the summary is open. */
const arrive = (kind: string) => window.dispatchEvent(new CustomEvent(ARRIVED, { detail: kind }))
/** Longer than notices that come together are gathered for. */
const afterGathering = () => new Promise((done) => setTimeout(done, 700))

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

  it('does not name a doctor who does not sign in, and says that those who approve summaries were told instead', async () => {
    answers(saved({ doctor_hears: false }))
    post.mockResolvedValue({ data: saved({ doctor_hears: false, ready_at: '2026-11-28 10:30', ready_by: nurse }) })
    open(nurse.id)
    const ready = (await screen.findByRole('button', { name: 'Ready — send for approval' })) as HTMLButtonElement
    expect(sendButton()).toBeNull()
    expect(ready.title).not.toMatch(/told/)

    fireEvent.click(ready)
    expect(await screen.findByText('Sent for approval.')).toBeTruthy()
    expect(screen.getByText(/Sent for approval on .+ 10:30 AM, by Sample Nurse\. Dr\. Asha Sample does not sign in, so those who approve summaries were told\./)).toBeTruthy()
    expect(screen.queryByText(/Sent to Dr\. Asha Sample/)).toBeNull()
  })
})

describe('a notice that a summary is ready, while one is open', () => {
  it('loads it again, so the doctor reads and approves what was last saved', async () => {
    answers(saved())
    open(doctor.id, true)
    await screen.findByText('Draft')
    answers(saved({ diagnosis: 'Sample fever, settled', ready_at: '2026-11-28 10:30', ready_by: nurse }))

    arrive('summary')
    expect(await screen.findByText(/Sent to you for approval on .+, by Sample Nurse\./, undefined, { timeout: 2000 })).toBeTruthy()
    expect(written('Final diagnosis')).toBe('Sample fever, settled')
  })

  it('leaves writing that is not saved yet alone, and is not moved by notices of other kinds', async () => {
    answers(saved())
    open(doctor.id, true)
    await screen.findByText('Draft')
    const reads = get.mock.calls.length
    arrive('task')
    await afterGathering()
    expect(get.mock.calls.length).toBe(reads)

    fireEvent.change(screen.getByLabelText('Final diagnosis'), { target: { value: 'Sample fever, typed and not saved' } })
    arrive('summary')
    await afterGathering()
    expect(get.mock.calls.length).toBe(reads)
    expect(written('Final diagnosis')).toBe('Sample fever, typed and not saved')
  })

  it('does not replace what was typed while the summary was on its way', async () => {
    answers(saved())
    open(doctor.id, true)
    await screen.findByText('Draft')
    let arrived: ((res: { data: DischargeSummary }) => void) | undefined
    get.mockImplementation(() => new Promise((done) => (arrived = done)))

    arrive('summary')
    await waitFor(() => expect(arrived).toBeDefined(), { timeout: 2000 })
    fireEvent.change(screen.getByLabelText('Final diagnosis'), { target: { value: 'Sample fever, typed meanwhile' } })
    arrived!({ data: saved({ diagnosis: 'Sample fever, from the server' }) })
    await afterGathering()
    expect(written('Final diagnosis')).toBe('Sample fever, typed meanwhile')
  })
})
