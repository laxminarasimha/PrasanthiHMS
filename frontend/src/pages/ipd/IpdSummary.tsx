import { useEffect, useState } from 'react'
import { api, fieldErrors } from '../../api'
import { MedicineRows, emptyMedicine, filledMedicines } from '../../components/MedicineRows'
import { clock, longDate } from '../../format'
import type { DischargeSummary, PrescribedMedicine } from '../../types'

export const SUMMARY_PARTS: { key: keyof DischargeSummary & string; label: string; hint?: string; rows?: number }[] = [
  { key: 'diagnosis', label: 'Final diagnosis', rows: 2 },
  { key: 'complaints', label: 'Presenting complaints', rows: 2 },
  { key: 'history', label: 'History', rows: 2 },
  { key: 'examination', label: 'Examination findings', rows: 3 },
  { key: 'investigations', label: 'Investigations', hint: 'Important results during the stay', rows: 3 },
  { key: 'procedures', label: 'Procedures and operations', rows: 2 },
  { key: 'course', label: 'Course in hospital', hint: 'Treatment given and how the patient responded', rows: 5 },
  { key: 'condition_at_discharge', label: 'Condition at discharge', rows: 2 },
]

type Texts = Record<string, string>

/** The discharge summary of one stay: written in parts, then approved by a doctor, which locks it. */
export function IpdSummary({ admissionId, canEdit, canApprove }: { admissionId: number; canEdit: boolean; canApprove: boolean }) {
  const [summary, setSummary] = useState<DischargeSummary | null>(null)
  const [texts, setTexts] = useState<Texts>({})
  const [medicines, setMedicines] = useState<PrescribedMedicine[]>([emptyMedicine()])
  const [followUp, setFollowUp] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const url = `/ipd/admissions/${admissionId}/summary`

  const apply = (next: DischargeSummary) => {
    setSummary(next)
    setTexts(Object.fromEntries([...SUMMARY_PARTS.map((p) => p.key), 'advice'].map((key) => [key, (next[key as keyof DischargeSummary] as string | null) ?? ''])))
    setMedicines(next.medicines?.length ? next.medicines : [emptyMedicine()])
    setFollowUp(next.follow_up_on ?? '')
    setError('')
  }

  useEffect(() => {
    api.get<DischargeSummary>(url).then((res) => apply(res.data))
  }, [url])

  if (!summary) return <p className="loading">Loading summary…</p>

  const run = async (request: Promise<{ data: DischargeSummary }>, done: string) => {
    setNotice('')
    try {
      apply((await request).data)
      setNotice(done)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }
  const save = () =>
    run(
      api.put(url, { ...Object.fromEntries(Object.entries(texts).map(([k, v]) => [k, v || null])), medicines: filledMedicines(medicines), follow_up_on: followUp || null }),
      'Discharge summary saved.',
    )

  const approved = Boolean(summary.approved_at)
  const locked = !canEdit || approved

  return (
    <div>
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
              <span className="pill status-arrived">Draft</span> <span className="muted small">Prepared by {summary.preparer?.name ?? 'staff'}. A doctor approves it to make it final.</span>
            </>
          ) : (
            <span className="muted small">Not written yet. The diagnosis below is suggested from the reason for admission.</span>
          )}
        </span>
        {summary.id && canApprove && (
          <button className={approved ? 'btn ghost' : 'btn primary'} onClick={() => run(api.post(`${url}/approve`, { approved: !approved }), approved ? 'Approval withdrawn; the summary can be changed again.' : 'Summary approved and locked.')}>
            {approved ? 'Withdraw approval' : 'Approve'}
          </button>
        )}
        {summary.id && (
          <a className="btn ghost" href={`/ipd/${admissionId}/summary/print`} target="_blank" rel="noreferrer">
            Print summary
          </a>
        )}
      </div>

      {notice && <p className="notice">{notice}</p>}
      {error && <p className="form-error gap-bottom">{error}</p>}

      <fieldset disabled={locked}>
        {SUMMARY_PARTS.map((part) => (
          <label className="field" key={part.key}>
            <span className="field-label">{part.label}</span>
            <textarea rows={part.rows ?? 2} placeholder={part.hint ?? ''} value={texts[part.key] ?? ''} onChange={(e) => setTexts((t) => ({ ...t, [part.key]: e.target.value }))} />
          </label>
        ))}

        <h3>Medicines on discharge</h3>
        <MedicineRows rows={medicines} onChange={setMedicines} />

        <label className="field">
          <span className="field-label">Advice on discharge</span>
          <textarea rows={3} placeholder="Diet, activity, wound care, when to come back urgently" value={texts.advice ?? ''} onChange={(e) => setTexts((t) => ({ ...t, advice: e.target.value }))} />
        </label>
        <div className="grid gap-top">
          <label className="field">
            <span className="field-label">Review date</span>
            <input type="date" value={followUp} onChange={(e) => setFollowUp(e.target.value)} />
          </label>
        </div>
      </fieldset>

      {canEdit && !approved && (
        <div className="panel-foot">
          <button className="btn primary" onClick={save}>
            Save summary
          </button>
        </div>
      )}
      {approved && canEdit && <p className="muted small gap-top">An approved summary is locked. {canApprove ? 'Withdraw the approval to change it.' : 'Ask a doctor to withdraw the approval to change it.'}</p>}
    </div>
  )
}
