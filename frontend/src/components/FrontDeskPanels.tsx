import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { STATUS_LABELS, longDate, rupees, today } from '../format'
import type { Appointment, AppointmentStatus, Attachment, DaySummary, Doctor, Service, Visit } from '../types'
import { SelectField, TextField } from './Field'

const Head = ({ title, sub, onClose }: { title: string; sub?: string; onClose: () => void }) => (
  <div className="panel-head">
    <div>
      <h2>{title}</h2>
      {sub && <span className="muted">{sub}</span>}
    </div>
    <button type="button" className="btn ghost" onClick={onClose}>
      Close
    </button>
  </div>
)

const VITALS = [
  ['weight_kg', 'Weight (kg)'], ['height_cm', 'Height (cm)'], ['bp_systolic', 'BP systolic'], ['bp_diastolic', 'BP diastolic'],
  ['pulse', 'Pulse (/min)'], ['temperature_f', 'Temp (°F)'], ['spo2', 'SpO₂ (%)'], ['resp_rate', 'Resp. rate (/min)'],
] as const

/** Vitals taken at the desk before the patient goes in; the doctor sees them on the visit pad. */
export function VitalsPanel({ appointment, onClose }: { appointment: Appointment; onClose: () => void }) {
  const [form, setForm] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    api.get<Visit>(`/appointments/${appointment.id}/consultation`).then((res) => {
      const c = res.data.consultation as Record<string, unknown> | null
      setForm(Object.fromEntries(VITALS.map(([key]) => [key, c?.[key] == null ? '' : String(c[key])])))
    })
  }, [appointment.id])

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setErrors({})
    try {
      await api.put(`/appointments/${appointment.id}/vitals`, Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])))
      setSaved(true)
    } catch (err) {
      setSaved(false)
      setErrors(fieldErrors(err))
    }
  }

  return (
    <form className="card panel" onSubmit={save} noValidate>
      <Head title="Vitals" sub={`${appointment.patient.name} · ${appointment.patient.uhid}`} onClose={onClose} />
      {saved && <p className="notice">Vitals saved. The doctor will see them on the visit pad.</p>}
      <div className="grid">
        {VITALS.map(([key, label]) => (
          <TextField key={key} label={label} type="number" step="any" value={form[key] ?? ''} onChange={(v) => { setSaved(false); setForm((f) => ({ ...f, [key]: v })) }} error={errors[key]} />
        ))}
      </div>
      <div className="panel-foot">
        <button className="btn primary">Save vitals</button>
      </div>
    </form>
  )
}

/** Reports and papers the patient brings: scanned or photographed and kept with their record. */
export function AttachmentsPanel({ patient, appointmentId, onClose }: { patient: { id: number; name: string; uhid: string }; appointmentId?: number; onClose?: () => void }) {
  const { user } = useAuth()
  const [list, setList] = useState<Attachment[]>([])
  const [title, setTitle] = useState('')
  const [date, setDate] = useState(today())
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api.get<Attachment[]>(`/patients/${patient.id}/attachments`).then((res) => setList(res.data))
  }, [patient.id])
  useEffect(load, [load])

  const upload = async (e: FormEvent) => {
    e.preventDefault()
    if (!file) return
    setBusy(true)
    setError('')
    const body = new FormData()
    body.append('title', title)
    body.append('document_date', date)
    body.append('file', file)
    if (appointmentId) body.append('appointment_id', String(appointmentId))
    try {
      await api.post(`/patients/${patient.id}/attachments`, body)
      setTitle('')
      setFile(null)
      ;(e.target as HTMLFormElement).reset()
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setBusy(false)
    }
  }

  // Files are private, so they are fetched with the signed-in session and opened from memory.
  const view = async (a: Attachment) => {
    const res = await api.get<Blob>(`/attachments/${a.id}/download`, { responseType: 'blob' })
    window.open(URL.createObjectURL(res.data), '_blank')
  }

  const body = (
    <>
      <ul className="history">
        {list.map((a) => (
          <li key={a.id}>
            <span>
              <button type="button" className="link" onClick={() => view(a)}>
                {a.title}
              </button>
              <small className="muted">
                {' '}
                {longDate(a.document_date)} · {(a.size / 1024).toFixed(0)} KB{a.uploader ? ` · ${a.uploader.name}` : ''}
              </small>
            </span>
            {user?.can_front_desk && (
              <button type="button" className="link danger" onClick={() => api.delete(`/attachments/${a.id}`).then(load)}>
                Remove
              </button>
            )}
          </li>
        ))}
        {list.length === 0 && <li className="muted">No attachments yet.</li>}
      </ul>
      {user?.can_front_desk && (
        <form onSubmit={upload} className="gap-top">
          <div className="grid">
            <TextField label="Title" required placeholder="Old ECG, discharge summary…" value={title} onChange={setTitle} />
            <TextField label="Document date" required type="date" value={date} onChange={setDate} />
            <label className="field span-2">
              <span className="field-label">File (PDF or image, up to 10 MB) *</span>
              <input type="file" accept=".pdf,image/png,image/jpeg,image/webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            </label>
          </div>
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={busy || !file || !title.trim()}>
              {busy ? 'Uploading…' : 'Add attachment'}
            </button>
          </div>
        </form>
      )}
    </>
  )

  if (!onClose) return body

  return (
    <aside className="card panel">
      <Head title="Attachments" sub={`${patient.name} · ${patient.uhid}`} onClose={onClose} />
      {body}
    </aside>
  )
}

