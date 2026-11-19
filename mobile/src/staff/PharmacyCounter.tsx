import { useCallback, useEffect, useMemo, useState } from 'react'
import { Pressable, Text, View } from 'react-native'
import { api, ApiError, niceDate, niceTime, todayIso } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Empty, Field, Loading, Notice, Switch, text } from '../ui'
import { Meter } from '../doctor/owner/parts'
import { ageSex, fullName, placeLabel, Tag } from './parts'

type Batch = { id: number; batch_no: string; expiry: string; unit_mrp: number; units_in_stock: number; rack_no: string | null; box_no: string | null }
type Found = { id: number; name: string; composition?: string | null; type: string | null; sellable_batches: Batch[] }
type Shelf = { id: number; name: string; type: string | null; rack_no: string | null; box_no: string | null; units_in_stock: number }
type Prescription = {
  id: number
  completed_at: string
  patient: { id: number; uhid: string; name: string; title?: string | null; gender: string; age_label: string }
  doctor: string | null
  medicines: { id: number; medicine: string; dose: string | null; frequency: string | null; duration: string | null }[]
  bill: { id: number; bill_no: string } | null
}
type Part = 'find' | 'place' | 'rx'

/**
 * The pharmacy in the hand: where a medicine is kept and how much is left, putting medicines on their racks while
 * walking the shelves, and the prescriptions waiting to be made up. Billing stays at the counter on the web system.
 */
export function PharmacyCounter({ refreshKey }: { refreshKey: number }) {
  const [part, setPart] = useState<Part>('find')
  return (
    <>
      <Switch
        options={[
          { value: 'find', label: 'Find' },
          { value: 'place', label: 'Racks' },
          { value: 'rx', label: 'Prescriptions' },
        ]}
        value={part}
        onChange={setPart}
      />
      {part === 'find' && <FindMedicine />}
      {part === 'place' && <PlaceMedicines refreshKey={refreshKey} />}
      {part === 'rx' && <Prescriptions refreshKey={refreshKey} />}
    </>
  )
}

/** Type a name or a drug: what is in stock, at what price, and on which rack. */
function FindMedicine() {
  const [words, setWords] = useState('')
  const [found, setFound] = useState<{ q: string; rows: Found[] } | null>(null)
  const [error, setError] = useState('')
  const q = words.trim()

  useEffect(() => {
    if (q.length < 2) return
    let stale = false
    const timer = setTimeout(() => {
      api
        .get<Found[]>('/pharmacy/sell-search', { q })
        .then((rows) => {
          if (stale) return
          setFound({ q, rows })
          setError('')
        })
        .catch((e: ApiError) => !stale && setError(e.message))
    }, 250)
    return () => {
      stale = true
      clearTimeout(timer)
    }
  }, [q])

  const rows = q.length >= 2 && found?.q === q ? found.rows : null
  return (
    <>
      <Field label="Medicine or drug name" value={words} onChangeText={setWords} placeholder="At least two letters" autoCapitalize="none" autoCorrect={false} />
      <Notice text={error} />
      {q.length < 2 && <Empty text="Type a medicine's name, or a drug it contains, to see the stock and where it is kept." />}
      {q.length >= 2 && !rows && !error && <Loading />}
      {rows?.length === 0 && <Empty text={`Nothing in stock matches "${q}".`} />}
      {rows?.map((m) => {
        const units = m.sellable_batches.reduce((n, b) => n + Number(b.units_in_stock), 0)
        const places = [...new Set(m.sellable_batches.map((b) => placeLabel(b.rack_no, b.box_no)).filter(Boolean))]
        return (
          <Card key={m.id}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
              <View style={{ flex: 1 }}>
                <Text style={text.strong}>{m.name}</Text>
                {m.composition ? <Text style={text.muted}>{m.composition}</Text> : null}
              </View>
              <Text style={text.strong}>
                {units} <Text style={text.muted}>in stock</Text>
              </Text>
            </View>
            {places.length > 0 ? <Tag label={places.join('  ·  ')} tone="done" /> : <Tag label="No rack given yet" tone="waiting" />}
            {m.sellable_batches.map((b) => (
              <View key={b.id} style={{ flexDirection: 'row', gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 6 }}>
                <View style={{ flex: 1 }}>
                  <Text style={text.body}>Batch {b.batch_no}</Text>
                  <Text style={text.muted}>Expires {niceDate(b.expiry).slice(5)}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={text.body}>{b.units_in_stock} units</Text>
                  <Text style={text.muted}>MRP ₹{Number(b.unit_mrp).toFixed(2)} each</Text>
                </View>
              </View>
            ))}
          </Card>
        )
      })}
    </>
  )
}

