import { BillDiscountBox } from '../../components/BillDiscountBox'
import { discountRupees, noDiscount, type BillDiscount } from '../../components/billDiscount'
import { useCallback, useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useUnsaved } from '../../unsaved'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { MedicinePick } from '../../components/MedicinePick'
import { PatientPicker } from '../../components/PatientPicker'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { PAYMENT_MODES, clock, daysUntil, expiryLabel, longDate, placeLabel, rupees, today } from '../../format'
import type { Patient, PharmacyInpatient, PharmacySale, PrescriptionForPharmacy, SellableMedicine } from '../../types'
import { PharmacyDay } from '../../components/PharmacyDay'
import { EarlierDate } from '../../components/EarlierDate'
import { BillLink, IpdLink } from '../../components/RecordLinks'
import { fillFromBatches, shortOf, visitOf, type CardTag } from './rxFill'

type Line = {
  batchId: number
  medicineId: number
  medicine: string
  batchNo: string
  expiry: string
  unitPrice: number
  available: number
  quantity: string
  discount: string
  /** Where to fetch it from: its rack and box, if it has been given a place. */
  place: string
  /**
   * The prescription cards its medicine was given from, whoever's they are: cards whose rows put it on the bill, and
   * visit cards whose Add all in stock was pressed while it was already on the bill. Nothing is ever taken off this
   * list. The saved bill is linked to the newest visit among them of the patient the bill is for, made on or before the
   * bill's day (visitOf), so choosing another patient and then this one again keeps the link.
   */
  cards?: CardTag[]
  /** The patient whose card `worked` and `short` were worked out from: they are shown only on that patient's bill. */
  notesFor?: number
  /**
   * The quantity card rows last put on this line. Once the pharmacist types another, the line is theirs: the other
   * stage of a taper is then added to it only on purpose, and its note starts again from what is on the bill.
   */
  gave?: string
  /** The card rows its medicine was added from (see rowKey): a row already on the bill is not added again. */
  rxRows?: string[]
  /**
   * Of those, the rows whose count went onto the medicine (not a row with no count, or one whose 1 later gave way).
   * Rows of one card add up only while every count on the bill came from a row still on that card as it is now.
   */
  counted?: string[]
  /** How the prescription's quantity was worked out, and, when the stock was less, by how much. */
  worked?: string
  short?: string
}
type CardMedicine = PrescriptionForPharmacy['medicines'][number]
type Batch = SellableMedicine['sellable_batches'][number]
/** A row of a prescription card, with its key. */
type CardRow = { m: CardMedicine; key: string }

const lineAmount = (l: Line) => Math.round(Number(l.quantity || 0) * l.unitPrice * (1 - Number(l.discount || 0) / 100) * 100) / 100
/** Whether a line's notes are this patient's: a line worked out from another patient's card says nothing here. */
const ownNotes = (l: Line, patientId: number | undefined) => l.notesFor === undefined || l.notesFor === patientId
/** A line given from no other patient's card: picked by name, typed, or from this patient's cards only. */
const onlyOf = (l: Line, patientId: number) => (l.cards ?? []).every((t) => t.patientId === patientId)
const sameCard = (a: CardTag, b: CardTag) => a.kind === b.kind && a.id === b.id && a.patientId === b.patientId
/** A line's cards with more added, each card once. */
const withCards = (had: CardTag[] | undefined, more: CardTag[]) => more.reduce((all, t) => (all.some((x) => sameCard(x, t)) ? all : [...all, t]), had ?? [])
/** A line that still holds what card rows put on it: nobody has typed another quantity since. */
const asGiven = (l: Line) => l.gave !== undefined && Number(l.gave) === Number(l.quantity)
/** A line that still holds the 1 a medicine starts at, picked by name or from a row with no count. */
const startingOne = (l: Line) => l.quantity === '1' && !l.worked
const inStock = (m: CardMedicine) => (m.match?.sellable_batches ?? []).reduce((sum, b) => sum + b.units_in_stock, 0)

const lineOf = (m: SellableMedicine, batch: Batch, more: Partial<Line> = {}): Line => ({
  batchId: batch.id, medicineId: m.id, medicine: m.name, batchNo: batch.batch_no, expiry: batch.expiry, unitPrice: batch.unit_mrp, available: batch.units_in_stock, quantity: '1', discount: '', place: placeLabel(batch.rack_no, batch.box_no), ...more,
})
/** The start of the key of every row of a card: the patient's, and the card's kind and id. */
const cardKey = (patientId: number, rx: PrescriptionForPharmacy) => `${patientId}-${rx.kind ?? 'visit'}-${rx.id}:`
/**
 * A row of a card, by what it says rather than its place, so a card read again with a ward order stopped or added
 * above keeps its rows, and by the patient, so choosing another patient and then this one again finds them still on
 * the bill.
 */
const rowKey = (patientId: number, rx: PrescriptionForPharmacy, m: CardMedicine) => `${cardKey(patientId, rx)}${m.medicine}|${m.directions}`
/** One note after another on a line. */
const andThen = (had?: string, more?: string) => (had && more ? `${had}; ${more}` : had || more)
/** How many more units a line's batch has beyond what the line already takes. */
const leftIn = (l: Line) => Math.max(0, l.available - (Number(l.quantity) || 0))
/** A medicine's lines on the bill, and its batches on sale that are not on the bill yet. */
const partsOf = (lines: Line[], match: SellableMedicine) => {
  const there = lines.filter((l) => l.medicineId === match.id)
  return { there, free: match.sellable_batches.filter((b) => !there.some((l) => l.batchId === b.id)) }
}
/**
 * How many more of a medicine can go on the bill, counting what its lines there already take; a count replaces the 1 a
 * medicine starts at (see withRows), so that 1 takes nothing.
 */
