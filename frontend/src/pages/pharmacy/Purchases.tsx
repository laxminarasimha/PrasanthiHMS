import { MedicineChoose } from '../../components/MedicineChoose'
import { NewMedicine } from '../../components/NewMedicine'
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { canLeave, UnsavedGuard } from '../../unsaved'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { PAYMENT_MODES, expiryLabel, longDate, modeLabel, rupees, today } from '../../format'
import type { Medicine, PurchaseInvoice, PurchaseOrder, Stockist } from '../../types'

type Line = {
  /** Set when the line is an existing batch on an invoice being corrected. */
  id?: number
  medicine_id: string
  batch_no: string
  expiry: string
  units_per_pack: string
  packs: string
  free_packs: string
  pack_price: string
  pack_mrp: string
  discount_percent: string
  gst_percent: string
  rack_no: string
  box_no: string
  hsn_code: string
  discount_amount: string
  tax_on_free: boolean
  /** Set on a line filled in from a purchase order: what the order says of it, and what it was last bought at. */
  from_order?: FromOrder
}
/** What is typed on a line. */
type Typed = Omit<Line, 'id' | 'from_order'>
type StillToCome = NonNullable<PurchaseOrder['still_to_come']>[number]
type FromOrder = {
  order_no: string
  name: string
  /** Packs of it still to come on the order when it was chosen, in the order's own pack size, rounded up. */
  to_come: number
  /** Exactly what was left of it on the order when it was chosen, in units. */
  units_to_come: number
  /** The order's pack size. */
  units_per_pack: number
  not_in_use: boolean
  last_rate: number | null
  last_mrp: number | null
  last_on: string | null
  /** The last rate is this stockist's own. */
  last_here: boolean
  /** The line as it was filled in. Null on a line that had already been typed when the order was chosen. */
  filled: Typed | null
}

const emptyLine = (): Line => ({
  medicine_id: '', batch_no: '', expiry: '', units_per_pack: '', packs: '', free_packs: '', pack_price: '', pack_mrp: '', discount_percent: '', gst_percent: '', rack_no: '', box_no: '', hsn_code: '', discount_amount: '', tax_on_free: false,
})

/** The most medicines one invoice takes. */
const MOST_LINES = 200
const NOT_OPEN: Record<PurchaseOrder['status'], string> = { draft: 'not approved yet', open: 'open', received: 'already received', cancelled: 'cancelled' }

const sameAs = (l: Line, other: Typed) => (Object.keys(other) as (keyof Typed)[]).every((key) => l[key] === other[key])
/** A line filled in from an order that nobody has typed into since. Only such a line ever comes off by itself. */
const untouched = (l: Line) => {
  const filled = l.from_order?.filled
  return filled ? sameAs(l, filled) : false
}
/** When the order or the stockist changes: the lines filled in from the order and never typed into come off; the rest stay as ordinary lines. */
const letGo = (rows: Line[]) => {
  const kept = rows.filter((l) => !untouched(l)).map((l) => (l.from_order ? { ...l, from_order: undefined } : l))
  return kept.length ? kept : [emptyLine()]
}
const offSentence = (off: string[]) => (off.length ? `Taken off, as nothing had been typed into ${off.length === 1 ? 'it' : 'them'}: ${off.join(', ')}.` : '')

/**
 * The lines once an order is chosen: one for each medicine still to come on it, with the order's pack size and the
 * packs still to come, after the lines already there. A medicine already on the invoice is not added again; the first
 * line of it only gets the order's note. Batch, expiry, rate and MRP are left to be typed from the stockist's bill.
 */
function fillFromOrder(rows: Line[], order: PurchaseOrder) {
  const blank = emptyLine()
  const toCome = order.still_to_come ?? []
  const note = (s: StillToCome, filled: Typed | null): FromOrder => ({
    order_no: order.order_no, name: s.medicine_name, to_come: s.packs, units_to_come: s.units_to_come ?? s.packs * s.units_per_pack, units_per_pack: s.units_per_pack, not_in_use: !s.is_active,
    last_rate: s.last_rate, last_mrp: s.last_mrp, last_on: s.last_on, last_here: s.last_from_this_stockist, filled,
  })
  const already: string[] = []
  const noted = new Set<string>()
  // A line with nothing typed on it makes way; every other line stays as it was typed. A medicine typed on two lines,
  // two batches of it, is told about once: how all its lines stand against the order shows on the first (againstOrder).
  const kept = rows
    .filter((l) => l.id !== undefined || l.from_order !== undefined || !sameAs(l, blank))
    .map((l) => {
      const s = l.from_order || noted.has(l.medicine_id) ? undefined : toCome.find((x) => String(x.medicine_id) === l.medicine_id)
      if (!s) return l
      noted.add(l.medicine_id)
      already.push(s.medicine_name)
      return { ...l, from_order: note(s, null) }
    })
  const onInvoice = new Set(kept.map((l) => l.medicine_id))
  const wanted = toCome.filter((s) => !onInvoice.has(String(s.medicine_id)))
  const fresh = wanted.slice(0, Math.max(0, MOST_LINES - kept.length)).map((s): Line => {
    const filled: Typed = {
      ...blank, medicine_id: String(s.medicine_id), units_per_pack: String(s.units_per_pack), packs: String(s.packs),
      gst_percent: s.gst_percent === null ? '' : String(s.gst_percent), hsn_code: s.hsn_code ?? '', rack_no: s.rack_no ?? '', box_no: s.box_no ?? '',
    }
    return { ...filled, from_order: note(s, filled) }
  })
  const lines = [...kept, ...fresh]
  return { lines: lines.length ? lines : [emptyLine()], added: fresh.length, already, left: wanted.length - fresh.length }
}

