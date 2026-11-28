import { useCallback, useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { parseCsv, rupees } from '../format'
import { SelectField, TextField } from './Field'
import { MedicineLink } from './MedicineLink'

type Company = { id: number; name: string; products_count?: number }
type Product = {
  id: number
  rep_company_id: number
  name: string
  composition: string | null
  form: string | null
  pack: string | null
  mrp: number | null
  rate: number | null
  scheme: string | null
  notes: string | null
  uses?: string | null
  medicine_id: number | null
  /** The company, with the stockist its brands come through. */
  company?: { id: number; name: string; stockist_id?: number | null; stockist?: { id: number; name: string } | null }
  /** The pharmacy's own medicine of the same name: what an order of this brand is for. Whom it is usually bought from is told only to the pharmacy. */
  medicine?: { id: number; name: string; units_per_pack: number; is_active: boolean; preferred_stockist_id?: number | null } | null
  /** Units of that medicine on order and not in yet. Told only to the pharmacy. */
  on_order?: number
  /** The pharmacy's own medicines that contain the same thing. */
  in_pharmacy: { name: string; on_shelf: number }[]
  /** The same thing in other companies' catalogues. */
  also_from: { id: number; name: string; company: string | null; mrp: number | null; rate: number | null; scheme: string | null }[]
}
/** `can_order` is sent only to the pharmacy: true for those who run it, who may order a brand from here. */
type Answer = { can_change: boolean; can_order?: boolean; companies: Company[]; products: Product[] }
type Made = { id: number; order_no: string; stockist: string; items: number }
type SheetRow = { line: number; name: string; composition: string | null; mrp: number | null; rate: number | null; scheme: string | null; errors: string[]; already: boolean; missing_composition: boolean }
type SheetAnswer = { rows: SheetRow[]; totals: { lines: number; good: number; wrong: number; new: number; updated: number; without_composition: number }; saved: number }

const blank = { rep_company_id: '', name: '', composition: '', uses: '', form: '', pack: '', mrp: '', rate: '', scheme: '', notes: '' }
/** The headings a company's sheet may use for each thing we keep. The first column that fits is taken. */
const HEADINGS: Record<string, string[]> = {
  name: ['name', 'brand', 'brand name', 'product', 'product name', 'item', 'medicine'],
  composition: ['composition', 'contains', 'generic', 'generic name', 'molecule', 'salt', 'content'],
  form: ['form', 'type', 'dosage form'],
  pack: ['pack', 'packing', 'pack size'],
  mrp: ['mrp', 'm.r.p', 'm.r.p.', 'mrp per pack'],
  rate: ['rate', 'ptr', 'price', 'price to retailer', 'offer rate', 'hospital rate', 'net rate'],
  scheme: ['scheme', 'offer', 'free', 'bonus'],
}
const money = (amount: number | null) => (amount === null ? '' : rupees(amount))
/** The supplier a brand is first ordered from: its company's stockist, else the medicine's usual one, else none, to be chosen. */
const firstSupplier = (p: Product) => String(p.company?.stockist_id ?? p.medicine?.preferred_stockist_id ?? '')
const stop = (e: { stopPropagation: () => void }) => e.stopPropagation()

/**
 * The catalogues of the companies whose representatives call: each brand, what it contains and what is on offer,
 * set beside what the pharmacy keeps of the same drug. What a brand contains is typed from the catalogue or loaded
 * from the company's own sheet.
 */
export function RepCatalogue() {
  const [data, setData] = useState<Answer | null>(null)
  const [query, setQuery] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [draft, setDraft] = useState<typeof blank | null>(null)
  const [editing, setEditing] = useState<number | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [sheet, setSheet] = useState<{ companyId: string; rows: Record<string, string>[]; answer: SheetAnswer | null; error: string } | null>(null)
  // What has been chosen to order: for each brand, how many of the pharmacy's packs and from whom. Only for those who run the pharmacy.
  const [basket, setBasket] = useState<Record<number, { packs: string; stockist: string }>>({})
  const [stockists, setStockists] = useState<{ id: number; name: string }[]>([])
  const [made, setMade] = useState<Made[] | null>(null)
  const [ordering, setOrdering] = useState(false)
  const [orderError, setOrderError] = useState('')
  const canOrder = Boolean(data?.can_order)
  useEffect(() => {
    if (canOrder) api.get<{ id: number; name: string }[]>('/pharmacy/stockists').then((res) => setStockists(res.data)).catch(() => undefined)
  }, [canOrder])

  const load = useCallback(
    () => api.get<Answer>('/reps/products', { params: { q: query || undefined, company_id: companyId || undefined } }).then((res) => setData(res.data)).catch(() => setNotice('The catalogue could not be loaded.')),
    [query, companyId],
  )
  useEffect(() => {
    const wait = window.setTimeout(load, 250)
    return () => window.clearTimeout(wait)
  }, [load])

  const set = (key: keyof typeof blank) => (value: string) => setDraft((d) => (d ? { ...d, [key]: value } : d))
  const open = (p: Product | null) => {
    setErrors({})
    setEditing(p?.id ?? null)
    setDraft(
      p
        ? { rep_company_id: String(p.rep_company_id), name: p.name, composition: p.composition ?? '', uses: p.uses ?? '', form: p.form ?? '', pack: p.pack ?? '', mrp: p.mrp === null ? '' : String(p.mrp), rate: p.rate === null ? '' : String(p.rate), scheme: p.scheme ?? '', notes: p.notes ?? '' }
        : { ...blank, rep_company_id: companyId },
    )
  }
  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!draft) return
    setSaving(true)
    setErrors({})
    const body = Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v.trim() === '' ? null : v.trim()]))
    try {
      await (editing ? api.put(`/reps/products/${editing}`, body) : api.post('/reps/products', body))
      setNotice(editing ? 'Saved.' : `${draft.name} added to the catalogue.`)
      // The next brand of the same company can be typed straight away.
      setDraft(editing ? null : { ...blank, rep_company_id: draft.rep_company_id })
      setEditing(null)
      void load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const remove = async (p: Product) => {
    if (!window.confirm(`Take ${p.name} out of ${p.company?.name ?? 'the'} catalogue?`)) return
    await api.delete(`/reps/products/${p.id}`)
    setDraft(null)
    setEditing(null)
    void load()
  }

  // A company's sheet: read here, checked on the server, and only then taken in.
  const readSheet = async (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file || !sheet) return
    const table = parseCsv(await file.text()).filter((r) => r.some((c) => c.trim()))
    const heads = (table[0] ?? []).map((h) => h.trim().toLowerCase())
    const at = Object.fromEntries(Object.entries(HEADINGS).map(([field, names]) => [field, heads.findIndex((h) => names.includes(h))]))
    if (at.name < 0) return setSheet({ ...sheet, rows: [], answer: null, error: 'The sheet needs a heading called “Name” (or “Brand”) on its first line. The others it can have: Composition, Form, Pack, MRP, Rate, Scheme.' })
    const rows = table.slice(1).map((r) => Object.fromEntries(Object.entries(at).filter(([, i]) => i >= 0).map(([field, i]) => [field, r[i] ?? ''])))
    await checkSheet({ ...sheet, rows, answer: null, error: '' }, false)
  }
  const checkSheet = async (state: NonNullable<typeof sheet>, saveIt: boolean) => {
    setSaving(true)
    try {
      const res = await api.post<SheetAnswer>('/reps/products/sheet', { company_id: state.companyId, rows: state.rows, ...(saveIt ? { save: true } : {}) })
      if (saveIt) {
        setSheet(null)
        setNotice(`${res.data.saved} ${res.data.saved === 1 ? 'brand was' : 'brands were'} taken into the catalogue.`)
        void load()
      } else {
        setSheet({ ...state, answer: res.data })
      }
    } catch (err) {
      setSheet({ ...state, error: Object.values(fieldErrors(err))[0] ?? 'The sheet could not be read.' })
    } finally {
      setSaving(false)
    }
  }

  // Each brand chosen becomes a line on a draft order, one draft for each supplier. Nothing is ordered until it is approved.
  const chosen = Object.entries(basket)
  const ready = chosen.length > 0 && chosen.every(([, line]) => Number(line.packs) >= 1 && line.stockist)
  const order = async () => {
    setOrdering(true)
    setOrderError('')
    try {
      const res = await api.post<{ orders: Made[] }>('/pharmacy/manager/orders', { from: 'catalogue', lines: chosen.map(([id, line]) => ({ rep_product_id: Number(id), stockist_id: Number(line.stockist), packs: Number(line.packs) })) })
      setMade(res.data.orders)
      setBasket({})
      void load()
    } catch (err) {
      setOrderError(Object.values(fieldErrors(err))[0] ?? 'The draft orders were not made.')
    } finally {
      setOrdering(false)
    }
  }
  const putBack = (id: number) => setBasket(Object.fromEntries(Object.entries(basket).filter(([key]) => Number(key) !== id)))

  if (!data) return <p className="loading">Loading…</p>
  const companies = data.companies.map((c) => ({ value: String(c.id), label: c.name }))

  return (
    <>
      <div className="toolbar">
        <input className="search" type="search" placeholder="Find by brand, by a drug inside, or by company" value={query} onChange={(e) => setQuery(e.target.value)} />
        <select className="narrow" value={companyId} onChange={(e) => setCompanyId(e.target.value)} aria-label="Company">
          <option value="">All companies</option>
          {data.companies.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
              {c.products_count ? ` (${c.products_count})` : ''}
            </option>
          ))}
        </select>
        <span className="grow" />
        {data.can_change && (
          <>
            <button className="btn ghost" onClick={() => setSheet({ companyId, rows: [], answer: null, error: '' })}>
              Load a company’s sheet
            </button>
            <button className="btn primary" onClick={() => open(null)}>
              + Add a brand
            </button>
          </>
        )}
      </div>
      {notice && <p className="notice">{notice}</p>}
      {made && (
        <p className="notice">
          {made.length === 1 ? 'A draft order was made: ' : `${made.length} draft orders were made, one for each supplier: `}
          {made.map((o, i) => (
            <span key={o.id}>
              {i > 0 ? '; ' : ''}
              <Link to={`/pharmacy/purchase-orders?open=${o.id}`}>{o.order_no}</Link> to {o.stockist} ({o.items} {o.items === 1 ? 'medicine' : 'medicines'})
            </span>
          ))}
          . Nothing is ordered until it is approved on Purchase orders.
        </p>
      )}
      {data.companies.length === 0 && <p className="muted">No company is on the list yet. A company is added with its first representative, under “Representatives”.</p>}

      {draft && (
        <form className="catalogue-form" onSubmit={save} noValidate>
          <h3>{editing ? 'Change the brand' : 'Add a brand'}</h3>
          <div className="grid three">
            <SelectField label="Company" required value={draft.rep_company_id} onChange={set('rep_company_id')} options={companies} placeholder="Select company" error={errors.rep_company_id} />
            <TextField label="Brand name" required value={draft.name} onChange={set('name')} error={errors.name} />
            <TextField label="What it contains" value={draft.composition} onChange={set('composition')} placeholder="Pantoprazole 40 mg, or Drug A 5 mg + Drug B 10 mg" error={errors.composition} />
            <TextField label="What it is used for" value={draft.uses} onChange={set('uses')} error={errors.uses} />
            <TextField label="Form" value={draft.form} onChange={set('form')} placeholder="Tablet, syrup, injection…" error={errors.form} />
            <TextField label="Pack" value={draft.pack} onChange={set('pack')} placeholder="Strip of 10" error={errors.pack} />
            <TextField label="MRP of a pack (₹)" type="number" min={0} step="0.01" value={draft.mrp} onChange={set('mrp')} error={errors.mrp} />
            <TextField label="Price we get a pack at (₹)" type="number" min={0} step="0.01" value={draft.rate} onChange={set('rate')} error={errors.rate} />
            <TextField label="Offer or scheme" value={draft.scheme} onChange={set('scheme')} placeholder="10 + 1, and the like" error={errors.scheme} />
            <TextField label="Notes" value={draft.notes} onChange={set('notes')} error={errors.notes} />
          </div>
          <p className="muted small">Type what it contains as the catalogue prints it. It is what sets this brand beside the same drug in your pharmacy and in other companies’ catalogues.</p>
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button type="button" className="btn ghost" onClick={() => { setDraft(null); setEditing(null) }}>
              Close
            </button>
            <button className="btn primary" disabled={saving || !draft.rep_company_id || draft.name.trim().length < 2}>
              {saving ? 'Saving…' : editing ? 'Save' : 'Add to the catalogue'}
            </button>
          </div>
        </form>
      )}

      {sheet && (
        <div className="catalogue-form">
          <h3>Load a company’s sheet</h3>
          <p className="muted small">
            Save the company’s price list from Excel as a CSV file. Its first line must be headings: <strong>Name</strong> (or Brand), and any of Composition, Form, Pack, MRP, Rate, Scheme. Every line is checked first; nothing is taken in until
            you say so. A brand already in the catalogue is brought up to date.
          </p>
          <div className="row wrap">
            <select className="narrow" value={sheet.companyId} onChange={(e) => setSheet({ companyId: e.target.value, rows: [], answer: null, error: '' })} aria-label="Whose sheet it is">
              <option value="">Whose sheet is it?</option>
              {data.companies.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
            <label className={`btn ghost${sheet.companyId ? '' : ' disabled'}`}>
              Choose the CSV file
              <input type="file" accept=".csv,text/csv" hidden disabled={!sheet.companyId || saving} onChange={(e) => void readSheet(e)} />
            </label>
            <button type="button" className="link" onClick={() => setSheet(null)}>
              Close
            </button>
          </div>
          {sheet.error && <p className="form-error">{sheet.error}</p>}
          {sheet.answer && (
            <>
              <p className="gap-top">
                <strong>{sheet.answer.totals.lines}</strong> lines read: <strong>{sheet.answer.totals.new}</strong> new, <strong>{sheet.answer.totals.updated}</strong> already in the catalogue (will be brought up to date)
                {sheet.answer.totals.wrong > 0 && (
                  <>
                    , <strong className="due">{sheet.answer.totals.wrong} cannot be taken in</strong>
                  </>
                )}
                .{sheet.answer.totals.without_composition > 0 && ` ${sheet.answer.totals.without_composition} have nothing under what they contain, so they will not be set beside your pharmacy’s medicines until that is typed.`}
              </p>
              {sheet.answer.totals.wrong > 0 && (
                <ul className="history">
                  {sheet.answer.rows
                    .filter((r) => r.errors.length > 0)
                    .slice(0, 30)
                    .map((r) => (
                      <li key={r.line}>
                        <span>
                          Line {r.line}
                          {r.name ? ` · ${r.name}` : ''}
                          <small className="due block">{r.errors.join(' ')}</small>
                        </span>
                      </li>
                    ))}
                </ul>
              )}
              <div className="panel-foot">
                <button className="btn primary" disabled={saving || sheet.answer.totals.good === 0} onClick={() => void checkSheet(sheet, true)}>
                  {saving ? 'Saving…' : `Take in the ${sheet.answer.totals.good} good ${sheet.answer.totals.good === 1 ? 'line' : 'lines'}`}
                </button>
              </div>
            </>
          )}
        </div>
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Brand</th>
              <th>Company</th>
              <th>Contains</th>
              <th className="num">MRP</th>
              <th className="num">Price we get</th>
              <th>Offer</th>
              <th>In our pharmacy</th>
              <th>Same from others</th>
            </tr>
          </thead>
          <tbody>
            {data.products.map((p) => (
              <tr key={p.id} className={data.can_change ? '' : 'static'} onClick={() => data.can_change && open(p)}>
                <td>
                  <strong>{p.name}</strong>
                  <small className="muted block">{[p.form, p.pack].filter(Boolean).join(' · ')}</small>
                  {p.uses && <small className="muted block">For {p.uses}</small>}
                </td>
                <td>{p.company?.name}</td>
                <td className="wrap">{p.composition ?? <span className="muted">Not typed yet</span>}</td>
                <td className="num">{money(p.mrp)}</td>
                <td className="num">{money(p.rate)}</td>
                <td>{p.scheme}</td>
                <td className="wrap">
                  {p.in_pharmacy.length === 0 ? (
                    <span className="muted">{p.composition ? 'Not kept' : ''}</span>
                  ) : (
                    p.in_pharmacy.slice(0, 3).map((m) => (
                      <small className="block" key={m.name}>
                        {m.name} <span className={m.on_shelf > 0 ? 'settled' : 'muted'}>{m.on_shelf > 0 ? `· ${m.on_shelf} on the shelf` : '· none on the shelf'}</span>
                      </small>
                    ))
                  )}
                  {p.in_pharmacy.length > 3 && <small className="muted block">and {p.in_pharmacy.length - 3} more</small>}
                  {/* For the pharmacy: the medicine of the same name that an order of this brand is for, and ordering it. */}
                  {p.on_order !== undefined &&
                    (!p.medicine ? (
                      <small className="muted block">Not in the pharmacy’s medicines under this name, so it cannot be ordered here.</small>
                    ) : (
                      <>
                        <small className="block">
                          Ours: <MedicineLink id={p.medicine.id}>{p.medicine.name}</MedicineLink>
                          {p.on_order > 0 && <span className="muted"> · {p.on_order} units already on order</span>}
                        </small>
                        {!p.medicine.is_active ? (
                          <small className="muted block">Marked not in use in the pharmacy, so it cannot be ordered.</small>
                        ) : !canOrder ? null : basket[p.id] ? (
                          <span className="option-order catalogue-order" onClick={stop}>
                            <input type="number" min={1} value={basket[p.id].packs} onChange={(e) => setBasket({ ...basket, [p.id]: { ...basket[p.id], packs: e.target.value } })} aria-label={`Packs of ${p.name} to order`} />
                            <small className="muted">packs of {p.medicine.units_per_pack} from</small>
                            <select value={basket[p.id].stockist} onChange={(e) => setBasket({ ...basket, [p.id]: { ...basket[p.id], stockist: e.target.value } })} aria-label={`Supplier for ${p.name}`}>
                              <option value="">Choose a supplier</option>
                              {stockists.map((s) => (
                                <option key={s.id} value={s.id}>
                                  {s.name}
                                  {s.id === p.company?.stockist_id ? ` (supplies ${p.company.name})` : s.id === p.medicine?.preferred_stockist_id ? ' (usual for this medicine)' : ''}
                                </option>
                              ))}
                            </select>
                            {!basket[p.id].stockist && !p.company?.stockist_id && <small className="due">{p.company?.name ?? 'This company'} has no stockist set: choose a supplier.</small>}
                            <button type="button" className="link danger" onClick={() => putBack(p.id)}>
                              Not this one
                            </button>
                          </span>
                        ) : (
                          <button
                            type="button"
                            className="link small block"
                            onClick={(e) => {
                              e.stopPropagation()
                              setMade(null)
                              setOrderError('')
                              setBasket({ ...basket, [p.id]: { packs: '1', stockist: firstSupplier(p) } })
                            }}
                          >
                            + Order
                          </button>
                        )}
                      </>
                    ))}
                </td>
                <td className="wrap">
                  {p.also_from.slice(0, 3).map((o) => (
                    <small className="block" key={o.id}>
                      {o.name} <span className="muted">· {o.company}{o.rate !== null ? ` · ${rupees(o.rate)}` : o.mrp !== null ? ` · MRP ${rupees(o.mrp)}` : ''}</span>
                    </small>
                  ))}
                  {p.also_from.length > 3 && <small className="muted block">and {p.also_from.length - 3} more</small>}
                </td>
              </tr>
            ))}
            {data.products.length === 0 && (
              <tr className="empty static">
                <td colSpan={8}>{query || companyId ? 'No brand in the catalogues matches.' : 'No brand has been entered yet. Add one, or load a company’s sheet.'}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      {chosen.length > 0 && (
        <div className="order-bar">
          <span>
            <b>{chosen.length}</b> {chosen.length === 1 ? 'brand' : 'brands'} chosen to order
            {!ready && <small className="due"> · give each one its packs and a supplier</small>}
            {orderError && <small className="due block">{orderError}</small>}
          </span>
          <span className="foot-buttons">
            <button type="button" className="link" onClick={() => setBasket({})}>
              Clear
            </button>
            <button type="button" className="btn primary" disabled={!ready || ordering} onClick={() => void order()}>
              {ordering ? 'Making…' : 'Make draft orders'}
            </button>
          </span>
        </div>
      )}
      {editing !== null && data.can_change && (
        <p className="gap-top">
          <button type="button" className="link danger" onClick={() => { const p = data.products.find((x) => x.id === editing); if (p) void remove(p) }}>
            Take this brand out of the catalogue
          </button>
        </p>
      )}
    </>
  )
}