const roomFor = (lines: Line[], match: SellableMedicine) => {
  const { there, free } = partsOf(lines, match)
  const fresh = there.length > 0 && there.every(startingOne)
  return there.reduce((sum, l) => sum + (fresh ? l.available : leftIn(l)), 0) + free.reduce((sum, b) => sum + b.units_in_stock, 0)
}
/**
 * How a card's row stands against the bill: on it already; free to add; or its medicine is on the bill some other way
 * (picked by name and given a quantity, from another card, as when a follow-up visit repeats the discharge medicines,
 * or from a row of this card that the doctor has changed since), when it is added only on purpose ("Add N more") and
 * never by Add all in stock. Rows of one card add up, as a taper's do, while every count of the medicine on the bill
 * came from a row still on this card as it is now; and a count replaces the 1 a medicine starts at.
 */
function rowState(lines: Line[], patientId: number, rx: PrescriptionForPharmacy, m: CardMedicine): 'placed' | 'free' | 'other' {
  if (lines.some((l) => (l.rxRows ?? []).includes(rowKey(patientId, rx, m)))) return 'placed'
  const there = m.match ? lines.filter((l) => l.medicineId === m.match!.id) : []
  if (there.length === 0 || there.every(startingOne)) return 'free'
  const now = rx.medicines.map((x) => rowKey(patientId, rx, x))
  return there.every((l) => asGiven(l) && (l.counted ?? []).length > 0 && (l.counted ?? []).every((k) => now.includes(k))) ? 'free' : 'other'
}
/** What a row said, from its key: the directions after the medicine's name. */
const saidIn = (key: string, card: string) => key.slice(card.length).slice(key.slice(card.length).indexOf('|') + 1)
/**
 * Medicines put on the bill from this card that are on it no more (the doctor took them off, or wrote another medicine
 * in their place): each once, with what its rows said and how many the bill has.
 */
function takenOff(lines: Line[], patientId: number, rx: PrescriptionForPharmacy): { name: string; said: string[]; onBill: number }[] {
  const card = cardKey(patientId, rx)
  const now = rx.medicines.map((x) => rowKey(patientId, rx, x))
  const gone: { id: number; name: string; said: string[]; onBill: number }[] = []
  for (const l of lines) {
    if (rx.medicines.some((x) => x.match?.id === l.medicineId)) continue
    const stale = (l.counted ?? []).filter((k) => k.startsWith(card) && !now.includes(k))
    if (stale.length === 0) continue
    let item = gone.find((g) => g.id === l.medicineId)
    if (!item) {
      item = { id: l.medicineId, name: l.medicine, said: [], onBill: lines.filter((x) => x.medicineId === l.medicineId).reduce((sum, x) => sum + (Number(x.quantity) || 0), 0) }
      gone.push(item)
    }
    for (const k of stale) if (!item.said.includes(saidIn(k, card))) item.said.push(saidIn(k, card))
  }
  return gone.map(({ name, said, onBill }) => ({ name, said, onBill }))
}
/**
 * What a medicine's rows on this card said when they put it on the bill, for the rows the doctor has changed or taken
 * off the card since: their old directions, each once.
 */
function changedFrom(lines: Line[], patientId: number, rx: PrescriptionForPharmacy, match: SellableMedicine): string[] {
  const card = cardKey(patientId, rx)
  const now = rx.medicines.map((x) => rowKey(patientId, rx, x))
  const said: string[] = []
  for (const l of lines) {
    if (l.medicineId !== match.id) continue
    for (const k of l.counted ?? []) {
      if (!k.startsWith(card) || now.includes(k)) continue
      const words = saidIn(k, card)
      if (!said.includes(words)) said.push(words)
    }
  }
  return said
}

/**
 * Rows of one card, all for one medicine, put on the bill: one row, or two or more when the medicine is on several
 * rows, such as a taper (1-0-1 for 5 days, then 1-0-0 for 5 days). Their counts are added up. For a medicine already on
 * the bill they go first onto its lines there, as far as each batch has stock, then onto its other batches, soonest
 * expiry first; never more than is in stock. When every line of it only holds the 1 a medicine starts at, the count
 * takes the place of those 1s: it is filled soonest expiry first over all its batches, and a line left with nothing
 * comes off. Rows with no count start a medicine that is not on the bill yet at 1, as when it is picked by name. Every
 * line of the medicine then carries the rows and counts the card among its cards, so a bill made from a visit's card
 * is linked to that visit (visitOf). Gives the bill, and the line to go to. When nothing more of it is in stock, the
 * bill is left as it was.
 */
