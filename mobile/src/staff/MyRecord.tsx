import { useCallback, useEffect, useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { ApiError, api, niceDate, niceTime, rupees, shortDay, todayIso } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Choice, Empty, Field, Loading, Notice, text } from '../ui'
import { DayRow, Tag } from './parts'

type Duty = { date: string; off: boolean; shift: string | null; starts_at: string | null; ends_at: string | null; rostered: boolean }
type LeaveType = { id: number; code: string; name: string; is_paid: boolean; allow_half_day: boolean; left: number }
type Leave = { id: number; type: { name: string } | null; from_date: string; to_date: string; days: number; status: string; reason: string | null; decision_note: string | null }
type Spell = { id: number; check_in: string | null; check_out: string | null; state: string }
type Mine = {
  employee: { name: string; employee_no: string; designation: string | null; department: { name: string } | null } | null
  totals?: { present: number; leave: number; absent: number; late_days: number }
  open?: Spell | null
  self_check_in?: boolean
  leave_types?: LeaveType[]
  leave?: Leave[]
  duties?: Duty[]
  payslips?: { id: number; period_name: string; slip_no: string | null; net: string; payment_status: string; paid_on: string | null }[]
  advances?: { id: number; advance_no: string | null; kind: string; amount: string; owed: string | null; status: string; next: { period: string; amount: string } | null }[]
}
type Slip = {
  period_name: string
  slip_no: string | null
  days: { in_month: number; present: number; paid_leave: number; unpaid: number } | null
  lines: { id: number; kind: 'earning' | 'deduction' | 'employer'; name: string; amount: string; explanation: string }[]
  gross: string
  deductions: string
  net: string
  payment_status: string
  paid_on: string | null
}

const STATUS: Record<string, { label: string; tone: 'todo' | 'waiting' | 'done' | 'stop' }> = {
  pending: { label: 'Waiting', tone: 'waiting' }, approved: { label: 'Approved', tone: 'done' }, rejected: { label: 'Refused', tone: 'stop' }, cancelled: { label: 'Cancelled', tone: 'todo' },
  paid: { label: 'Paid', tone: 'done' }, unpaid: { label: 'Not paid yet', tone: 'waiting' }, held: { label: 'Held back', tone: 'stop' },
}
/** "20:00" → "8 PM". */
const clock = (hhmm: string) => {
  const [h, m] = hhmm.split(':').map(Number)
  return `${h % 12 === 0 ? 12 : h % 12}${m ? `:${String(m).padStart(2, '0')}` : ''} ${h < 12 ? 'AM' : 'PM'}`
}
const money = (amount: string | number) => rupees(Number(amount))
/** "Mon 1 Jun". */
const day = (iso: string) => {
  const d = shortDay(iso)
  return `${d.weekday} ${d.day} ${d.month}`
}

