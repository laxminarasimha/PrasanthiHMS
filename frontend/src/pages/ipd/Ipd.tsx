import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PatientPicker } from '../../components/PatientPicker'
import { ADMISSION_STATUS, clock, longDate, nowLocal, rupees } from '../../format'
import type { Admission, Bed, Doctor, Patient } from '../../types'

const FILTERS = ['admitted', 'discharged', 'lama', 'dama', 'absconded', 'died', 'all']

export function Ipd() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const [status, setStatus] = useState('admitted')
  const [query, setQuery] = useState('')
  const [cases, setCases] = useState<Admission[] | null>(null)
  const [admitting, setAdmitting] = useState(false)
  const [beds, setBeds] = useState<Bed[]>([])
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [patient, setPatient] = useState<Patient | null>(null)
  const [form, setForm] = useState({ doctor_id: '', bed_id: '', admitted_at: nowLocal(), reason: '', attendant_name: '', attendant_phone: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    api.get<Admission[]>('/ipd/admissions', { params: { status, q: query.trim() || undefined } }).then((res) => setCases(res.data))
  }, [status, query])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const startAdmitting = () => {
    api.get<Bed[]>('/ipd/beds').then((res) => setBeds(res.data))
    api.get<Doctor[]>('/doctors').then((res) => {
      setDoctors(res.data)
      if (res.data.length === 1) setForm((f) => ({ ...f, doctor_id: String(res.data[0].id) }))
    })
    setPatient(null)
    setErrors({})
    setForm((f) => ({ ...f, admitted_at: nowLocal(), bed_id: '', reason: '', attendant_name: '', attendant_phone: '' }))
    setAdmitting(true)
  }

  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const freeBeds = beds.filter((b) => b.is_active && !b.current_admission)

  const admit = async (e: FormEvent) => {
    e.preventDefault()
    if (!patient) {
      setErrors({ patient_id: 'Choose a patient first.' })
      return
    }
    setSaving(true)
    setErrors({})
    try {
      const res = await api.post<Admission>('/ipd/admissions', {
        ...form,
        patient_id: patient.id,
        bed_id: form.bed_id || null,
        doctor_id: form.doctor_id || null,
        admitted_at: form.admitted_at.replace('T', ' '),
      })
      navigate(`/ipd/${res.data.id}`)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const dueTotal = cases?.reduce((sum, c) => sum + Math.max(0, c.due), 0) ?? 0

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>IPD cases</h1>
          <input className="search" type="search" placeholder="Find by patient name, mobile, ID or IPD no." value={query} onChange={(e) => setQuery(e.target.value)} />
          <Link className="btn ghost" to="/ipd/reports">
            Reports
          </Link>
          {user?.can_manage_setup && (
            <Link className="btn ghost" to="/ipd/setup">
              Beds, services &amp; templates
            </Link>
          )}
          <button className="btn primary" onClick={startAdmitting}>
            + Admit patient
          </button>
        </div>

        <div className="tabs">
          {FILTERS.map((f) => (
            <button key={f} className={status === f ? 'active' : ''} onClick={() => setStatus(f)}>
              {f === 'all' ? 'All' : ADMISSION_STATUS[f]}
            </button>
          ))}
          <span className="muted push">
            {cases?.length ?? 0} case(s) · {rupees(dueTotal)} due
          </span>
        </div>

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>IPD no.</th>
                <th>Patient</th>
                <th>Bed</th>
                <th>Doctor</th>
                <th>Admitted</th>
                <th>Discharged</th>
                <th className="num">Bill</th>
                <th className="num">Paid</th>
                <th className="num">Due</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {cases?.map((c) => (
                <tr key={c.id} onClick={() => navigate(`/ipd/${c.id}`)}>
                  <td className="mono">
                    <strong>{c.ipd_no}</strong>
                  </td>
                  <td>
                    <strong>{c.patient.name}</strong>
                    <small className="muted block">
                      {c.patient.uhid} · {c.patient.age}y / {c.patient.gender.charAt(0).toUpperCase()}
                    </small>
                  </td>
                  <td>{c.bed ? `${c.bed.ward} · ${c.bed.bed_no}` : '—'}</td>
                  <td>{c.doctor.name}</td>
                  <td>
                    {longDate(c.admitted_at)}
                    <small className="muted block">{clock(c.admitted_at)}</small>
                  </td>
                  <td>{c.discharged_at ? longDate(c.discharged_at) : '—'}</td>
                  <td className="num">{rupees(c.net)}</td>
                  <td className="num">{rupees(c.paid)}</td>
                  <td className={`num ${c.due > 0 ? 'due' : ''}`}>{c.due < 0 ? `${rupees(-c.due)} advance` : rupees(c.due)}</td>
                  <td>
                    <span className={`pill ${c.status === 'admitted' ? 'status-ongoing' : c.status === 'discharged' ? 'ok' : 'off'}`}>{ADMISSION_STATUS[c.status]}</span>
                  </td>
                </tr>
              ))}
              {cases && cases.length === 0 && (
                <tr className="empty">
                  <td colSpan={10}>{status === 'admitted' ? 'No patients are admitted right now.' : 'No cases found.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {admitting && (
        <form className="card panel" onSubmit={admit} noValidate>
          <div className="panel-head">
            <h2>Admit patient</h2>
            <button type="button" className="btn ghost" onClick={() => setAdmitting(false)}>
              Close
            </button>
          </div>
          <PatientPicker patient={patient} onChange={setPatient} error={errors.patient_id} />
          <div className="grid gap-top">
            <SelectField label="Doctor" required value={form.doctor_id} onChange={set('doctor_id')} options={doctors.map((d) => ({ value: String(d.id), label: d.name }))} placeholder="Select doctor" error={errors.doctor_id} />
            <SelectField
              label="Bed"
              value={form.bed_id}
              onChange={set('bed_id')}
              options={freeBeds.map((b) => ({ value: String(b.id), label: `${b.ward} · Bed ${b.bed_no}${b.daily_charge ? ` (₹${b.daily_charge}/day)` : ''}` }))}
              placeholder={freeBeds.length ? 'Assign later' : 'No free beds'}
              error={errors.bed_id}
            />
            <div className="span-2">
              <TextField label="Admission date and time" required type="datetime-local" value={form.admitted_at} onChange={set('admitted_at')} error={errors.admitted_at} />
            </div>
            <div className="span-2">
              <TextField label="Reason for admission" value={form.reason} onChange={set('reason')} error={errors.reason} />
            </div>
            <TextField label="Attendant name" value={form.attendant_name} onChange={set('attendant_name')} error={errors.attendant_name} />
            <TextField label="Attendant phone" inputMode="tel" value={form.attendant_phone} onChange={set('attendant_phone')} error={errors.attendant_phone} />
          </div>
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving}>
              {saving ? 'Admitting…' : 'Admit'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