/** What the form says once an order has been chosen. */
function filledSentence(order: PurchaseOrder, result: ReturnType<typeof fillFromOrder>, off: string[]) {
  const said = [offSentence(off)]
  if (order.status !== 'open') said.push(`Order ${order.order_no} is ${NOT_OPEN[order.status]}, so nothing was filled in.`)
  else if (!(order.still_to_come ?? []).length) said.push(`Nothing is still to come on order ${order.order_no}.`)
  if (result.added)
    said.push(
      `${result.added === 1 ? 'The medicine' : `The ${result.added} medicines`} still to come on ${order.order_no} ${result.added === 1 ? 'is' : 'are'} filled in with the packs still to come. Type the batch, expiry, rate and MRP from the stockist’s bill, change the packs if fewer came, and remove any that did not come.`,
    )
  if (result.already.length) said.push(`Already on this invoice, and left as typed: ${result.already.join(', ')}.`)
  if (result.left > 0) said.push(`An invoice takes at most ${MOST_LINES} medicines, so ${result.left} more ${result.left === 1 ? 'stays' : 'stay'} on the order for the next invoice.`)
  return said.filter(Boolean).join(' ')
}

/**
 * How the packs typed for a medicine stand against what is still to come of it on the order, over every line of that
 * medicine: two batches of it are two lines. Worked out in units, as the order is matched with its invoices, since a
 * stockist may send another pack size; told in the order's packs, or in units once another pack size is typed. Free
 * packs come on top of an order and do not count. Said once, on the first line of the medicine filled in from the order.
 */
function againstOrder(rows: Line[], index: number, removed: number[]) {
  const from = rows[index].from_order
  const medicine = rows[index].medicine_id
  if (!from || rows.findIndex((r) => r.from_order !== undefined && r.medicine_id === medicine) !== index) return null
  const typed = rows.filter((r) => r.medicine_id === medicine && !(r.id !== undefined && removed.includes(r.id)) && r.packs.trim() !== '' && !Number.isNaN(Number(r.packs)))
  if (!typed.length) return null
  const perPack = Math.max(1, from.units_per_pack)
  const sizeOf = (r: Line) => Number(r.units_per_pack) || perPack
  const left = from.units_to_come - typed.reduce((sum, r) => sum + Number(r.packs) * sizeOf(r), 0)
  if (left === 0) return null
  // Told in packs only while it comes to whole packs of the order's size.
  const inPacks = typed.every((r) => sizeOf(r) === perPack) && left % perPack === 0
  const n = Math.abs(left) / (inPacks ? perPack : 1)
  const what = inPacks ? String(n) : `${n} ${n === 1 ? 'unit' : 'units'}`
  return left > 0 ? `${what} ${n === 1 ? 'stays' : 'stay'} on order` : `${what} more than ordered`
}

/**
 * When the line holding the order's note on a medicine comes off, or is changed to another medicine, the next line of
 * that medicine takes the note, so its other batch still shows how it stands against the order. The note goes as on a
 * line that was typed, so that line never comes off by itself.
 */
function passNote(rows: Line[], from: FromOrder | undefined, medicine: string) {
  if (!from || rows.some((r) => r.from_order !== undefined && r.medicine_id === medicine)) return rows
  const next = rows.findIndex((r) => r.medicine_id === medicine)
  return next === -1 ? rows : rows.map((r, j) => (j === next ? { ...r, from_order: { ...from, filled: null } } : r))
}

/** What the stockist charges for the line before tax: paid packs, less the discount in % and in rupees. */
const lineNet = (l: Line) => Math.max(0, Number(l.packs || 0) * Number(l.pack_price || 0) * (1 - Number(l.discount_percent || 0) / 100) - Number(l.discount_amount || 0))
const lineTax = (l: Line) => ((lineNet(l) + (l.tax_on_free ? Number(l.free_packs || 0) * Number(l.pack_price || 0) : 0)) * Number(l.gst_percent || 0)) / 100
/** The real cost of one unit once the free packs are counted, and the margin against MRP. */
const costing = (l: Line) => {
  const perPack = Number(l.units_per_pack || 0)
  const units = (Number(l.packs || 0) + Number(l.free_packs || 0)) * perPack
  if (!units || !Number(l.pack_price)) return null
  const cost = lineNet(l) / units
  const mrp = perPack && Number(l.pack_mrp) ? Number(l.pack_mrp) / perPack : 0
  return { units, free: Number(l.free_packs || 0) * perPack, cost, mrp, margin: mrp ? ((mrp - cost) / mrp) * 100 : null }
}
const two = (n: number) => Math.round(n * 100) / 100

