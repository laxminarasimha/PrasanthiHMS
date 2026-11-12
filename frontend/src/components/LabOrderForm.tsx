import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { rupees } from '../format'
import type { LabCatalogueTest, LabOrder, Patient } from '../types'
import { TextField } from './Field'
import { PatientPicker } from './PatientPicker'

type Props = {
  tests: LabCatalogueTest[]
  onClose: () => void
  onCreated: (order: LabOrder) => void
}

export function LabOrderForm({ tests, onClose, onCreated }: Props) {
  const [patient, setPatient] = useState<Patient | null>(null)
  const [chosen, setChosen] = useState<number[]>([])
  const [filter, setFilter] = useState('')
  const [referredBy, setReferredBy] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const toggle = (id: number) => setChosen((ids) => (ids.includes(id) ? ids.filter((i) => i !== id) : [...ids, id]))
  const visible = tests.filter((t) => t.name.toLowerCase().includes(filter.trim().toLowerCase()))
  const total = tests.filter((t) => chosen.includes(t.id)).reduce((sum, t) => sum + t.price, 0)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!patient) {
      setErrors({ patient_id: 'Choose a patient first.' })
      return
    }
    setSaving(true)
    setErrors({})
    try {
      const res = await api.post<LabOrder>('/lab/orders', { patient_id: patient.id, test_ids: chosen, referred_by: referredBy || null })
      onCreated(res.data)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="card panel" onSubmit={submit} noValidate>
      <div className="panel-head">
        <h2>New lab order</h2>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      <PatientPicker patient={patient} onChange={setPatient} error={errors.patient_id} />

      <div className="gap-top">
        <TextField label="Referred by (optional)" placeholder="Doctor's name" value={referredBy} onChange={setReferredBy} error={errors.referred_by} />
      </div>

      <h3>Tests</h3>
      <input type="search" placeholder="Filter tests" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter tests" />
      <ul className="checklist">
        {visible.map((t) => (
          <li key={t.id}>
            <label>
              <input type="checkbox" checked={chosen.includes(t.id)} onChange={() => toggle(t.id)} />
              <span className="grow">
                {t.name}
                {t.sample_type && <small className="muted"> · {t.sample_type}</small>}
              </span>
              <span className="mono">{rupees(t.price)}</span>
            </label>
          </li>
        ))}
        {visible.length === 0 && <li className="muted">No tests match.</li>}
      </ul>
      {errors.test_ids && <span className="field-error">{errors.test_ids}</span>}

      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot between">
        <span>
          {chosen.length} test{chosen.length === 1 ? '' : 's'} · <strong>{rupees(total)}</strong>
        </span>
        <button className="btn primary" disabled={saving || chosen.length === 0}>
          {saving ? 'Saving…' : 'Create order'}
        </button>
      </div>
    </form>
  )
}
