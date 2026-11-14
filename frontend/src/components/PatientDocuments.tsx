import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { fullName, longDate, today } from '../format'

type Kind = 'medical' | 'fitness' | 'referral' | 'consent' | 'letter'
type Doc = { id: number; kind: Kind; doc_no: string; title: string; addressed_to: string | null; issued_on: string; cancelled_at: string | null; cancel_reason: string | null; doctor: { id: number; name: string } | null; issuer: { name: string } | null; canceller: { name: string } | null }
type Draft = {
  kinds: { key: Kind; label: string }[]
  doctors: { id: number; name: string; department: string | null }[]
  me: number | null
  last_visit: { date: string; complaints: string | null; diagnosis: string | null; findings: string | null; medicines: string[] } | null
}
type PatientLike = { id: number; name: string; title?: string | null; gender: string; age_label: string; uhid: string }

const days = (from: string, to: string) => Math.round((new Date(to).getTime() - new Date(from).getTime()) / 86400000) + 1

/** Sensible wording for each kind of document, from a few answers. The doctor can change any of it before issuing. */
function wording(kind: Kind, p: PatientLike, f: Record<string, string>, last: Draft['last_visit']): string {
  const who = `${fullName(p)}, aged ${p.age_label.replace('y', ' years').replace('m', ' months').replace('d', ' days')}, ${p.gender}, Patient ID ${p.uhid}`
  switch (kind) {
    case 'medical': {
      const span = f.from && f.to && f.to >= f.from ? `${days(f.from, f.to)} day${days(f.from, f.to) === 1 ? '' : 's'}, from ${longDate(f.from)} to ${longDate(f.to)}` : '______ days'
      return `This is to certify that ${who}, was examined by me on ${longDate(f.seen || today())} and is suffering from ${f.condition || '____________'}.\n\nThe patient is advised rest for ${span}.`
    }
    case 'fitness':
      return `This is to certify that ${who}, was under my treatment for ${f.condition || '____________'} and was examined by me on ${longDate(f.seen || today())}.\n\nThe patient has recovered and is fit to resume ${f.purpose || 'normal duties'} from ${f.from ? longDate(f.from) : '____________'}.`
    case 'referral':
      return [
        `Dear ${f.to || 'Doctor'},`,
        `I am referring ${who}, for ${f.reason || 'your opinion and further management'}.`,
        last?.complaints ? `Complaints: ${last.complaints}` : '',
        last?.findings ? `Findings: ${last.findings}` : '',
        f.condition || last?.diagnosis ? `Diagnosis: ${f.condition || last?.diagnosis}` : '',
        last?.medicines.length ? `Current treatment: ${last.medicines.join('; ')}` : '',
        'Kindly do the needful. Thank you.',
      ].filter(Boolean).join('\n\n')
    case 'consent':
      return [
        `I, ${fullName(p)} (Patient ID ${p.uhid}), or the person signing below on the patient's behalf, have been explained in a language I understand about ${f.procedure || '____________'}: why it is being done, how it is done, its expected benefits, its risks and possible complications, and the alternatives including not having it done.`,
        `${f.risks ? `The risks explained to me include: ${f.risks}.\n\n` : ''}I have had the chance to ask questions and they have been answered. I give my consent of my own free will for the above procedure, for the anaesthesia it needs, and for any further procedure that the doctor finds necessary during it for my safety.`,
        'Patient / guardian signature: ____________________      Name: ____________________\n\nRelationship to the patient (if guardian): ____________________\n\nWitness signature: ____________________      Name: ____________________\n\nDate and time: ____________________',
      ].join('\n\n')
    default:
      return `TO WHOM IT MAY CONCERN\n\nThis is to state that ${who}, is under my care.\n\n`
  }
}

