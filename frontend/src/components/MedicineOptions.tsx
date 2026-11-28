import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { CompositionImport } from './CompositionImport'
import { longDate, rupees } from '../format'

type Supplier = { stockist_id: number; name: string; times: number; last_on: string; last_rate: number; last_mrp: number; units_per_pack: number }
type Option = {
  id: number
  name: string
  composition: string | null
  type: string | null
  manufacturer: string | null
  units_per_pack: number
  on_shelf: number
  /** The individual drugs inside: one for a plain medicine, several for a combination. */
  drugs: { id: number; name: string; strength: string | null }[]
  usual_stockist_id: number | null
  usual_stockist: string | null
  suppliers: Supplier[]
}
/** A brand in a medicine company's catalogue that contains the same thing, with what is on offer and whom to ring. */
type Offer = { id: number; name: string; company: string | null; composition: string; form: string | null; pack: string | null; mrp: number | null; rate: number | null; scheme: string | null; reps: { name: string; mobile: string | null }[] }
type Show = 'all' | 'out' | 'untagged'
type Made = { id: number; order_no: string; stockist: string; items: number }

/** Two ways of typing the same composition are one composition. */
const same = (composition: string) => composition.toLowerCase().replace(/\s+/g, ' ').trim()
const perUnit = (s: Supplier) => (s.units_per_pack > 0 ? s.last_rate / s.units_per_pack : s.last_rate)

/**
 * The medicines list seen as choices: brands that contain the same thing sit together, each with
 * what is on the shelf and every supplier it has been bought from, with the last rate and date.
 * It answers "this is out of stock: what else do we have, and where do we usually order it?"
 */
