import { useEffect, useRef, useState } from 'react'
import { api, fieldErrors } from '../../api'
import { useUnsaved } from '../../unsaved'
import { MedicineRows, emptyMedicine, filledMedicines } from '../../components/MedicineRows'
import { clock, longDate, today } from '../../format'
import type { DischargeSummary, PrescribedMedicine } from '../../types'

export const SUMMARY_PARTS: { key: keyof DischargeSummary & string; label: string; hint?: string; rows?: number }[] = [
  { key: 'diagnosis', label: 'Final diagnosis', rows: 2 },
  { key: 'complaints', label: 'Presenting complaints', rows: 2 },
  { key: 'history', label: 'History', rows: 2 },
  { key: 'examination', label: 'Examination findings', rows: 3 },
  { key: 'investigations', label: 'Investigations', hint: 'Important results during the stay', rows: 3 },
  { key: 'treatment_given', label: 'Treatment given', rows: 3 },
  { key: 'procedures', label: 'Surgery or procedure', rows: 2 },
  { key: 'operative_findings', label: 'Operative findings', rows: 2 },
  { key: 'course', label: 'Course in hospital', hint: 'Treatment given and how the patient responded', rows: 5 },
  { key: 'condition_at_discharge', label: 'Condition at discharge', rows: 2 },
]

/** The parts that come after the medicines and advice. */
export const AFTER_PARTS: { key: keyof DischargeSummary & string; label: string; hint?: string }[] = [
  { key: 'tests_requested', label: 'Tests requested', hint: 'Tests to be done before the review' },
  { key: 'other_instructions', label: 'Other instructions', hint: 'Nutrition, physiotherapy and the like' },
  { key: 'acknowledgement', label: 'Acknowledgement', hint: 'For example: explained to the patient and attendant in their own language' },
]

type Texts = Record<string, string>

/** What the stay's own records offer for the summary; see StaySummary on the server. */
type FromStay = { investigations: string; treatment_given: string; procedures: string; operative_findings: string; medicines: PrescribedMedicine[] }
const FROM_STAY: Partial<Record<string, { key: keyof Omit<FromStay, 'medicines'>; add: string }>> = {
  investigations: { key: 'investigations', add: 'Add the lab results of this stay' },
  treatment_given: { key: 'treatment_given', add: 'Add the medicines given, from the chart' },
  procedures: { key: 'procedures', add: 'Add from the OT notes' },
  operative_findings: { key: 'operative_findings', add: 'Add from the OT notes' },
}

/** Ready phrases for the parts that are nearly the same on every summary. */
const PHRASES: Record<string, string[]> = {
  condition_at_discharge: ['Stable', 'Improved', 'Afebrile, haemodynamically stable', 'Tolerating oral feeds, ambulant'],
  acknowledgement: ['Explained to the patient and attendant in their own language', 'Discharge medicines and warning signs explained'],
}
/** The parts a summary should not be approved without. */
const NEEDED = ['diagnosis', 'course', 'condition_at_discharge', 'advice']
const REVIEW_IN: [string, number][] = [['3 days', 3], ['1 week', 7], ['2 weeks', 14], ['1 month', 30]]

