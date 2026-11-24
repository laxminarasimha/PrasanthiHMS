import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { PAYMENT_MODES, rupees } from '../format'
import type { Appointment, Doctor, Patient, Service } from '../types'
import { SelectField, TextField } from './Field'
import { useBillingPreferences } from './useBillingPreferences'
import { PatientPicker } from './PatientPicker'
import { DiscountWhy } from './DiscountWhy'
import { noWhy, whyFields } from './discountFields'
import { offeredTo, usualService } from '../doctorFee'

type Props = {
  date: string
  /** Set when the form is opened from a calendar slot. */
  time?: string
  doctorId?: string
  status?: 'arrived' | 'booked'
  /** Set when booking for someone already chosen, such as a follow-up. */
  patient?: Appointment['patient']
  doctors: Doctor[]
  services: Service[]
  onClose: () => void
  onBooked: (appointment: Appointment) => void
}

const DURATIONS = [5, 10, 15, 20, 30, 45, 60, 90, 120].map((m) => ({
  value: String(m),
  label: m < 60 ? `${m} mins` : `${m / 60} hr${m > 60 ? 's' : ''}`,
}))
const STATUSES = [
  { value: 'arrived', label: 'Arrived (patient is here)' },
  { value: 'booked', label: 'Booked (coming later)' },
]

const nowTime = () => new Date().toTimeString().slice(0, 5)

/** Shown when a visit booked for later falls outside the doctor's hours: the desk reads why, and may book it anyway. */
export function OutsideHours({ message, checked, onChange }: { message: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return (
    <div className="span-2 outside-hours">
      <p>{message}</p>
      <label className="check flush">
        <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />
        Book at this time anyway, then press Save again
      </label>
    </div>
  )
}

export function AppointmentForm({ patient: startPatient, date, time: startTime, doctorId: startDoctor, status: startStatus, doctors, services, onClose, onBooked }: Props) {
  const { user } = useAuth()
  const [patient, setPatient] = useState<Patient | null>((startPatient as Patient | undefined) ?? null)
  const [doctorId, setDoctorId] = useState(startDoctor || (doctors.length === 1 ? String(doctors[0].id) : ''))
  // The doctor's own consultation is put in with the doctor; the desk may still choose another service.
  const [serviceId, setServiceId] = useState(() => String(usualService(services, doctorId)?.id ?? ''))
  const [day, setDay] = useState(date)
  const [time, setTime] = useState(startTime ?? nowTime())
  // A doctor with their own slot length gets visits of that length unless the desk picks another.
  const [chosenDuration, setDuration] = useState<string | null>(null)
  const duration = chosenDuration ?? String(doctors.find((d) => String(d.id) === doctorId)?.slot_minutes ?? 15)
  const [status, setStatus] = useState<string>(startStatus ?? 'arrived')
  const [notes, setNotes] = useState('')
  const [skipBilling, setSkipBilling] = useState(false)
  const [price, setPrice] = useState(() => String(usualService(services, doctorId)?.price ?? ''))
  const [quantity, setQuantity] = useState('1')
  const [discount, setDiscount] = useState('')
  const [paid, setPaid] = useState('')
  const prefs = useBillingPreferences()
  // The hospital's usual mode until the desk picks another.
  const [chosenMode, setMode] = useState<string | null>(null)
  const mode = chosenMode ?? prefs.default_payment_mode
  const payLater = prefs.pay_timing === 'after'
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [why, setWhy] = useState(noWhy)
  // Set once the desk has read that the time is outside the doctor's hours and chosen to book it anyway.
  const [anyway, setAnyway] = useState(false)

  // Offer the chosen doctor's own services, then those open to every doctor.
  const offered = offeredTo(services, doctorId)
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
        ...(anyway ? { outside_hours: true } : {}),
        ...(billing ? { unit_price: price || 0, quantity: quantity || 1, discount: discount || 0 } : {}),
        ...(billing && Number(discount) > 0 ? whyFields(why) : {}),
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
            setAnyway(false)
            // Each doctor has their own fee: the bill takes it as soon as the doctor is chosen.
            chooseService(String(usualService(services, v)?.id ?? ''))
          }}
          options={doctors.map((d) => ({ value: String(d.id), label: d.name }))}
          placeholder="Select doctor"
          error={errors.doctor_id}
        />
        <SelectField
          label="Service"
          value={serviceId}
          onChange={chooseService}
          options={offered.map((s) => ({ value: String(s.id), label: `${s.name}${s.doctor_id ? ` · ${s.doctor?.name ?? 'this doctor'}` : ''} — ₹${s.price}` }))}
          placeholder="Choose service"
          error={errors.service_id}
        />
        <SelectField label="Status" value={status} onChange={setStatus} options={STATUSES} />
        <SelectField label="Duration" value={duration} onChange={setDuration} options={DURATIONS} />
        <TextField
          label="Date"
          required
          type="date"
          value={day}
          onChange={(v) => {
            setDay(v)
            setAnyway(false)
          }}
          error={errors.scheduled_at}
        />
        <TextField
          label="Time"
          required
          type="time"
          value={time}
          onChange={(v) => {
            setTime(v)
            setAnyway(false)
          }}
        />
        {errors.outside_hours && <OutsideHours message={errors.outside_hours} checked={anyway} onChange={setAnyway} />}
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
                <TextField label="Unit price (₹)" type="number" min={0} step="0.01" value={price} onChange={setPrice} error={errors.unit_price} disabled={!user?.can_change_price} title={user?.can_change_price ? undefined : 'You are not allowed to change the price'} />
                <TextField label="Qty" type="number" min={1} value={quantity} onChange={setQuantity} error={errors.quantity} />
                <TextField label="Discount (₹)" type="number" min={0} step="0.01" value={discount} onChange={setDiscount} error={errors.discount} />
              </div>
              {Number(discount) > 0 && <DiscountWhy id="booking-why" value={why} onChange={setWhy} />}
              {(errors.discount_reason || errors.discount_allowed_by) && <p className="form-error">{errors.discount_reason ?? errors.discount_allowed_by}</p>}
              <p className="net">
                {service.gst_percent > 0 && <span className="muted">Tax {service.gst_percent}% · </span>}
                Net price <strong>{rupees(netWithTax)}</strong>
              </p>
              {payLater && <p className="muted small">The bill is made now and paid after the consultation. Enter an amount only if the patient pays now.</p>}
              <div className="grid">
                <TextField label="Paid now (₹)" type="number" min={0} step="0.01" placeholder="0" value={paid} onChange={setPaid} error={errors.paid} />
                <SelectField label="Payment mode" value={mode} onChange={setMode} options={PAYMENT_MODES} error={errors.payment_mode} />
              </div>
              {!payLater && (
                <button type="button" className="link add-medicine" onClick={() => setPaid(String(netWithTax))}>
                  Paid in full ({rupees(netWithTax)})
                </button>
              )}
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