export function MedicineOptions({ canEdit, startWith, onOpen }: { canEdit: boolean; startWith?: Show; onOpen: (name: string) => void }) {
  const [options, setOptions] = useState<Option[] | null>(null)
  const [compositions, setCompositions] = useState<string[]>([])
  const [drugs, setDrugs] = useState<string[]>([])
  const [offers, setOffers] = useState<Offer[]>([])
  const [query, setQuery] = useState('')
  const [show, setShow] = useState<Show>(startWith ?? 'all')
  const [many, setMany] = useState(60)
  const [tagging, setTagging] = useState<{ id: number; text: string } | null>(null)
  const [error, setError] = useState('')
  // What has been chosen to order: for each brand, how many packs and from whom.
  const [basket, setBasket] = useState<Record<number, { packs: string; stockist: string }>>({})
  const [stockists, setStockists] = useState<{ id: number; name: string }[]>([])
  const [made, setMade] = useState<Made[] | null>(null)
  const [ordering, setOrdering] = useState(false)

  const reload = () =>
    api.get<{ medicines: Option[]; compositions: string[]; drugs: string[]; offers?: Offer[] }>('/pharmacy/medicines/options').then((res) => {
      setOptions(res.data.medicines)
      setCompositions(res.data.compositions)
      setDrugs(res.data.drugs)
      setOffers(res.data.offers ?? [])
    })

  useEffect(() => {
    api.get<{ id: number; name: string }[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
    api.get<{ medicines: Option[]; compositions: string[]; drugs: string[]; offers?: Offer[] }>('/pharmacy/medicines/options').then(
      (res) => {
        setOptions(res.data.medicines)
        setCompositions(res.data.compositions)
        setDrugs(res.data.drugs)
        setOffers(res.data.offers ?? [])
      },
      () => setError('Could not load the medicines.'),
    )
  }, [])

  const groups = useMemo(() => {
    const term = query.trim().toLowerCase()
    const byKey = new Map<string, { composition: string | null; brands: Option[] }>()
    for (const o of options ?? []) {
      // A medicine whose composition is not set stands alone until someone says what it contains.
      const key = o.composition ? same(o.composition) : `#${o.id}`
      const group = byKey.get(key) ?? { composition: o.composition, brands: [] }
      group.brands.push(o)
      byKey.set(key, group)
    }
    return [...byKey.values()]
      .filter((g) => {
        if (show === 'untagged' && g.composition) return false
        if (show === 'out' && !g.brands.some((b) => b.on_shelf === 0)) return false
        return !term || (g.composition ?? '').toLowerCase().includes(term) || g.brands.some((b) => b.name.toLowerCase().includes(term) || (b.manufacturer ?? '').toLowerCase().includes(term) || b.suppliers.some((s) => s.name.toLowerCase().includes(term)))
      })
      .sort((a, b) => Number(!a.composition) - Number(!b.composition) || (a.composition ?? a.brands[0].name).localeCompare(b.composition ?? b.brands[0].name))
  }, [options, query, show])

  // Catalogue brands under the composition they share with the pharmacy's own, less those the pharmacy already lists by name.
  const offersFor = useMemo(() => {
    const listed = new Set((options ?? []).map((o) => o.name.toLowerCase()))
    const by = new Map<string, Offer[]>()
    for (const f of offers) {
      if (listed.has(f.name.toLowerCase())) continue
      by.set(same(f.composition), [...(by.get(same(f.composition)) ?? []), f])
    }
    return by
  }, [offers, options])

  const counts = useMemo(() => {
    const list = options ?? []
    return { all: list.length, out: list.filter((o) => o.on_shelf === 0).length, untagged: list.filter((o) => !o.composition).length }
  }, [options])

  const saveComposition = async () => {
    if (!tagging) return
    setError('')
    try {
      await api.put(`/pharmacy/medicines/${tagging.id}/composition`, { composition: tagging.text.trim() || null })
      setTagging(null)
      // Fetched afresh, so the brand shows under its composition with its drugs linked.
      await reload()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  const chosen = Object.entries(basket)
  const ready = chosen.length > 0 && chosen.every(([, line]) => Number(line.packs) >= 1 && line.stockist)
  const order = async () => {
    setOrdering(true)
    setError('')
    try {
      const res = await api.post<{ orders: Made[] }>('/pharmacy/manager/orders', { from: 'options', lines: chosen.map(([id, line]) => ({ medicine_id: Number(id), stockist_id: Number(line.stockist), packs: Number(line.packs) })) })
      setMade(res.data.orders)
      setBasket({})
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setOrdering(false)
    }
  }

  if (!options) return error ? <p className="form-error">{error}</p> : <p className="loading">Loading the options…</p>

  return (
    <section className="card">
      <div className="toolbar">
        <h1>Options to order</h1>
        <input className="search" type="search" placeholder="Find by what it contains, brand, maker or supplier" value={query} onChange={(e) => { setQuery(e.target.value); setMany(60) }} />
      </div>
      <p className="muted small">
        Brands that contain the same thing are listed together, so when one is out of stock you can see what else is on the shelf and where each was last bought. A brand joins others once its composition is filled in. For a
        combination, put a plus between its drugs: “Paracetamol 325 mg + Aceclofenac 100 mg”.
      </p>
      {drugs.length > 0 && (
        <p className="small">
          <span className="muted">Find by drug, alone or in a combination: </span>
          <span className="drug-chips">
            {drugs.slice(0, 40).map((d) => (
              <button key={d} type="button" className="drug-chip" onClick={() => { setQuery(d); setMany(60) }}>
                {d}
              </button>
            ))}
            {drugs.length > 40 && <span className="muted">and {drugs.length - 40} more: type the name above</span>}
          </span>
        </p>
      )}
      <div className="tabs flush gap-top" role="group" aria-label="Which medicines to show">
        {([['all', 'All medicines'], ['out', 'Out of stock'], ['untagged', 'Composition not set']] as [Show, string][]).map(([key, label]) => (
          <button key={key} className={show === key ? 'active' : ''} onClick={() => { setShow(key); setMany(60) }}>
            {label} <span>{counts[key]}</span>
          </button>
        ))}
      </div>
      {canEdit && <CompositionImport medicines={options} onSaved={() => void reload()} />}
      {error && <p className="form-error">{error}</p>}
      {made && (
        <p className="notice gap-top">
          {made.length === 1 ? 'A draft order was made: ' : `${made.length} draft orders were made, one for each supplier: `}
          {made.map((o) => `${o.order_no} to ${o.stockist} (${o.items} ${o.items === 1 ? 'medicine' : 'medicines'})`).join('; ')}. <Link to="/pharmacy/purchase-orders">Open purchase orders</Link> to check and send them.
        </p>
      )}
      <datalist id="known-compositions">
        {compositions.map((c) => (
          <option key={c} value={c} />
        ))}
      </datalist>

      <div className="option-groups">
        {groups.slice(0, many).map((g) => {
          const rates = g.brands.flatMap((b) => b.suppliers.map((s) => perUnit(s)))
          const lowest = rates.length > 1 ? Math.min(...rates) : null
          const inStock = g.brands.filter((b) => b.on_shelf > 0).length
          return (
            <div key={g.composition ? same(g.composition) : `#${g.brands[0].id}`} className={`option-group ${g.composition ? '' : 'untagged'}`}>
              <div className="option-head">
                <strong>{g.composition ?? 'Composition not set'}</strong>
                {g.composition && (
                  <span className="muted">
                    {g.brands[0].drugs.length > 1 ? 'Combination · ' : ''}
                    {g.brands.length} {g.brands.length === 1 ? 'brand' : 'brands'} · {inStock ? `${inStock} in stock` : 'none in stock'}
                  </span>
                )}
                {g.brands[0].drugs.length > 1 && (
                  <span className="drug-chips">
                    {g.brands[0].drugs.map((d) => (
                      <button key={d.id} type="button" className="drug-chip" onClick={() => { setQuery(d.name); setMany(60) }} title={`Show everything that contains ${d.name}`}>
                        {d.name}
                      </button>
                    ))}
                  </span>
                )}
              </div>
              {g.brands.map((b) => (
                <div key={b.id} className="option-row">
                  <div className="option-brand">
                    <button type="button" className="link" onClick={() => onOpen(b.name)} title="Open this medicine in the list">
                      {b.name}
                    </button>
                    <small className="muted block">
                      {[b.type, b.manufacturer, `${b.units_per_pack} in a pack`].filter(Boolean).join(' · ')}
                    </small>
                    {tagging?.id === b.id ? (
                      <span className="option-tag">
                        <input
                          autoFocus
                          list="known-compositions"
                          placeholder="Paracetamol 650 mg, or Drug A 5 mg + Drug B 10 mg"
                          value={tagging.text}
                          onChange={(e) => setTagging({ id: b.id, text: e.target.value })}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') void saveComposition()
                            if (e.key === 'Escape') setTagging(null)
                          }}
                          aria-label={`What ${b.name} contains`}
                        />
                        <button type="button" className="btn primary small-btn" onClick={saveComposition}>
                          Save
                        </button>
                        <button type="button" className="link" onClick={() => setTagging(null)}>
                          Cancel
                        </button>
                      </span>
                    ) : (
                      canEdit && (
                        <button type="button" className="link small" onClick={() => setTagging({ id: b.id, text: b.composition ?? '' })}>
                          {b.composition ? 'Change composition' : '+ Say what it contains'}
                        </button>
                      )
                    )}
                  </div>
                  <div className="option-stock">
                    {b.on_shelf > 0 ? <span className="pill ok">{b.on_shelf} on the shelf</span> : <span className="pill off">Out of stock</span>}
                    {canEdit &&
                      (basket[b.id] ? (
                        <span className="option-order">
                          <input type="number" min={1} value={basket[b.id].packs} onChange={(e) => setBasket({ ...basket, [b.id]: { ...basket[b.id], packs: e.target.value } })} aria-label={`Packs of ${b.name} to order`} />
                          <small className="muted">packs from</small>
                          <select value={basket[b.id].stockist} onChange={(e) => setBasket({ ...basket, [b.id]: { ...basket[b.id], stockist: e.target.value } })} aria-label={`Supplier for ${b.name}`}>
                            <option value="">Choose a supplier</option>
                            {stockists.map((s) => (
                              <option key={s.id} value={s.id}>
                                {s.name}
                                {s.id === b.usual_stockist_id ? ' (usual)' : ''}
                              </option>
                            ))}
                          </select>
                          <button type="button" className="link danger" onClick={() => setBasket(Object.fromEntries(Object.entries(basket).filter(([id]) => Number(id) !== b.id)))}>
                            Not this one
                          </button>
                        </span>
                      ) : (
                        <button type="button" className="link small block" onClick={() => { setMade(null); setBasket({ ...basket, [b.id]: { packs: '1', stockist: b.usual_stockist_id ? String(b.usual_stockist_id) : '' } }) }}>
                          + Order this
                        </button>
                      ))}
                  </div>
                  <div className="option-suppliers">
                    {b.suppliers.map((s) => (
                      <span key={s.stockist_id} className={`supplier ${s.stockist_id === b.usual_stockist_id ? 'usual' : ''}`}>
                        <strong>{s.name}</strong>
                        {s.stockist_id === b.usual_stockist_id && <span className="pill status-ongoing">usual</span>}
                        {lowest !== null && Math.abs(perUnit(s) - lowest) < 0.0005 && <span className="pill ok">lowest rate</span>}
                        <small className="muted block">
                          {rupees(s.last_rate)} a pack · {longDate(s.last_on)} · bought {s.times} {s.times === 1 ? 'time' : 'times'}
                        </small>
                      </span>
                    ))}
                    {b.suppliers.length === 0 && <span className="muted">{b.usual_stockist ? `Not bought yet. Usually ordered from ${b.usual_stockist}.` : 'Not bought from any supplier yet.'}</span>}
                  </div>
                </div>
              ))}
              {g.composition && (offersFor.get(same(g.composition)) ?? []).length > 0 && (
                <div className="option-offers">
                  <strong>Also offered by medical representatives</strong>
                  {(offersFor.get(same(g.composition)) ?? []).map((f) => (
                    <span key={f.id} className="option-offer">
                      {f.name} <span className="muted">· {f.company}</span>
                      <small className="muted block">
                        {[f.pack, f.rate !== null ? `${rupees(f.rate)} a pack offered` : null, f.mrp !== null ? `MRP ${rupees(f.mrp)}` : null, f.scheme ? `scheme ${f.scheme}` : null].filter(Boolean).join(' · ')}
                        {f.reps.length > 0 && ` · ring ${f.reps.map((r) => [r.name, r.mobile].filter(Boolean).join(' ')).join(', ')}`}
                      </small>
                    </span>
                  ))}
                </div>
              )}
            </div>
          )
        })}
        {groups.length === 0 && <p className="muted gap-top">No medicine matches.</p>}
      </div>
      {chosen.length > 0 && (
        <div className="order-bar">
          <span>
            <b>{chosen.length}</b> {chosen.length === 1 ? 'medicine' : 'medicines'} chosen to order
            {!ready && <small className="due"> · give each one its packs and a supplier</small>}
          </span>
          <span className="foot-buttons">
            <button type="button" className="link" onClick={() => setBasket({})}>
              Clear
            </button>
            <button type="button" className="btn primary" disabled={!ready || ordering} onClick={order}>
              {ordering ? 'Making…' : 'Make draft orders'}
            </button>
          </span>
        </div>
      )}
      {groups.length > many && (
        <div className="panel-foot">
          <button type="button" className="btn ghost" onClick={() => setMany(many + 120)}>
            Show more ({groups.length - many} left)
          </button>
        </div>
      )}
    </section>
  )
}
