import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { Field, TextField } from '../components/Field'
import { clock, longDate } from '../format'
import type { Consultation, Medicine, Visit } from '../types'

const VITALS = [
  { key: 'weight_kg', label: 'Weight (kg)' },
  { key: 'height_cm', label: 'Height (cm)' },
  { key: 'bp_systolic', label: 'BP systolic' },
  { key: 'bp_diastolic', label: 'BP diastolic' },
  { key: 'pulse', label: 'Pulse (/min)' },
  { key: 'temperature_f', label: 'Temp (°F)' },
  { key: 'spo2', label: 'SpO₂ (%)' },
] as const

const NOTES = [
  { key: 'complaints', label: 'Complaints', hint: 'What the patient reports, and for how long' },
  { key: 'findings', label: 'Examination findings', hint: '' },
  { key: 'diagnosis', label: 'Diagnosis', hint: '' },
] as const

const FREQUENCIES = ['1-0-0', '0-1-0', '0-0-1', '1-0-1', '1-1-0', '0-1-1', '1-1-1', '1-1-1-1', 'SOS', 'Once a week']
const TIMINGS = ['After food', 'Before food', 'Empty stomach', 'At bedtime']

const emptyMedicine = (): Medicine => ({ medicine: '', dose: '', frequency: '', timing: '', duration: '', instructions: '' })

type Form = Record<string, string>

function toForm(c: Consultation | null): Form {
  const form: Form = {}
  for (const key of [...VITALS.map((v) => v.key), ...NOTES.map((n) => n.key), 'tests_advised', 'advice', 'follow_up_on']) {
    const value = c?.[key as keyof Consultation]
    form[key] = value === null || value === undefined ? '' : String(value)
  }
  return form
}