/** Walk the shelves with the phone: pick a medicine, say which rack and box it is on, save. */
function PlaceMedicines({ refreshKey }: { refreshKey: number }) {
  const [rows, setRows] = useState<Shelf[] | null>(null)
  const [only, setOnly] = useState<'unplaced' | 'all'>('unplaced')
  const [words, setWords] = useState('')
  const [editing, setEditing] = useState<number | null>(null)
  const [rack, setRack] = useState('')
  const [box, setBox] = useState('')
  // The rack last saved: the next medicine picked is usually on the same one.
  const [lastRack, setLastRack] = useState('')
  const [error, setError] = useState('')
  const [said, setSaid] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => api.get<Shelf[]>('/pharmacy/shelves').then(setRows).catch((e: ApiError) => setError(e.message)), [])
  useEffect(() => {
    void load()
  }, [load, refreshKey])

  const inStock = useMemo(() => (rows ?? []).filter((m) => m.units_in_stock > 0), [rows])
  const placed = inStock.filter((m) => m.rack_no).length
  const q = words.trim().toLowerCase()
  const listed = (rows ?? []).filter((m) => (only === 'all' || (!m.rack_no && m.units_in_stock > 0)) && (!q || m.name.toLowerCase().includes(q)))

  const pick = (m: Shelf) => {
    setEditing(m.id)
    setRack(m.rack_no ?? lastRack)
    setBox(m.box_no ?? '')
    setError('')
    setSaid('')
  }
  const save = async (m: Shelf) => {
    setBusy(true)
    setError('')
    try {
      const place = { rack_no: rack.trim() || null, box_no: rack.trim() ? box.trim() || null : null }
      await api.put('/pharmacy/shelves', { places: [{ medicine_id: m.id, ...place }] })
      setRows((all) => all && all.map((r) => (r.id === m.id ? { ...r, ...place } : r)))
      setLastRack(place.rack_no ?? lastRack)
      setSaid(place.rack_no ? `${m.name} is on ${placeLabel(place.rack_no, place.box_no)}.` : `${m.name} no longer has a place.`)
      setEditing(null)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy(false)
    }
  }

  if (!rows) return error ? <Notice text={error} /> : <Loading />
  return (
    <>
      <Card>
        <Meter label="Medicines in stock with a place" used={placed} of={inStock.length} />
        <Text style={text.muted}>
          {inStock.length - placed === 0 ? 'Every medicine in stock can be found from the billing screen.' : `${inStock.length - placed} still to place. Stand at a rack, pick each medicine on it, and save.`}
        </Text>
      </Card>
      <Switch
        options={[
          { value: 'unplaced', label: `No place yet (${inStock.length - placed})` },
          { value: 'all', label: 'All medicines' },
        ]}
        value={only}
        onChange={setOnly}
      />
      <Field label="Find a medicine" value={words} onChangeText={setWords} placeholder="Name" autoCapitalize="none" autoCorrect={false} />
      <Notice text={error} />
      <Notice text={said} kind="ok" />
      {listed.length === 0 && <Empty text={q ? `No medicine here matches "${words.trim()}".` : only === 'unplaced' ? 'Every medicine in stock has a place.' : 'No medicines.'} />}
      {listed.slice(0, 40).map((m) =>
        editing === m.id ? (
          <Card key={m.id}>
            <Text style={text.strong}>{m.name}</Text>
            <Text style={text.muted}>{m.units_in_stock > 0 ? `${m.units_in_stock} units in stock` : 'None in stock now'}</Text>
            <View style={{ flexDirection: 'row', gap: space.sm }}>
              <View style={{ flex: 1 }}>
                <Field label="Rack" value={rack} onChangeText={setRack} maxLength={30} autoCapitalize="characters" autoCorrect={false} placeholder="A, 1, Fridge" />
              </View>
              <View style={{ flex: 1 }}>
                <Field label="Box (if used)" value={box} onChangeText={setBox} maxLength={30} autoCapitalize="characters" autoCorrect={false} placeholder="3" />
              </View>
            </View>
            <View style={{ flexDirection: 'row', gap: space.sm, justifyContent: 'flex-end' }}>
              <Button label="Cancel" kind="ghost" small onPress={() => setEditing(null)} />
              <Button label="Save the place" small busy={busy} disabled={!rack.trim() && !m.rack_no} onPress={() => void save(m)} />
            </View>
          </Card>
        ) : (
          <Pressable key={m.id} onPress={() => pick(m)} accessibilityRole="button" accessibilityLabel={`Set the place of ${m.name}`}>
            <Card>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                <View style={{ flex: 1 }}>
                  <Text style={text.strong}>{m.name}</Text>
                  <Text style={text.muted}>{m.units_in_stock > 0 ? `${m.units_in_stock} units in stock` : 'None in stock now'}</Text>
                </View>
                {m.rack_no ? <Tag label={placeLabel(m.rack_no, m.box_no)} tone="done" /> : <Text style={text.link}>Set place</Text>}
              </View>
            </Card>
          </Pressable>
        ),
      )}
      {listed.length > 40 && <Text style={[text.muted, { textAlign: 'center' }]}>Showing the first 40 of {listed.length}. Type a name to narrow the list.</Text>}
    </>
  )
}

