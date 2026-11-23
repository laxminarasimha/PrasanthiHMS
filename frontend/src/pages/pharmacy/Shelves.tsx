import { useCallback, useEffect, useMemo, useState } from 'react'
import { PlaceProgress } from '../../components/PlaceProgress'
import { api, fieldErrors } from '../../api'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { placeLabel } from '../../format'
import { UnsavedGuard } from '../../unsaved'

type Row = { id: number; name: string; type: string | null; rack_no: string | null; box_no: string | null; units_in_stock: number }
type Place = { rack: string; box: string }
/** Which medicines the list shows: all of them, those with stock and no place, or one rack. */
type Show = { kind: 'all' } | { kind: 'unplaced' } | { kind: 'rack'; rack: string }

/** Racks named A, B, 1, 2, 10 read in the order a person expects, not 1, 10, 2. */
const natural = (a: string, b: string) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
const LIMIT = 300

/**
 * Where each medicine is kept. Type a rack and a box against a medicine, or tick several and put them in one place;
 * the counter, the stock list and the stock counts then say where to find it. Each rack's list can be printed
 * to stick on the shelf.
 */
export function PharmacyShelves() {
  const [rows, setRows] = useState<Row[] | null>(null)
  const [show, setShow] = useState<Show>({ kind: 'unplaced' })
  const [query, setQuery] = useState('')
  // What has been typed and not saved yet, by medicine.
  const [typed, setTyped] = useState<Record<number, Place>>({})
  const [ticked, setTicked] = useState<number[]>([])
  const [bulk, setBulk] = useState<Place>({ rack: '', box: '' })
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  const load = useCallback(() => api.get<Row[]>('/pharmacy/shelves').then((res) => setRows(res.data)), [])
  useEffect(() => {
    load().catch(() => setError('Could not load the medicines.'))
  }, [load])

  const all = useMemo(() => rows ?? [], [rows])
  const placeOf = (m: Row): Place => typed[m.id] ?? { rack: m.rack_no ?? '', box: m.box_no ?? '' }
  const changed = all.filter((m) => typed[m.id] && (typed[m.id].rack.trim() !== (m.rack_no ?? '') || typed[m.id].box.trim() !== (m.box_no ?? '')))

  // The racks as they stand on record, each with how many medicines it holds.
  const racks = useMemo(() => {
    const count = new Map<string, number>()
    for (const m of all) if (m.rack_no) count.set(m.rack_no, (count.get(m.rack_no) ?? 0) + 1)
    return [...count.entries()].sort(([a], [b]) => natural(a, b))
  }, [all])
  const unplaced = all.filter((m) => !m.rack_no && m.units_in_stock > 0)
  const words = query.trim().toLowerCase()
  const matching = all.filter(
    (m) =>
      (show.kind === 'all' || (show.kind === 'unplaced' ? !m.rack_no && m.units_in_stock > 0 : m.rack_no === show.rack) || Boolean(typed[m.id])) && (!words || m.name.toLowerCase().includes(words)),
  )
  const listed = (show.kind === 'rack' ? [...matching].sort((a, b) => natural(a.box_no ?? '', b.box_no ?? '') || a.name.localeCompare(b.name)) : matching).slice(0, LIMIT)

  const type = (m: Row, key: keyof Place, value: string) => {
    // What the last save said is about the last save: it goes as soon as something new is typed.
    setNotice('')
    setTyped((t) => ({ ...t, [m.id]: { ...placeOf(m), [key]: value } }))
  }
  const tick = (id: number) => setTicked((ids) => (ids.includes(id) ? ids.filter((i) => i !== id) : [...ids, id]))
  /** Puts every ticked medicine in the rack and box typed above the list. Nothing is saved until Save is pressed. */
  const putTicked = () => {
    setNotice('')
    setTyped((t) => ({ ...t, ...Object.fromEntries(ticked.map((id) => [id, { rack: bulk.rack, box: bulk.box }])) }))
    setTicked([])
  }
  /** Throws away what was typed and not saved, and reads the places again from the server. */
  const startAgain = () => {
    setTyped({})
    setTicked([])
    setNotice('')
    setError('')
    load().catch(() => setError('Could not load the medicines.'))
  }

  const save = async () => {
    setSaving(true)
    setError('')
    setNotice('')
    try {
      // What is being done to each, so the message afterwards can say it plainly: given a place, moved, or taken off the shelves.
      const sent = changed.map((m) => ({ medicine_id: m.id, rack_no: typed[m.id].rack.trim() || null, box_no: typed[m.id].rack.trim() ? typed[m.id].box.trim() || null : null }))
      const cleared = changed.filter((m) => !typed[m.id].rack.trim()).length
      const moved = changed.filter((m) => typed[m.id].rack.trim() && m.rack_no).length
      const placed = changed.length - cleared - moved
      const where = [...new Set(sent.filter((s) => s.rack_no).map((s) => placeLabel(s.rack_no, s.box_no)))]
      const res = await api.put<{ changed: number }>('/pharmacy/shelves', { places: sent })
      const count = (n: number, what: string) => (n > 0 ? `${n} ${n === 1 ? 'medicine' : 'medicines'} ${what}` : '')
      setNotice(
        res.data.changed === 0
          ? 'Nothing needed changing: those medicines were already at the places typed.'
          : `Saved. ${[count(placed, 'given a place'), count(moved, 'moved'), count(cleared, `no longer ${cleared === 1 ? 'has' : 'have'} a place`)].filter(Boolean).join(', ')}.` +
              (where.length > 0 ? ` Now at: ${where.slice(0, 6).join('; ')}${where.length > 6 ? ' and more' : ''}.` : '') +
              (cleared > 0 ? ' The ones without a place are listed again under "No place yet" if they have stock.' : ''),
      )
      setTyped({})
      setTicked([])
      const fresh = (await api.get<Row[]>('/pharmacy/shelves')).data
      setRows(fresh)
      // The rack being looked at may have just been emptied: fall back to the whole list rather than an empty screen.
      setShow((now) => (now.kind === 'rack' && !fresh.some((m) => m.rack_no === now.rack) ? { kind: 'all' } : now))
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'The places were not saved. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  // What the printed sheet holds: the rack being looked at, or every rack, each under its boxes.
  const printed = (show.kind === 'rack' ? racks.filter(([rack]) => rack === show.rack) : racks).map(([rack]) => {
    const inRack = all.filter((m) => m.rack_no === rack)
    const boxes = [...new Set(inRack.map((m) => m.box_no ?? ''))].sort(natural)
    return { rack, boxes: boxes.map((box) => ({ box, medicines: inRack.filter((m) => (m.box_no ?? '') === box).sort((a, b) => a.name.localeCompare(b.name)) })) }
  })

  return (
    <>
      <PharmacyTabs />
      <UnsavedGuard on={changed.length > 0} message="The places you typed are not saved yet. Leave without saving them?" />
      <section className="card no-print shelves">
        <div className="toolbar">
          <h1 className="grow">Racks and boxes</h1>
          <button type="button" className="btn ghost" onClick={() => window.print()} disabled={racks.length === 0} title="A sheet for each rack, listing what belongs in each box">
            {show.kind === 'rack' ? `Print rack ${show.rack}` : 'Print all racks'}
          </button>
          {changed.length > 0 && (
            <button type="button" className="btn ghost" onClick={startAgain} disabled={saving} title="Forget what was typed and not saved">
              Undo typing
            </button>
          )}
          <button type="button" className="btn primary" onClick={save} disabled={saving || changed.length === 0}>
            {saving ? 'Saving…' : changed.length > 0 ? `Save ${changed.length} ${changed.length === 1 ? 'place' : 'places'}` : 'Save'}
          </button>
        </div>
        <p className="muted small">
          Give every medicine a rack and, if you use them, a box on that rack. Name them the way they are labelled in the pharmacy: A, B, 1, 2, Fridge, Counter. The place then shows wherever the medicine is picked: on the
          billing screen, the inventory and the stock counts. New stock of the medicine goes to the same place by itself.
        </p>

        <div className="tiles small">
          <div className="tile">
            <span>Medicines with a place</span>
            <strong>{all.filter((m) => m.rack_no).length}</strong>
          </div>
          <div className="tile">
            <span>In stock, no place yet</span>
            <strong className={unplaced.length > 0 ? 'due' : 'settled'}>{unplaced.length}</strong>
          </div>
          <div className="tile">
            <span>Racks in use</span>
            <strong>{racks.length}</strong>
          </div>
        </div>
        {rows && <PlaceProgress placed={all.filter((m) => m.rack_no && m.units_in_stock > 0).length} inStock={all.filter((m) => m.units_in_stock > 0).length} />}

        <div className="desk-filters">
          <div className="tabs flush" role="group" aria-label="Which medicines to show">
            <button type="button" className={show.kind === 'unplaced' ? 'active' : ''} onClick={() => setShow({ kind: 'unplaced' })}>
              No place yet <span>{unplaced.length}</span>
            </button>
            <button type="button" className={show.kind === 'all' ? 'active' : ''} onClick={() => setShow({ kind: 'all' })}>
              All <span>{all.length}</span>
            </button>
            {racks.map(([rack, count]) => (
              <button key={rack} type="button" className={show.kind === 'rack' && show.rack === rack ? 'active' : ''} onClick={() => setShow({ kind: 'rack', rack })}>
                {placeLabel(rack)} <span>{count}</span>
              </button>
            ))}
          </div>
          <input className="search" type="search" placeholder="Find a medicine" value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Find a medicine" />
        </div>

        {ticked.length > 0 && (
          <div className="shelf-bulk">
            <strong>
              {ticked.length} ticked
            </strong>
            <span>put in rack</span>
            <input className="narrow" maxLength={30} value={bulk.rack} onChange={(e) => setBulk({ ...bulk, rack: e.target.value })} aria-label="Rack for the ticked medicines" />
            <span>box</span>
            <input className="narrow" maxLength={30} value={bulk.box} onChange={(e) => setBulk({ ...bulk, box: e.target.value })} aria-label="Box for the ticked medicines" />
            <button type="button" className="btn" onClick={putTicked} disabled={!bulk.rack.trim()}>
              Put them there
            </button>
            <button type="button" className="link" onClick={() => setTicked([])}>
              Untick all
            </button>
          </div>
        )}

        {notice && <p className="notice" role="status">{notice}</p>}
        {error && <p className="form-error" role="alert">{error}</p>}
        {changed.length > 0 && !saving && (
          <p className="muted small" role="status">
            {changed.length} {changed.length === 1 ? 'change is' : 'changes are'} typed and <strong>not saved yet</strong> (the green rows). Press Save at the top.
          </p>
        )}
        {!rows && !error && <p className="loading">Loading the medicines…</p>}

        <div className="table-wrap">
          <table className="compact">
            <thead>
              <tr>
                <th />
                <th>Medicine</th>
                <th className="num">In stock</th>
                <th>Rack</th>
                <th>Box</th>
              </tr>
            </thead>
            <tbody>
              {listed.map((m) => {
                const place = placeOf(m)
                const dirty = changed.includes(m)
                return (
                  <tr key={m.id} className={`static ${dirty ? 'added-line' : ''}`}>
                    <td>
                      <input type="checkbox" checked={ticked.includes(m.id)} onChange={() => tick(m.id)} aria-label={`Tick ${m.name}`} />
                    </td>
                    <td>
                      <strong>{m.name}</strong>
                      {m.type && <small className="muted"> · {m.type}</small>}
                      {dirty && <small className="muted block">was {placeLabel(m.rack_no, m.box_no) || 'not placed'}</small>}
                    </td>
                    <td className={`num ${m.units_in_stock === 0 ? 'muted' : ''}`}>{m.units_in_stock}</td>
                    <td>
                      <input className="narrow" maxLength={30} value={place.rack} onChange={(e) => type(m, 'rack', e.target.value)} aria-label={`Rack for ${m.name}`} />
                    </td>
                    <td>
                      <input className="narrow" maxLength={30} value={place.box} disabled={!place.rack.trim()} onChange={(e) => type(m, 'box', e.target.value)} aria-label={`Box for ${m.name}`} />
                    </td>
                  </tr>
                )
              })}
              {rows && listed.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>{words ? `No medicine here matches "${query.trim()}".` : show.kind === 'unplaced' ? 'Every medicine in stock has a place.' : 'No medicines here.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {matching.length > LIMIT && (
          <p className="muted small">
            Showing the first {LIMIT} of {matching.length}. Type part of a name above to find the rest.
          </p>
        )}
      </section>

      {/* Only on paper: what belongs in each rack and box, to stick on the shelf. */}
      <div className="print-only shelf-sheets">
        {printed.map(({ rack, boxes }) => (
          <section key={rack} className="shelf-sheet">
            <h1>{placeLabel(rack)}</h1>
            {boxes.map(({ box, medicines }) => (
              <div key={box} className="shelf-box">
                <h2>{box ? placeLabel(rack, box).split(' · ')[1] : 'No box'}</h2>
                <ul>
                  {medicines.map((m) => (
                    <li key={m.id}>
                      {m.name}
                      {m.type ? <small> · {m.type}</small> : null}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </section>
        ))}
      </div>
    </>
  )
}