/** A member of staff's own record: today's duty, the coming duties, this month, leave, payslips and advances. */
export function MyRecord({ refreshKey }: { refreshKey: number }) {
  const [mine, setMine] = useState<Mine | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState('')
  const [asking, setAsking] = useState(false)
  const [slip, setSlip] = useState<Slip | null>(null)

  const load = useCallback(() => {
    api
      .get<Mine>('/workforce/me')
      .then((res) => {
        setMine(res)
        setError('')
      })
      .catch((e: ApiError) => setError(e.message))
  }, [])
  useEffect(load, [load, refreshKey])

  const punch = async () => {
    setBusy('punch')
    setNotice('')
    try {
      const r = await api.post<Spell>('/workforce/me/punch')
      setNotice(r.state === 'open' ? 'Checked in.' : 'Checked out.')
      load()
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy('')
    }
  }
  const cancelLeave = async (l: Leave) => {
    setBusy(`leave-${l.id}`)
    try {
      await api.post(`/workforce/me/leave/${l.id}/cancel`)
      setNotice('Leave request withdrawn.')
      load()
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy('')
    }
  }
  const openSlip = async (id: number) => {
    setBusy(`slip-${id}`)
    try {
      setSlip(await api.get<Slip>(`/workforce/me/payslips/${id}`))
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy('')
    }
  }

  if (error && !mine) return <Notice text={error} />
  if (!mine) return <Loading />
  if (!mine.employee) return <Empty text="Your login is not joined to a staff record yet. Ask whoever keeps the staff directory to join them." />
  if (slip) return <Payslip slip={slip} onBack={() => setSlip(null)} />
  if (asking) return <AskLeave types={mine.leave_types ?? []} onBack={() => setAsking(false)} onSent={() => { setAsking(false); setNotice('Leave asked for. You will see here when it is decided.'); load() }} />

  const today = mine.duties?.find((d) => d.date === todayIso())
  const e = mine.employee
  return (
    <View style={{ gap: space.md }}>
      {notice ? <Notice text={notice} kind="ok" /> : null}
      {error ? <Notice text={error} /> : null}

      <Card>
        <Text style={text.strong}>{e.name}</Text>
        <Text style={text.muted}>{[e.employee_no, e.designation, e.department?.name].filter(Boolean).join(' · ')}</Text>
        <View style={styles.today}>
          <Text style={styles.heading}>Today</Text>
          <Text style={text.strong}>{today ? (today.off ? 'Day off' : today.starts_at ? `${today.shift ?? 'Duty'} · ${clock(today.starts_at)} to ${clock(today.ends_at!)}` : 'No shift set') : '—'}</Text>
          {mine.open?.check_in && <Text style={text.muted}>Checked in at {niceTime(mine.open.check_in)}</Text>}
        </View>
        {mine.self_check_in && <Button label={mine.open ? 'Check out' : 'Check in'} busy={busy === 'punch'} onPress={() => void punch()} />}
      </Card>

      {(mine.duties?.length ?? 0) > 0 && (
        <Card>
          <Text style={styles.heading}>My duties, the next two weeks</Text>
          {mine.duties!.map((d) => (
            <View key={d.date} style={styles.row}>
              <Text style={[text.body, { width: 92 }]}>{day(d.date)}</Text>
              <Text style={[text.body, { flex: 1 }, d.off && { color: colors.muted }]}>
                {d.off ? 'Day off' : d.starts_at ? `${d.shift ?? 'Duty'} · ${clock(d.starts_at)}–${clock(d.ends_at!)}` : 'No shift set'}
              </Text>
              {d.starts_at && d.ends_at && d.ends_at <= d.starts_at && !d.off && <Tag label="Night" tone="todo" />}
            </View>
          ))}
        </Card>
      )}

      {mine.totals && (
        <Card>
          <Text style={styles.heading}>This month</Text>
          <View style={styles.tiles}>
            <Tile label="Present" value={mine.totals.present} />
            <Tile label="Leave" value={mine.totals.leave} />
            <Tile label="Absent" value={mine.totals.absent} />
            <Tile label="Late" value={mine.totals.late_days} />
          </View>
        </Card>
      )}

      <Card>
        <View style={styles.headRow}>
          <Text style={styles.heading}>My leave</Text>
          {(mine.leave_types?.length ?? 0) > 0 && <Button label="Ask for leave" small onPress={() => setAsking(true)} />}
        </View>
        {(mine.leave_types ?? []).map((t) => (
          <View key={t.id} style={styles.row}>
            <Text style={[text.body, { flex: 1 }]}>{t.name}</Text>
            <Text style={text.strong}>{t.left} left</Text>
          </View>
        ))}
        {(mine.leave ?? []).slice(0, 8).map((l) => (
          <View key={l.id} style={styles.item}>
            <View style={{ flex: 1 }}>
              <Text style={text.body}>
                {l.type?.name ?? 'Leave'} · {l.from_date === l.to_date ? niceDate(l.from_date) : `${niceDate(l.from_date)} to ${niceDate(l.to_date)}`}
              </Text>
              <Text style={text.muted}>{[`${l.days} ${l.days === 1 ? 'day' : 'days'}`, l.decision_note].filter(Boolean).join(' · ')}</Text>
            </View>
            <View style={{ alignItems: 'flex-end', gap: 4 }}>
              <Tag {...(STATUS[l.status] ?? { label: l.status, tone: 'todo' })} />
              {l.status === 'pending' && (
                <Pressable onPress={() => void cancelLeave(l)} disabled={busy === `leave-${l.id}`} accessibilityRole="button">
                  <Text style={text.link}>Withdraw</Text>
                </Pressable>
              )}
            </View>
          </View>
        ))}
        {(mine.leave_types?.length ?? 0) === 0 && <Text style={text.muted}>No kinds of leave are set up yet.</Text>}
      </Card>

      {(mine.payslips?.length ?? 0) > 0 && (
        <Card>
          <Text style={styles.heading}>My payslips</Text>
          {mine.payslips!.map((p) => (
            <Pressable key={p.id} onPress={() => void openSlip(p.id)} style={styles.item} accessibilityRole="button">
              <View style={{ flex: 1 }}>
                <Text style={text.strong}>{p.period_name}</Text>
                <Text style={text.muted}>{[p.slip_no, p.paid_on ? `paid ${niceDate(p.paid_on)}` : ''].filter(Boolean).join(' · ')}</Text>
              </View>
              <View style={{ alignItems: 'flex-end', gap: 4 }}>
                <Text style={text.strong}>{money(p.net)}</Text>
                <Tag {...(STATUS[p.payment_status] ?? { label: p.payment_status, tone: 'todo' })} />
              </View>
            </Pressable>
          ))}
        </Card>
      )}

      {(mine.advances?.length ?? 0) > 0 && (
        <Card>
          <Text style={styles.heading}>My advances and loans</Text>
          {mine.advances!.map((a) => (
            <View key={a.id} style={styles.item}>
              <View style={{ flex: 1 }}>
                <Text style={text.body}>
                  {a.kind === 'loan' ? 'Loan' : 'Advance'} {a.advance_no} · {money(a.amount)}
                </Text>
                <Text style={text.muted}>{a.owed !== null ? `${money(a.owed)} still owed` : 'Waiting for a decision'}{a.next ? ` · ${money(a.next.amount)} from the next salary` : ''}</Text>
              </View>
            </View>
          ))}
        </Card>
      )}
    </View>
  )
}

