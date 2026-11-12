import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../../api'
import { TextField } from '../../components/Field'
import { rupees } from '../../format'
import type { Bed, IpdService } from '../../types'

const blankBed = { ward: '', bed_no: '', daily_charge: '', is_active: true }
const blankService = { name: '', category: '', price: '', is_active: true }
type Editing = { kind: 'bed' | 'service'; id: number | null } | null

export function IpdSetup() {
  const [beds, setBeds] = useState<Bed[]>([])
  const [services, setServices] = useState<IpdService[]>([])
  const [editing, setEditing] = useState<Editing>(null)
  const [bed, setBed] = useState(blankBed)
  const [service, setService] = useState(blankService)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<Bed[]>('/ipd/beds').then((res) => setBeds(res.data))
    api.get<IpdService[]>('/ipd/services', { params: { all: 1 } }).then((res) => setServices(res.data))
  }, [])
  useEffect(load, [load])

  const openBed = (b: Bed | null) => {
    setEditing({ kind: 'bed', id: b?.id ?? null })
    setErrors({})
    setNotice('')
    setBed(b ? { ward: b.ward, bed_no: b.bed_no, daily_charge: String(b.daily_charge), is_active: b.is_active } : blankBed)
  }
  const openService = (s: IpdService | null) => {
    setEditing({ kind: 'service', id: s?.id ?? null })
    setErrors({})
    setNotice('')
    setService(s ? { name: s.name, category: s.category ?? '', price: String(s.price), is_active: s.is_active } : blankService)
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!editing) return
    const base = editing.kind === 'bed' ? '/ipd/beds' : '/ipd/services'
    const payload = editing.kind === 'bed' ? { ...bed, daily_charge: bed.daily_charge || 0 } : service
    try {
      await (editing.id ? api.put(`${base}/${editing.id}`, payload) : api.post(base, payload))
      setNotice('Saved.')
      setEditing(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }

  const occupied = beds.filter((b) => b.current_admission).length

  return (
    <div className="split">
      <div className="stack grow">
        <section className="card">
          <div className="toolbar">
            <Link className="btn ghost" to="/ipd">
              ← Cases
            </Link>
            <h1 className="grow">Beds</h1>
            <span className="muted">
              {occupied} of {beds.filter((b) => b.is_active).length} occupied
            </span>
            <button className="btn primary" onClick={() => openBed(null)}>
              + Add bed
            </button>
          </div>
          {notice && <p className="notice">{notice}</p>}
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Ward</th>
                  <th>Bed</th>
                  <th className="num">Charge per day</th>
                  <th>Occupied by</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {beds.map((b) => (
                  <tr key={b.id} onClick={() => openBed(b)} className={editing?.kind === 'bed' && editing.id === b.id ? 'selected' : ''}>
                    <td>
                      <strong>{b.ward}</strong>
                    </td>
                    <td>{b.bed_no}</td>
                    <td className="num">{rupees(b.daily_charge)}</td>
                    <td>{b.current_admission ? `${b.current_admission.patient.name} (${b.current_admission.ipd_no})` : '—'}</td>
                    <td>
                      <span className={`pill ${!b.is_active ? 'off' : b.current_admission ? 'status-ongoing' : 'ok'}`}>{!b.is_active ? 'Not in use' : b.current_admission ? 'Occupied' : 'Free'}</span>
                    </td>
                  </tr>
                ))}
                {beds.length === 0 && (
                  <tr className="empty">
                    <td colSpan={5}>No beds yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        <section className="card">
          <div className="toolbar">
            <h1 className="grow">IPD services</h1>
            <button className="btn ghost" onClick={() => openService(null)}>
              + Add service
            </button>
          </div>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Service</th>
                  <th>Category</th>
                  <th className="num">Price</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {services.map((s) => (
                  <tr key={s.id} onClick={() => openService(s)} className={editing?.kind === 'service' && editing.id === s.id ? 'selected' : ''}>
                    <td>
                      <strong>{s.name}</strong>
                    </td>
                    <td>{s.category ?? '—'}</td>
                    <td className="num">{rupees(s.price)}</td>
                    <td>
                      <span className={s.is_active ? 'pill ok' : 'pill off'}>{s.is_active ? 'Active' : 'Hidden'}</span>
                    </td>
                  </tr>
                ))}
                {services.length === 0 && (
                  <tr className="empty">
                    <td colSpan={4}>No IPD services yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      </div>

      {editing && (
        <form className="card panel" onSubmit={save} noValidate>
          <div className="panel-head">
            <h2>
              {editing.id ? 'Edit' : 'Add'} {editing.kind === 'bed' ? 'bed' : 'IPD service'}
            </h2>
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Close
            </button>
          </div>
          {editing.kind === 'bed' ? (
            <div className="grid">
              <div className="span-2">
                <TextField label="Ward" required placeholder="General Ward" value={bed.ward} onChange={(v) => setBed((b) => ({ ...b, ward: v }))} error={errors.ward} autoFocus />
              </div>
              <TextField label="Bed number" required value={bed.bed_no} onChange={(v) => setBed((b) => ({ ...b, bed_no: v }))} error={errors.bed_no} />
              <TextField label="Charge per day (₹)" type="number" min={0} value={bed.daily_charge} onChange={(v) => setBed((b) => ({ ...b, daily_charge: v }))} error={errors.daily_charge} />
              {editing.id && (
                <label className="check span-2">
                  <input type="checkbox" checked={bed.is_active} onChange={(e) => setBed((b) => ({ ...b, is_active: e.target.checked }))} />
                  In use (untick to stop offering this bed)
                </label>
              )}
              {errors.is_active && <span className="field-error span-2">{errors.is_active}</span>}
            </div>
          ) : (
            <div className="grid">
              <div className="span-2">
                <TextField label="Service name" required value={service.name} onChange={(v) => setService((s) => ({ ...s, name: v }))} error={errors.name} autoFocus />
              </div>
              <TextField label="Category" placeholder="Nursing" value={service.category} onChange={(v) => setService((s) => ({ ...s, category: v }))} error={errors.category} />
              <TextField label="Price (₹)" required type="number" min={0} step="0.01" value={service.price} onChange={(v) => setService((s) => ({ ...s, price: v }))} error={errors.price} />
              {editing.id && (
                <label className="check span-2">
                  <input type="checkbox" checked={service.is_active} onChange={(e) => setService((s) => ({ ...s, is_active: e.target.checked }))} />
                  Active
                </label>
              )}
            </div>
          )}
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary">Save</button>
          </div>
        </form>
      )}
    </div>
  )
}
