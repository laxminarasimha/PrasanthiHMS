import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../../api'
import { SelectField, TextField } from '../../components/Field'
import { longDate, rupees, today } from '../../format'
import type { Doctor } from '../../types'

type Visit = { id: number; doctor: { id: number; name: string | null }; visited_on: string; kind: 'first' | 'later'; note: string | null; can_cancel: boolean; /** Sent only to those who look after consultants' fees. */ fee?: number }

/**
 * The consultants who came to see this inpatient: which doctor and on which day. It is the record the hospital pays
 * its consultants from (the first visit to a patient at one fee, each later day at another), and adds nothing to
 * the patient's bill. The doctor the patient is admitted under is not listed here: their share comes from the bill.
 */
export function IpdConsultants({ admissionId, canEdit, minDate, maxDate }: { admissionId: number; canEdit: boolean; /** The day of admission. */ minDate: string; /** The day of discharge, once discharged. */ maxDate?: string }) {
  const [visits, setVisits] = useState<Visit[] | null>(null)
  const [under, setUnder] = useState<number | null>(null)
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const last = maxDate && maxDate < today() ? maxDate : today()
  const [form, setForm] = useState({ doctor_id: '', visited_on: last, note: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    api.get<{ admitted_under: number; visits: Visit[] }>(`/ipd/admissions/${admissionId}/consultant-visits`).then((res) => {
      setVisits(res.data.visits)
      setUnder(res.data.admitted_under)
    })
  }, [admissionId])
  useEffect(load, [load])
  useEffect(() => {
    api.get<Doctor[]>('/doctors').then((res) => setDoctors(res.data))
  }, [])

  const add = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setNotice('')
    try {
      const res = await api.post<Visit>(`/ipd/admissions/${admissionId}/consultant-visits`, { doctor_id: form.doctor_id, visited_on: form.visited_on, note: form.note || null })
      setNotice(`Written down: ${res.data.doctor.name}, ${longDate(res.data.visited_on)} (${res.data.kind === 'first' ? 'first visit' : 'later visit'}).`)
      setForm((f) => ({ ...f, note: '' }))
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const takeOff = async (v: Visit) => {
    const reason = window.prompt(`Take off ${v.doctor.name}'s visit on ${longDate(v.visited_on)}? Say why:`)
    if (!reason?.trim()) return
    try {
      await api.post(`/consultant-visits/${v.id}/cancel`, { reason: reason.trim() })
      setNotice('The visit was taken off.')
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    }
  }
  const showFee = visits?.some((v) => v.fee !== undefined)

  return (
    <div className="ipd-consultants">
      <p className="muted small">
        Write down each consultant who comes to see this patient, once for a day. The hospital pays its consultants from this record; it adds nothing to the patient's bill. The doctor the patient is admitted under is not entered here.
      </p>
      {notice && <p className="notice">{notice}</p>}
      {canEdit && (
        <form className="row wrap ipd-consultant-form" onSubmit={add} noValidate>
          <SelectField label="Consultant" required value={form.doctor_id} onChange={(v) => setForm((f) => ({ ...f, doctor_id: v }))} options={doctors.filter((d) => d.id !== under).map((d) => ({ value: String(d.id), label: d.name }))} placeholder="Choose the doctor who came" error={errors.doctor_id} />
          <TextField label="Day" required type="date" min={minDate} max={last} value={form.visited_on} onChange={(v) => setForm((f) => ({ ...f, visited_on: v }))} error={errors.visited_on} />
          <TextField label="Note (optional)" value={form.note} onChange={(v) => setForm((f) => ({ ...f, note: v }))} placeholder="What they were called for" error={errors.note} />
          <button className="btn primary" disabled={saving || !form.doctor_id || !form.visited_on}>
            {saving ? 'Saving…' : 'Write down the visit'}
          </button>
        </form>
      )}
      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="table-wrap gap-top">
        <table>
          <thead>
            <tr>
              <th>Day</th>
              <th>Consultant</th>
              <th>Visit</th>
              {showFee && <th className="num">Fee to the consultant</th>}
              <th>Note</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {visits?.map((v) => (
              <tr key={v.id} className="static">
                <td>{longDate(v.visited_on)}</td>
                <td>
                  <strong>{v.doctor.name}</strong>
                </td>
                <td>
                  <span className={`pill ${v.kind === 'first' ? 'status-ongoing' : 'status-booked'}`}>{v.kind === 'first' ? 'First visit' : 'Later visit'}</span>
                </td>
                {showFee && <td className="num">{v.fee !== undefined ? rupees(v.fee) : ''}</td>}
                <td className="wrap">{v.note ?? ''}</td>
                <td className="num">
                  {v.can_cancel && (
                    <button type="button" className="link danger" onClick={() => void takeOff(v)}>
                      Take off
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {visits && visits.length === 0 && (
              <tr className="empty">
                <td colSpan={showFee ? 6 : 5}>No consultant's visit has been written down for this patient.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