const QUESTIONS: Record<Kind, { key: string; label: string; type?: 'date'; placeholder?: string }[]> = {
  medical: [{ key: 'condition', label: 'Suffering from', placeholder: 'e.g. viral fever' }, { key: 'seen', label: 'Examined on', type: 'date' }, { key: 'from', label: 'Rest from', type: 'date' }, { key: 'to', label: 'Rest to', type: 'date' }],
  fitness: [{ key: 'condition', label: 'Was treated for', placeholder: 'e.g. typhoid fever' }, { key: 'seen', label: 'Examined on', type: 'date' }, { key: 'from', label: 'Fit from', type: 'date' }, { key: 'purpose', label: 'Fit to resume', placeholder: 'normal duties, school, travel' }],
  referral: [{ key: 'to', label: 'Referred to', placeholder: 'Dr name, speciality, hospital' }, { key: 'reason', label: 'Reason', placeholder: 'e.g. cardiology opinion for chest pain' }, { key: 'condition', label: 'Diagnosis (optional)' }],
  consent: [{ key: 'procedure', label: 'Procedure', placeholder: 'e.g. laparoscopic appendicectomy' }, { key: 'risks', label: 'Risks explained (optional)', placeholder: 'bleeding, infection, …' }],
  letter: [],
}

type Props = { patient: PatientLike; canIssue: boolean; appointmentId?: number; admissionId?: number }