/** Change the doctor, time, service or status of a visit already booked. */
export function EditAppointmentPanel({ appointment, doctors, services, onClose, onSaved }: { appointment: Appointment; doctors: Doctor[]; services: Service[]; onClose: () => void; onSaved: () => void }) {
  const [doctorId, setDoctorId] = useState(String(appointment.doctor.id))
  const [serviceId, setServiceId] = useState(appointment.service ? String(appointment.service.id) : '')
  const [day, setDay] = useState(appointment.scheduled_at.slice(0, 10))
  const [time, setTime] = useState(appointment.scheduled_at.slice(11, 16))
  const [status, setStatus] = useState<string>(appointment.status)
  const [notes, setNotes] = useState(appointment.notes ?? '')
  const [errors, setErrors] = useState<FieldErrors>({})

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await api.put(`/appointments/${appointment.id}`, { doctor_id: doctorId, service_id: serviceId || null, scheduled_at: `${day} ${time}`, status, notes: notes || null })
      onSaved()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }

  return (
    <form className="card panel" onSubmit={save} noValidate>
      <Head title="Edit appointment" sub={`${appointment.patient.name} · token ${appointment.token_no ?? '—'}`} onClose={onClose} />
      <div className="grid">
        <SelectField label="Doctor" required value={doctorId} onChange={setDoctorId} options={doctors.map((d) => ({ value: String(d.id), label: d.name }))} error={errors.doctor_id} />
        <SelectField label="Service" value={serviceId} onChange={setServiceId} options={services.filter((s) => s.type === 'appointment').map((s) => ({ value: String(s.id), label: s.name }))} placeholder="None" error={errors.service_id} />
        <TextField label="Date" required type="date" value={day} onChange={setDay} error={errors.scheduled_at} />
        <TextField label="Time" required type="time" value={time} onChange={setTime} />
        <SelectField label="Status" value={status} onChange={setStatus} options={(Object.keys(STATUS_LABELS) as AppointmentStatus[]).map((s) => ({ value: s, label: STATUS_LABELS[s] }))} />
        <TextField label="Notes" value={notes} onChange={setNotes} />
      </div>
      <p className="muted small">Changing the service here does not change a bill already made; edit the bill for that.</p>
      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary">Save changes</button>
      </div>
    </form>
  )
}

/** The day in numbers: money by department, and appointments by doctor. */
export function SummaryPanel({ date, onClose }: { date: string; onClose: () => void }) {
  const [data, setData] = useState<DaySummary | null>(null)

  useEffect(() => {
    api.get<DaySummary>('/front-desk/summary', { params: { date } }).then((res) => setData(res.data))
  }, [date])

  const rows: [keyof DaySummary['bills'], string][] = [['all', 'All'], ['appointment', 'Appointments'], ['lab', 'Lab'], ['other', 'Other']]

  return (
    <aside className="card panel wide">
      <Head title="Summary" sub={longDate(date)} onClose={onClose} />
      {data && (
        <>
          <h3>Bills</h3>
          <table className="compact">
            <thead>
              <tr>
                <th />
                <th className="num">Billed</th>
                <th className="num">Collected</th>
                <th className="num">Refunded</th>
                <th className="num">Bills</th>
                <th className="num">Outstanding</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(([key, label]) => (
                <tr key={key} className="static">
                  <td>
                    <strong>{label}</strong>
                  </td>
                  <td className="num">{rupees(data.bills[key].billed)}</td>
                  <td className="num">{rupees(data.bills[key].collected)}</td>
                  <td className="num">{rupees(data.bills[key].refunded)}</td>
                  <td className="num">{data.bills[key].orders}</td>
                  <td className={`num ${data.bills[key].outstanding > 0 ? 'due' : ''}`}>{rupees(data.bills[key].outstanding)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <h3>Appointments</h3>
          <table className="compact">
            <thead>
              <tr>
                <th>Doctor</th>
                <th className="num">Total</th>
                <th className="num">Booked</th>
                <th className="num">Arrived</th>
                <th className="num">On-going</th>
                <th className="num">Reviewed</th>
              </tr>
            </thead>
            <tbody>
              {data.appointments.map((a) => (
                <tr key={a.doctor} className="static">
                  <td>{a.doctor}</td>
                  <td className="num">{a.total}</td>
                  <td className="num">{a.booked}</td>
                  <td className="num">{a.arrived}</td>
                  <td className="num">{a.ongoing}</td>
                  <td className="num">{a.reviewed}</td>
                </tr>
              ))}
              {data.appointments.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>No appointments on this day.</td>
                </tr>
              )}
            </tbody>
          </table>
          <p className="muted small">Bills are counted on the day they were made; money on the day it was received.</p>
        </>
      )}
    </aside>
  )
}
