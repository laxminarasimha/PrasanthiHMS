import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import type { Medicine } from '../../types'

type Line = { medicine_id: string; batch_no: string; expiry: string; units_per_pack: string; quantity: string; mrp: string; cost_price: string; gst_percent: string }
const emptyLine = (): Line => ({ medicine_id: '', batch_no: '', expiry: '', units_per_pack: '', quantity: '', mrp: '', cost_price: '', gst_percent: '' })

/** For stock already on the shelf that has no purchase invoice in this system. */
export function PharmacyOpeningStock() {
  const { user } = useAuth()
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [errors, setErrors] = useState<string[]>([])
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get<Medicine[]>('/pharmacy/medicines').then((res) => setMedicines(res.data))
  }, [])

  const setLine = (index: number, key: keyof Line, value: string) =>
    setLines((rows) =>
      rows.map((row, i) => {
        if (i !== index) return row
        const next = { ...row, [key]: value }
        if (key === 'medicine_id') {
          const medicine = medicines.find((m) => String(m.id) === value)
          if (medicine) {
            next.units_per_pack = String(medicine.units_per_pack)
            next.gst_percent = String(medicine.gst_percent)
          }
        }
        return next
      }),
    )

  const filled = lines.filter((l) => l.medicine_id || l.batch_no || l.quantity)

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors([])
    setNotice('')
    try {
      const res = await api.post<{ added: number }>('/pharmacy/opening-stock', { items: filled })
      setNotice(`${res.data.added} batch(es) added to stock.`)
      setLines([emptyLine()])
    } catch (err) {
      setErrors([...new Set(Object.values(fieldErrors(err)))])
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PharmacyTabs />
      <form className="card" onSubmit={save} noValidate>
        <div className="toolbar">
          <h1 className="grow">Opening stock</h1>
        </div>
        <p className="muted small">Use this for stock that is already on the shelf and has no purchase invoice here. Quantity is in single units (tablets, bottles); MRP and cost are per pack. If a medicine is missing, add it under Medicines first.</p>
        {notice && <p className="notice">{notice}</p>}
        <div className="rx">
          <div className="opening-row rx-head">
            <span>Medicine</span>
            <span>Batch</span>
            <span>Expiry</span>
            <span>Units/pack</span>
            <span>Quantity (units)</span>
            <span>MRP/pack</span>
            <span>Cost/pack</span>
            <span>GST %</span>
            <span />
          </div>
          {lines.map((l, i) => (
            <div className="opening-row" key={i}>
              <select value={l.medicine_id} onChange={(e) => setLine(i, 'medicine_id', e.target.value)} aria-label={`Medicine ${i + 1}`}>
                <option value="">Select medicine</option>
                {medicines.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.name}
                  </option>
                ))}
              </select>
              <input value={l.batch_no} onChange={(e) => setLine(i, 'batch_no', e.target.value)} aria-label="Batch number" />
              <input type="month" value={l.expiry} onChange={(e) => setLine(i, 'expiry', e.target.value)} aria-label="Expiry month" />
              <input type="number" min={1} value={l.units_per_pack} onChange={(e) => setLine(i, 'units_per_pack', e.target.value)} aria-label="Units per pack" />
              <input type="number" min={1} value={l.quantity} onChange={(e) => setLine(i, 'quantity', e.target.value)} aria-label="Quantity in units" />
              <input type="number" min={0} step="0.01" value={l.mrp} onChange={(e) => setLine(i, 'mrp', e.target.value)} aria-label="MRP per pack" />
              <input type="number" min={0} step="0.01" value={l.cost_price} onChange={(e) => setLine(i, 'cost_price', e.target.value)} aria-label="Cost per pack" />
              <input type="number" min={0} step="0.01" value={l.gst_percent} onChange={(e) => setLine(i, 'gst_percent', e.target.value)} aria-label="GST percent" />
              <button type="button" className="link danger" onClick={() => setLines((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyLine()]))}>
                Remove
              </button>
            </div>
          ))}
        </div>
        <button type="button" className="link add-medicine" onClick={() => setLines((rows) => [...rows, emptyLine()])}>
          + Add another medicine
        </button>
        {errors.length > 0 && (
          <div className="form-error">
            {errors.map((message) => (
              <div key={message}>{message}</div>
            ))}
          </div>
        )}
        <div className="panel-foot">
          <button className="btn primary" disabled={saving || filled.length === 0 || !user?.can_run_pharmacy}>
            {saving ? 'Saving…' : 'Save stock'}
          </button>
        </div>
      </form>
    </>
  )
}
