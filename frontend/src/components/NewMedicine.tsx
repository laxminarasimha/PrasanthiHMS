import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { MEDICINE_CATEGORIES, MEDICINE_TYPES } from '../pages/pharmacy/medicineChoices'
import type { Medicine } from '../types'
import { SelectField, TextField } from './Field'
import { Popup } from './Popup'

type Start = { name?: string; units_per_pack?: string; gst_percent?: string; hsn_code?: string; rack_no?: string; box_no?: string }

/**
 * Adds a medicine the pharmacy has never stocked, without leaving the invoice or order being typed: the essentials
 * only, the rest can be filled in later under Medicines. The new medicine goes straight into the line it was asked
 * for from.
 */
export function NewMedicine({ start, onClose, onAdded }: { start?: Start; onClose: () => void; onAdded: (medicine: Medicine) => void }) {
  const [form, setForm] = useState({
    name: start?.name ?? '', type: '', composition: '', manufacturer: '', category: '', units_per_pack: start?.units_per_pack || '1', gst_percent: start?.gst_percent || '12',
    hsn_code: start?.hsn_code ?? '', rack_no: start?.rack_no ?? '', box_no: start?.box_no ?? '', is_scheduled: false,
  })
  const [makers, setMakers] = useState<{ id: number; name: string }[]>([])
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    api.get<{ id: number; name: string }[]>('/pharmacy/manufacturers').then((res) => setMakers(res.data)).catch(() => undefined)
  }, [])
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (saving) return
    setSaving(true)
    setErrors({})
    const body = Object.fromEntries(Object.entries({ ...form, name: form.name.trim() }).map(([k, v]) => [k, typeof v === 'string' && v.trim() === '' ? null : v]))
    try {
      onAdded((await api.post<Medicine>('/pharmacy/medicines', body)).data)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Popup
      title="Add a new medicine"
      sub="It goes into this line straight away. Anything not filled in here can be added later under Medicines."
      onClose={onClose}
      onSubmit={save}
      foot={
        <>
          <button type="button" className="btn ghost" onClick={onClose}>
            Cancel
          </button>
          <button className="btn primary" disabled={saving || !form.name.trim() || !form.units_per_pack || form.gst_percent === ''}>
            {saving ? 'Adding…' : 'Add and use it'}
          </button>
        </>
      }
    >
      <TextField label="Name, as on the strip or bottle" required value={form.name} onChange={set('name')} placeholder="Dolo 650mg Tablet" error={errors.name} />
      <div className="grid three">
        <SelectField label="Form" value={form.type} onChange={set('type')} options={MEDICINE_TYPES} placeholder="Choose" error={errors.type} />
        <TextField label="Units in a pack" required type="number" min={1} value={form.units_per_pack} onChange={set('units_per_pack')} error={errors.units_per_pack} />
        <TextField label="GST %" required type="number" min={0} max={40} step="0.01" value={form.gst_percent} onChange={set('gst_percent')} error={errors.gst_percent} />
      </div>
      <TextField label="Composition" value={form.composition} onChange={set('composition')} placeholder="Paracetamol 650mg" error={errors.composition} />
      <div className="grid">
        <div>
          <TextField label="Manufacturer" list="new-medicine-makers" value={form.manufacturer} onChange={set('manufacturer')} error={errors.manufacturer} />
          <datalist id="new-medicine-makers">
            {makers.map((m) => (
              <option key={m.id} value={m.name} />
            ))}
          </datalist>
        </div>
        <SelectField label="Category" value={form.category} onChange={set('category')} options={MEDICINE_CATEGORIES.map((c) => ({ value: c, label: c }))} placeholder="None" error={errors.category} />
      </div>
      <div className="grid three">
        <TextField label="HSN code" maxLength={20} value={form.hsn_code} onChange={set('hsn_code')} error={errors.hsn_code} />
        <TextField label="Rack" maxLength={30} value={form.rack_no} onChange={set('rack_no')} error={errors.rack_no} />
        <TextField label="Box" maxLength={30} value={form.box_no} onChange={set('box_no')} error={errors.box_no} />
      </div>
      <label className="check">
        <input type="checkbox" checked={form.is_scheduled} onChange={(e) => setForm((f) => ({ ...f, is_scheduled: e.target.checked }))} />
        A scheduled drug (sold only on a prescription)
      </label>
    </Popup>
  )
}
