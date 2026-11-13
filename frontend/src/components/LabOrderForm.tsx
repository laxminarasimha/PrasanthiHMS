import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { rupees } from '../format'
import type { LabCatalogueTest, LabOrder, LabPackage, Patient } from '../types'
import { TextField } from './Field'
import { PatientPicker } from './PatientPicker'
import { ReferralList } from './ReferralList'

type Props = {
  tests: LabCatalogueTest[]
  packages?: LabPackage[]
  /** Set when the order comes from a doctor's visit: who it is for and what was advised. */
  visit?: { patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile'>; appointmentId: number; doctor: string; advised: string }
  onClose: () => void
  onCreated: (order: LabOrder) => void
}

// The same test under the names doctors commonly write.
const SAME_TEST = [
  ['cbc', 'cbp', 'complete blood count', 'complete blood picture', 'hemogram', 'haemogram'],
  ['cue', 'urine routine', 'urine r/e', 'complete urine examination'],
  ['rbs', 'grbs', 'random blood sugar'],
  ['fbs', 'fasting blood sugar'],
]

/** Every way a lab test may be written: its name, a short form in brackets such as "(TSH)", its initials, and common other names. */
function namesOf(test: LabCatalogueTest) {
  const full = test.name.toLowerCase().trim()
  const short = [...full.matchAll(/\(([^)]+)\)/g)].map((m) => m[1].trim())
  const plain = full.replace(/\([^)]*\)/g, ' ').replace(/\s+/g, ' ').trim()
  const initials = plain.split(' ').map((w) => w[0]).join('')
  const names = new Set([full, plain, ...short, ...(initials.length >= 3 ? [initials] : [])])
  for (const group of SAME_TEST) if (group.some((n) => names.has(n))) group.forEach((n) => names.add(n))
  return names
}

/**
 * Matches what the doctor typed ("CBC, TSH" or one test per line) to tests the lab offers.
 * A line matches a test's name, short form or common other name, or a longer line that contains the name;
 * the rest are returned as not found.
 */
export function matchAdvised(advised: string, tests: LabCatalogueTest[]) {
  const known = tests.map((t) => ({ id: t.id, names: namesOf(t) }))
  const ids: number[] = []
  const missing: string[] = []
  for (const line of advised.split(/[\n,;]+/).map((l) => l.trim()).filter(Boolean)) {
    const term = line.toLowerCase()
    const found =
      known.find((t) => t.names.has(term)) ??
      (term.length >= 4 ? known.find((t) => [...t.names].some((n) => n.length >= 4 && (n.includes(term) || term.includes(n)))) : undefined)
    if (found) ids.push(found.id)
    else missing.push(line)
  }
  return { ids: [...new Set(ids)], missing }
}

export function LabOrderForm({ tests, packages = [], visit, onClose, onCreated }: Props) {
  const advised = visit ? matchAdvised(visit.advised, tests) : null
  const [patient, setPatient] = useState<Patient | null>((visit?.patient as Patient | undefined) ?? null)
  const [chosen, setChosen] = useState<number[]>(advised?.ids ?? [])
  const [chosenPackages, setChosenPackages] = useState<number[]>([])
  const [filter, setFilter] = useState('')
  const [referredBy, setReferredBy] = useState(visit?.doctor ?? '')
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
      const res = await api.post<LabOrder>('/lab/orders', { patient_id: patient.id, appointment_id: visit?.appointmentId ?? null, test_ids: chosen, package_ids: chosenPackages, referred_by: referredBy || null })
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
        <ReferralList id="lab-referred-by" direction="by" />
        <TextField label="Referred by (optional)" placeholder="Doctor's name" list="lab-referred-by" value={referredBy} onChange={setReferredBy} error={errors.referred_by} />
      </div>

      {visit && advised && (
        <div className="advised">
          <strong>Advised by {visit.doctor}</strong>
          <p>{visit.advised}</p>
          <small>
            {advised.ids.length > 0 ? `${advised.ids.length} ticked below from the lab's list. ` : ''}
            {advised.missing.length > 0 ? `Not in the lab's list: ${advised.missing.join(', ')}. Tick the right test below, or it is done outside.` : 'Check the ticks before saving.'}
          </small>
        </div>
      )}

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