/** Today's prescriptions from the doctors: which are waiting to be made up, and which are billed. */
function Prescriptions({ refreshKey }: { refreshKey: number }) {
  const [orders, setOrders] = useState<Prescription[] | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    const load = () =>
      api
        .get<Prescription[]>('/pharmacy/orders', { date: todayIso() })
        .then((list) => {
          setOrders(list)
          setError('')
        })
        .catch((e: ApiError) => setError(e.message))
    void load()
    const timer = setInterval(() => void load(), 30000)
    return () => clearInterval(timer)
  }, [refreshKey])

  const waiting = (orders ?? []).filter((o) => !o.bill)
  return (
    <>
      <Notice text={error} />
      {!orders && !error && <Loading />}
      {orders?.length === 0 && <Empty text="No prescriptions have been written today." />}
      {orders && orders.length > 0 && (
        <Text style={[text.muted, { textAlign: 'center' }]}>
          {waiting.length} waiting · {orders.length - waiting.length} billed
        </Text>
      )}
      {[...waiting, ...(orders ?? []).filter((o) => o.bill)].map((o) => (
        <Card key={o.id}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
            <View style={{ flex: 1 }}>
              <Text style={text.strong}>{fullName(o.patient)}</Text>
              <Text style={text.muted}>
                {ageSex(o.patient)} · {o.patient.uhid} · {niceTime(o.completed_at)}
                {o.doctor ? ` · ${o.doctor}` : ''}
              </Text>
            </View>
            <Tag label={o.bill ? `Billed ${o.bill.bill_no}` : 'Waiting'} tone={o.bill ? 'done' : 'waiting'} />
          </View>
          {o.medicines.map((m) => (
            <Text key={m.id} style={text.body}>
              {m.medicine}
              <Text style={text.muted}>{[m.dose, m.frequency, m.duration].filter(Boolean).length ? `  ${[m.dose, m.frequency, m.duration].filter(Boolean).join(' · ')}` : ''}</Text>
            </Text>
          ))}
        </Card>
      ))}
    </>
  )
}
