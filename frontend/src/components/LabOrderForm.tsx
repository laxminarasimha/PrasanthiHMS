import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { rupees } from '../format'
import type { LabCatalogueTest, LabOrder, LabPackage, Patient } from '../types'
import { TextField } from './Field'
import { PatientPicker } from './PatientPicker'

type Props = {
  tests: LabCatalogueTest[]
  packages?: LabPackage[]
  onClose: () => void
  onCreated: (order: LabOrder) => void
}

export function LabOrderForm({ tests, packages = [], onClose, onCreated }: Props) {
  const [patient, setPatient] = useState<Patient | null>(null)
  const [chosen, setChosen] = useState<number[]>([])
  const [chosenPackages, setChosenPackages] = useState<number[]>([])
  const [filter, setFilter] = useState('')
  const [referredBy, setReferredBy] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const flip = (id: number) => (ids: number[]) => (ids.includes(id) ? ids.filter((i) => i !== id) : [...ids, id])
  const matches = (name: string) => name.toLowerCase().includes(filter.trim().toLowerCase())
  const visible = tests.filter((t) => matches(t.name))
  const visiblePackages = packages.filter((p) => matches(p.name))

  // A test that is already part of a chosen package is not charged again.
  const inPackage = new Set(packages.filter((p) => chosenPackages.includes(p.id)).flatMap((p) => p.tests.map((t) => t.id)))
  const total =
    packages.filter((p) => chosenPackages.includes(p.id)).reduce((sum, p) => sum + p.price, 0) +
    tests.filter((t) => chosen.includes(t.id) && !inPackage.has(t.id)).reduce((sum, t) => sum + t.price, 0)
  const count = new Set([...inPackage, ...chosen]).size

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!patient) {
      setErrors({ patient_id: 'Choose a patient first.' })
      return
    }
    setSaving(true)
    setErrors({})
    try {
      const res = await api.post<LabOrder>('/lab/orders', { patient_id: patient.id, test_ids: chosen, package_ids: chosenPackages, referred_by: referredBy || null })
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
      <input type="search" placeholder="Filter tests and packages" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter tests" />
      <ul className="checklist">
        {visiblePackages.map((p) => (
          <li key={`p${p.id}`}>
            <label>
              <input type="checkbox" checked={chosenPackages.includes(p.id)} onChange={() => setChosenPackages(flip(p.id))} />
              <span className="grow">
                {p.name} <span className="pill status-ongoing">Package</span>
                <small className="muted block">{p.tests.map((t) => t.name).join(', ')}</small>
              </span>
              <span className="mono">{rupees(p.price)}</span>
            </label>
          </li>
        ))}
        {visible.map((t) => (
          <li key={t.id}>
            <label>
              <input type="checkbox" checked={chosen.includes(t.id) || inPackage.has(t.id)} disabled={inPackage.has(t.id)} onChange={() => setChosen(flip(t.id))} />
              <span className="grow">
                {t.name}
                {t.sample_type && <small className="muted"> · {t.sample_type}</small>}
              </span>
              <span className="mono">{inPackage.has(t.id) ? 'in package' : rupees(t.price)}</span>
            </label>
          </li>
        ))}
        {visible.length + visiblePackages.length === 0 && <li className="muted">No tests match.</li>}
      </ul>
      {errors.test_ids && <span className="field-error">{errors.test_ids}</span>}

      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot between">
        <span>
          {count} test{count === 1 ? '' : 's'} · <strong>{rupees(total)}</strong>
        </span>
        <button className="btn primary" disabled={saving || count === 0}>
          {saving ? 'Saving…' : 'Create order'}
        </button>
      </div>
    </form>
  )
}
