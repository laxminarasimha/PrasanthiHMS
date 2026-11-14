import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { OpeningStockImport } from '../../components/OpeningStockImport'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { rupees } from '../../format'
import type { Medicine } from '../../types'

type Line = { medicine_id: string; batch_no: string; expiry: string; units_per_pack: string; quantity: string; mrp: string; cost_price: string; gst_percent: string; rack_no: string; box_no: string; hsn_code: string }
const emptyLine = (): Line => ({ medicine_id: '', batch_no: '', expiry: '', units_per_pack: '', quantity: '', mrp: '', cost_price: '', gst_percent: '', rack_no: '', box_no: '', hsn_code: '' })

/** What a line of stock is worth: per unit and in all, at cost and at MRP. */
const worth = (l: Line) => {
  const perPack = Number(l.units_per_pack || 0)
  const units = Number(l.quantity || 0)
  if (!perPack || !units) return null
  const cost = Number(l.cost_price || 0) / perPack
  const mrp = Number(l.mrp || 0) / perPack
  return { units, packs: Math.floor(units / perPack), loose: units % perPack, cost, mrp, atCost: cost * units, atMrp: mrp * units, margin: mrp ? ((mrp - cost) / mrp) * 100 : null }
}
const two = (n: number) => Math.round(n * 100) / 100

/** For stock already on the shelf that has no purchase invoice in this system. */
export function PharmacyOpeningStock() {
  const { user } = useAuth()
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [errors, setErrors] = useState<string[]>([])
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [way, setWay] = useState<'type' | 'sheet'>('type')

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
            next.hsn_code = medicine.hsn_code ?? ''
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
      const res = await api.post<{ added: number }>('/pharmacy/opening-stock', { items: filled.map((l) => ({ ...l, rack_no: l.rack_no || null, box_no: l.box_no || null, hsn_code: l.hsn_code || null })) })
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
      <div className="tabs no-print">
        <button type="button" className={way === 'type' ? 'active' : ''} onClick={() => setWay('type')}>
          Type it in
        </button>
        <button type="button" className={way === 'sheet' ? 'active' : ''} onClick={() => setWay('sheet')}>
          Import a sheet
        </button>
      </div>
      {way === 'sheet' && (
        <>
          {notice && <p className="notice">{notice}</p>}
          <OpeningStockImport canSave={Boolean(user?.can_run_pharmacy)} onAdded={setNotice} />
        </>
      )}
      <form className="card" onSubmit={save} noValidate hidden={way !== 'type'}>
        <div className="toolbar">
          <h1 className="grow">Opening stock</h1>
        </div>
        <p className="muted small">Use this for stock that is already on the shelf and has no purchase invoice here. Count what is on the shelf in single units (tablets, bottles); the purchase rate and MRP are per pack, as printed on the strip and the bill. If a medicine is missing, add it under Medicines first.</p>
        {notice && <p className="notice">{notice}</p>}
        {lines.map((l, i) => {
          const w = worth(l)
          const num = (key: keyof Line, label: string, step?: string, min = 0) => (
            <label>
              {label}
              <input type="number" min={min} step={step} value={l[key]} onChange={(e) => setLine(i, key, e.target.value)} />
            </label>
          )
          return (
            <div className="purchase-line" key={i}>
              <div className="purchase-fields">
                <label className="wide">
                  Medicine
                  <select value={l.medicine_id} onChange={(e) => setLine(i, 'medicine_id', e.target.value)} aria-label={`Medicine ${i + 1}`}>
                    <option value="">Select medicine</option>
                    {medicines.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.name}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  Batch
                  <input value={l.batch_no} onChange={(e) => setLine(i, 'batch_no', e.target.value)} />
                </label>
                <label>
                  Expiry
                  <input type="month" value={l.expiry} onChange={(e) => setLine(i, 'expiry', e.target.value)} />
                </label>
                {num('units_per_pack', 'Units in a pack', undefined, 1)}
                {num('quantity', 'On the shelf (units)', undefined, 1)}
                {num('cost_price', 'Purchase rate / pack ₹', '0.01')}
                {num('mrp', 'MRP / pack ₹', '0.01')}
                {num('gst_percent', 'GST %', '0.01')}
                <label>
                  HSN code
                  <input maxLength={20} value={l.hsn_code} onChange={(e) => setLine(i, 'hsn_code', e.target.value)} />
                </label>
                <label>
                  Rack
                  <input maxLength={30} value={l.rack_no} onChange={(e) => setLine(i, 'rack_no', e.target.value)} />
                </label>
                <label>
                  Box
                  <input maxLength={30} value={l.box_no} onChange={(e) => setLine(i, 'box_no', e.target.value)} />
                </label>
              </div>
              <div className="purchase-foot">
                <span className="grow purchase-cost">
                  {w ? (
                    <>
                      <b>{w.units}</b> units = {w.packs} pack{w.packs === 1 ? '' : 's'}
                      {w.loose ? ` + ${w.loose} loose` : ''}
                      {Number(l.cost_price) > 0 && (
                        <>
                          {' '}· cost <b>{rupees(two(w.cost))}</b> a unit, worth {rupees(two(w.atCost))}
                        </>
                      )}
                      {w.mrp > 0 && (
                        <>
                          {' '}· MRP {rupees(two(w.mrp))} a unit, worth {rupees(two(w.atMrp))}
                          {Number(l.cost_price) > 0 && w.margin !== null && (
                            <>
                              {' '}· margin <b className={w.margin < 0 ? 'due' : 'settled'}>{w.margin.toFixed(1)}%</b>
                            </>
                          )}
                        </>
                      )}
                    </>
                  ) : (
                    <span className="muted">Fill in the pack size and the count on the shelf to see what it is worth.</span>
                  )}
                </span>
                <button type="button" className="link danger" onClick={() => setLines((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyLine()]))}>
                  Remove
                </button>
              </div>
            </div>
          )
        })}
        <button type="button" className="link add-medicine" onClick={() => setLines((rows) => [...rows, emptyLine()])}>
          + Add another medicine
        </button>
        {filled.some((l) => worth(l)) && (
          <dl className="totals narrow-totals">
            <dt>Stock being added, at cost</dt>
            <dd>{rupees(two(filled.reduce((sum, l) => sum + (worth(l)?.atCost ?? 0), 0)))}</dd>
            <dt className="strong">At MRP</dt>
            <dd className="strong">{rupees(two(filled.reduce((sum, l) => sum + (worth(l)?.atMrp ?? 0), 0)))}</dd>
          </dl>
        )}
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
