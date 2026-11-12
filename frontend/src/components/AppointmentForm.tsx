import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { PAYMENT_MODES, rupees } from '../format'
import type { Appointment, Doctor, Patient, Service } from '../types'
import { SelectField, TextField } from './Field'
import { PatientPicker } from './PatientPicker'

type Props = {
  date: string
  /** Set when the form is opened from a calendar slot. */
  time?: string
  doctorId?: string
  status?: 'arrived' | 'booked'
  doctors: Doctor[]
  services: Service[]
  onClose: () => void
  onBooked: (appointment: Appointment) => void
}

const DURATIONS = [5, 10, 15, 30, 45, 60, 90, 120].map((m) => ({
  value: String(m),
  label: m < 60 ? `${m} mins` : `${m / 60} hr${m > 60 ? 's' : ''}`,
}))
const STATUSES = [
  { value: 'arrived', label: 'Arrived (patient is here)' },
  { value: 'booked', label: 'Booked (coming later)' },
]

const nowTime = () => new Date().toTimeString().slice(0, 5)

export function AppointmentForm({ date, time: startTime, doctorId: startDoctor, status: startStatus, doctors, services, onClose, onBooked }: Props) {
  const [patient, setPatient] = useState<Patient | null>(null)
  const [doctorId, setDoctorId] = useState(startDoctor || (doctors.length === 1 ? String(doctors[0].id) : ''))
  const [serviceId, setServiceId] = useState('')
  const [day, setDay] = useState(date)
  const [time, setTime] = useState(startTime ?? nowTime())
  const [duration, setDuration] = useState('15')
  const [status, setStatus] = useState<string>(startStatus ?? 'arrived')
  const [notes, setNotes] = useState('')
  const [skipBilling, setSkipBilling] = useState(false)
  const [price, setPrice] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [discount, setDiscount] = useState('')
  const [paid, setPaid] = useState('')
  const [mode, setMode] = useState('cash')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  // Offer the services that are open to every doctor plus the chosen doctor's own.
  const offered = services.filter((s) => s.type === 'appointment' && (!s.doctor_id || String(s.doctor_id) === doctorId))
  const service = services.find((s) => String(s.id) === serviceId)
  const net = service ? Math.max(0, Number(price || 0) * Number(quantity || 1) - Number(discount || 0)) : 0
  const netWithTax = Math.round(net * (1 + (service?.gst_percent ?? 0) / 100) * 100) / 100
  const billing = Boolean(service) && !skipBilling

  const chooseService = (value: string) => {
    setServiceId(value)
    setPrice(String(services.find((s) => String(s.id) === value)?.price ?? ''))
    setQuantity('1')
    setDiscount('')
    setPaid('')
  }

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
        status,
        notes: notes || null,
        skip_billing: skipBilling,
        ...(billing ? { unit_price: price || 0, quantity: quantity || 1, discount: discount || 0 } : {}),
        ...(billing && Number(paid) > 0 ? { paid, payment_mode: mode } : {}),
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
            chooseService('')
          }}
          options={doctors.map((d) => ({ value: String(d.id), label: d.name }))}
          placeholder="Select doctor"
          error={errors.doctor_id}
        />
        <SelectField
          label="Service"
          value={serviceId}
          onChange={chooseService}
          options={offered.map((s) => ({ value: String(s.id), label: `${s.name} — ₹${s.price}` }))}
          placeholder="Choose service"
          error={errors.service_id}
        />
        <SelectField label="Status" value={status} onChange={setStatus} options={STATUSES} />
        <SelectField label="Duration" value={duration} onChange={setDuration} options={DURATIONS} />
        <TextField label="Date" required type="date" value={day} onChange={setDay} error={errors.scheduled_at} />
        <TextField label="Time" required type="time" value={time} onChange={setTime} />
        <div className="span-2">
          <TextField label="Notes (optional)" value={notes} onChange={setNotes} error={errors.notes} />
        </div>
      </div>

      {service && (
        <div className="bill-box">
          <label className="check flush">
            <input type="checkbox" checked={skipBilling} onChange={(e) => setSkipBilling(e.target.checked)} />
            Skip billing for this visit
          </label>
          {!skipBilling && (
            <>
              <div className="grid three gap-top">
                <TextField label="Unit price (₹)" type="number" min={0} step="0.01" value={price} onChange={setPrice} error={errors.unit_price} />
                <TextField label="Qty" type="number" min={1} value={quantity} onChange={setQuantity} error={errors.quantity} />
                <TextField label="Discount (₹)" type="number" min={0} step="0.01" value={discount} onChange={setDiscount} error={errors.discount} />
              </div>
              <p className="net">
                {service.gst_percent > 0 && <span className="muted">Tax {service.gst_percent}% · </span>}
                Net price <strong>{rupees(netWithTax)}</strong>
              </p>
              <div className="grid">
                <TextField label="Paid now (₹)" type="number" min={0} step="0.01" placeholder="0" value={paid} onChange={setPaid} error={errors.paid} />
                <SelectField label="Payment mode" value={mode} onChange={setMode} options={PAYMENT_MODES} error={errors.payment_mode} />
              </div>
              <button type="button" className="link add-medicine" onClick={() => setPaid(String(netWithTax))}>
                Paid in full ({rupees(netWithTax)})
              </button>
            </>
          )}
        </div>
      )}

      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving}>
          {saving ? 'Saving…' : 'Save appointment'}
        </button>
      </div>
    </form>
  )
}