export function PharmacyPurchases() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [invoices, setInvoices] = useState<PurchaseInvoice[]>([])
  const [viewing, setViewing] = useState<PurchaseInvoice | null>(null)
  // Arriving from a stockist's page: with one of their invoices to open, or to add a new one for them. Or from an
  // open purchase order, to enter the invoice for it: the order brings its own stockist, so any other is not trusted.
  const [params, setParams] = useSearchParams()
  const [arrived] = useState(() => {
    const order = canEdit ? params.get('order') : null
    return { invoice: params.get('invoice'), add: canEdit && !order ? params.get('add') : null, order }
  })
  const [adding, setAdding] = useState(Boolean(arrived.add || arrived.order))
  const [editing, setEditing] = useState<PurchaseInvoice | null>(null)
  const [reason, setReason] = useState('')
  /** On an invoice being corrected: the saved lines that are to come off it. */
  const [removed, setRemoved] = useState<number[]>([])
  const [stockists, setStockists] = useState<Stockist[]>([])
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [stockistId, setStockistId] = useState(arrived.add ?? '')
  const [invoiceNo, setInvoiceNo] = useState('')
  const [invoiceDate, setInvoiceDate] = useState(today())
  const [orders, setOrders] = useState<PurchaseOrder[]>([])
  const [orderId, setOrderId] = useState('')
  const [remarks, setRemarks] = useState('')
  const [extras, setExtras] = useState({ other_charges: '', cash_discount: '', round_off: '' })
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  // What choosing an order filled in or took off, said above the lines.
  const [fillNote, setFillNote] = useState('')
  // An order opened from its own page, which may be older than the open orders listed for the form.
  const [pinned, setPinned] = useState<PurchaseOrder | null>(null)
  // The chosen order's medicines, which the list may not hold: one no longer in use, or the list not in yet.
  const [orderMedicines, setOrderMedicines] = useState<Medicine[]>([])
  const choices = useMemo(() => [...medicines, ...orderMedicines.filter((m) => !medicines.some((x) => x.id === m.id))], [medicines, orderMedicines])
  const openOrders = useMemo(() => (pinned && !orders.some((o) => o.id === pinned.id) ? [pinned, ...orders] : orders), [orders, pinned])
  // The order chosen last: the reply about an order chosen before it is not used.
  const asked = useRef('')
  const linesNow = useRef(lines)
  useEffect(() => {
    linesNow.current = lines
  }, [lines])
  // The line a new medicine is being added for, from its medicine list.
  const [newFor, setNewFor] = useState<number | null>(null)
  const [newName, setNewName] = useState('')
  const adopt = (m: Medicine, index: number) => {
    setMedicines((list) => [...list.filter((x) => x.id !== m.id), { ...m, units_in_stock: 0 }].sort((a, b) => a.name.localeCompare(b.name)))
    // A new medicine on a line filled in from an order is no longer what the order said, as when another is chosen (setLine).
    setLines((rows) => {
      const before = rows[index]
      const changed = rows.map((row, i) =>
        i === index
          ? { ...row, medicine_id: String(m.id), units_per_pack: String(m.units_per_pack), gst_percent: String(m.gst_percent), hsn_code: m.hsn_code ?? '', rack_no: m.rack_no ?? row.rack_no, box_no: m.box_no ?? row.box_no, from_order: String(m.id) === row.medicine_id ? row.from_order : undefined }
          : row,
      )
      return before && String(m.id) !== before.medicine_id ? passNote(changed, before.from_order, before.medicine_id) : changed
    })
    setNewFor(null)
  }
  const [errors, setErrors] = useState<string[]>([])
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [payAmount, setPayAmount] = useState('')
  const [payMode, setPayMode] = useState('cash')
  const [payRef, setPayRef] = useState('')
  const [payDate, setPayDate] = useState(today())
  const [payError, setPayError] = useState('')

  const load = useCallback(() => {
    api.get<PurchaseInvoice[]>('/pharmacy/purchases').then((res) => setInvoices(res.data))
  }, [])
  useEffect(load, [load])
  useEffect(() => {
    if (arrived.invoice) api.get<PurchaseInvoice>(`/pharmacy/purchases/${arrived.invoice}`).then((res) => setViewing(res.data)).catch(() => undefined)
    if (arrived.add || arrived.order) api.get<Medicine[]>('/pharmacy/medicines', { params: { list: 1 } }).then((res) => setMedicines(res.data))
    // The address goes back to the plain page, so a refresh does not open the same thing again.
    if (arrived.invoice || arrived.add || arrived.order) setParams({}, { replace: true })
  }, [arrived, setParams])

  /**
   * Fills the form from an order: a line for each medicine still to come on it. An order that is no longer open fills
   * nothing and is let go, as the invoice could not be saved against it.
   */
  const fill = (order: PurchaseOrder, off: string[]) => {
    if (order.status === 'open') {
      const toCome = order.still_to_come ?? []
      setOrderMedicines((list) => [
        ...list.filter((m) => !toCome.some((s) => s.medicine_id === m.id)),
        ...toCome.map((s) => ({ id: s.medicine_id, name: s.medicine_name, units_per_pack: s.units_per_pack, gst_percent: s.gst_percent ?? 0, hsn_code: s.hsn_code, rack_no: s.rack_no, box_no: s.box_no, is_active: s.is_active }) as Medicine),
      ])
      setLines((rows) => fillFromOrder(rows, order).lines)
    } else {
      asked.current = ''
      setOrderId('')
      setOrders((list) => list.filter((o) => o.id !== order.id))
    }
    setFillNote(filledSentence(order, order.status === 'open' ? fillFromOrder(linesNow.current, order) : { lines: [], added: 0, already: [], left: 0 }, off))
  }

  /** Choosing an order fills in what is still to come on it, once the lines filled in from the one chosen before, and never typed into, have come off. */
  const chooseOrder = (next: string) => {
    asked.current = next
    setOrderId(next)
    const off = lines.filter(untouched).map((l) => l.from_order?.name ?? '')
    setLines(letGo)
    if (!next) {
      setFillNote(offSentence(off))
      return
    }
    setFillNote([offSentence(off), 'Filling in what is still to come on the order…'].filter(Boolean).join(' '))
    api.get<PurchaseOrder>(`/pharmacy/purchase-orders/${next}`).then(
      (res) => {
        if (asked.current === next) fill(res.data, off)
      },
      () => {
        if (asked.current === next) setFillNote('What is still to come on this order could not be loaded. Choose it again, or type the lines in.')
      },
    )
  }

  // Arriving from an open order (?order=ID): its stockist and the order are chosen, and what is still to come filled in.
  const arrival = useRef(false)
  useEffect(() => {
    if (!arrived.order || arrival.current) return
    arrival.current = true
    const id = arrived.order
    asked.current = id
    api.get<PurchaseOrder>(`/pharmacy/purchase-orders/${id}`).then(
      (res) => {
        // Something else was chosen meanwhile.
        if (asked.current !== id) return
        const order = res.data
        if (order.status === 'open') {
          setStockists((list) => (list.some((s) => s.id === order.stockist.id) ? list : [order.stockist as Stockist, ...list]))
          setStockistId(String(order.stockist.id))
          setPinned(order)
          setOrderId(String(order.id))
        }
        fill(order, [])
      },
      () => {
        if (asked.current === id) setFillNote('That order could not be opened. Choose the stockist and the order here.')
      },
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [arrived])

  // The lists the form chooses from are fetched as the page opens, so they are there by the time "Add invoice" is
  // pressed, and fetched again each time the form opens in case a stockist or medicine was added meanwhile.
  // Until they arrive the form says it is loading; it never says "no stockists" about a list it has not received yet.
  const [lists, setLists] = useState<'loading' | 'ready' | 'failed'>('loading')
  const loadLists = useCallback(() => {
    return Promise.all([
      api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data)),
      api.get<PurchaseOrder[]>('/pharmacy/purchase-orders').then((res) => setOrders(res.data.filter((o) => o.status === 'open'))),
    ])
      .then(() => setLists('ready'))
      .catch(() => setLists((now) => (now === 'ready' ? now : 'failed')))
  }, [])
  useEffect(() => {
    void loadLists()
  }, [loadLists])
  /** Fetches the lists again when the form is opened, or after a failure; a list already in hand stays on screen meanwhile. */
  const refreshLists = () => {
    setLists((now) => (now === 'ready' ? now : 'loading'))
    void loadLists()
  }

  /** A new form, or one being corrected, has nothing to do with an order asked about before it. */
  const forgetOrder = () => {
    asked.current = ''
    setOrderId('')
    setPinned(null)
    setOrderMedicines([])
    setFillNote('')
  }

  const startAdding = () => {
    refreshLists()
    api.get<Medicine[]>('/pharmacy/medicines', { params: { list: 1 } }).then((res) => setMedicines(res.data))
    forgetOrder()
    setViewing(null)
    setEditing(null)
    setStockistId('')
    setInvoiceNo('')
    setInvoiceDate(today())
    setRemarks('')
    setExtras({ other_charges: '', cash_discount: '', round_off: '' })
    setLines([emptyLine()])
    setRemoved([])
    setAdding(true)
    setNotice('')
    setErrors([])
  }

  // Opens a saved invoice in the same form, to correct what was typed.
  const startEditing = (invoice: PurchaseInvoice) => {
    // The invoice's own stockist shows at once; the full list follows. Without this the box read "Select stockist" until it arrived.
    setStockists((list) => (list.some((s) => s.id === invoice.stockist.id) ? list : [invoice.stockist as Stockist, ...list]))
    refreshLists()
    // The medicines already on the invoice show at once; the whole list follows, for a medicine that was left out.
    const onInvoice = (invoice.batches ?? []).map((b) => ({ id: b.medicine_id, name: b.medicine?.name ?? '' }) as Medicine)
    setMedicines(onInvoice)
    api.get<Medicine[]>('/pharmacy/medicines', { params: { list: 1 } }).then((res) => setMedicines([...res.data, ...onInvoice.filter((m) => !res.data.some((all) => all.id === m.id))]))
    setRemoved([])
    forgetOrder()
    setEditing(invoice)
    setStockistId(String(invoice.stockist.id))
    setInvoiceNo(invoice.invoice_no)
    setInvoiceDate(invoice.invoice_date.slice(0, 10))
    setRemarks(invoice.remarks ?? '')
    setExtras({ other_charges: invoice.other_charges ? String(invoice.other_charges) : '', cash_discount: invoice.cash_discount ? String(invoice.cash_discount) : '', round_off: invoice.round_off ? String(invoice.round_off) : '' })
    setReason('')
    setLines(
      (invoice.batches ?? []).map((b) => ({
        id: b.id, medicine_id: String(b.medicine_id), batch_no: b.batch_no, expiry: b.expiry.slice(0, 7), units_per_pack: String(b.units_per_pack),
        packs: String(b.packs), free_packs: String(b.free_packs), pack_price: String(b.pack_price), pack_mrp: String(b.pack_mrp),
        discount_percent: String(b.discount_percent), gst_percent: String(b.gst_percent), rack_no: b.rack_no ?? '', box_no: b.box_no ?? '', hsn_code: b.hsn_code ?? '',
        discount_amount: b.discount_amount ? String(b.discount_amount) : '', tax_on_free: Boolean(b.tax_on_free),
      })),
    )
    setViewing(null)
    setAdding(true)
    setNotice('')
    setErrors([])
  }

  const setLine = (index: number, key: keyof Line, value: string | boolean) =>
    setLines((rows) => {
      const before = rows[index]
      const changed = rows.map((row, i) => {
        if (i !== index) return row
        const next = { ...row, [key]: value }
        // Choosing a medicine fills in its usual pack size and GST.
        if (key === 'medicine_id') {
          // Another medicine on a line filled in from an order is no longer what the order said.
          if (value !== row.medicine_id) next.from_order = undefined
          const medicine = choices.find((m) => String(m.id) === value)
          if (medicine) {
            next.units_per_pack = String(medicine.units_per_pack)
            next.gst_percent = String(medicine.gst_percent)
            next.hsn_code = medicine.hsn_code ?? ''
            // Where it is already kept. A medicine with no place yet takes the one typed here.
            next.rack_no = medicine.rack_no ?? ''
            next.box_no = medicine.box_no ?? ''
          }
        }
        // A scheme typed the way it is said, "10+2", fills both boxes: ten paid, two free.
        if (key === 'packs' && typeof value === 'string') {
          const scheme = value.match(/^\s*(\d+)\s*\+\s*(\d+)\s*$/)
          if (scheme) {
            next.packs = scheme[1]
            next.free_packs = scheme[2]
          }
        }
        return next
      })
      return key === 'medicine_id' && before && value !== before.medicine_id ? passNote(changed, before.from_order, before.medicine_id) : changed
    })

  const filled = lines.filter((l) => (l.medicine_id || l.batch_no || l.packs) && !(l.id && removed.includes(l.id)))
  const added = editing ? filled.filter((l) => !l.id) : []
  const subtotal = filled.reduce((sum, l) => sum + lineNet(l), 0)
  const tax = filled.reduce((sum, l) => sum + lineTax(l), 0)
  const grand = subtotal + tax + Number(extras.other_charges || 0) - Number(extras.cash_discount || 0) + Number(extras.round_off || 0)

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors([])
    try {
      const body = {
        stockist_id: stockistId || null,
        invoice_no: invoiceNo,
        invoice_date: invoiceDate,
        ...(editing ? {} : { purchase_order_id: orderId || null }),
        remarks: remarks || null,
        other_charges: extras.other_charges || 0,
        cash_discount: extras.cash_discount || 0,
        round_off: extras.round_off || 0,
      }
      // What the order said of a line is for the screen only; the bill's figures are what is sent.
      const tidy = (l: Line) => ({ ...l, from_order: undefined, free_packs: l.free_packs || 0, discount_percent: l.discount_percent || 0, discount_amount: l.discount_amount || 0, rack_no: l.rack_no || null, box_no: l.box_no || null, hsn_code: l.hsn_code || null })
      // On a saved invoice the lines go three ways: the ones corrected, the ones added, and the ones taken off.
      const res = editing
        ? await api.put<PurchaseInvoice>(`/pharmacy/purchases/${editing.id}`, { ...body, reason, items: filled.filter((l) => l.id).map(tidy), add: added.map(tidy), remove: removed })
        : await api.post<PurchaseInvoice>('/pharmacy/purchases', { ...body, items: filled.map(tidy) })
      const did = [added.length ? `${added.length} added` : '', removed.length ? `${removed.length} taken off` : ''].filter(Boolean).join(', ')
      // Whether the order this invoice answers is now complete, or how much of it is still to come.
      const order = editing ? null : res.data.order
      const packs = order?.packs_to_come ?? 0
      const ofMedicines = order?.medicines_to_come ?? 0
      const orderSays = !order?.status
        ? ''
        : order.status === 'open'
          ? ` Order ${order.order_no} stays open: ${packs} ${packs === 1 ? 'pack' : 'packs'} of ${ofMedicines} ${ofMedicines === 1 ? 'medicine' : 'medicines'} still to come.`
          : ` Order ${order.order_no} is now complete.`
      setNotice(editing ? `Invoice ${res.data.invoice_no} changed${did ? ` (${did})` : ''} and stock corrected. The change is recorded in the Invoice modified report.` : `Invoice ${res.data.invoice_no} saved. ${res.data.batches?.length ?? 0} batch(es) added to stock.${orderSays}`)
      setEditing(null)
      setAdding(false)
      setInvoiceNo('')
      setRemarks('')
      setLines([emptyLine()])
      setRemoved([])
      forgetOrder()
      load()
    } catch (err) {
      setErrors([...new Set(Object.values(fieldErrors(err)))])
    } finally {
      setSaving(false)
    }
  }

  const view = (invoice: PurchaseInvoice) => {
    setAdding(false)
    api.get<PurchaseInvoice>(`/pharmacy/purchases/${invoice.id}`).then((res) => setViewing(res.data))
  }

  return (
    <>
      <PharmacyTabs />
      <UnsavedGuard
        on={adding && (editing ? added.length > 0 || removed.length > 0 : lines.some((l) => l.medicine_id !== '' || l.batch_no.trim() !== '' || l.packs !== ''))}
        message={editing ? 'The changes to this invoice are not saved yet. Leave without saving them?' : 'This purchase invoice is not saved yet. Leave without saving it?'}
      />
      {adding ? (
        <form className="card" onSubmit={save} noValidate>
          <div className="toolbar">
            <h1 className="grow">{editing ? `Correct invoice ${editing.invoice_no}` : 'Add purchase invoice'}</h1>
            <button type="button" className="btn ghost" onClick={() => canLeave() && setAdding(false)}>
              Cancel
            </button>
          </div>
          <div className="grid four">
            <SelectField
              label="Stockist"
              required
              value={stockistId}
              // An order belongs to one stockist: choosing another drops the order that was picked, instead of sending it along
              // unseen, and the lines filled in from it that nobody typed into come off.
              onChange={(next) => {
                setStockistId(next)
                if (editing) setOrderId('')
                else chooseOrder('')
              }}
              options={stockists.map((s) => ({ value: String(s.id), label: s.name }))}
              placeholder={lists === 'loading' && stockists.length === 0 ? 'Loading the stockists…' : 'Select stockist'}
            />
            <TextField label="Invoice number" required value={invoiceNo} onChange={setInvoiceNo} />
            <TextField label="Invoice date" required type="date" value={invoiceDate} onChange={setInvoiceDate} />
            {!editing && (
              <SelectField
                label="Against purchase order"
                value={openOrders.some((o) => String(o.id) === orderId && String(o.stockist.id) === stockistId) ? orderId : ''}
                onChange={chooseOrder}
                options={openOrders.filter((o) => String(o.stockist.id) === stockistId).map((o) => ({ value: String(o.id), label: `${o.order_no} · ${longDate(o.order_date)}` }))}
                placeholder={!stockistId ? 'Choose the stockist first' : openOrders.some((o) => String(o.stockist.id) === stockistId) ? 'No order' : 'No open order with this stockist'}
              />
            )}
            <TextField label="Remarks" value={remarks} onChange={setRemarks} />
          </div>
          {!editing && fillNote && <p className="notice">{fillNote}</p>}
          {stockists.length === 0 && lists === 'loading' && <p className="muted small">Loading the stockists…</p>}
          {stockists.length === 0 && lists === 'failed' && (
            <p className="form-error">
              The stockists could not be loaded.{' '}
              <button type="button" className="link" onClick={refreshLists}>
                Try again
              </button>
            </p>
          )}
          {stockists.length === 0 && lists === 'ready' && <p className="muted small">No stockists yet. Add one first under Inventory → Medicines, in the Stockists list.</p>}

          {editing && (
            <p className="muted small">
              Correct what was typed wrongly: changing the packs changes the stock by the same number of units. A medicine that was left out can be added at the bottom, and one entered by mistake taken off, as long as none of it has
              been sold yet. For the wrong medicine or pack size, take the line off and add it again.
            </p>
          )}
          <h3>Medicines received</h3>
          <p className="muted small">For a scheme, type it the way the stockist says it: <b>10+2</b> in the Qty box means ten paid packs and two free. Stock goes up by all twelve; you pay for ten.</p>
          {lines.map((l, i) => {
            const c = costing(l)
            // A hint is greyed in the empty box, and never becomes the figure: that is typed from the bill.
            const num = (key: keyof Typed, label: string, more: { step?: string; min?: number; max?: number; disabled?: boolean; hint?: string } = {}) => (
              <label>
                {label}
                <input type="number" min={more.min ?? 0} max={more.max} step={more.step} disabled={more.disabled} placeholder={more.hint} title={more.hint} value={String(l[key] ?? '')} onChange={(e) => setLine(i, key, e.target.value)} />
              </label>
            )
            // On a line filled in from an order: how the packs typed of it stand against what is still to come, and what was paid last time.
            const from = l.from_order
            const standing = againstOrder(lines, i, removed)
            const lastRate = from && from.last_rate !== null ? `last ${rupees(from.last_rate)}` : undefined
            const lastMrp = from && from.last_mrp !== null ? `last ${rupees(from.last_mrp)}` : undefined
            // A line already saved on the invoice keeps its medicine and pack size; a new one is typed in full.
            const saved = editing?.batches?.find((b) => b.id === l.id)
            if (saved && removed.includes(saved.id)) {
              return (
                <div className="purchase-line taken-off" key={i}>
                  <span className="grow">
                    <s>
                      {saved.medicine?.name} · batch {saved.batch_no} · {saved.packs} packs{saved.free_packs ? ` + ${saved.free_packs} free` : ''}
                    </s>
                    <small className="muted block">Comes off the invoice when you save. Stock goes down by {saved.units_in_stock} units.</small>
                  </span>
                  <button type="button" className="link" onClick={() => setRemoved((ids) => ids.filter((id) => id !== saved.id))}>
                    Keep it
                  </button>
                </div>
              )
            }
            const gone = saved ? saved.units_received - saved.units_in_stock : 0
            return (
              <div className={`purchase-line ${editing && !saved ? 'added-line' : ''}${from ? ' from-order' : ''}`} key={i}>
                {editing && !saved && <small className="pill status-arrived">New on this invoice</small>}
                {from && (
                  <p className="from-order-note">
                    <small className="pill status-ongoing">
                      From {from.order_no} ·{' '}
                      {from.units_to_come === from.to_come * from.units_per_pack
                        ? `${from.to_come} ${from.to_come === 1 ? 'pack' : 'packs'}`
                        : `${from.units_to_come} ${from.units_to_come === 1 ? 'unit' : 'units'}`}{' '}
                      still to come
                    </small>
                    {standing && <small className="pill warn">{standing}</small>}
                    <small className="muted">
                      {from.not_in_use ? 'Marked not in use in the medicine list. ' : ''}
                      {from.last_on ? `Last bought ${longDate(from.last_on)} ${from.last_here ? 'from this stockist' : 'from another stockist'}.` : 'Not bought before in this pack size.'}
                    </small>
                  </p>
                )}
                <div className="purchase-fields">
                  <label className="wide">
                    Medicine
                    <MedicineChoose medicines={choices} value={l.medicine_id} disabled={Boolean(saved)} onChange={(id) => setLine(i, 'medicine_id', id)} onNew={(typed) => { setNewName(typed); setNewFor(i) }} label={`Medicine ${i + 1}`} />
                  </label>
                  <label>
                    Batch
                    <input value={l.batch_no} onChange={(e) => setLine(i, 'batch_no', e.target.value)} />
                  </label>
                  <label>
                    Expiry
                    <input type="month" value={l.expiry} onChange={(e) => setLine(i, 'expiry', e.target.value)} />
                  </label>
                  {num('units_per_pack', 'Units in a pack', { min: 1, disabled: Boolean(saved) })}
                  <label>
                    Qty (packs)
                    <input inputMode="numeric" placeholder="10 or 10+2" value={l.packs} onChange={(e) => setLine(i, 'packs', e.target.value)} onBlur={(e) => setLine(i, 'packs', e.target.value.replace(/[^0-9]/g, ''))} />
                  </label>
                  {num('free_packs', 'Free (packs)')}
                  {num('pack_price', 'Purchase rate / pack ₹', { step: '0.01', hint: lastRate })}
                  {num('pack_mrp', 'MRP / pack ₹', { step: '0.01', hint: lastMrp })}
                  {num('discount_percent', 'Discount %', { step: '0.01', max: 100 })}
                  {num('discount_amount', 'Discount ₹', { step: '0.01' })}
                  {num('gst_percent', 'GST %', { step: '0.01' })}
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
                  <label className="check">
                    <input type="checkbox" checked={l.tax_on_free} disabled={!Number(l.free_packs)} onChange={(e) => setLine(i, 'tax_on_free', e.target.checked)} />
                    GST charged on the free packs too
                  </label>
                  <span className="grow purchase-cost">
                    {c ? (
                      <>
                        You pay <b>{rupees(two(lineNet(l)))}</b> + GST {rupees(two(lineTax(l)))} · stock goes up by <b>{c.units}</b> units{c.free ? ` (${c.free} free)` : ''} · real cost <b>{rupees(two(c.cost))}</b> a unit
                        {c.mrp ? (
                          <>
                            {' '}against MRP {rupees(two(c.mrp))} · margin <b className={c.margin !== null && c.margin < 0 ? 'due' : 'settled'}>{c.margin !== null ? `${c.margin.toFixed(1)}%` : '—'}</b>
                          </>
                        ) : null}
                      </>
                    ) : (
                      <span className="muted">Fill in the quantity and rate to see the real cost and margin.</span>
                    )}
                  </span>
                  {!saved ? (
                    <button type="button" className="link danger" onClick={() => setLines((rows) => (rows.length > 1 ? passNote(rows.filter((_, r) => r !== i), rows[i]?.from_order, rows[i]?.medicine_id ?? '') : [emptyLine()]))}>
                      Remove
                    </button>
                  ) : gone === 0 ? (
                    <button type="button" className="link danger" onClick={() => setRemoved((ids) => [...ids, saved.id])}>
                      Take off the invoice
                    </button>
                  ) : (
                    <small className="muted">{gone > 0 ? `${gone} units already gone out, so it cannot be taken off` : 'Stock corrected since, so it cannot be taken off'}</small>
                  )}
                </div>
              </div>
            )
          })}
          <button type="button" className="link add-medicine" onClick={() => setLines((rows) => [...rows, emptyLine()])}>
            {editing ? '+ Add a medicine that was left out' : '+ Add another medicine'}
          </button>

          <dl className="totals narrow-totals">
            <dt>Total before tax</dt>
            <dd>{rupees(Math.round(subtotal * 100) / 100)}</dd>
            <dt>GST</dt>
            <dd>{rupees(Math.round(tax * 100) / 100)}</dd>
            <dt>Freight and other charges ₹</dt>
            <dd>
              <input type="number" min={0} step="0.01" className="narrow num" value={extras.other_charges} onChange={(e) => setExtras({ ...extras, other_charges: e.target.value })} aria-label="Freight and other charges" />
            </dd>
            <dt>Cash discount on the bill ₹</dt>
            <dd>
              <input type="number" min={0} step="0.01" className="narrow num" value={extras.cash_discount} onChange={(e) => setExtras({ ...extras, cash_discount: e.target.value })} aria-label="Cash discount" />
            </dd>
            <dt>Round off ₹ (+ or −)</dt>
            <dd>
              <input type="number" min={-10} max={10} step="0.01" className="narrow num" value={extras.round_off} onChange={(e) => setExtras({ ...extras, round_off: e.target.value })} aria-label="Round off" />
            </dd>
            <dt className="strong">Invoice total</dt>
            <dd className="strong">{rupees(two(grand))}</dd>
          </dl>

          {errors.length > 0 && (
            <div className="form-error">
              {errors.map((message) => (
                <div key={message}>{message}</div>
              ))}
            </div>
          )}
          {editing && (
            <div className="gap-top">
              <TextField label="Reason for the change" required value={reason} onChange={setReason} />
            </div>
          )}
          <div className="panel-foot">
            {!stockistId && filled.length > 0 && <span className="muted small grow">Choose the stockist at the top before saving.</span>}
            <button className="btn primary" disabled={saving || filled.length === 0 || !stockistId || (Boolean(editing) && reason.trim().length < 3)}>
              {saving ? 'Saving…' : editing ? 'Save corrections' : 'Save invoice and add to stock'}
            </button>
          </div>
        </form>
      ) : (
        <div className="split">
          <section className="card">
            <div className="toolbar">
              <h1 className="grow">Purchase invoices</h1>
              {canEdit && (
                <button className="btn primary" onClick={startAdding}>
                  + Add invoice
                </button>
              )}
            </div>
            {notice && <p className="notice">{notice}</p>}
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Invoice</th>
                    <th>Stockist</th>
                    <th>Date</th>
                    <th className="num">Lines</th>
                    <th className="num">Total</th>
                    <th className="num">Paid</th>
                    <th className="num">Balance</th>
                    {canEdit && <th />}
                  </tr>
                </thead>
                <tbody>
                  {invoices.map((inv) => (
                    <tr key={inv.id} onClick={() => view(inv)} className={viewing?.id === inv.id ? 'selected' : ''}>
                      <td>
                        <strong>{inv.invoice_no}</strong>
                      </td>
                      <td className="fit-wrap">
                        <Link className="link" to={`/pharmacy/stockists/${inv.stockist.id}`} title={`All bills from ${inv.stockist.name}`} onClick={(e) => e.stopPropagation()}>
                          {inv.stockist.name}
                        </Link>
                      </td>
                      <td>{longDate(inv.invoice_date)}</td>
                      <td className="num">{inv.batches_count}</td>
                      <td className="num">{rupees(inv.total)}</td>
                      <td className="num">{rupees(inv.paid)}</td>
                      <td className={`num ${inv.total - inv.paid > 0.004 ? 'due' : ''}`}>{rupees(Math.round((inv.total - inv.paid) * 100) / 100)}</td>
                      {canEdit && (
                        <td className="num">
                          <button
                            type="button"
                            className="link"
                            title="Correct this invoice: change, add or take off medicines"
                            onClick={(e) => {
                              e.stopPropagation()
                              api.get<PurchaseInvoice>(`/pharmacy/purchases/${inv.id}`).then((res) => startEditing(res.data))
                            }}
                          >
                            Edit
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                  {invoices.length === 0 && (
                    <tr className="empty">
                      <td colSpan={canEdit ? 8 : 7}>No purchase invoices yet.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>

          {viewing && (
            <aside className="card panel wide">
              <div className="panel-head">
                <div>
                  <h2>Invoice {viewing.invoice_no}</h2>
                  <span className="muted">
                    <Link className="link" to={`/pharmacy/stockists/${viewing.stockist.id}`} title={`All bills from ${viewing.stockist.name}`}>
                      {viewing.stockist.name}
                    </Link>{' '}
                    · {longDate(viewing.invoice_date)}
                  </span>
                </div>
                <div className="row">
                  {canEdit && (
                    <button className="btn ghost" onClick={() => startEditing(viewing)}>
                      Edit
                    </button>
                  )}
                  <button className="btn ghost" onClick={() => setViewing(null)}>
                    Close
                  </button>
                </div>
              </div>
              {viewing.order && <p className="muted small">Against purchase order {viewing.order.order_no}.</p>}
              {viewing.flagged ? (
                <p className="form-error flag-note">
                  {viewing.flagged} {viewing.flagged === 1 ? 'line needs' : 'lines need'} a second look, marked below. Check with the stockist's bill before paying.
                </p>
              ) : null}
              <table className="compact">
                <thead>
                  <tr>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th className="num">Packs</th>
                    <th className="num">Rate / pack</th>
                    <th className="num">MRP / pack</th>
                    <th className="num">Disc</th>
                    <th className="num">GST %</th>
                    <th className="num">Real cost / unit</th>
                    <th className="num">Margin</th>
                  </tr>
                </thead>
                <tbody>
                  {viewing.batches?.map((b) => (
                    <tr key={b.id} className="static">
                      <td>
                        {b.medicine?.name}
                        {b.flags?.map((flag) => (
                          <small key={flag} className="block import-warn">
                            {flag}
                          </small>
                        ))}
                      </td>
                      <td>{b.batch_no}</td>
                      <td>{expiryLabel(b.expiry)}</td>
                      <td className="num">
                        {b.packs}
                        {b.free_packs > 0 ? ` + ${b.free_packs} free` : ''}
                      </td>
                      <td className="num">{rupees(b.pack_price)}</td>
                      <td className="num">{rupees(b.pack_mrp)}</td>
                      <td className="num">{[b.discount_percent ? `${b.discount_percent}%` : '', b.discount_amount ? rupees(b.discount_amount) : ''].filter(Boolean).join(' + ') || '—'}</td>
                      <td className="num">
                        {b.gst_percent}
                        {b.tax_on_free ? <small className="muted block">also on free</small> : null}
                      </td>
                      <td className="num">{b.unit_cost !== undefined ? rupees(b.unit_cost) : '—'}</td>
                      <td className={`num ${b.margin_percent != null && b.margin_percent < 0 ? 'due' : ''}`}>{b.margin_percent != null ? `${b.margin_percent}%` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <dl className="totals">
                <dt>Before tax</dt>
                <dd>{rupees(viewing.subtotal)}</dd>
                <dt>GST</dt>
                <dd>{rupees(viewing.tax)}</dd>
                {viewing.other_charges ? (
                  <>
                    <dt>Freight and other charges</dt>
                    <dd>{rupees(viewing.other_charges)}</dd>
                  </>
                ) : null}
                {viewing.cash_discount ? (
                  <>
                    <dt>Cash discount</dt>
                    <dd>−{rupees(viewing.cash_discount)}</dd>
                  </>
                ) : null}
                {viewing.round_off ? (
                  <>
                    <dt>Round off</dt>
                    <dd>{viewing.round_off > 0 ? '+' : '−'}{rupees(Math.abs(viewing.round_off))}</dd>
                  </>
                ) : null}
                <dt className="strong">Total</dt>
                <dd className="strong">{rupees(viewing.total)}</dd>
                <dt>Paid to stockist</dt>
                <dd>{rupees(viewing.paid)}</dd>
                <dt className="strong">Balance owed</dt>
                <dd className={`strong ${viewing.total - viewing.paid > 0.004 ? 'due' : 'settled'}`}>{rupees(Math.round((viewing.total - viewing.paid) * 100) / 100)}</dd>
              </dl>
              {canEdit && viewing.total - viewing.paid > 0.004 && (
                <form
                  className="row gap-top"
                  onSubmit={async (e) => {
                    e.preventDefault()
                    try {
                      const res = await api.post<PurchaseInvoice>(`/pharmacy/purchases/${viewing.id}/payments`, { amount: payAmount, mode: payMode, reference: payRef || null, paid_on: payDate })
                      setViewing(res.data)
                      setPayAmount('')
                      setPayRef('')
                      setPayError('')
                      load()
                    } catch (err) {
                      setPayError(Object.values(fieldErrors(err))[0])
                    }
                  }}
                >
                  <input type="number" min={0} step="0.01" placeholder="Amount paid to stockist" value={payAmount} onChange={(e) => setPayAmount(e.target.value)} aria-label="Amount paid to stockist" />
                  <select value={payMode} onChange={(e) => setPayMode(e.target.value)} aria-label="How it was paid">
                    {PAYMENT_MODES.filter((m) => m.value !== 'insurance').map((m) => (
                      <option key={m.value} value={m.value}>
                        {m.label}
                      </option>
                    ))}
                  </select>
                  <input placeholder="Cheque or transfer no." value={payRef} onChange={(e) => setPayRef(e.target.value)} aria-label="Cheque or transfer number" />
                  <input type="date" max={today()} value={payDate} onChange={(e) => setPayDate(e.target.value || today())} aria-label="Paid on" />
                  <button className="btn primary" disabled={!payAmount || Number(payAmount) <= 0}>
                    Record payment
                  </button>
                </form>
              )}
              {payError && <p className="form-error">{payError}</p>}
              {viewing.due_date && viewing.total - viewing.paid > 0.004 && <p className="muted small">Due to be paid by {longDate(viewing.due_date)}.</p>}
              {viewing.payments && viewing.payments.length > 0 && (
                <ul className="plain-list small">
                  {viewing.payments.map((pay) => (
                    <li key={pay.id}>
                      {longDate(pay.paid_on)} · {rupees(pay.amount)} by {modeLabel(pay.mode)}
                      {pay.reference ? ` (${pay.reference})` : ''}
                      {pay.payer ? ` · ${pay.payer.name}` : ''}
                    </li>
                  ))}
                </ul>
              )}
              {viewing.remarks && <p className="muted">{viewing.remarks}</p>}
            </aside>
          )}
        </div>
      )}
      {newFor !== null && <NewMedicine start={{ name: newName, units_per_pack: lines[newFor].units_per_pack, gst_percent: lines[newFor].gst_percent, hsn_code: lines[newFor].hsn_code, rack_no: lines[newFor].rack_no, box_no: lines[newFor].box_no }} onClose={() => setNewFor(null)} onAdded={(m) => adopt(m, newFor)} />}
    </>
  )
}
