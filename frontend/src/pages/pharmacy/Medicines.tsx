import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { downloadCsv, rupees, today } from '../../format'
import type { Manufacturer, Medicine, Stockist } from '../../types'

const TYPES = ['Tablet', 'Capsule', 'Chewable Tablet', 'Dispersible Tablet', 'Syrup', 'Suspension', 'Solution', 'Injection', 'Ointment', 'Cream', 'Gel', 'Lotion', 'Drops', 'Powder', 'Sachet', 'Inhaler', 'Spray', 'Suppository', 'Surgical', 'Other'].map((t) => ({ value: t, label: t }))
const CATEGORIES = ['General', 'Scheduled Drug H', 'Scheduled Drug H1', 'Scheduled Drug X', 'Fast Moving', 'Emergency Medicine', 'High Risk Medicine', 'Surgical and consumables']
const VED = [{ value: 'V', label: 'Vital' }, { value: 'E', label: 'Essential' }, { value: 'D', label: 'Desirable' }]
const SDE = [{ value: 'S', label: 'Scarce' }, { value: 'D', label: 'Difficult to get' }, { value: 'E', label: 'Easy to get' }]
const FSN = [{ value: 'F', label: 'Fast moving' }, { value: 'S', label: 'Slow moving' }, { value: 'N', label: 'Non-moving' }]
const classLabel = (list: { value: string; label: string }[], value?: string | null) => list.find((o) => o.value === value)?.label

const blankMedicine = { name: '', type: 'Tablet', manufacturer: '', category: '', sub_category: '', ved: '', sde: '', fsn: '', hsn_code: '', gst_percent: '12', units_per_pack: '10', reorder_level: '0', is_active: true, is_scheduled: false }
const blankStockist = { name: '', phone: '', email: '', gstin: '', address: '' }

type Editing = { kind: 'medicine' | 'stockist' | 'manufacturer'; id: number | null } | null