function withRows(lines: Line[], match: SellableMedicine, rows: CardRow[], from: CardTag): { lines: Line[]; focus?: number } {
  // Notes from another patient's card are not carried on: a line that takes this card's count starts its note afresh.
  const fair = (l: Line): Line => (ownNotes(l, from.patientId) ? l : { ...l, worked: undefined, short: undefined, notesFor: undefined })
  const { there, free } = partsOf(lines, match)
  const counted = rows.filter((r) => r.m.quantity)
  const prescribed = counted.reduce((sum, r) => sum + (r.m.quantity ?? 0), 0)
  // The 1 a medicine starts at, picked by name or from a row with no count, gives way to the count; it is not added to.
  const fresh = prescribed > 0 && there.length > 0 && there.every(startingOne)
  const had = (l: Line) => (fresh ? 0 : Number(l.quantity) || 0)
  // In place of the 1s, the batch that expires first goes first, as everywhere at the counter. Each line's own batch is
  // used, in the server's order (expiry, then batch): the card's list of batches may be older than the bill.
  const order = fresh ? there.slice().sort((a, b) => (a.expiry < b.expiry ? -1 : a.expiry > b.expiry ? 1 : a.batchId - b.batchId)) : there
  let left = prescribed
  const onto: Record<number, number> = {}
  for (const l of order) {
    onto[l.batchId] = Math.min(left, Math.max(0, l.available - had(l)))
    left -= onto[l.batchId]
  }
  const taken = there.length === 0 && prescribed === 0 ? [{ batch: match.sellable_batches[0], quantity: 1 }] : fillFromBatches(free, left)
  const added = prescribed - left + taken.reduce((sum, t) => sum + t.quantity, 0)
  if (prescribed > 0 && added === 0) return { lines, focus: there.length > 0 ? there[0].batchId : undefined }

  // The first line to take some says how the count was worked out, and how short the stock was; any other, where the rest came from.
  const first = order.find((l) => onto[l.batchId] > 0)?.batchId ?? (taken.length > 0 ? taken[0].batch.id : there[0].batchId)
  const working = counted.map((r) => r.m.worked_out ?? '').filter(Boolean).join('; ')
  const rest = `rest of the ${prescribed} prescribed`
  const short = added >= prescribed ? undefined : there.length > 0 && !fresh ? `prescribed ${prescribed}, only ${added} more in stock` : shortOf(prescribed, added)
  const keys = rows.map((r) => r.key)
  const countedKeys = counted.map((r) => r.key)
  // A line that only held a 1 and took none of the count comes off; the rows and cards it was from stay with the medicine.
  const gone = fresh ? there.filter((l) => !(onto[l.batchId] > 0)) : []
  const goneKeys = gone.reduce<string[]>((all, l) => all.concat(l.rxRows ?? []), [])
  const goneCards = gone.reduce<CardTag[]>((all, l) => withCards(all, l.cards ?? []), [])
  const mark = (l: Line): Line => ({
    ...l,
    cards: withCards(withCards(l.cards, goneCards), [from]),
    rxRows: [...(l.rxRows ?? []), ...goneKeys, ...keys],
    counted: [...(fresh ? [] : (l.counted ?? [])), ...countedKeys],
  })
  const next = lines.filter((l) => gone.indexOf(l) < 0).map((was) => {
    if (was.medicineId !== match.id) return was
    const more = onto[was.batchId] ?? 0
    if (more === 0) return mark(was)
    const l = fair(was)
    const before = had(l)
    const said = l.batchId === first ? working : rest
    return mark({
      ...l,
      quantity: String(before + more),
      gave: String(before + more),
      // A note goes on from the card's own working only while the line still holds what the card gave.
      worked: fresh ? said || undefined : andThen(l.worked && asGiven(l) ? l.worked : `${before} already on the bill`, said),
      short: l.batchId === first ? andThen(asGiven(l) ? l.short : undefined, short) : l.short,
      notesFor: from.patientId,
    })
  })
  return {
    lines: [
      ...next,
      ...taken.map((t) =>
        lineOf(match, t.batch, {
          quantity: String(t.quantity),
          gave: String(t.quantity),
          cards: withCards(goneCards, [from]),
          rxRows: [...goneKeys, ...keys],
          counted: countedKeys,
          notesFor: from.patientId,
          worked: prescribed === 0 ? undefined : t.batch.id === first ? working || undefined : rest,
          short: t.batch.id === first ? short : undefined,
        }),
      ),
    ],
    focus: first,
  }
}