const plusDays = (from: string, days: number) => {
  const d = new Date(`${from}T00:00:00`)
  d.setDate(d.getDate() + days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const snapshot = (texts: Texts, medicines: PrescribedMedicine[], followUp: string) => JSON.stringify([texts, filledMedicines(medicines), followUp])

/** A text box that grows with what is written in it, so a long part is read without scrolling inside a small box. */
function GrowingText({ value, rows, placeholder, onChange, label }: { value: string; rows: number; placeholder?: string; onChange: (value: string) => void; label: string }) {
  const box = useRef<HTMLTextAreaElement>(null)
  useEffect(() => {
    const el = box.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${el.scrollHeight + 2}px`
  }, [value])
  return <textarea ref={box} rows={rows} placeholder={placeholder ?? ''} value={value} onChange={(e) => onChange(e.target.value)} aria-label={label} />
}

type Props = {
  admissionId: number
  canEdit: boolean
  canApprove: boolean
  /** The day the patient left, when the case is closed: review dates count from it. */
  dischargedOn?: string | null
  /** The admission's doctor, who is sent the summary when it is ready for approval. */
  doctor: { id: number; name: string }
  /** Who is looking. */
  userId?: number
}

/**
 * The discharge summary of one stay: written in parts, helped by what the stay's records already say, then approved
 * by a doctor, which locks it. Whoever writes it for the admission's doctor sends it to them once it is ready, and the
 * doctor is told.
 */
export function IpdSummary({ admissionId, canEdit, canApprove, dischargedOn, doctor, userId }: Props) {
  const [summary, setSummary] = useState<DischargeSummary | null>(null)
  const [texts, setTexts] = useState<Texts>({})
  const [medicines, setMedicines] = useState<PrescribedMedicine[]>([emptyMedicine()])
  const [followUp, setFollowUp] = useState('')
  const [saved, setSaved] = useState('')
  const [stay, setStay] = useState<FromStay | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const url = `/ipd/admissions/${admissionId}/summary`

  const apply = (next: DischargeSummary) => {
    const nextTexts = Object.fromEntries([...SUMMARY_PARTS.map((p) => p.key), 'advice', ...AFTER_PARTS.map((p) => p.key)].map((key) => [key, (next[key as keyof DischargeSummary] as string | null) ?? '']))
    const nextMedicines = next.medicines?.length ? next.medicines : [emptyMedicine()]
    setSummary(next)
    setTexts(nextTexts)
    setMedicines(nextMedicines)
    setFollowUp(next.follow_up_on ?? '')
    setSaved(snapshot(nextTexts, nextMedicines, next.follow_up_on ?? ''))
    setError('')
  }

  useEffect(() => {
    api.get<DischargeSummary>(url).then((res) => apply(res.data))
    if (canEdit) api.get<FromStay>(`${url}/from-stay`).then((res) => setStay(res.data)).catch(() => setStay(null))
  }, [url, canEdit])

  const approved = Boolean(summary?.approved_at)
  const locked = !canEdit || approved
  const dirty = Boolean(summary) && !locked && snapshot(texts, medicines, followUp) !== saved

  const run = async (request: Promise<{ data: DischargeSummary }>, done: string) => {
    setNotice('')
    setSaving(true)
    try {
      apply((await request).data)
      setNotice(done)
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
      return false
    } finally {
      setSaving(false)
    }
  }
  const save = () =>
    run(
      api.put(url, { ...Object.fromEntries(Object.entries(texts).map(([k, v]) => [k, v || null])), medicines: filledMedicines(medicines), follow_up_on: followUp || null }),
      'Discharge summary saved.',
    )
  // The saved summary goes to the admission's doctor, who is told it is ready. What comes back is the summary as it now
  // stands; should the answer not carry it, it is read again.
  const sendForApproval = () =>
    run(
      api.post<DischargeSummary | ''>(`${url}/ready`).then((res) => (res.data && typeof res.data === 'object' ? { data: res.data } : api.get<DischargeSummary>(url))),
      `Sent to ${doctor.name} for approval.`,
    )

  // Ctrl+S (or ⌘S) saves, as in the visit pad. The latest save is kept in a ref so the key handler never goes stale.
  const saveNow = useRef(save)
  useEffect(() => {
    saveNow.current = save
  })
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault()
        if (document.querySelector('.summary-form')) saveNow.current()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  // Leaving with unsaved writing asks first: another tab of the case, another page, Back, or closing the window.
  useUnsaved(dirty, 'The discharge summary has changes that are not saved yet. Leave without saving them?')

  if (!summary) return <p className="loading">Loading summary…</p>

  const setText = (key: string, value: string) => setTexts((t) => ({ ...t, [key]: value }))
  /** Adds a ready line under whatever is already written, unless it is there already. */
  const addTo = (key: string, text: string) => setTexts((t) => ((t[key] ?? '').includes(text) ? t : { ...t, [key]: [(t[key] ?? '').trimEnd(), text].filter(Boolean).join('\n') }))
  const offered = (key: string) => {
    const from = FROM_STAY[key]
    const text = from && stay ? stay[from.key] : ''
    return !locked && text && !(texts[key] ?? '').includes(text) ? { text, add: from!.add } : null
  }
  const medicinesOffered = !locked && stay ? stay.medicines.filter((m) => !medicines.some((have) => have.medicine.trim().toLowerCase() === m.medicine.trim().toLowerCase())) : []

  const allParts = [...SUMMARY_PARTS.map((p) => ({ key: p.key, label: p.label })), { key: 'medicines', label: 'Medicines on discharge' }, { key: 'advice', label: 'Advice on discharge' }, ...AFTER_PARTS.map((p) => ({ key: p.key, label: p.label })), { key: 'follow_up_on', label: 'Review date' }]
  const written = (key: string) => (key === 'medicines' ? filledMedicines(medicines).length > 0 : key === 'follow_up_on' ? followUp !== '' : (texts[key] ?? '').trim() !== '')
  const missing = allParts.filter((p) => [...NEEDED, 'follow_up_on'].includes(p.key) && !written(p.key))
  const jumpTo = (key: string) => document.getElementById(`summary-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  const reviewFrom = dischargedOn ?? today()
  // Someone writing it for the admission's doctor sends it to them, once it is saved, until the doctor approves it.
  const handsIn = canEdit && userId !== doctor.id && Boolean(summary.id) && !approved
  const sentAt = !approved ? (summary.ready_at ?? null) : null
  const sentLine = sentAt ? `Sent to ${userId === doctor.id ? 'you' : doctor.name} for approval on ${longDate(sentAt)} ${clock(sentAt)}${summary.ready_by ? `, by ${summary.ready_by.name}` : ''}.` : null

  const part = (p: { key: string; label: string; hint?: string; rows?: number }) => {
    const offer = offered(p.key)
    return (
      <div className="field summary-part" key={p.key} id={`summary-${p.key}`}>
        <span className="field-label">
          {p.label}
          {NEEDED.includes(p.key) && !written(p.key) && !locked && <small className="needed"> · needed before approval</small>}
        </span>
        <GrowingText rows={p.rows ?? 2} placeholder={p.hint} value={texts[p.key] ?? ''} onChange={(v) => setText(p.key, v)} label={p.label} />
        {offer && (
          <button type="button" className="link from-stay" onClick={() => addTo(p.key, offer.text)} title={offer.text}>
            + {offer.add}
          </button>
        )}
        {!locked && PHRASES[p.key] && (
          <div className="chips phrase-chips">
            {PHRASES[p.key]
              .filter((phrase) => !(texts[p.key] ?? '').includes(phrase))
              .map((phrase) => (
                <button type="button" key={phrase} className="chip" onClick={() => addTo(p.key, phrase)}>
                  {phrase}
                </button>
              ))}
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="summary-form">
      <div className="print-bar">
        <span className="grow">
          {approved ? (
            <>
              <span className="pill ok">Approved</span>{' '}
              <span className="muted small">
                by {summary.approver?.name} on {longDate(summary.approved_at!)} {clock(summary.approved_at!)}
              </span>
            </>
          ) : summary.id ? (
            <>
              <span className={`pill ${sentLine ? 'status-ongoing' : 'status-arrived'}`}>{sentLine ? 'Sent for approval' : 'Draft'}</span>{' '}
              <span className="muted small">
                Prepared by {summary.preparer?.name ?? 'staff'}. {sentLine ?? 'A doctor approves it to make it final.'}
              </span>
            </>
          ) : (
            <span className="muted small">Not written yet. The diagnosis below is suggested from the reason for admission.</span>
          )}
        </span>
        {handsIn && !sentLine && (
          <>
            <button className="btn send-ready" disabled={saving || dirty} title={dirty ? 'Save the summary first' : `${doctor.name} is told that it is ready to approve`} onClick={() => void sendForApproval()}>
              Ready — send to {doctor.name} for approval
            </button>
            {dirty && <small className="muted">Save the changes first.</small>}
          </>
        )}
        {summary.id && canApprove && (
          <button
            className={approved ? 'btn ghost' : 'btn primary'}
            disabled={saving || dirty}
            title={dirty ? 'Save the summary first' : ''}
            onClick={() => {
              if (!approved && missing.length > 0 && !window.confirm(`Still empty: ${missing.map((m) => m.label).join(', ')}.\n\nApprove the summary anyway?`)) return
              run(api.post(`${url}/approve`, { approved: !approved }), approved ? 'Approval withdrawn; the summary can be changed again.' : 'Summary approved and locked.')
            }}
          >
            {approved ? 'Withdraw approval' : 'Approve'}
          </button>
        )}
        {summary.id && (
          <a className="btn ghost" href={`/ipd/${admissionId}/summary/print`} target="_blank" rel="noreferrer">
            Print summary
          </a>
        )}
      </div>

      <nav className="pad-jump summary-jump" aria-label="Parts of the discharge summary">
        {allParts.map((p) => (
          <button key={p.key} type="button" className={written(p.key) ? 'filled' : ''} onClick={() => jumpTo(p.key)} title={written(p.key) ? 'Something is written here' : 'Nothing written yet'}>
            {p.label}
          </button>
        ))}
      </nav>
      <p className="muted small summary-progress">
        {allParts.filter((p) => written(p.key)).length} of {allParts.length} parts written
        {!locked && missing.length > 0 && <> · still needed: {missing.map((m) => m.label).join(', ')}</>}
      </p>

      {notice && <p className="notice">{notice}</p>}
      {error && <p className="form-error gap-bottom">{error}</p>}

      <fieldset disabled={locked}>
        {SUMMARY_PARTS.map(part)}

        <div className="summary-part" id="summary-medicines">
          <h3>Medicines on discharge</h3>
          <MedicineRows rows={medicines} onChange={setMedicines} />
          {medicinesOffered.length > 0 && (
            <button type="button" className="link from-stay" onClick={() => setMedicines([...medicines.filter((m) => m.medicine.trim() !== ''), ...medicinesOffered.map((m) => ({ ...emptyMedicine(), medicine: m.medicine, dose: m.dose ?? '', frequency: m.frequency ?? '' }))])} title={medicinesOffered.map((m) => m.medicine).join(', ')}>
              + Copy the {medicinesOffered.length} medicine{medicinesOffered.length === 1 ? '' : 's'} still running on the chart, to change for going home
            </button>
          )}
        </div>

        {part({ key: 'advice', label: 'Advice on discharge', hint: 'Diet, activity, wound care, when to come back urgently', rows: 3 })}
        {AFTER_PARTS.map(part)}
        <div className="summary-part" id="summary-follow_up_on">
          <div className="grid gap-top">
            <label className="field">
              <span className="field-label">Review date</span>
              <input type="date" value={followUp} min={reviewFrom} onChange={(e) => setFollowUp(e.target.value)} />
            </label>
          </div>
          {!locked && (
            <div className="chips phrase-chips">
              {REVIEW_IN.map(([label, days]) => (
                <button type="button" key={label} className={`chip ${followUp === plusDays(reviewFrom, days) ? 'on' : ''}`} onClick={() => setFollowUp(plusDays(reviewFrom, days))} title={`${label} after ${dischargedOn ? 'discharge' : 'today'}: ${longDate(plusDays(reviewFrom, days))}`}>
                  In {label}
                </button>
              ))}
            </div>
          )}
        </div>
      </fieldset>

      {canEdit && !approved && (
        <div className="summary-save">
          <span className={`save-state ${dirty || !summary.id ? 'unsaved' : ''}`}>{dirty ? 'Not saved yet' : summary.id ? 'All saved' : 'Not written yet'}</span>
          <span className="muted small grow">Ctrl + S saves</span>
          <button className="btn primary" disabled={saving || (!dirty && Boolean(summary.id))} onClick={save}>
            {saving ? 'Saving…' : 'Save summary'}
          </button>
        </div>
      )}
      {approved && canEdit && <p className="muted small gap-top">An approved summary is locked. {canApprove ? 'Withdraw the approval to change it.' : 'Ask a doctor to withdraw the approval to change it.'}</p>}
    </div>
  )
}
