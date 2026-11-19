import { useCallback, useEffect, useState } from 'react'
import { Pressable, Text, View } from 'react-native'
import { api, ApiError, niceDate, niceTime, todayIso } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Empty, Loading, Notice, text } from '../ui'
import { whole } from '../doctor/owner/parts'
import { ageSex, DayRow, fullName, Tag } from './parts'

type Stage = 'booked' | 'collected' | 'reported' | 'approved'
type Result = { id: number; parameter: string; is_heading: boolean; unit: string | null; reference_range: string | null; value: string | null; flag: 'high' | 'low' | null }
type Item = {
  id: number
  test_name: string
  kind?: 'values' | 'text'
  stage: Stage
  template_version?: number | null
  sample_type?: string | null
  remarks?: string | null
  report_text?: string | null
  reported_at?: string | null
  approved_at?: string | null
  reporter?: { name: string } | null
  approver?: { name: string } | null
  results?: Result[]
}
type Order = {
  id: number
  order_no: string
  ordered_at: string
  referred_by?: string | null
  patient: { id: number; uhid: string; name: string; title?: string | null; gender: string; age_label: string }
  items: Item[]
  bill: { bill_no: string; total: number; paid: number; due?: number } | null
}

const STAGE: Record<Stage, { label: string; tone: 'todo' | 'waiting' | 'done' }> = {
  booked: { label: 'Sample to collect', tone: 'todo' },
  collected: { label: 'Result to enter', tone: 'waiting' },
  reported: { label: 'Reported', tone: 'done' },
  approved: { label: 'Approved', tone: 'done' },
}

/**
 * The lab's work list on a phone. Samples can be marked as collected at the bedside or the counter, a report can
 * be read, and reported results approved. Typing in the values stays on the web system, where the whole sheet
 * and the patient's earlier values are in view.
 */