export function PharmacyBilling() {
  const { user } = useAuth()
  const canSell = Boolean(user?.can_run_pharmacy)
  const [walkIn, setWalkIn] = useState(true)
  const [patient, setPatient] = useState<Patient | null>(null)
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  // Who prescribed: not typed at the counter. It is taken from the patient's latest prescription or their IPD case,
  // and kept on the bill for the prescription-medicines register.
  const [doctor, setDoctor] = useState('')
  const [lines, setLines] = useState<Line[]>([])
  const [discount, setDiscount] = useState<BillDiscount>(noDiscount())
  const [paid, setPaid] = useState('')
  const [paidTouched, setPaidTouched] = useState(false)
  const [mode, setMode] = useState('cash')
  const [reference, setReference] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [done, setDone] = useState<PharmacySale | null>(null)
  // The counter is worked from the keyboard: which match is highlighted, and which line's quantity to jump to next.
  const jumpTo = useRef<number | null>(null)
  const [given, setGiven] = useState('')
  const searchBox = useRef<HTMLInputElement>(null)
  const quantityBoxes = useRef<Record<number, HTMLInputElement | null>>({})
  // The cards on screen, with the patient they were asked for. Each choice of patient is numbered, and an answer for an
  // earlier choice is dropped, so a slow answer can never put one patient's cards under another's name.
  const [cardsOf, setCardsOf] = useState<{ patientId: number; cards: PrescriptionForPharmacy[] } | null>(null)
  const asking = useRef(0)
  const loadCards = useCallback((patientId: number | null, takeDoctor?: (doctor: string) => void) => {
    const ask = ++asking.current
    setCardsOf(null)
    if (patientId === null) return
    api.get<PrescriptionForPharmacy[]>('/pharmacy/prescriptions', { params: { patient_id: patientId } }).then((res) => {
      if (ask !== asking.current) return
      setCardsOf({ patientId, cards: res.data })
      if (takeDoctor && res.data[0]?.doctor) takeDoctor(res.data[0].doctor)
    })
  }, [])
  // Today's bills, newest first, shown under the counter so an earlier bill can be reopened to add medicines.
  const [todays, setTodays] = useState<PharmacySale[] | null>(null)
  // The bill whose customer is being looked at as a whole: all their bills in one window.
  const [together, setTogether] = useState<number | null>(null)
  const navigate = useNavigate()
  const loadTodays = useCallback(() => {
    api
      .get<PharmacySale[]>('/pharmacy/sales')
      .then((res) => setTodays([...res.data].sort((a, b) => b.id - a.id)))
      .catch(() => setTodays([]))
  }, [])
  useEffect(loadTodays, [loadTodays, done])

  // The patients admitted now. A bill for one of them can go on the IPD bill instead of being paid at the counter.
  const [inpatients, setInpatients] = useState<PharmacyInpatient[]>([])
  const [fromWard, setFromWard] = useState(false)
  const [onIpd, setOnIpd] = useState(true)
  useEffect(() => {
    api.get<PharmacyInpatient[]>('/pharmacy/inpatients').then((res) => setInpatients(res.data)).catch(() => undefined)
  }, [done])

  // Choosing a registered patient brings up what the doctor prescribed recently.
  const choosePatient = (next: Patient | null) => {
    setPatient(next)
    setOnIpd(true)
    setDoctor('')
    loadCards(next ? next.id : null, (first) => setDoctor((current) => current || first))
  }


  // Once a newly added line is on the screen, the cursor goes to its quantity, with the 1 selected so a number replaces it.
  useEffect(() => {
    if (jumpTo.current === null) return
    const box = quantityBoxes.current[jumpTo.current]
    jumpTo.current = null
    box?.focus()
    box?.select()
  }, [lines])

  // Ctrl+S (or ⌘S) saves the bill from wherever the cursor is.
  const saveKey = useRef<() => void>(() => {})
  useEffect(() => {
    searchBox.current?.focus()
    const onKey = (e: globalThis.KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault()
        saveKey.current()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const [params, setParams] = useSearchParams()
  const presetPatient = params.get('patient')
  useEffect(() => {
    if (!presetPatient) return
    // Chosen from a patient's page, or in the search at the top of this screen: the bill is for them, unless another
    // patient was chosen while this one was being looked up.
    const ask = ++asking.current
    api.get<Patient>(`/patients/${presetPatient}`).then((res) => {
      setParams({}, { replace: true })
      if (ask !== asking.current) return
      setWalkIn(false)
      setFromWard(false)
      setOnIpd(true)
      setDoctor('')
      setDone(null)
      setPatient(res.data)
      // Straight on to the medicines: the next thing typed is the first medicine's name.
      setTimeout(() => searchBox.current?.focus(), 0)
      loadCards(res.data.id, setDoctor)
    })
  }, [presetPatient, setParams, loadCards])
  // Only the chosen patient's own cards are ever shown or pressed.
  const prescriptions = cardsOf && patient && cardsOf.patientId === patient.id ? cardsOf.cards : []

  // A bill with medicines on it that has not been saved: leaving the counter asks first.
  useUnsaved(lines.length > 0 && !saving, 'This pharmacy bill is not saved yet. Leave without saving it?')
  // The chosen patient's IPD case, if they are admitted, and whether this bill is going onto it.
  const admitted = !walkIn && patient ? inpatients.find((a) => a.patient.id === patient.id) : undefined
  const toIpd = Boolean(admitted) && !admitted!.bill_approved && onIpd
  const subtotal = lines.reduce((sum, l) => sum + lineAmount(l), 0)
  const discountAmount = discountRupees(discount, subtotal)
  const total = Math.max(0, Math.round(subtotal - discountAmount))
  // Until the cashier types an amount, assume the customer pays in full.
  const paidValue = paidTouched ? paid : String(total)

  // Straight to the quantity of that line: at once if it is on the bill already, otherwise as soon as it appears.
  const jumpToLine = (batchId: number, already: boolean) => {
    const there = quantityBoxes.current[batchId]
    if (there && already) {
      there.focus()
      there.select()
    } else {
      jumpTo.current = batchId
    }
  }

  const add = (m: SellableMedicine, batch: Batch) => {
    setDone(null)
    setError('')
    setLines((rows) => (rows.some((r) => r.batchId === batch.id) ? rows : [...rows, lineOf(m, batch)]))
    jumpToLine(batch.id, lines.some((r) => r.batchId === batch.id))
  }

  // Rows of a prescription card put on the bill: the row whose Add was pressed, or every row in stock. When the card
  // has worked out how many, they are taken from the batch that expires first, then the next, never more than is in
  // stock; otherwise the medicine starts at 1, as when picked by name. A row already on the bill is not added again:
  // the cursor goes to its line. Nor is a row whose medicine is on the bill some other way (rowState), unless "Add N
  // more" was pressed for it (onPurpose). Rows of one medicine go on together (see withRows).
  const addFromCard = (rx: PrescriptionForPharmacy, picked: number[], onPurpose = false) => {
    if (!patient) return
    const from: CardTag = { kind: rx.kind ?? 'visit', id: rx.id, at: rx.completed_at, patientId: patient.id, doctor: rx.doctor }
    const groups: { match: SellableMedicine; rows: CardRow[] }[] = []
    // Medicines whose rows are skipped as on the bill some other way: their lines of this patient still count this
    // card among theirs, so a bill given from a visit's card is linked to that visit. Nothing is added, the row stays as
    // it was, and a line from another patient's card is left alone.
    const carry: number[] = []
    let focus: number | undefined
    for (const index of picked) {
      const m = rx.medicines[index]
      const match = m.match
      const key = rowKey(patient.id, rx, m)
      // The same line written twice on one card is one row.
      if (groups.some((g) => g.rows.some((r) => r.key === key))) continue
      const state = rowState(lines, patient.id, rx, m)
      const placed = lines.find((l) => (state === 'placed' ? (l.rxRows ?? []).includes(key) : match !== null && l.medicineId === match.id))
      if (state === 'placed' || (state === 'other' && !onPurpose)) {
        focus = placed?.batchId ?? focus
        if (state === 'other' && match) carry.push(match.id)
      } else if (match && match.sellable_batches.length > 0) {
        const group = groups.find((g) => g.match.id === match.id)
        if (group) group.rows.push({ m, key })
        else groups.push({ match, rows: [{ m, key }] })
      }
    }
    let next = carry.length > 0 ? lines.map((l) => (carry.indexOf(l.medicineId) >= 0 && onlyOf(l, patient.id) ? { ...l, cards: withCards(l.cards, [from]) } : l)) : lines
    for (const { match, rows } of groups) {
      const added = withRows(next, match, rows, from)
      next = added.lines
      focus = added.focus ?? focus
    }
    if (focus === undefined) return
    setDone(null)
    setError('')
    setLines(next)
    jumpToLine(focus, next === lines)
  }

  /** "On the bill" on a row whose medicine is on the bill some other way: only to its first line; nothing changes. */
  const goToLine = (m: CardMedicine) => {
    const line = m.match ? lines.find((l) => l.medicineId === m.match!.id) : undefined
    if (line) jumpToLine(line.batchId, true)
  }

  /** Enter in a quantity or discount box goes back to the search box for the next medicine, instead of saving. */
  const backToSearch = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      searchBox.current?.focus()
    }
  }

  const setLine = (batchId: number, key: 'quantity' | 'discount', value: string) =>
    setLines((rows) => rows.map((r) => (r.batchId === batchId ? { ...r, [key]: value } : r)))

  // Reprints the last bill made today, for the customer who comes back to the counter for a copy.
  const printPrevious = async () => {
    setError('')
    const res = await api.get<PharmacySale[]>('/pharmacy/sales')
    const last = [...res.data].sort((a, b) => b.id - a.id)[0]
    if (last) window.open(`/pharmacy/bills/${last.id}/print`, '_blank')
    else setError('No pharmacy bill has been made today yet. Older bills can be printed from All bills.')
  }

  // The day the medicines were given, when a bill that was missed is entered later. Money taken with it counts on that
  // day too. Only a day chosen on purpose is kept: otherwise it is today, read from the clock each time, so a counter
  // left open past midnight never dates the next bill as yesterday's.
  const [earlierOn, setEarlierOn] = useState<string | null>(null)
  const soldOn = earlierOn ?? today()
  // The visit the bill is linked to when it is saved: the newest of this patient's visit cards the medicines were given
  // from, made on or before the bill's day, as the server requires.
  const visitFor = (day: string) => (walkIn || !patient ? null : visitOf(lines.reduce<CardTag[]>((all, l) => withCards(all, l.cards ?? []), []), patient.id, day))
  const linkedVisit = visitFor(soldOn)
  // A bill whose medicines came from a visit card's rows is made from that prescription, so it cannot be dated before
  // it, as the server holds. A card only pressed for medicines already on the bill puts no rows on it and does not count.
  const tooEarly = (day: string) => {
    if (walkIn || !patient) return null
    const late = lines
      .reduce<CardTag[]>((all, l) => withCards(all, l.cards ?? []), [])
      .filter((t) => t.kind === 'visit' && t.patientId === patient.id && t.at.slice(0, 10) > day && lines.some((l) => (l.rxRows ?? []).some((k) => k.startsWith(`${t.patientId}-visit-${t.id}:`))))
    if (late.length === 0) return null
    const newest = late.reduce((a, b) => (b.at > a.at ? b : a))
    return `Medicines on this bill came from the prescription of ${longDate(newest.at)}${newest.doctor ? ` by ${newest.doctor}` : ''}, so the bill cannot be dated earlier. Date it that day or later, or take them off.`
  }
  const dateProblem = tooEarly(soldOn)
  const reset = () => {
    setLines([])
    setPatient(null)
    loadCards(null)
    setName('')
    setPhone('')
    setDoctor('')
    // The next bill starts with no discount, typed the same way as the last one.
    setDiscount((d) => noDiscount(d.mode))
    setPaid('')
    setPaidTouched(false)
    setReference('')
    setError('')
    setGiven('')
    // Every bill starts as today's: an earlier day is chosen again for each bill that was missed.
    setEarlierOn(null)
    searchBox.current?.focus()
  }

  const save = async (e?: FormEvent, print = false) => {
    e?.preventDefault()
    const customer = walkIn ? name.trim() : patient?.name
    if (!customer) {
      setError(walkIn ? 'Enter the customer name.' : fromWard ? 'Choose the inpatient.' : 'Choose a patient.')
      return
    }
    const problem = tooEarly(earlierOn ?? today())
    if (problem) {
      setError(problem)
      return
    }
    setSaving(true)
    setError('')
    // The print page is opened at the click itself; a window opened after the wait would be blocked as a pop-up.
    const printTab = print ? window.open('', '_blank') : null
    // The bill's day and its visit, as they are at this moment.
    const visit = visitFor(earlierOn ?? today())
    try {
      const res = await api.post<PharmacySale>('/pharmacy/sales', {
        patient_id: walkIn ? null : patient?.id,
        customer_name: customer,
        customer_phone: (walkIn ? phone : patient?.mobile) || null,
        // Who prescribed: the doctor of the visit the bill is linked to, else the patient's latest card or IPD case.
        referred_doctor: walkIn ? null : (visit?.doctor || doctor) || null,
        discount: discountAmount,
        // An inpatient's medicines go on the IPD bill: nothing is taken at the counter.
        paid: toIpd ? 0 : paidValue || 0,
        ...(toIpd ? { admission_id: admitted!.admission_id } : {}),
        // The visit whose prescription card the medicines came from, so that prescription shows as filled by this bill.
        consultation_id: visit?.id ?? null,
        payment_mode: mode,
        reference: reference || null,
        sold_on: earlierOn,
        items: lines.map((l) => ({ stock_batch_id: l.batchId, quantity: l.quantity || 0, discount_percent: l.discount || 0 })),
      })
      reset()
      setDone(res.data)
      if (printTab) printTab.location.href = `/pharmacy/bills/${res.data.id}/print`
    } catch (err) {
      printTab?.close()
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setSaving(false)
    }
  }

  saveKey.current = () => {
    if (!saving && lines.length > 0 && canSell) void save()
  }
  const change = mode === 'cash' && Number(given) > 0 ? Math.round((Number(given) - Number(paidValue || 0)) * 100) / 100 : 0

  return (
    <>
      <PharmacyTabs />
      <form className="split" onSubmit={save} noValidate>
        <section className="card">
          <div className="toolbar">
            <h1>Pharmacy billing</h1>
            <div className="tabs flush">
              <button
                type="button"
                className={walkIn ? 'active' : ''}
                onClick={() => {
                  setWalkIn(true)
                  setFromWard(false)
                }}
              >
                Walk-in
              </button>
              <button
                type="button"
                className={!walkIn && !fromWard ? 'active' : ''}
                onClick={() => {
                  setWalkIn(false)
                  setFromWard(false)
                }}
              >
                Registered patient
              </button>
              <button
                type="button"
                className={!walkIn && fromWard ? 'active' : ''}
                onClick={() => {
                  setWalkIn(false)
                  setFromWard(true)
                  if (patient && !inpatients.some((a) => a.patient.id === patient.id)) choosePatient(null)
                }}
              >
                Inpatient
                {inpatients.length > 0 && (
                  <span className="count" title={inpatients.some((a) => a.orders_waiting) ? 'Patients admitted now. Some have medicines ordered on the ward that nothing has been sent for yet.' : 'Patients admitted now'}>
                    {inpatients.length}
                    {inpatients.some((a) => a.orders_waiting) ? ' •' : ''}
                  </span>
                )}
              </button>
            </div>
          </div>

          {done && (
            <p className="notice">
              Bill {done.bill_no} saved for {done.customer_name}: {rupees(done.total)}
              {done.ipd_transfer > 0 ? `, ${rupees(done.ipd_transfer)} put on the IPD bill` : done.due > 0 ? `, ${rupees(done.due)} due` : ', paid'}.{' '}
              <a href={`/pharmacy/bills/${done.id}/print`} target="_blank" rel="noreferrer">
                Print bill
              </a>
            </p>
          )}

          {walkIn ? (
            <div className="grid">
              <TextField label="Customer name" required value={name} onChange={setName} />
              <TextField label="Phone" inputMode="tel" value={phone} onChange={setPhone} />
            </div>
          ) : (
            <div>
              <div>
                {fromWard ? (
                  <label className="field">
                    <span className="field-label">
                      Inpatient <span className="req">*</span>
                    </span>
                    <select
                      value={admitted ? String(admitted.admission_id) : ''}
                      onChange={(e) => {
                        const chosen = inpatients.find((a) => String(a.admission_id) === e.target.value)
                        choosePatient(chosen?.patient ?? null)
                        if (chosen?.doctor) setDoctor(chosen.doctor)
                      }}
                      autoFocus
                    >
                      <option value="">{inpatients.length ? 'Choose the patient' : 'Nobody is admitted just now'}</option>
                      {inpatients.map((a) => (
                        <option key={a.admission_id} value={a.admission_id}>
                          {a.bed ? `${a.bed} · ` : ''}
                          {a.patient.name} · {a.ipd_no}
                          {a.orders_waiting ? ` · ${a.orders_waiting} new order${a.orders_waiting === 1 ? '' : 's'}` : ''}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : (
                  <PatientPicker patient={patient} onChange={choosePatient} recent />
                )}
              </div>
            </div>
          )}

          {admitted && (
            <div className={`ipd-choice ${toIpd ? 'on' : ''}`}>
              <label className="check two-line">
                <input type="checkbox" checked={toIpd} disabled={admitted.bill_approved} onChange={(e) => setOnIpd(e.target.checked)} />
                <span>
                  Put this bill on the IPD bill
                  <small className="muted block">
                    {admitted.patient.name} is admitted: <IpdLink id={admitted.admission_id}>{admitted.ipd_no}</IpdLink>
                    {admitted.bed ? ` · ${admitted.bed}` : ''}
                    {admitted.doctor ? ` · ${admitted.doctor}` : ''}.{' '}
                    {admitted.bill_approved
                      ? 'That IPD bill has been approved, so nothing more can go on it: take the money here.'
                      : toIpd
                        ? 'Nothing is collected at the counter; the medicines are listed on the IPD bill.'
                        : 'Unticked: the money is taken here at the counter, as for any other bill.'}
                  </small>
                </span>
              </label>
            </div>
          )}

          {!walkIn &&
            prescriptions.map((rx) => {
              // The card's rows that are in stock, by their place on the card.
              const available = rx.medicines.map((m, i) => (m.match?.sellable_batches.length ? i : -1)).filter((i) => i >= 0)
              return (
                <div className={`rx-card ${rx.kind ?? 'visit'}`} key={`${rx.kind ?? 'visit'}-${rx.id}`}>
                  <div className="row">
                    <strong className="grow">
                      {rx.kind === 'ward' ? 'Ward orders' : rx.kind === 'discharge' ? `Discharge medicines · ${longDate(rx.completed_at)}` : `Prescription · ${longDate(rx.completed_at)}`}
                      {rx.doctor ? ` · ${rx.doctor}` : ''}
                      {rx.bill && (
                        <span className="pill ok">
                          {rx.filled ? 'Filled' : 'Billed since'} ·{' '}
                          <BillLink id={rx.bill.id} pharmacy>
                            {rx.bill.bill_no}
                          </BillLink>
                        </span>
                      )}
                      {rx.note && <small className="muted block">{rx.note}</small>}
                      {patient &&
                        takenOff(lines, patient.id, rx).map((g) => (
                          <small key={g.name} className="due block">
                            No longer on this card: {g.name} (“{g.said.join('; ')}”). The bill has {g.onBill}: check it.
                          </small>
                        ))}
                    </strong>
                    <button type="button" className="btn ghost" disabled={available.length === 0} onClick={() => addFromCard(rx, available)}>
                      Add all in stock ({available.length})
                    </button>
                  </div>
                  <ul>
                    {rx.medicines.map((m, i) => {
                      const state = patient ? rowState(lines, patient.id, rx, m) : 'free'
                      // Said once for the medicine, on its first row on this card: what it was when it went on the bill.
                      const firstOfIt = m.match ? rx.medicines.findIndex((x) => x.match?.id === m.match!.id) === i : false
                      const changed = patient && m.match && firstOfIt ? changedFrom(lines, patient.id, rx, m.match) : []
                      const onBill = m.match ? lines.filter((l) => l.medicineId === m.match!.id).reduce((sum, l) => sum + (Number(l.quantity) || 0), 0) : 0
                      // What Add puts on: the count, less what the bill already takes of the medicine's stock.
                      const room = m.match ? roomFor(lines, m.match) : 0
                      return (
                        <li key={i}>
                          <span className="grow">
                            {m.medicine}
                            {m.new && <span className="pill status-arrived">New</span>}
                            {m.directions && <small className="muted"> · {m.directions}</small>}
                            {m.match?.sellable_batches[0] && placeLabel(m.match.sellable_batches[0].rack_no, m.match.sellable_batches[0].box_no) && (
                              <small className="shelf-place"> {placeLabel(m.match.sellable_batches[0].rack_no, m.match.sellable_batches[0].box_no)}</small>
                            )}
                            {m.worked_out && m.quantity ? (
                              <small className="muted block">
                                {m.worked_out}
                                {inStock(m) < m.quantity && <span className="due"> · {shortOf(m.quantity, inStock(m))}</span>}
                              </small>
                            ) : null}
                            {changed.length > 0 && (
                              <small className="due block">
                                Changed on the card after it went on the bill as “{changed.join('; ')}”. The bill has {onBill}: check the quantity.
                              </small>
                            )}
                          </span>
                          {!m.match?.sellable_batches.length ? (
                            <small className="due">Not in stock under this name</small>
                          ) : state === 'placed' ? (
                            <button type="button" className="link" onClick={() => addFromCard(rx, [i])} title="Already on the bill: go to its line">
                              On the bill
                            </button>
                          ) : m.quantity && room === 0 ? (
                            <small className="due">All in stock is on the bill</small>
                          ) : state === 'other' && !m.quantity ? (
                            <button type="button" className="link" onClick={() => goToLine(m)} title="This medicine is already on the bill: go to its line">
                              On the bill
                            </button>
                          ) : state === 'other' ? (
                            <span className="rx-row-acts">
                              <button type="button" className="link" onClick={() => goToLine(m)} title="This medicine is already on the bill: go to its line">
                                On the bill
                              </button>
                              <button type="button" className="link" onClick={() => addFromCard(rx, [i], true)} title="Put this row's count on top of what is already on the bill">
                                Add {Math.min(m.quantity!, room)} more
                              </button>
                            </span>
                          ) : (
                            <button type="button" className="link" onClick={() => addFromCard(rx, [i])}>
                              {m.quantity ? `Add ${Math.min(m.quantity, room)}` : 'Add'}
                            </button>
                          )}
                        </li>
                      )
                    })}
                  </ul>
                </div>
              )
            })}

          <h3>Medicines</h3>
          <MedicinePick inputRef={searchBox} onPick={add} hint="↑ ↓ to choose · Enter to add · type the quantity · Enter for the next medicine · Ctrl+S to save" />

          <div className="table-wrap gap-top">
            <table className="compact">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch</th>
                  <th>Expiry</th>
                  <th className="num">Price</th>
                  <th className="num">Qty</th>
                  <th className="num">Disc %</th>
                  <th className="num">Amount</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {lines.map((l) => (
                  <tr key={l.batchId} className="static">
                    <td>
                      <strong>{l.medicine}</strong>
                      {l.place && <small className="shelf-place block">{l.place}</small>}
                      {l.worked && ownNotes(l, walkIn ? undefined : patient?.id) && <small className="muted block">{l.worked}</small>}
                      {l.short && ownNotes(l, walkIn ? undefined : patient?.id) && <small className="due block">{l.short}</small>}
                    </td>
                    <td>{l.batchNo}</td>
                    <td>
                      {expiryLabel(l.expiry)}
                      {daysUntil(l.expiry) <= 90 && <small className="soon block">expires soon</small>}
                    </td>
                    <td className="num">{rupees(l.unitPrice)}</td>
                    <td className="num">
                      <input
                        className="qty"
                        type="number"
                        min={1}
                        max={l.available}
                        value={l.quantity}
                        ref={(box) => {
                          quantityBoxes.current[l.batchId] = box
                        }}
                        onKeyDown={backToSearch}
                        onChange={(e) => setLine(l.batchId, 'quantity', e.target.value)}
                        aria-label={`Quantity of ${l.medicine}`}
                        aria-invalid={Number(l.quantity) > l.available}
                      />
                      <small className={`block ${Number(l.quantity) > l.available ? 'due' : 'muted'}`}>of {l.available}</small>
                    </td>
                    <td className="num">
                      <input className="qty" type="number" min={0} max={100} value={l.discount} onKeyDown={backToSearch} onChange={(e) => setLine(l.batchId, 'discount', e.target.value)} aria-label={`Discount for ${l.medicine}`} />
                    </td>
                    <td className="num">{rupees(lineAmount(l))}</td>
                    <td className="num">
                      <button type="button" className="link danger" onClick={() => setLines((rows) => rows.filter((r) => r.batchId !== l.batchId))}>
                        Remove
                      </button>
                    </td>
                  </tr>
                ))}
                {lines.length === 0 && (
                  <tr className="empty">
                    <td colSpan={8}>Type a medicine name above to start the bill.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        <aside className="card panel narrow">
          <h2>Bill summary</h2>
          <dl className="totals">
            <dt>Items</dt>
            <dd>{lines.length}</dd>
            <dt>Amount</dt>
            <dd>{rupees(Math.round(subtotal * 100) / 100)}</dd>
            <dt>Discount</dt>
            <dd>
              <BillDiscountBox value={discount} onChange={setDiscount} subtotal={subtotal} />
            </dd>
            <dt className="strong">Bill amount</dt>
            <dd className="strong bill-total">{rupees(total)}</dd>
          </dl>
          <p className="muted small">Prices include GST. The bill is rounded to the nearest rupee.</p>

          {toIpd && (
            <div className="tile gap-top ipd-tile">
              <span>Goes on IPD bill {admitted!.ipd_no}</span>
              <strong>{rupees(total)}</strong>
            </div>
          )}
          <div className={toIpd ? 'hidden' : 'grid gap-top'}>
            <TextField
              label="Paid (₹)"
              type="number"
              min={0}
              step="0.01"
              value={paidValue}
              onChange={(v) => {
                setPaid(v)
                setPaidTouched(true)
              }}
            />
            <SelectField label="Mode" value={mode} onChange={setMode} options={PAYMENT_MODES} />
            {mode === 'cash' ? (
              <>
                <TextField label="Cash given (optional)" type="number" min={0} step="1" value={given} onChange={setGiven} />
                <div className="field">
                  <span className="field-label">To return</span>
                  <strong className={`change ${change > 0 ? 'has' : ''}`}>{change > 0 ? rupees(change) : '—'}</strong>
                </div>
              </>
            ) : (
              <div className="span-2">
                <TextField label="Reference (optional)" value={reference} onChange={setReference} />
              </div>
            )}
          </div>

          {linkedVisit && (
            <p className="muted small">
              Given from the prescription of {longDate(linkedVisit.at)}
              {linkedVisit.doctor ? ` by ${linkedVisit.doctor}` : ''}.
            </p>
          )}
          {canSell && (
            <EarlierDate
              key={done?.id ?? 0}
              what="bill"
              value={soldOn}
              onChange={(day) => {
                setEarlierOn(day === today() ? null : day)
                // What was said about the day before no longer holds.
                setError('')
              }}
            />
          )}
          {dateProblem && dateProblem !== error && <p className="form-error">{dateProblem}</p>}
          {error && <p className="form-error">{error}</p>}
          {!canSell && <p className="muted gap-top">Only pharmacy staff can save bills.</p>}
          <div className="bill-actions">
            <button className="btn ghost" disabled={saving || lines.length === 0 || !canSell} title="Ctrl + S">
              {saving ? 'Saving…' : 'Save'}
            </button>
            <button type="button" className="btn primary" disabled={saving || lines.length === 0 || !canSell} onClick={() => save(undefined, true)}>
              Save and print
            </button>
          </div>
          <div className="bill-links">
            <button type="button" className="link" onClick={() => (lines.length === 0 || window.confirm('Clear this bill? The medicines on it will be taken off.')) && reset()}>
              Clear bill
            </button>
            <button type="button" className="link" onClick={printPrevious}>
              Print previous bill
            </button>
          </div>
        </aside>
      </form>

      <section className="card gap-top todays-bills">
        <div className="toolbar">
          <h2>Today's bills</h2>
          {todays && todays.length > 0 && (
            <span className="muted">
              {todays.filter((s) => !s.voided_at).length === 1 ? '1 bill' : `${todays.filter((s) => !s.voided_at).length} bills`} · {rupees(todays.filter((s) => !s.voided_at).reduce((sum, s) => sum + s.total - s.returned, 0))}
            </span>
          )}
          <Link className="link" to="/pharmacy/bills">
            All bills and earlier days
          </Link>
        </div>
        <div className="table-wrap">
          <table className="compact">
            <thead>
              <tr>
                <th>Bill</th>
                <th>Customer</th>
                <th className="num">Amount</th>
                <th className="num">Balance</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {todays?.map((s) => (
                <tr key={s.id} className={`static ${s.voided_at ? 'faded' : ''}`}>
                  <td>
                    <span className="mono">{s.bill_no}</span>
                    <small className="muted block">{clock(s.sold_at)}</small>
                  </td>
                  <td>
                    <button type="button" className="patient-link" onClick={() => setTogether(s.id)} title="All of this customer's pharmacy bills together">
                      {s.customer_name}
                    </button>
                    {s.customer_phone && <small className="muted block">{s.customer_phone}</small>}
                  </td>
                  <td className="num">{rupees(s.total)}</td>
                  <td className={`num ${s.due > 0 ? 'due strong' : ''}`}>{s.voided_at ? <span className="pill off">Deleted</span> : rupees(s.due)}</td>
                  <td className="num actions">
                    {canSell && !s.voided_at && s.returned === 0 && s.ipd_transfer === 0 && (
                      <Link className="link" to={`/pharmacy/bills?add=${s.id}`}>
                        Add medicines
                      </Link>
                    )}
                    <a className="link" href={`/pharmacy/bills/${s.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                  </td>
                </tr>
              ))}
              {todays && todays.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>No pharmacy bill has been made today yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
      {together !== null && (
        <PharmacyDay saleId={together} canCollect={canSell} onClose={() => setTogether(null)} onChange={loadTodays} onOpenSale={(id) => navigate(`/pharmacy/bills?open=${id}`)} />
      )}
    </>
  )
}
