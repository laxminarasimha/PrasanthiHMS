import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { SetupTabs } from '../components/SetupTabs'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { rupees } from '../format'
import type { Doctor, Service } from '../types'

const TYPES = [
  { value: 'appointment', label: 'Appointment service' },
  { value: 'other', label: 'Other service' },
]

const blank = { code: '', name: '', type: 'appointment', price: '', gst_percent: '0', priority: '2', doctor_id: '', is_active: true }
const PRIORITIES = [
  { value: '1', label: '1 — shown first' },
  { value: '2', label: '2 — normal' },
  { value: '3', label: '3 — shown last' },
]
type Form = typeof blank

export function Services() {
  const [services, setServices] = useState<Service[]>([])
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [editing, setEditing] = useState<Service | 'new' | null>(null)
  const [form, setForm] = useState<Form>(blank)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [tab, setTab] = useState<'appointment' | 'other'>('appointment')

  const load = useCallback(() => {
    api.get<Service[]>('/services', { params: { all: 1 } }).then((res) => setServices(res.data))
  }, [])

  useEffect(() => {
    load()
    api.get<Doctor[]>('/doctors').then((res) => setDoctors(res.data))
  }, [load])

  const open = (target: Service | 'new') => {
    setEditing(target)
    setErrors({})
    setNotice('')
    setForm(
      target === 'new'
        ? { ...blank, type: tab }
        : {
            code: target.code ?? '', name: target.name, type: target.type, price: String(target.price),
            gst_percent: String(target.gst_percent), priority: String(target.priority ?? 2), doctor_id: target.doctor_id ? String(target.doctor_id) : '', is_active: target.is_active,
          },
    )
  }

  const set = (key: keyof Form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const payload = { ...form, code: form.code || null, doctor_id: form.doctor_id || null, gst_percent: form.gst_percent || 0 }
    try {
      const res = editing === 'new' ? await api.post<Service>('/services', payload) : await api.put<Service>(`/services/${(editing as Service).id}`, payload)
      setNotice(`${res.data.name} was ${editing === 'new' ? 'added' : 'updated'}.`)
      setEditing(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
    <SetupTabs />
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Services</h1>
          <span className="muted grow">What the hospital charges for. Prices are copied onto each bill when it is made.</span>
          <button className="btn primary" onClick={() => open('new')}>
            + New service
          </button>
        </div>

        <div className="tabs">
          <button className={tab === 'appointment' ? 'active' : ''} onClick={() => setTab('appointment')}>
            Appointment services <span>{services.filter((s) => s.type === 'appointment').length}</span>
          </button>
          <button className={tab === 'other' ? 'active' : ''} onClick={() => setTab('other')}>
            Other services <span>{services.filter((s) => s.type === 'other').length}</span>
          </button>
        </div>

        {notice && <p className="notice">{notice}</p>}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Service</th>
                <th>Code</th>
                <th className="num">Price</th>
                <th className="num">GST %</th>
                <th>Doctor</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {services.filter((s) => s.type === tab).map((s) => (
                <tr key={s.id} onClick={() => open(s)} className={editing !== 'new' && editing?.id === s.id ? 'selected' : ''}>
                  <td>
                    <strong>{s.name}</strong>
                  </td>
                  <td>{s.code ?? '—'}</td>
                  <td className="num">{rupees(s.price)}</td>
                  <td className="num">{s.gst_percent}</td>
                  <td>{s.doctor?.name ?? 'Any doctor'}</td>
                  <td>
                    <span className={s.is_active ? 'pill ok' : 'pill off'}>{s.is_active ? 'Active' : 'Hidden'}</span>
                  </td>
                </tr>
              ))}
              {services.filter((s) => s.type === tab).length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>No services yet. Add a consultation service to start booking appointments.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {editing && (
        <form className="card panel" onSubmit={save} noValidate>
          <div className="panel-head">
            <h2>{editing === 'new' ? 'Add service' : `Edit ${editing.name}`}</h2>
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Close
            </button>
          </div>
          <div className="grid">
            <div className="span-2">
              <TextField label="Service name" required value={form.name} onChange={set('name')} error={errors.name} autoFocus />
            </div>
            <SelectField label="Type" required value={form.type} onChange={set('type')} options={TYPES} error={errors.type} />
            <TextField label="Code (optional)" value={form.code} onChange={set('code')} error={errors.code} />
            <TextField label="Price (₹)" required type="number" min={0} step="0.01" value={form.price} onChange={set('price')} error={errors.price} />
            <TextField label="GST %" type="number" min={0} max={100} step="0.01" value={form.gst_percent} onChange={set('gst_percent')} error={errors.gst_percent} />
            <SelectField label="Priority in lists" value={form.priority} onChange={set('priority')} options={PRIORITIES} error={errors.priority} />
            <div>
              <SelectField
                label="Doctor"
                value={form.doctor_id}
                onChange={set('doctor_id')}
                options={doctors.map((d) => ({ value: String(d.id), label: d.name }))}
                placeholder="Any doctor"
                error={errors.doctor_id}
              />
            </div>
          </div>
          {editing !== 'new' && (
            <label className="check">
              <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
              Active (untick to hide it from new appointments and bills)
            </label>
          )}
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving}>
              {saving ? 'Saving…' : editing === 'new' ? 'Add service' : 'Save changes'}
            </button>
          </div>
        </form>
      )}
    </div>
    </>
  )
}
