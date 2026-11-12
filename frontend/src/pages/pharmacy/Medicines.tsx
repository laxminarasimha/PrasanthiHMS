import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import type { Medicine, Stockist } from '../../types'

const TYPES = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Ointment', 'Drops', 'Sachet', 'Inhaler', 'Surgical', 'Other'].map((t) => ({ value: t, label: t }))

const blankMedicine = { name: '', type: 'Tablet', manufacturer: '', category: '', hsn_code: '', gst_percent: '12', units_per_pack: '10', reorder_level: '0', is_active: true }
const blankStockist = { name: '', phone: '', gstin: '', address: '' }

type Editing = { kind: 'medicine'; id: number | null } | { kind: 'stockist'; id: number | null } | null

export function PharmacyMedicines() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [stockists, setStockists] = useState<Stockist[]>([])
  const [query, setQuery] = useState('')
  const [editing, setEditing] = useState<Editing>(null)
  const [medicine, setMedicine] = useState(blankMedicine)
  const [stockist, setStockist] = useState(blankStockist)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<Medicine[]>('/pharmacy/medicines', { params: { all: 1, q: query.trim() || undefined } }).then((res) => setMedicines(res.data))
  }, [query])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const loadStockists = useCallback(() => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
  }, [])
  useEffect(loadStockists, [loadStockists])

  const openMedicine = (m: Medicine | null) => {
    setEditing({ kind: 'medicine', id: m?.id ?? null })
    setErrors({})
    setNotice('')
    setMedicine(
      m
        ? {
            name: m.name, type: m.type ?? '', manufacturer: m.manufacturer ?? '', category: m.category ?? '', hsn_code: m.hsn_code ?? '',
            gst_percent: String(m.gst_percent), units_per_pack: String(m.units_per_pack), reorder_level: String(m.reorder_level), is_active: m.is_active,
          }
        : blankMedicine,
    )
  }

  const openStockist = (s: Stockist | null) => {
    setEditing({ kind: 'stockist', id: s?.id ?? null })
    setErrors({})
    setNotice('')
    setStockist(s ? { name: s.name, phone: s.phone ?? '', gstin: s.gstin ?? '', address: s.address ?? '' } : blankStockist)
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!editing) return
    setSaving(true)
    setErrors({})
    const base = editing.kind === 'medicine' ? '/pharmacy/medicines' : '/pharmacy/stockists'
    const payload = editing.kind === 'medicine' ? medicine : stockist
    try {
      const res = editing.id ? await api.put<{ name: string }>(`${base}/${editing.id}`, payload) : await api.post<{ name: string }>(base, payload)
      setNotice(`${res.data.name} was ${editing.id ? 'updated' : 'added'}.`)
      setEditing(null)
      load()
      loadStockists()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const setM = (key: keyof typeof blankMedicine) => (value: string) => setMedicine((f) => ({ ...f, [key]: value }))
  const setS = (key: keyof typeof blankStockist) => (value: string) => setStockist((f) => ({ ...f, [key]: value }))

  return (
    <>
      <PharmacyTabs />
      <div className="split">
        <div className="stack grow">
          <section className="card">
            <div className="toolbar">
              <h1>Medicines</h1>
              <input className="search" type="search" placeholder="Filter by name or manufacturer" value={query} onChange={(e) => setQuery(e.target.value)} />
              {canEdit && (
                <button className="btn primary" onClick={() => openMedicine(null)}>
                  + New medicine
                </button>
              )}
            </div>
            {notice && <p className="notice">{notice}</p>}
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Medicine</th>
                    <th>Type</th>
                    <th>Manufacturer</th>
                    <th className="num">Per pack</th>
                    <th className="num">GST %</th>
                    <th className="num">In stock</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {medicines.map((m) => (
                    <tr key={m.id} onClick={() => openMedicine(m)} className={editing?.kind === 'medicine' && editing.id === m.id ? 'selected' : ''}>
                      <td>
                        <strong>{m.name}</strong>
                      </td>
                      <td>{m.type ?? '—'}</td>
                      <td>{m.manufacturer ?? '—'}</td>
                      <td className="num">{m.units_per_pack}</td>
                      <td className="num">{m.gst_percent}</td>
                      <td className="num">
                        {m.units_in_stock}{' '}
                        {m.is_active && m.units_in_stock <= m.reorder_level && <span className="pill off">{m.units_in_stock === 0 ? 'Out of stock' : 'Low'}</span>}
                      </td>
                      <td>
                        <span className={m.is_active ? 'pill ok' : 'pill off'}>{m.is_active ? 'Active' : 'Hidden'}</span>
                      </td>
                    </tr>
                  ))}
                  {medicines.length === 0 && (
                    <tr className="empty">
                      <td colSpan={7}>No medicines found.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>

          <section className="card">
            <div className="toolbar">
              <h1 className="grow">Stockists</h1>
              {canEdit && (
                <button className="btn ghost" onClick={() => openStockist(null)}>
                  + New stockist
                </button>
              )}
            </div>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>GSTIN</th>
                    <th>Address</th>
                  </tr>
                </thead>
                <tbody>
                  {stockists.map((s) => (
                    <tr key={s.id} onClick={() => openStockist(s)} className={editing?.kind === 'stockist' && editing.id === s.id ? 'selected' : ''}>
                      <td>
                        <strong>{s.name}</strong>
                      </td>
                      <td className="mono">{s.phone ?? '—'}</td>
                      <td className="mono">{s.gstin ?? '—'}</td>
                      <td>{s.address ?? '—'}</td>
                    </tr>
                  ))}
                  {stockists.length === 0 && (
                    <tr className="empty">
                      <td colSpan={4}>No stockists yet.</td>
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
                {editing.id ? 'Edit' : 'Add'} {editing.kind}
              </h2>
              <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
                Close
              </button>
            </div>
            <fieldset disabled={!canEdit}>
              {editing.kind === 'medicine' ? (
                <div className="grid">
                  <div className="span-2">
                    <TextField label="Name and strength" required placeholder="Paracetamol 650mg Tablet" value={medicine.name} onChange={setM('name')} error={errors.name} autoFocus />
                  </div>
                  <SelectField label="Type" value={medicine.type} onChange={setM('type')} options={TYPES} placeholder="Select" error={errors.type} />
                  <TextField label="Manufacturer" value={medicine.manufacturer} onChange={setM('manufacturer')} error={errors.manufacturer} />
                  <TextField label="Category" placeholder="Scheduled drug" value={medicine.category} onChange={setM('category')} error={errors.category} />
                  <TextField label="HSN code" value={medicine.hsn_code} onChange={setM('hsn_code')} error={errors.hsn_code} />
                  <TextField label="Units per pack" required type="number" min={1} value={medicine.units_per_pack} onChange={setM('units_per_pack')} error={errors.units_per_pack} />
                  <TextField label="GST %" required type="number" min={0} step="0.01" value={medicine.gst_percent} onChange={setM('gst_percent')} error={errors.gst_percent} />
                  <TextField label="Low-stock alert at (units)" type="number" min={0} value={medicine.reorder_level} onChange={setM('reorder_level')} error={errors.reorder_level} />
                  {editing.id && (
                    <label className="check span-2">
                      <input type="checkbox" checked={medicine.is_active} onChange={(e) => setMedicine((f) => ({ ...f, is_active: e.target.checked }))} />
                      Active (untick to hide it from billing)
                    </label>
                  )}
                </div>
              ) : (
                <div className="grid">
                  <div className="span-2">
                    <TextField label="Stockist name" required value={stockist.name} onChange={setS('name')} error={errors.name} autoFocus />
                  </div>
                  <TextField label="Phone" inputMode="tel" value={stockist.phone} onChange={setS('phone')} error={errors.phone} />
                  <TextField label="GSTIN" value={stockist.gstin} onChange={setS('gstin')} error={errors.gstin} />
                  <div className="span-2">
                    <TextField label="Address" value={stockist.address} onChange={setS('address')} error={errors.address} />
                  </div>
                </div>
              )}
            </fieldset>
            {errors._ && <p className="form-error">{errors._}</p>}
            {canEdit && (
              <div className="panel-foot">
                <button className="btn primary" disabled={saving}>
                  {saving ? 'Saving…' : 'Save'}
                </button>
              </div>
            )}
          </form>
        )}
      </div>
    </>
  )
}
