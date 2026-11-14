import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { longDate } from '../format'

type Item = { id: number; kind: string; title: string; detail: string | null; since: string | null; is_active: boolean; ended_on: string | null; creator?: { name: string } | null }
type Summary = { patient_id: number; allergies: string | null; sections: { kind: string; label: string; items: Item[] }[] }

/** What each section asks for, and the words for something that is over. */
const HINTS: Record<string, { title: string; detail: string; since: string; over: string; back: string }> = {
  condition: { title: 'e.g. Type 2 diabetes', detail: 'Note (optional)', since: 'Since, e.g. 2018', over: 'Resolved', back: 'Still present' },
  medication: { title: 'e.g. Metformin 500mg', detail: 'Dose and timing, e.g. 1-0-1 after food', since: 'Since', over: 'Stopped', back: 'Restart' },
  surgery: { title: 'e.g. Appendicectomy', detail: 'Where, or a note (optional)', since: 'When, e.g. 2009', over: '', back: '' },
  family: { title: 'e.g. Father: heart attack at 55', detail: 'Note (optional)', since: '', over: '', back: '' },
  habit: { title: 'e.g. Smoker, 10 a day', detail: 'Note (optional)', since: 'Since', over: 'Stopped', back: 'Started again' },
  immunisation: { title: 'e.g. Tetanus toxoid', detail: 'Dose or batch (optional)', since: 'Given on', over: '', back: '' },
}

type Props = {
  patientId: number
  canEdit: boolean
  /** Hide the allergy line where the screen already has its own allergy box. */
  hideAllergies?: boolean
  /** When given, regular medicines can be copied into today's prescription. */
  onUseMedicines?: (medicines: { medicine: string; instructions: string | null }[]) => void
}

/** The lasting facts of a patient's health, kept once and shown at every visit. */
export function HealthSummary({ patientId, canEdit, hideAllergies, onUseMedicines }: Props) {
  const [data, setData] = useState<Summary | null>(null)
  const [adding, setAdding] = useState<string | null>(null)
  const [form, setForm] = useState({ title: '', detail: '', since: '' })
  const [allergies, setAllergies] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [denied, setDenied] = useState(false)

  useEffect(() => {
    setData(null)
    api.get<Summary>(`/patients/${patientId}/health`).then((res) => setData(res.data)).catch(() => setDenied(true))
  }, [patientId])

  if (denied) return null
  if (!data) return <p className="muted">Loading health summary…</p>

  const run = async (request: Promise<{ data: Summary }>) => {
    setError('')
    try {
      setData((await request).data)
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save.')
      return false
    }
  }

  const add = async (e: FormEvent, kind: string) => {
    e.preventDefault()
    if (!form.title.trim()) return
    if (await run(api.post(`/patients/${patientId}/health`, { kind, title: form.title, detail: form.detail || null, since: form.since || null }))) setForm({ title: '', detail: '', since: '' })
  }

  const current = data.sections.find((s) => s.kind === 'medication')?.items.filter((i) => i.is_active) ?? []

  return (
    <div className="health-summary">
      {!hideAllergies && (
        <div className={`health-allergy ${data.allergies ? 'has' : ''}`}>
          <b>Allergies:</b>{' '}
          {allergies === null ? (
            <>
              {data.allergies ?? 'None known'}
              {canEdit && (
                <button type="button" className="link" onClick={() => setAllergies(data.allergies ?? '')}>
                  Change
                </button>
              )}
            </>
          ) : (
            <form
              className="row"
              onSubmit={async (e) => {
                e.preventDefault()
                if (await run(api.put(`/patients/${patientId}/health/allergies`, { allergies }))) setAllergies(null)
              }}
            >
              <input autoFocus value={allergies} maxLength={1000} placeholder="None known" onChange={(e) => setAllergies(e.target.value)} aria-label="Allergies" />
              <button className="btn small-btn">Save</button>
              <button type="button" className="link" onClick={() => setAllergies(null)}>
                Cancel
              </button>
            </form>
          )}
        </div>
      )}
      {error && <p className="form-error">{error}</p>}

      {data.sections.map((section) => {
        const hint = HINTS[section.kind]
        return (
          <div key={section.kind} className="health-section">
            <div className="health-head">
              <h4>{section.label}</h4>
              {section.kind === 'medication' && onUseMedicines && current.length > 0 && (
                <button type="button" className="link" onClick={() => onUseMedicines(current.map((i) => ({ medicine: i.title, instructions: i.detail })))}>
                  Add to today's prescription
                </button>
              )}
              {canEdit && adding !== section.kind && (
                <button
                  type="button"
                  className="link"
                  onClick={() => {
                    setAdding(section.kind)
                    setForm({ title: '', detail: '', since: '' })
                    setError('')
                  }}
                >
                  + Add
                </button>
              )}
            </div>
            {section.items.length === 0 && adding !== section.kind && <p className="muted small">None recorded.</p>}
            <ul>
              {section.items.map((item) => (
                <li key={item.id} className={item.is_active ? '' : 'over'}>
                  <span className="grow">
                    <strong>{item.title}</strong>
                    {item.since ? <span className="muted"> · {section.kind === 'condition' || section.kind === 'medication' || section.kind === 'habit' ? 'since ' : ''}{item.since}</span> : null}
                    {item.detail ? <span className="block muted">{item.detail}</span> : null}
                    {!item.is_active && <span className="block muted">{hint.over || 'Over'}{item.ended_on ? ` on ${longDate(item.ended_on)}` : ''}</span>}
                  </span>
                  {canEdit && (
                    <span className="actions">
                      {hint.over && (
                        <button type="button" className="link" onClick={() => run(api.put(`/health-items/${item.id}`, { is_active: !item.is_active }))}>
                          {item.is_active ? hint.over : hint.back}
                        </button>
                      )}
                      <button type="button" className="link danger" onClick={() => window.confirm(`Remove "${item.title}" from the summary? Use this only for a mistake; for something that is over, mark it instead.`) && run(api.delete(`/health-items/${item.id}`))}>
                        Remove
                      </button>
                    </span>
                  )}
                </li>
              ))}
            </ul>
            {adding === section.kind && (
              <form className="health-add" onSubmit={(e) => add(e, section.kind)}>
                <input autoFocus required maxLength={255} placeholder={hint.title} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} aria-label={`${section.label}: what to add`} />
                {hint.since && <input maxLength={30} placeholder={hint.since} value={form.since} onChange={(e) => setForm({ ...form, since: e.target.value })} aria-label="Since or when" />}
                <input maxLength={500} placeholder={hint.detail} value={form.detail} onChange={(e) => setForm({ ...form, detail: e.target.value })} aria-label="Detail" />
                <span className="row">
                  <button className="btn small-btn">Add</button>
                  <button type="button" className="link" onClick={() => setAdding(null)}>
                    Done
                  </button>
                </span>
              </form>
            )}
          </div>
        )
      })}
    </div>
  )
}
