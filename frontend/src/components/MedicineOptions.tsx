import { useEffect, useMemo, useState } from 'react'
import { api, fieldErrors } from '../api'
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
type Show = 'all' | 'out' | 'untagged'

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
  const [query, setQuery] = useState('')
  const [show, setShow] = useState<Show>(startWith ?? 'all')
  const [many, setMany] = useState(60)
  const [tagging, setTagging] = useState<{ id: number; text: string } | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api.get<{ medicines: Option[]; compositions: string[]; drugs: string[] }>('/pharmacy/medicines/options').then(
      (res) => {
        setOptions(res.data.medicines)
        setCompositions(res.data.compositions)
        setDrugs(res.data.drugs)
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
      const fresh = await api.get<{ medicines: Option[]; compositions: string[]; drugs: string[] }>('/pharmacy/medicines/options')
      setOptions(fresh.data.medicines)
      setCompositions(fresh.data.compositions)
      setDrugs(fresh.data.drugs)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
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
      {error && <p className="form-error">{error}</p>}
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
                  <div className="option-stock">{b.on_shelf > 0 ? <span className="pill ok">{b.on_shelf} on the shelf</span> : <span className="pill off">Out of stock</span>}</div>
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
            </div>
          )
        })}
        {groups.length === 0 && <p className="muted gap-top">No medicine matches.</p>}
      </div>
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