/** Certificates, referral letters and consent forms for one patient: issue a new one, reprint or cancel an old one. */
export function PatientDocuments({ patient, canIssue, appointmentId, admissionId }: Props) {
  const [docs, setDocs] = useState<Doc[] | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [kind, setKind] = useState<Kind | null>(null)
  const [answers, setAnswers] = useState<Record<string, string>>({})
  const [form, setForm] = useState({ title: '', addressed_to: '', body: '', issued_on: today(), doctor_id: '' })
  const [edited, setEdited] = useState(false)
  const [error, setError] = useState('')
  const [denied, setDenied] = useState(false)
  const [busy, setBusy] = useState(false)
  const url = `/patients/${patient.id}/documents`

  const load = useCallback(() => {
    api.get<Doc[]>(url).then((res) => setDocs(res.data)).catch(() => setDenied(true))
  }, [url])
  useEffect(load, [load])

  if (denied) return null

  const start = async (next: Kind) => {
    setError('')
    const d = draft ?? (await api.get<Draft>(`${url}/draft`)).data
    setDraft(d)
    const first: Record<string, string> = { seen: d.last_visit?.date ?? today(), from: today(), condition: d.last_visit?.diagnosis?.split('\n')[0] ?? '' }
    setAnswers(first)
    setKind(next)
    setEdited(false)
    setForm({ title: d.kinds.find((k) => k.key === next)?.label ?? '', addressed_to: '', body: wording(next, patient, first, d.last_visit), issued_on: today(), doctor_id: d.me ? String(d.me) : d.doctors.length === 1 ? String(d.doctors[0].id) : '' })
  }

  const answer = (key: string, value: string) => {
    const next = { ...answers, [key]: value }
    setAnswers(next)
    // Keep the wording in step with the answers until the doctor starts rewording it by hand.
    if (kind && !edited) setForm((f) => ({ ...f, body: wording(kind, patient, next, draft?.last_visit ?? null), addressed_to: kind === 'referral' ? (next.to ?? '') : f.addressed_to }))
  }

  const issue = async (e: FormEvent) => {
    e.preventDefault()
    if (!kind) return
    setBusy(true)
    setError('')
    try {
      const res = await api.post<Doc>(url, { ...form, kind, addressed_to: form.addressed_to || null, appointment_id: appointmentId ?? null, admission_id: admissionId ?? null })
      setKind(null)
      load()
      window.open(`/documents/${res.data.id}/print`, '_blank')
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not issue the document.')
    } finally {
      setBusy(false)
    }
  }

  const cancel = async (d: Doc) => {
    const reason = window.prompt(`Cancel ${d.doc_no} (${d.title})? It stays on the record, marked as cancelled. Why is it being cancelled?`)
    if (!reason?.trim()) return
    try {
      await api.post(`/documents/${d.id}/cancel`, { reason: reason.trim() })
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not cancel.')
    }
  }

  return (
    <div className="documents">
      {canIssue && !kind && (
        <div className="row wrap gap-bottom">
          <span className="muted small">Issue a new:</span>
          {([['medical', 'Medical certificate'], ['fitness', 'Fitness certificate'], ['referral', 'Referral letter'], ['consent', 'Consent form'], ['letter', 'Other letter']] as const).map(([key, label]) => (
            <button key={key} type="button" className="btn ghost small-btn" onClick={() => start(key)}>
              {label}
            </button>
          ))}
        </div>
      )}
      {error && <p className="form-error">{error}</p>}

      {kind && draft && (
        <form className="document-form" onSubmit={issue}>
          <div className="grid">
            <label className="field">
              <span className="field-label">Heading</span>
              <input required maxLength={255} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
            </label>
            <label className="field">
              <span className="field-label">Signed by *</span>
              <select required value={form.doctor_id} onChange={(e) => setForm({ ...form, doctor_id: e.target.value })}>
                <option value="">Choose the doctor</option>
                {draft.doctors.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </select>
            </label>
            {QUESTIONS[kind].map((q) => (
              <label key={q.key} className="field">
                <span className="field-label">{q.label}</span>
                <input type={q.type ?? 'text'} placeholder={q.placeholder} value={answers[q.key] ?? ''} onChange={(e) => answer(q.key, e.target.value)} />
              </label>
            ))}
            <label className="field">
              <span className="field-label">Date on the document</span>
              <input type="date" required max={today()} value={form.issued_on} onChange={(e) => setForm({ ...form, issued_on: e.target.value })} />
            </label>
            <label className="field span-2">
              <span className="field-label">Wording (change anything you like before issuing)</span>
              <textarea
                rows={kind === 'consent' ? 14 : 8}
                required
                maxLength={10000}
                value={form.body}
                onChange={(e) => {
                  setEdited(true)
                  setForm({ ...form, body: e.target.value })
                }}
              />
            </label>
          </div>
          {edited && QUESTIONS[kind].length > 0 && (
            <p className="muted small">
              You have reworded this by hand, so the boxes above no longer change it.{' '}
              <button type="button" className="link" onClick={() => { setEdited(false); setForm((f) => ({ ...f, body: wording(kind, patient, answers, draft.last_visit) })) }}>
                Write it again from the boxes
              </button>
            </p>
          )}
          <p className="muted small">Once issued, the wording cannot be changed; a wrong one is cancelled and a new one issued.</p>
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setKind(null)}>
              Close
            </button>
            <button className="btn primary" disabled={busy}>
              {busy ? 'Issuing…' : 'Issue and print'}
            </button>
          </div>
        </form>
      )}

      {docs && docs.length > 0 && (
        <div className="table-wrap">
          <table className="compact">
            <tbody>
              {docs.map((d) => (
                <tr key={d.id} className={`static ${d.cancelled_at ? 'not-here' : ''}`}>
                  <td className="mono">{d.doc_no}</td>
                  <td>
                    {d.title}
                    {d.addressed_to && <small className="muted block">To {d.addressed_to}</small>}
                    {d.cancelled_at && (
                      <small className="due block">
                        Cancelled by {d.canceller?.name ?? 'staff'}: {d.cancel_reason}
                      </small>
                    )}
                  </td>
                  <td>
                    {longDate(d.issued_on)}
                    <small className="muted block">{d.doctor?.name}</small>
                  </td>
                  <td className="num actions">
                    <a className="link" href={`/documents/${d.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                    {canIssue && !d.cancelled_at && (
                      <button type="button" className="link danger" onClick={() => cancel(d)}>
                        Cancel
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {docs?.length === 0 && !kind && <p className="muted">No certificates or letters issued yet.</p>}
    </div>
  )
}