export function PharmacyMedicines() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [stockists, setStockists] = useState<Stockist[]>([])
  const [manufacturers, setManufacturers] = useState<Manufacturer[]>([])
  const [manufacturerName, setManufacturerName] = useState('')
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState({ category: '', ved: '', sde: '', fsn: '' })
  const [editing, setEditing] = useState<Editing>(null)
  const [medicine, setMedicine] = useState(blankMedicine)
  const [stockist, setStockist] = useState(blankStockist)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<Medicine[]>('/pharmacy/medicines', { params: { all: 1, q: query.trim() || undefined, category: filter.category || undefined, ved: filter.ved || undefined, sde: filter.sde || undefined, fsn: filter.fsn || undefined } }).then((res) => setMedicines(res.data))
  }, [query, filter])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const loadStockists = useCallback(() => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
    api.get<Manufacturer[]>('/pharmacy/manufacturers').then((res) => setManufacturers(res.data))
  }, [])
  useEffect(loadStockists, [loadStockists])

  const openMedicine = (m: Medicine | null) => {
    setEditing({ kind: 'medicine', id: m?.id ?? null })
    setErrors({})
    setNotice('')
    setMedicine(
      m
        ? {
            name: m.name, type: m.type ?? '', manufacturer: m.manufacturer ?? '', category: m.category ?? '', sub_category: m.sub_category ?? '', ved: m.ved ?? '', sde: m.sde ?? '', fsn: m.fsn ?? '', hsn_code: m.hsn_code ?? '',
            gst_percent: String(m.gst_percent), units_per_pack: String(m.units_per_pack), reorder_level: String(m.reorder_level), is_active: m.is_active,
            is_scheduled: m.is_scheduled,
          }
        : blankMedicine,
    )
  }

  const openStockist = (s: Stockist | null) => {
    setEditing({ kind: 'stockist', id: s?.id ?? null })
    setErrors({})
    setNotice('')
    setStockist(s ? { name: s.name, phone: s.phone ?? '', email: s.email ?? '', gstin: s.gstin ?? '', address: s.address ?? '' } : blankStockist)
  }

  const openManufacturer = (m: Manufacturer | null) => {
    setEditing({ kind: 'manufacturer', id: m?.id ?? null })
    setErrors({})
    setNotice('')
    setManufacturerName(m?.name ?? '')
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!editing) return
    setSaving(true)
    setErrors({})
    const base = { medicine: '/pharmacy/medicines', stockist: '/pharmacy/stockists', manufacturer: '/pharmacy/manufacturers' }[editing.kind]
    const payload = editing.kind === 'medicine' ? { ...medicine, ved: medicine.ved || null, sde: medicine.sde || null, fsn: medicine.fsn || null } : editing.kind === 'stockist' ? stockist : { name: manufacturerName }
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
              <select className="narrow" value={filter.category} onChange={(e) => setFilter({ ...filter, category: e.target.value })} aria-label="Category">
                <option value="">Any category</option>
                {CATEGORIES.map((c) => (
                  <option key={c}>{c}</option>
                ))}
              </select>
              {([['ved', VED, 'VED'], ['sde', SDE, 'SDE'], ['fsn', FSN, 'FSN']] as const).map(([key, list, name]) => (
                <select key={key} className="narrow" value={filter[key]} onChange={(e) => setFilter({ ...filter, [key]: e.target.value })} aria-label={`${name} class`}>
                  <option value="">Any {name}</option>
                  {list.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              ))}
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
                    <th>Category and class</th>
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
                        <strong>{m.name}</strong> {m.is_scheduled && <span className="pill status-ongoing">Scheduled</span>}
                      </td>
                      <td>{m.type ?? '—'}</td>
                      <td>{m.manufacturer ?? '—'}</td>
                      <td>
                        {m.category ?? '—'}
                        {m.sub_category ? ` · ${m.sub_category}` : ''}
                        {(m.ved || m.sde || m.fsn) && <small className="muted block">{[classLabel(VED, m.ved), classLabel(SDE, m.sde), classLabel(FSN, m.fsn)].filter(Boolean).join(' · ')}</small>}
                      </td>
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
                      <td colSpan={8}>No medicines found.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>

          <section className="card">
            <div className="toolbar">
              <h1>Stockists</h1>
              <span className="muted grow">
                We owe in all: <strong className={stockists.some((s) => (s.balance ?? 0) > 0) ? 'due' : ''}>{rupees(stockists.reduce((sum, s) => sum + Math.max(0, s.balance ?? 0), 0))}</strong>
              </span>
              <button
                className="btn ghost"
                disabled={stockists.length === 0}
                onClick={() => downloadCsv(`stockists-${today()}.csv`, ['Name', 'Phone', 'Email', 'GSTIN', 'Address', 'Invoices', 'Billed', 'Paid', 'Returned', 'We owe'], stockists.map((s) => [s.name, s.phone, s.email, s.gstin, s.address, s.invoices ?? 0, s.billed ?? 0, s.paid ?? 0, s.returned ?? 0, s.balance ?? 0]))}
              >
                Export
              </button>
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
                    <th>Phone and email</th>
                    <th>GSTIN</th>
                    <th className="num">Invoices</th>
                    <th className="num">Billed</th>
                    <th className="num">Paid</th>
                    <th className="num">Returned</th>
                    <th className="num">We owe</th>
                  </tr>
                </thead>
                <tbody>
                  {stockists.map((s) => (
                    <tr key={s.id} onClick={() => openStockist(s)} className={editing?.kind === 'stockist' && editing.id === s.id ? 'selected' : ''}>
                      <td>
                        <strong>{s.name}</strong>
                      </td>
                      <td className="mono">
                        {s.phone ?? '—'}
                        {s.email && <small className="muted block">{s.email}</small>}
                      </td>
                      <td className="mono">{s.gstin ?? '—'}</td>
                      <td className="num">{s.invoices ?? 0}</td>
                      <td className="num">{rupees(s.billed ?? 0)}</td>
                      <td className="num">{rupees(s.paid ?? 0)}</td>
                      <td className="num">{s.returned ? rupees(s.returned) : '—'}</td>
                      <td className={`num ${(s.balance ?? 0) > 0 ? 'due' : 'settled'}`}>{rupees(s.balance ?? 0)}</td>
                    </tr>
                  ))}
                  {stockists.length === 0 && (
                    <tr className="empty">
                      <td colSpan={8}>No stockists yet.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>

          <section className="card">
            <div className="toolbar">
              <h1 className="grow">Manufacturers</h1>
              {canEdit && (
                <button className="btn ghost" onClick={() => openManufacturer(null)}>
                  + New manufacturer
                </button>
              )}
            </div>
            <div className="chips">
              {manufacturers.map((m) => (
                <button key={m.id} className={`chip ${editing?.kind === 'manufacturer' && editing.id === m.id ? 'absent' : ''}`} onClick={() => openManufacturer(m)}>
                  {m.name}
                </button>
              ))}
              {manufacturers.length === 0 && <span className="muted">Manufacturers are added here, or automatically when you type one on a medicine.</span>}
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
                  <label className="field">
                    <span className="field-label">Manufacturer</span>
                    <input list="manufacturer-names" value={medicine.manufacturer} onChange={(e) => setM('manufacturer')(e.target.value)} />
                    <datalist id="manufacturer-names">
                      {manufacturers.map((m) => (
                        <option key={m.id} value={m.name} />
                      ))}
                    </datalist>
                  </label>
                  <label className="field">
                    <span className="field-label">Category</span>
                    <input list="medicine-categories" placeholder="Choose or type" value={medicine.category} onChange={(e) => setM('category')(e.target.value)} />
                    <datalist id="medicine-categories">
                      {CATEGORIES.map((c) => (
                        <option key={c} value={c} />
                      ))}
                    </datalist>
                  </label>
                  <TextField label="Sub category" placeholder="e.g. Antibiotic" value={medicine.sub_category} onChange={setM('sub_category')} error={errors.sub_category} />
                  <SelectField label="How vital (VED)" value={medicine.ved} onChange={setM('ved')} options={VED} placeholder="Not set" error={errors.ved} />
                  <SelectField label="How easy to get (SDE)" value={medicine.sde} onChange={setM('sde')} options={SDE} placeholder="Not set" error={errors.sde} />
                  <SelectField label="How fast it sells (FSN)" value={medicine.fsn} onChange={setM('fsn')} options={FSN} placeholder="Not set" error={errors.fsn} />
                  <TextField label="HSN code" value={medicine.hsn_code} onChange={setM('hsn_code')} error={errors.hsn_code} />
                  <TextField label="Units per pack" required type="number" min={1} value={medicine.units_per_pack} onChange={setM('units_per_pack')} error={errors.units_per_pack} />
                  <TextField label="GST %" required type="number" min={0} step="0.01" value={medicine.gst_percent} onChange={setM('gst_percent')} error={errors.gst_percent} />
                  <TextField label="Low-stock alert at (units)" type="number" min={0} value={medicine.reorder_level} onChange={setM('reorder_level')} error={errors.reorder_level} />
                  <label className="check span-2">
                    <input type="checkbox" checked={medicine.is_scheduled} onChange={(e) => setMedicine((f) => ({ ...f, is_scheduled: e.target.checked }))} />
                    Scheduled drug (sold only on prescription; listed in the schedule drug report)
                  </label>
                  {editing.id && (
                    <label className="check span-2">
                      <input type="checkbox" checked={medicine.is_active} onChange={(e) => setMedicine((f) => ({ ...f, is_active: e.target.checked }))} />
                      Active (untick to hide it from billing)
                    </label>
                  )}
                </div>
              ) : editing.kind === 'manufacturer' ? (
                <>
                  <TextField label="Manufacturer name" required value={manufacturerName} onChange={setManufacturerName} error={errors.name} autoFocus />
                  {editing.id && <p className="muted small">Renaming also updates every medicine that carries the old name.</p>}
                </>
              ) : (
                <div className="grid">
                  <div className="span-2">
                    <TextField label="Stockist name" required value={stockist.name} onChange={setS('name')} error={errors.name} autoFocus />
                  </div>
                  <TextField label="Phone" inputMode="tel" value={stockist.phone} onChange={setS('phone')} error={errors.phone} />
                  <TextField label="GSTIN" value={stockist.gstin} onChange={setS('gstin')} error={errors.gstin} />
                  <div className="span-2">
                    <TextField label="Email" type="email" value={stockist.email} onChange={setS('email')} error={errors.email} />
                  </div>
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
