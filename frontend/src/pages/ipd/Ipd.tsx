import { Popup } from '../../components/Popup'
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { useLoadOnChange } from '../../loading'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PatientPicker } from '../../components/PatientPicker'
import { ADMISSION_STATUS, clock, longDate, nowLocal, rupees } from '../../format'
import { PatientLink } from '../../components/PatientLink'
import type { AdminEntry, Admission, AdmitAdvice, Bed, Doctor, Patient } from '../../types'
import { DoctorLink } from '../../components/DoctorLink'

const FILTERS = ['admitted', 'discharged', 'lama', 'dama', 'absconded', 'died', 'all']

/** Days the bed has been used so far, counting part of a day as a full day. */
const stayDays = (from: string, to?: string | null) =>
  Math.max(1, Math.ceil(((to ? new Date(to.replace(' ', 'T')) : new Date()).getTime() - new Date(from.replace(' ', 'T')).getTime()) / 86400000))

/** What is owed, or what is held in advance, in a few words. */
const money = (due: number) => (due > 0 ? `${rupees(due)} due` : due < 0 ? `${rupees(-due)} advance` : 'Nothing due')

type View = 'beds' | 'list'
const savedView = (): View => {
  try {
    return localStorage.getItem('ipd_view') === 'list' ? 'list' : 'beds'
  } catch {
    return 'beds'
  }
}

