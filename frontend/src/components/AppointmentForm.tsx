import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import type { Appointment, Doctor, Patient, Service } from '../types'
import { SelectField, TextField } from './Field'
import { PatientPicker } from './PatientPicker'

type Props = {
  date: string
  doctors: Doctor[]
  services: Service[]
  onClose: () => void
  onBooked: (appointment: Appointment) => void
}

const DURATIONS = [5, 10, 15, 30, 45, 60, 90, 120].map((m) => ({
  value: String(m),
  label: m < 60 ? `${m} mins` : `${m / 60} hr${m > 60 ? 's' : ''}`,
}))

const nowTime = () => new Date().toTimeString().slice(0, 5)

export function AppointmentForm({ date, doctors, services, onClose, onBooked }: Props) {
  const [patient, setPatient] = useState<Patient | null>(null)
  const [doctorId, setDoctorId] = useState(doctors.length === 1 ? String(doctors[0].id) : '')
  const [serviceId, setServiceId] = useState('')
  const [day, setDay] = useState(date)
  const [time, setTime] = useState(nowTime())
  const [duration, setDuration] = useState('15')
  const [arrived, setArrived] = useState(true)
  const [notes, setNotes] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  // Offer the services that are open to every doctor plus the chosen doctor's own.
  const offered = services.filter((s) => s.type === 'appointment' && (!s.doctor_id || String(s.doctor_id) === doctorId))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!patient) {
      setErrors({ patient_id: 'Choose a patient first.' })
      return
    }
    setSaving(true)
    setErrors({})
    try {
      const res = await api.post<Appointment>('/appointments', {
        patient_id: patient.id,
        doctor_id: doctorId || null,
        service_id: serviceId || null,
        scheduled_at: `${day} ${time}`,
        duration_minutes: duration,
        status: arrived ? 'arrived' : 'booked',
        notes: notes || null,
      })
      onBooked(res.data)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="card panel" onSubmit={submit} noValidate>
      <div className="panel-head">
        <h2>New appointment</h2>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      <PatientPicker patient={patient} onChange={setPatient} error={errors.patient_id} />

      <div className="grid gap-top">
        <SelectField
          label="Doctor"
          required
          value={doctorId}
          onChange={(v) => {
            setDoctorId(v)
            setServiceId('')
          }}
          options={doctors.map((d) => ({ value: String(d.id), label: d.name }))}
          placeholder="Select doctor"
          error={errors.doctor_id}
        />
        <SelectField
          label="Service"
          value={serviceId}
          onChange={setServiceId}
          options={offered.map((s) => ({ value: String(s.id), label: `${s.name} — ₹${s.price}` }))}
          placeholder="No charge"
          error={errors.service_id}
        />
        <TextField label="Date" required type="date" value={day} onChange={setDay} error={errors.scheduled_at} />
        <TextField label="Time" required type="time" value={time} onChange={setTime} />
        <SelectField label="Duration" value={duration} onChange={setDuration} options={DURATIONS} />
        <div className="span-2">
          <TextField label="Notes (optional)" value={notes} onChange={setNotes} error={errors.notes} />
        </div>
      </div>

      <label className="check">
        <input type="checkbox" checked={arrived} onChange={(e) => setArrived(e.target.checked)} />
        Patient is here now (mark as Arrived)
      </label>

      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving}>
          {saving ? 'Booking…' : 'Book appointment'}
        </button>
      </div>
    </form>
  )
}