export function LabBoard({ canRun, refreshKey }: { canRun: boolean; refreshKey: number }) {
  const [date, setDate] = useState(todayIso())
  const [orders, setOrders] = useState<Order[] | null>(null)
  const [approval, setApproval] = useState(false)
  const [open, setOpen] = useState<Order | null>(null)
  const [error, setError] = useState('')
  const [said, setSaid] = useState('')
  const [busy, setBusy] = useState('')

  const load = useCallback(
    () =>
      api
        .get<Order[]>('/lab/orders', { date })
        .then((list) => {
          setOrders(list)
          setError('')
        })
        .catch((e: ApiError) => setError(e.message)),
    [date],
  )
  useEffect(() => {
    setOrders(null)
    void load()
    const timer = setInterval(() => void load(), 60000)
    return () => clearInterval(timer)
  }, [load, refreshKey])
  useEffect(() => {
    api.get<{ approval_required: boolean }>('/lab/settings').then((s) => setApproval(Boolean(s.approval_required))).catch(() => undefined)
  }, [])

  const act = async (key: string, path: string, done: string, id: number) => {
    setBusy(key)
    setSaid('')
    setError('')
    try {
      const fresh = await api.put<Order>(path)
      setSaid(done)
      if (open?.id === id) setOpen(fresh)
      await load()
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy('')
    }
  }
  const show = async (o: Order) => {
    setError('')
    setSaid('')
    setBusy(`open-${o.id}`)
    try {
      setOpen(await api.get<Order>(`/lab/orders/${o.id}`))
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy('')
    }
  }

  if (open) {
    const waiting = open.items.filter((i) => i.stage === 'reported').length
    const toCollect = open.items.filter((i) => i.stage === 'booked').length
    return (
      <>
        <Button label="‹ Back to the lab list" kind="ghost" small onPress={() => setOpen(null)} />
        <Notice text={error} />
        <Notice text={said} kind="ok" />
        <Card>
          <Text style={text.h2}>{fullName(open.patient)}</Text>
          <Text style={text.muted}>
            {ageSex(open.patient)} · {open.patient.uhid}
          </Text>
          <Text style={text.body}>
            {open.order_no} · {niceDate(open.ordered_at)} {niceTime(open.ordered_at)}
            {open.referred_by ? ` · Referred by ${open.referred_by}` : ''}
          </Text>
          {canRun && toCollect > 0 && (
            <Button label={toCollect === 1 ? 'Mark the sample collected' : `Mark all ${toCollect} samples collected`} busy={busy === `collect-${open.id}`} onPress={() => void act(`collect-${open.id}`, `/lab/orders/${open.id}/collect`, 'Marked as collected.', open.id)} />
          )}
          {canRun && approval && waiting > 0 && (
            <Button label={waiting === 1 ? 'Approve the reported result' : `Approve all ${waiting} reported results`} busy={busy === `approve-${open.id}`} onPress={() => void act(`approve-${open.id}`, `/lab/orders/${open.id}/approve`, 'Approved.', open.id)} />
          )}
        </Card>
        {open.items.map((item) => (
          <Card key={item.id}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
              <View style={{ flex: 1 }}>
                <Text style={text.strong}>{item.test_name}</Text>
                <Text style={text.muted}>{[item.sample_type, item.template_version ? `Template v${item.template_version}` : ''].filter(Boolean).join(' · ')}</Text>
              </View>
              <Tag label={STAGE[item.stage].label} tone={STAGE[item.stage].tone} />
            </View>
            {item.stage === 'booked' || item.stage === 'collected' ? (
              <Text style={text.muted}>{item.stage === 'booked' ? 'Waiting for the sample.' : 'Sample taken. The result is entered on the web system.'}</Text>
            ) : item.kind === 'text' ? (
              <Text style={text.body}>{item.report_text || 'No report written.'}</Text>
            ) : (
              (item.results ?? []).map((r) =>
                r.is_heading ? (
                  <Text key={r.id} style={[text.muted, { fontWeight: '700', marginTop: space.xs }]}>
                    {r.parameter.toUpperCase()}
                  </Text>
                ) : (
                  <View key={r.id} style={{ flexDirection: 'row', gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 6 }}>
                    <View style={{ flex: 1 }}>
                      <Text style={text.body}>{r.parameter}</Text>
                      {r.reference_range ? <Text style={text.muted}>Normal {r.reference_range}</Text> : null}
                    </View>
                    <Text style={[text.strong, r.flag ? { color: colors.danger } : null]}>
                      {r.value ?? '—'}
                      {r.flag ? (r.flag === 'high' ? ' H' : ' L') : ''}
                      {r.unit ? <Text style={text.muted}> {r.unit}</Text> : null}
                    </Text>
                  </View>
                ),
              )
            )}
            {item.remarks && (item.stage === 'reported' || item.stage === 'approved') ? <Text style={text.muted}>{item.remarks}</Text> : null}
            {item.reported_at ? (
              <Text style={text.muted}>
                Reported {niceDate(item.reported_at)} {niceTime(item.reported_at)}
                {item.reporter ? ` by ${item.reporter.name}` : ''}
                {item.approved_at ? ` · Approved${item.approver ? ` by ${item.approver.name}` : ''}` : ''}
              </Text>
            ) : null}
          </Card>
        ))}
      </>
    )
  }

  const tests = (orders ?? []).reduce((n, o) => n + o.items.length, 0)
  const patients = new Set((orders ?? []).map((o) => o.patient.id)).size
  const plural = (n: number, one: string) => `${n} ${one}${n === 1 ? '' : 's'}`
  return (
    <>
      <DayRow date={date} onChange={setDate} future={false} />
      <Notice text={error} />
      <Notice text={said} kind="ok" />
      {!orders && !error && <Loading />}
      {orders?.length === 0 && <Empty text="No lab orders for this day." />}
      {orders && orders.length > 0 && (
        <Text style={[text.muted, { textAlign: 'center' }]}>
          {plural(patients, 'patient')} · {plural(orders.length, 'order')} · {plural(tests, 'test')}
        </Text>
      )}
      {orders?.map((o) => {
        const toCollect = o.items.filter((i) => i.stage === 'booked').length
        const toEnter = o.items.filter((i) => i.stage === 'collected').length
        const toApprove = approval ? o.items.filter((i) => i.stage === 'reported').length : 0
        const next = toCollect ? 'booked' : toEnter ? 'collected' : toApprove ? 'reported' : 'approved'
        const label = toCollect ? STAGE.booked.label : toEnter ? STAGE.collected.label : toApprove ? 'To approve' : 'Ready'
        const due = o.bill ? Number(o.bill.due ?? o.bill.total - o.bill.paid) : 0
        return (
          <Card key={o.id}>
            <Pressable onPress={() => void show(o)} accessibilityRole="button" accessibilityLabel={`Open ${o.order_no}`}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                <View style={{ flex: 1 }}>
                  <Text style={text.strong}>{fullName(o.patient)}</Text>
                  <Text style={text.muted}>
                    {ageSex(o.patient)} · {o.patient.uhid} · {o.order_no} · {niceTime(o.ordered_at)}
                  </Text>
                </View>
                <Tag label={label} tone={STAGE[next as Stage].tone} />
              </View>
              {o.items.map((i) => (
                <Text key={i.id} style={text.body}>
                  <Text style={{ color: i.stage === 'booked' ? colors.muted : i.stage === 'collected' ? colors.accent : colors.ok }}>● </Text>
                  {i.test_name}
                </Text>
              ))}
              {o.bill ? (
                <Text style={text.muted}>
                  {o.bill.bill_no} · {whole(o.bill.total)} · <Text style={{ color: due > 0 ? colors.danger : colors.ok, fontWeight: '700' }}>{due > 0 ? `${whole(due)} due` : 'Paid'}</Text>
                </Text>
              ) : null}
            </Pressable>
            <View style={{ flexDirection: 'row', gap: space.sm, justifyContent: 'flex-end' }}>
              <Button label="Open" kind="ghost" small busy={busy === `open-${o.id}`} onPress={() => void show(o)} />
              {canRun && toCollect > 0 && <Button label="Mark collected" small busy={busy === `collect-${o.id}`} onPress={() => void act(`collect-${o.id}`, `/lab/orders/${o.id}/collect`, `Sample collected for ${o.patient.name}.`, o.id)} />}
            </View>
          </Card>
        )
      })}
    </>
  )
}