export function Ipd() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const [status, setStatus] = useState('admitted')
  const [query, setQuery] = useState('')
  const [cases, setCases] = useState<Admission[] | null>(null)
  // Coming from a patient's page with "admit", the admit form opens for that patient straight away.
  const location = useLocation()
  const asked = location.state as { admit?: Patient } | null
  const [admitting, setAdmitting] = useState(Boolean(asked?.admit))
  const [beds, setBeds] = useState<Bed[]>([])
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [patient, setPatient] = useState<Patient | null>(asked?.admit ?? null)
  const [payers, setPayers] = useState<AdminEntry[]>([])
  const [payerId, setPayerId] = useState('')
  const [form, setForm] = useState({ doctor_id: '', bed_id: '', admitted_at: nowLocal(), reason: '', attendant_name: '', attendant_phone: '' })
  const [dayCare, setDayCare] = useState(false)
  // Admission a doctor advised at an OPD visit, and the one being acted on in the admit form.
  const [advised, setAdvised] = useState<AdmitAdvice[]>([])
  const [advice, setAdvice] = useState<AdmitAdvice | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const [view, setView] = useState<View>(savedView)
  const searchBox = useRef<HTMLInputElement>(null)

  const load = useCallback(() => {
    api.get<Admission[]>('/ipd/admissions', { params: { status, q: query.trim() || undefined } }).then((res) => setCases(res.data))
    api.get<Bed[]>('/ipd/beds').then((res) => setBeds(res.data))
    api.get<AdmitAdvice[]>('/ipd/advised').then((res) => setAdvised(res.data)).catch(() => setAdvised([]))
  }, [status, query])

  // At once when the screen opens; after a short pause when a search or filter changes.
  useLoadOnChange(load)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof Element && e.target.closest('input, textarea, select, [contenteditable]')
      if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) {
        e.preventDefault()
        searchBox.current?.focus()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const choose = (next: View) => {
    setView(next)
    try {
      localStorage.setItem('ipd_view', next)
    } catch {
      // The choice simply is not remembered.
    }
  }

  /** The lists the admit form chooses from. */
  const loadChoices = useCallback(() => {
    api.get<Bed[]>('/ipd/beds').then((res) => setBeds(res.data))
    api.get<AdminEntry[]>('/admin-lists/payer').then((res) => setPayers(res.data))
    api.get<Doctor[]>('/doctors').then((res) => {
      setDoctors(res.data)
      if (res.data.length === 1) setForm((f) => ({ ...f, doctor_id: String(res.data[0].id) }))
    })
  }, [])

  useEffect(() => {
    if (!asked?.admit) return
    loadChoices()
    // Asked once: a refresh or coming back does not open the form again.
    navigate(location.pathname, { replace: true, state: null })
  }, [asked, loadChoices, navigate, location.pathname])

  /** Opens the admit form: empty, for a free bed, or filled from a doctor's advice. */
  const startAdmitting = (bedId = '', from: AdmitAdvice | null = null) => {
    loadChoices()
    setPatient(from?.patient ?? null)
    setAdvice(from)
    setErrors({})
    setForm((f) => ({ ...f, admitted_at: nowLocal(), bed_id: bedId, reason: from?.reason ?? '', attendant_name: '', attendant_phone: '', ...(from?.doctor ? { doctor_id: String(from.doctor.id) } : {}) }))
    setAdmitting(true)
  }

  const notComing = async (a: AdmitAdvice) => {
    if (!window.confirm(`${a.patient.name} is not coming in for admission? This takes them off the list. The doctor's note stays on the visit.`)) return
    await api.put(`/ipd/advised/${a.id}/not-coming`)
    load()
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
        payer_id: payerId || null,
        admitted_at: form.admitted_at.replace('T', ' '),
        is_day_care: dayCare,
        // Only while the form is still for the patient the advice was about.
        consultation_id: advice && advice.patient.id === patient.id ? advice.id : null,
      })
      navigate(`/ipd/${res.data.id}`)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const dueTotal = cases?.reduce((sum, c) => sum + Math.max(0, c.due), 0) ?? 0
  const advanceTotal = cases?.reduce((sum, c) => sum + Math.max(0, -c.due), 0) ?? 0
  const inUse = beds.filter((b) => b.is_active || b.current_admission)
  const occupied = inUse.filter((b) => b.current_admission).length
  // The board is the wards as they stand now, so it only goes with the list of people still admitted.
  const board = status === 'admitted' && view === 'beds' && query.trim() === ''
  const byId = new Map((cases ?? []).map((c) => [c.id, c]))
  const wards = [...new Set(inUse.map((b) => b.ward))]
  const noBed = status === 'admitted' ? (cases ?? []).filter((c) => !c.bed) : []

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>IPD cases</h1>
          <input ref={searchBox} className="search" type="search" placeholder="Find by patient name, mobile, ID or IPD no. — press /" value={query} onChange={(e) => setQuery(e.target.value)} />
          <button className="btn primary" onClick={() => startAdmitting()}>
            + Admit patient
          </button>
        </div>
        <div className="toolbar minor">
          <Link className="btn" to="/ipd/reports">
            Reports
          </Link>
          {user?.can_manage_setup && (
            <Link className="btn" to="/ipd/setup">
              Beds, services &amp; templates
            </Link>
          )}
        </div>

        <div className="desk-filters">
          <div className="tabs flush" role="group" aria-label="Which cases to show">
            {FILTERS.map((f) => (
              <button key={f} className={status === f ? 'active' : ''} onClick={() => setStatus(f)}>
                {f === 'all' ? 'All' : ADMISSION_STATUS[f]}
                {status === f && cases && <span>{cases.length}</span>}
              </button>
            ))}
          </div>
          {status === 'admitted' && query.trim() === '' && (
            <div className="tabs flush view-switch" role="group" aria-label="How to show the admitted patients">
              <button className={view === 'beds' ? 'active' : ''} onClick={() => choose('beds')}>
                Beds
              </button>
              <button className={view === 'list' ? 'active' : ''} onClick={() => choose('list')}>
                List
              </button>
            </div>
          )}
        </div>

        {status === 'admitted' && query.trim() === '' && advised.length > 0 && (
          <div className="ipd-advised">
            <h3>
              Advised admission <small className="muted">by a doctor at an OPD visit, not admitted yet</small>
            </h3>
            <ul>
              {advised.map((a) => (
                <li key={a.id}>
                  <div className="grow">
                    <strong>
                      <PatientLink id={a.patient.id}>{a.patient.name}</PatientLink>
                    </strong>{' '}
                    <small className="muted">
                      {a.patient.uhid} · {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()}
                      {a.patient.mobile ? ` · ${a.patient.mobile}` : ''}
                    </small>
                    <small className="block">
                      {a.reason || 'No reason written'}
                      <span className="muted">
                        {' '}
                        · {a.doctor ? <DoctorLink id={a.doctor.id}>{a.doctor.name}</DoctorLink> : 'Doctor'} · {longDate(a.advised_at)} {clock(a.advised_at)}
                      </span>
                    </small>
                  </div>
                  {user?.can_ipd && (
                    <>
                      <button className="btn primary small-btn" onClick={() => startAdmitting('', a)}>
                        Admit
                      </button>
                      <button className="btn ghost small-btn" onClick={() => notComing(a)}>
                        Not coming
                      </button>
                    </>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}

        {status === 'admitted' && query.trim() === '' && (
          <div className="tiles small ipd-stats">
            <div className="tile">
              <span>Admitted now</span>
              <strong>{cases?.length ?? '—'}</strong>
            </div>
            <div className="tile">
              <span>Beds free</span>
              <strong>
                {inUse.length - occupied} <small className="muted">of {inUse.length}</small>
              </strong>
            </div>
            <div className="tile">
              <span>Due from admitted patients</span>
              <strong className={dueTotal > 0 ? 'due' : ''}>{rupees(dueTotal)}</strong>
            </div>
            <div className="tile">
              <span>Advances held</span>
              <strong>{rupees(advanceTotal)}</strong>
            </div>
          </div>
        )}

        {board ? (
          <div className="bed-board">
            {wards.map((ward) => {
              const wardBeds = inUse.filter((b) => b.ward === ward)
              const taken = wardBeds.filter((b) => b.current_admission).length
              return (
                <section key={ward}>
                  <h3>
                    {ward} <small className="muted">{taken} of {wardBeds.length} occupied</small>
                  </h3>
                  <div className="beds">
                    {wardBeds.map((b) => {
                      const stay = b.current_admission
                      const c = stay ? byId.get(stay.id) : undefined
                      return stay ? (
                        <button key={b.id} className="bed taken" onClick={() => navigate(`/ipd/${stay.id}`)} title={`Open ${stay.ipd_no}`}>
                          <span className="bed-no">
                            Bed {b.bed_no} <small>Day {stayDays(stay.admitted_at)}</small>
                          </span>
                          <strong>{stay.patient.name}</strong>
                          <small className="muted">
                            {stay.ipd_no}
                            {c ? ` · ${c.patient.age_label} / ${c.patient.gender.charAt(0).toUpperCase()}` : ''}
                          </small>
                          {c && <small className="muted">{c.doctor.name}</small>}
                          {c && <small className={c.due > 0 ? 'due' : 'settled'}>{money(c.due)}</small>}
                        </button>
                      ) : (
                        <button key={b.id} className="bed free" onClick={() => startAdmitting(String(b.id))} title={`Admit a patient to bed ${b.bed_no}`}>
                          <span className="bed-no">Bed {b.bed_no}</span>
                          <strong>Free</strong>
                          {b.daily_charge > 0 && <small className="muted">{rupees(b.daily_charge)} a day</small>}
                          <small className="admit-here">+ Admit here</small>
                        </button>
                      )
                    })}
                  </div>
                </section>
              )
            })}
            {noBed.length > 0 && (
              <section>
                <h3>
                  No bed assigned <small className="muted">{noBed.length} admitted</small>
                </h3>
                <div className="beds">
                  {noBed.map((c) => (
                    <button key={c.id} className="bed taken" onClick={() => navigate(`/ipd/${c.id}`)}>
                      <span className="bed-no">
                        No bed <small>Day {stayDays(c.admitted_at)}</small>
                      </span>
                      <strong>{c.patient.name}</strong>
                      <small className="muted">
                        {c.ipd_no} · {c.patient.age_label} / {c.patient.gender.charAt(0).toUpperCase()}
                      </small>
                      <small className="muted">{c.doctor.name}</small>
                      <small className={c.due > 0 ? 'due' : 'settled'}>{money(c.due)}</small>
                    </button>
                  ))}
                </div>
              </section>
            )}
            {inUse.length === 0 && noBed.length === 0 && <p className="muted when-loaded">No beds are set up yet. Add them under Beds, services &amp; templates.</p>}
          </div>
        ) : (
          <div className="table-wrap">
            <table className="fit-table">
              <thead>
                <tr>
                  <th>IPD no.</th>
                  <th>Patient</th>
                  <th>Bed</th>
                  <th>Doctor</th>
                  <th>Stay</th>
                  <th className="num">Bill</th>
                  <th className="num">Due</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {cases?.map((c) => (
                  <tr key={c.id} className={c.status === 'admitted' ? '' : 'done'} onClick={() => navigate(`/ipd/${c.id}`)}>
                    <td className="mono">
                      <strong>{c.ipd_no}</strong>
                    </td>
                    <td className="fit-wrap">
                      <strong>{c.patient.name}</strong>
                      <small className="muted block">
                        {c.patient.uhid} · {c.patient.age_label} / {c.patient.gender.charAt(0).toUpperCase()}
                      </small>
                    </td>
                    <td className="wrap">
                      {c.bed ? `${c.bed.ward} · ${c.bed.bed_no}` : '—'}
                      {c.is_day_care && <small className="muted block">Day care</small>}
                    </td>
                    <td className="wrap">
                      <DoctorLink id={c.doctor.id}>{c.doctor.name}</DoctorLink>
                      {c.payer && <small className="muted block">{c.payer.name}</small>}
                    </td>
                    <td className="wrap">
                      {longDate(c.admitted_at)} <span className="muted">{clock(c.admitted_at)}</span>
                      <small className="muted block">
                        {c.discharged_at ? `to ${longDate(c.discharged_at)} · ` : 'Day '}
                        {stayDays(c.admitted_at, c.discharged_at)}
                        {c.discharged_at ? ` day${stayDays(c.admitted_at, c.discharged_at) === 1 ? '' : 's'}` : ''}
                      </small>
                    </td>
                    <td className="num">
                      {rupees(c.net)}
                      <small className="muted block">{rupees(c.paid)} paid</small>
                    </td>
                    <td className={`num ${c.due > 0 ? 'due' : ''}`}>{c.due < 0 ? `${rupees(-c.due)} advance` : rupees(c.due)}</td>
                    <td>
                      <span className={`pill ${c.status === 'admitted' ? 'status-ongoing' : c.status === 'discharged' ? 'ok' : 'off'}`}>{ADMISSION_STATUS[c.status]}</span>
                    </td>
                  </tr>
                ))}
                {cases && cases.length === 0 && (
                  <tr className="empty">
                    <td colSpan={8}>{status === 'admitted' ? 'No patients are admitted right now.' : 'No cases found.'}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {admitting && (
        <Popup title={'Admit patient'} onClose={() => setAdmitting(false)} onSubmit={admit}>
          <PatientPicker patient={patient} onChange={setPatient} error={errors.patient_id} recent />
          {advice && patient?.id === advice.patient.id && (
            <p className="muted gap-top">
              Advised by {advice.doctor?.name ?? 'the doctor'} at the OPD visit of {longDate(advice.advised_at)}. The case will remember that visit.
            </p>
          )}
          <div className="grid gap-top">
            <SelectField label="Doctor" required value={form.doctor_id} onChange={set('doctor_id')} options={doctors.map((d) => ({ value: String(d.id), label: d.name }))} placeholder="Select doctor" error={errors.doctor_id} />
            <SelectField
              label="Bed"
              value={form.bed_id}
              onChange={set('bed_id')}
              options={freeBeds.map((b) => {
                const rate = dayCare && b.day_care_charge != null ? b.day_care_charge : b.daily_charge
                return { value: String(b.id), label: `${b.ward} · Bed ${b.bed_no}${rate ? ` (₹${rate}/day${dayCare && b.day_care_charge != null ? ', day care' : ''})` : ''}` }
              })}
              placeholder={freeBeds.length ? 'Assign later' : 'No free beds'}
              error={errors.bed_id}
            />
            <label className="check span-2 two-line">
              <input type="checkbox" checked={dayCare} onChange={(e) => setDayCare(e.target.checked)} />
              <span>
                Day care
                <small className="muted block">A same-day stay in a ward bed. Beds with a day-care rate are charged at that rate.</small>
              </span>
            </label>
            <div className="span-2">
              <TextField label="Admission date and time" required type="datetime-local" value={form.admitted_at} onChange={set('admitted_at')} error={errors.admitted_at} />
            </div>
            <div className="span-2">
              <TextField label="Reason for admission" value={form.reason} onChange={set('reason')} error={errors.reason} />
            </div>
            {payers.length > 0 && (
              <div className="span-2">
                <SelectField label="Bill to be settled by" value={payerId} onChange={setPayerId} options={payers.map((p) => ({ value: String(p.id), label: p.name }))} placeholder="The patient" error={errors.payer_id} />
              </div>
            )}
            <TextField label="Attendant name" value={form.attendant_name} onChange={set('attendant_name')} error={errors.attendant_name} />
            <TextField label="Attendant phone" inputMode="tel" value={form.attendant_phone} onChange={set('attendant_phone')} error={errors.attendant_phone} />
          </div>
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving}>
              {saving ? 'Admitting…' : 'Admit'}
            </button>
          </div>
        </Popup>
      )}
    </div>
  )
}