export function VisitPad() {
  const { id } = useParams()
  const { user } = useAuth()
  const [visit, setVisit] = useState<Visit | null>(null)
  const [form, setForm] = useState<Form>(toForm(null))
  const [medicines, setMedicines] = useState<Medicine[]>([emptyMedicine()])
  const [suggestions, setSuggestions] = useState<string[]>([])
  const [history, setHistory] = useState<Consultation[]>([])
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')

  const apply = (next: Visit) => {
    setVisit(next)
    setForm(toForm(next.consultation))
    setMedicines(next.consultation?.medicines.length ? next.consultation.medicines : [emptyMedicine()])
  }

  useEffect(() => {
    api.get<Visit>(`/appointments/${id}/consultation`).then((res) => {
      apply(res.data)
      api.get<Consultation[]>(`/patients/${res.data.patient.id}/consultations`).then((h) => setHistory(h.data))
    })
    api.get<string[]>('/medicines/suggest').then((res) => setSuggestions(res.data))
  }, [id])

  if (!visit) return <p className="loading">Loading visit…</p>

  const canEdit = Boolean(user?.can_consult) && visit.appointment.status !== 'cancelled'
  const completed = Boolean(visit.consultation?.completed_at)
  const set = (key: string) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const setMedicine = (index: number, key: keyof Medicine, value: string) =>
    setMedicines((rows) => rows.map((row, i) => (i === index ? { ...row, [key]: value } : row)))

  const save = async (complete: boolean) => {
    setSaving(true)
    setErrors({})
    setNotice('')
    const payload: Record<string, unknown> = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    // Rows left completely blank are dropped rather than reported as errors.
    payload.medicines = medicines
      .filter((m) => Object.entries(m).some(([k, v]) => k !== 'id' && v))
      .map(({ medicine, dose, frequency, timing, duration, instructions }) => ({ medicine, dose, frequency, timing, duration, instructions }))
    if (complete) payload.complete = true
    try {
      const res = await api.put<Visit>(`/appointments/${id}/consultation`, payload)
      apply(res.data)
      setNotice(complete ? 'Visit completed. You can print the prescription now.' : 'Draft saved.')
      if (complete) api.get<Consultation[]>(`/patients/${res.data.patient.id}/consultations`).then((h) => setHistory(h.data))
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const errorList = Object.entries(errors)
  const pastVisits = history.filter((h) => h.id !== visit.consultation?.id)

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to="/doctor">
            ← Queue
          </Link>
          <div className="grow">
            <h1>{visit.patient.name}</h1>
            <span className="muted">
              {visit.patient.uhid} · {visit.patient.age}y / {visit.patient.gender.charAt(0).toUpperCase()} · {visit.patient.mobile}
              {visit.patient.blood_group ? ` · ${visit.patient.blood_group}` : ''} · {visit.service?.name ?? 'Visit'} with {visit.doctor.name},{' '}
              {longDate(visit.appointment.scheduled_at)} {clock(visit.appointment.scheduled_at)}
            </span>
          </div>
          {completed && <span className="pill ok">Completed</span>}
          {visit.consultation && (
            <a className="btn ghost" href={`/visits/${visit.appointment.id}/print`} target="_blank" rel="noreferrer">
              Print prescription
            </a>
          )}
        </div>

        {notice && <p className="notice">{notice}</p>}
        {errorList.length > 0 && (
          <div className="form-error">
            {errorList.map(([field, message]) => (
              <div key={field}>{message}</div>
            ))}
          </div>
        )}

        <fieldset disabled={!canEdit}>
          <h3>Vitals</h3>
          <div className="vitals">
            {VITALS.map((v) => (
              <TextField key={v.key} label={v.label} type="number" step="any" value={form[v.key]} onChange={set(v.key)} error={errors[v.key]} />
            ))}
          </div>

          {NOTES.map((n) => (
            <Field key={n.key} label={n.label} error={errors[n.key]}>
              <textarea rows={2} placeholder={n.hint} value={form[n.key]} onChange={(e) => set(n.key)(e.target.value)} />
            </Field>
          ))}

          <h3>Medicines</h3>
          <datalist id="medicine-names">
            {suggestions.map((name) => (
              <option key={name} value={name} />
            ))}
          </datalist>
          <datalist id="frequencies">
            {FREQUENCIES.map((f) => (
              <option key={f} value={f} />
            ))}
          </datalist>
          <datalist id="timings">
            {TIMINGS.map((t) => (
              <option key={t} value={t} />
            ))}
          </datalist>
          <div className="rx">
            <div className="rx-row rx-head">
              <span>Medicine</span>
              <span>Dose</span>
              <span>Frequency</span>
              <span>When</span>
              <span>Duration</span>
              <span>Instructions</span>
              <span />
            </div>
            {medicines.map((m, i) => (
              <div className="rx-row" key={i}>
                <input list="medicine-names" placeholder="Medicine name and strength" value={m.medicine} onChange={(e) => setMedicine(i, 'medicine', e.target.value)} aria-label={`Medicine ${i + 1}`} aria-invalid={Boolean(errors[`medicines.${i}.medicine`])} />
                <input placeholder="1 tablet" value={m.dose ?? ''} onChange={(e) => setMedicine(i, 'dose', e.target.value)} aria-label="Dose" />
                <input list="frequencies" placeholder="1-0-1" value={m.frequency ?? ''} onChange={(e) => setMedicine(i, 'frequency', e.target.value)} aria-label="Frequency" />
                <input list="timings" placeholder="After food" value={m.timing ?? ''} onChange={(e) => setMedicine(i, 'timing', e.target.value)} aria-label="When to take" />
                <input placeholder="5 days" value={m.duration ?? ''} onChange={(e) => setMedicine(i, 'duration', e.target.value)} aria-label="Duration" />
                <input value={m.instructions ?? ''} onChange={(e) => setMedicine(i, 'instructions', e.target.value)} aria-label="Instructions" />
                <button type="button" className="link danger" onClick={() => setMedicines((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyMedicine()]))}>
                  Remove
                </button>
              </div>
            ))}
          </div>
          <button type="button" className="link add-medicine" onClick={() => setMedicines((rows) => [...rows, emptyMedicine()])}>
            + Add medicine
          </button>

          <div className="grid gap-top">
            <Field label="Tests advised" error={errors.tests_advised}>
              <textarea rows={2} placeholder="One test per line" value={form.tests_advised} onChange={(e) => set('tests_advised')(e.target.value)} />
            </Field>
            <Field label="Advice" error={errors.advice}>
              <textarea rows={2} placeholder="Diet, rest, warning signs" value={form.advice} onChange={(e) => set('advice')(e.target.value)} />
            </Field>
            <TextField label="Follow-up date" type="date" value={form.follow_up_on} onChange={set('follow_up_on')} error={errors.follow_up_on} />
          </div>
        </fieldset>

        {canEdit ? (
          <div className="panel-foot row">
            <button className="btn ghost" disabled={saving} onClick={() => save(false)}>
              {completed ? 'Save changes' : 'Save draft'}
            </button>
            {!completed && (
              <button className="btn primary" disabled={saving} onClick={() => save(true)}>
                Complete visit
              </button>
            )}
          </div>
        ) : (
          <p className="muted gap-top">You can view this visit but not change it.</p>
        )}
      </section>

      <aside className="card panel">
        <h2>Past visits</h2>
        {pastVisits.length === 0 && <p className="muted">No earlier visits recorded here.</p>}
        {pastVisits.map((h) => (
          <details key={h.id} className="past">
            <summary>
              <strong>{longDate(h.completed_at ?? '')}</strong>
              <span className="muted"> · {h.diagnosis?.split('\n')[0] || 'No diagnosis noted'}</span>
            </summary>
            {h.doctor && <p className="muted">{h.doctor.name}</p>}
            {h.complaints && <p><b>Complaints:</b> {h.complaints}</p>}
            {h.findings && <p><b>Findings:</b> {h.findings}</p>}
            {h.bp_systolic && <p><b>BP:</b> {h.bp_systolic}/{h.bp_diastolic}{h.weight_kg ? ` · ${h.weight_kg} kg` : ''}</p>}
            {h.medicines.length > 0 && (
              <ul>
                {h.medicines.map((m) => (
                  <li key={m.id}>
                    {m.medicine} {[m.dose, m.frequency, m.timing, m.duration].filter(Boolean).join(' · ')}
                  </li>
                ))}
              </ul>
            )}
            {h.advice && <p><b>Advice:</b> {h.advice}</p>}
            {h.medicines.length > 0 && canEdit && (
              <button type="button" className="link" onClick={() => setMedicines(h.medicines.map(({ id: _id, ...rest }) => rest))}>
                Repeat these medicines
              </button>
            )}
          </details>
        ))}
      </aside>
    </div>
  )
}