function Tile({ label, value }: { label: string; value: number }) {
  return (
    <View style={styles.tile}>
      <Text style={text.muted}>{label}</Text>
      <Text style={[text.strong, { fontSize: 20 }]}>{value}</Text>
    </View>
  )
}

/** Asking for leave from the phone: the kind, the days, and why. */
function AskLeave({ types, onBack, onSent }: { types: LeaveType[]; onBack: () => void; onSent: () => void }) {
  const [typeId, setTypeId] = useState(types[0] ? String(types[0].id) : '')
  const [from, setFrom] = useState(todayIso())
  const [to, setTo] = useState(todayIso())
  const [part, setPart] = useState<'full' | 'first_half' | 'second_half'>('full')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const type = types.find((t) => String(t.id) === typeId)
  const send = async () => {
    setBusy(true)
    setErrors({})
    try {
      await api.post('/workforce/me/leave', { leave_type_id: typeId, from_date: from, to_date: to < from ? from : to, from_part: from === to ? part : 'full', to_part: from === to ? part : 'full', reason: reason.trim() || null })
      onSent()
    } catch (e) {
      const err = e as ApiError
      setErrors(Object.keys(err.fields).length ? err.fields : { _: err.message })
    } finally {
      setBusy(false)
    }
  }
  return (
    <View style={{ gap: space.md }}>
      <Button label="‹ Back to my record" kind="ghost" small onPress={onBack} />
      <Card>
        <Text style={text.strong}>Ask for leave</Text>
        <Choice label="Kind of leave" value={typeId} options={types.map((t) => ({ value: String(t.id), label: `${t.name} (${t.left} left)` }))} onChange={setTypeId} />
        <Text style={styles.heading}>From</Text>
        <DayRow date={from} onChange={(d) => { setFrom(d); if (to < d) setTo(d) }} />
        <Text style={styles.heading}>To</Text>
        <DayRow date={to < from ? from : to} onChange={setTo} />
        {from === to && type?.allow_half_day && <Choice label="Part of the day" value={part} options={[{ value: 'full', label: 'Whole day' }, { value: 'first_half', label: 'First half' }, { value: 'second_half', label: 'Second half' }]} onChange={setPart} />}
        <Field label="Reason (optional)" value={reason} onChangeText={setReason} error={errors.reason} />
        {Object.entries(errors).filter(([k]) => k !== 'reason').map(([k, m]) => <Notice key={k} text={m} />)}
        <Button label="Send" busy={busy} disabled={!typeId} onPress={() => void send()} />
      </Card>
    </View>
  )
}

/** A payslip, line by line with how each amount was arrived at. */
function Payslip({ slip, onBack }: { slip: Slip; onBack: () => void }) {
  const lines = (kind: string) => slip.lines.filter((l) => l.kind === kind)
  const block = (kind: string, title: string) =>
    lines(kind).length > 0 && (
      <Card>
        <Text style={styles.heading}>{title}</Text>
        {lines(kind).map((l) => (
          <View key={l.id} style={styles.item}>
            <View style={{ flex: 1 }}>
              <Text style={text.body}>{l.name}</Text>
              <Text style={text.muted}>{l.explanation}</Text>
            </View>
            <Text style={text.strong}>{money(l.amount)}</Text>
          </View>
        ))}
      </Card>
    )
  return (
    <View style={{ gap: space.md }}>
      <Button label="‹ Back to my record" kind="ghost" small onPress={onBack} />
      <Card>
        <Text style={text.strong}>Payslip for {slip.period_name}</Text>
        <Text style={text.muted}>{[slip.slip_no, slip.days ? `${slip.days.present} worked · ${slip.days.paid_leave} paid leave · ${slip.days.unpaid} unpaid` : ''].filter(Boolean).join(' · ')}</Text>
        <View style={styles.row}>
          <Text style={[text.body, { flex: 1 }]}>Net pay</Text>
          <Text style={[text.strong, { fontSize: 20 }]}>{money(slip.net)}</Text>
        </View>
        <Text style={text.muted}>{slip.payment_status === 'paid' && slip.paid_on ? `Paid on ${niceDate(slip.paid_on)}` : (STATUS[slip.payment_status]?.label ?? '')}</Text>
      </Card>
      {block('earning', 'Earnings')}
      {block('deduction', 'Deductions')}
      <Card>
        <View style={styles.row}>
          <Text style={[text.body, { flex: 1 }]}>Gross</Text>
          <Text style={text.body}>{money(slip.gross)}</Text>
        </View>
        <View style={styles.row}>
          <Text style={[text.body, { flex: 1 }]}>Deductions</Text>
          <Text style={text.body}>{money(slip.deductions)}</Text>
        </View>
      </Card>
    </View>
  )
}

const styles = StyleSheet.create({
  heading: { fontSize: 12, fontWeight: '700', color: colors.muted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 4 },
  today: { marginTop: space.md, gap: 2 },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingVertical: 6, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
  item: { flexDirection: 'row', alignItems: 'flex-start', gap: space.sm, paddingVertical: 8, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
  headRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: space.xs },
  tiles: { flexDirection: 'row', gap: space.sm, marginTop: space.sm },
  tile: { flex: 1, padding: space.sm, borderRadius: 10, backgroundColor: colors.bg, alignItems: 'center' },
})
