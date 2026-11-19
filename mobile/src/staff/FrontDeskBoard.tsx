import { useCallback, useEffect, useState } from 'react'
import { Text, View } from 'react-native'
import { api, ApiError, niceTime, todayIso } from '../api'
import { colors } from '../theme'
import { Button, Card, Empty, Field, Loading, Notice, Pill, text } from '../ui'
import { Tile, Tiles, whole } from '../doctor/owner/parts'
import { ageSex, Call, DayRow, fullName } from './parts'

type Money = { id: number; bill_no: string; kind: string; total: number; paid: number; due: number }
type Person = { id: number; uhid: string; name: string; title?: string | null; gender: string; age_label: string; mobile: string }
type Visit = { id: number; scheduled_at: string; status: string; token_no: number | null; patient: Person; doctor: { id: number; name: string }; service: { name: string } | null; bills: Money[] }
type DayList = { data: Visit[]; counts: Record<string, number> }
type WalkIn = Money & { created_at: string; patient: Person; items: { id: number; description: string }[] }

/**
 * The front desk's day on a phone: who is booked, who has come, and who still owes money. A patient who has
 * walked in can be marked as arrived from here; booking, billing and taking money stay on the web system.
 */
export function FrontDeskBoard({ refreshKey }: { refreshKey: number }) {
  const [date, setDate] = useState(todayIso())
  const [list, setList] = useState<DayList | null>(null)
  const [walkIns, setWalkIns] = useState<WalkIn[]>([])
  const [error, setError] = useState('')
  const [said, setSaid] = useState('')
  const [busy, setBusy] = useState(0)
  const [find, setFind] = useState('')

  const load = useCallback(
    () =>
      Promise.all([api.get<DayList>('/appointments', { date }), api.get<WalkIn[]>('/front-desk/bills', { date })])
        .then(([day, bills]) => {
          setList(day)
          setWalkIns(bills)
          setError('')
        })
        .catch((e: ApiError) => setError(e.message)),
    [date],
  )
  useEffect(() => {
    setList(null)
    void load()
    const timer = setInterval(() => void load(), 30000)
    return () => clearInterval(timer)
  }, [load, refreshKey])

  const arrived = async (v: Visit) => {
    setBusy(v.id)
    setSaid('')
    try {
      await api.put(`/appointments/${v.id}`, { status: 'arrived' })
      setSaid(`${v.patient.name} is marked as arrived.`)
      await load()
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy(0)
    }
  }

  const words = find.trim().toLowerCase()
  const matches = (p: Person) => !words || p.name.toLowerCase().includes(words) || p.uhid.toLowerCase().includes(words) || p.mobile.includes(words)
  const visits = (list?.data ?? []).filter((v) => matches(v.patient))
  // A bill made for a patient with a visit that day is already under the visit; the rest came without a booking.
  const visiting = new Set((list?.data ?? []).filter((v) => v.status !== 'cancelled').map((v) => v.patient.id))
  const others = walkIns.filter((b) => !visiting.has(b.patient.id) && matches(b.patient))
  const money = [...(list?.data ?? []).flatMap((v) => v.bills), ...walkIns.filter((b) => !visiting.has(b.patient.id))]
  const sum = (key: 'total' | 'paid' | 'due') => money.reduce((n, b) => n + Number(b[key] ?? 0), 0)
  const count = (status: string) => list?.counts[status] ?? 0

  return (
    <>
      <DayRow date={date} onChange={setDate} />
      <Notice text={error} />
      <Notice text={said} kind="ok" />
      {!list && !error && <Loading />}
      {list && (
        <>
          <Tiles>
            <Tile label="Yet to arrive" value={String(count('booked'))} />
            <Tile label="Waiting" value={String(count('arrived') + count('ongoing'))} note={count('ongoing') ? `${count('ongoing')} with the doctor` : undefined} />
            <Tile label="Seen" value={String(count('reviewed'))} />
            <Tile label="Still to collect" value={whole(sum('due'))} tone={sum('due') > 0 ? 'danger' : 'ok'} note={`${whole(sum('paid'))} of ${whole(sum('total'))} paid`} />
          </Tiles>
          <Field label="Find in this day" value={find} onChangeText={setFind} placeholder="Name, mobile or patient ID" autoCapitalize="none" autoCorrect={false} />

          {visits.length === 0 && others.length === 0 && <Empty text={words ? 'No one on this day matches.' : 'No appointments or bills for this day.'} />}
          {visits.map((v) => {
            const due = v.bills.reduce((n, b) => n + Number(b.due), 0)
            const billed = v.bills.reduce((n, b) => n + Number(b.total), 0)
            return (
              <Card key={v.id}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                  <View style={{ flex: 1 }}>
                    <Text style={text.strong}>{fullName(v.patient)}</Text>
                    <Text style={text.muted}>
                      {ageSex(v.patient)} · {v.patient.uhid}
                    </Text>
                  </View>
                  <Pill status={v.status} />
                </View>
                <Text style={text.body}>
                  {niceTime(v.scheduled_at)}
                  {v.token_no ? ` · Token ${v.token_no}` : ''} · {v.doctor.name}
                  {v.service ? ` · ${v.service.name}` : ''}
                </Text>
                {v.bills.length > 0 ? (
                  <Text style={text.body}>
                    Billed {whole(billed)} ·{' '}
                    <Text style={{ fontWeight: '700', color: due > 0 ? colors.danger : colors.ok }}>{due > 0 ? `${whole(due)} due` : 'Paid'}</Text>
                  </Text>
                ) : (
                  v.status !== 'cancelled' && <Text style={text.muted}>Not billed yet</Text>
                )}
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                  <Call mobile={v.patient.mobile} />
                  {v.status === 'booked' && date === todayIso() && <Button label="Mark arrived" small busy={busy === v.id} onPress={() => void arrived(v)} />}
                </View>
              </Card>
            )
          })}

          {others.length > 0 && <Text style={text.h2}>Came without a booking</Text>}
          {others.map((b) => (
            <Card key={`bill-${b.id}`}>
              <Text style={text.strong}>{fullName(b.patient)}</Text>
              <Text style={text.muted}>
                {ageSex(b.patient)} · {b.patient.uhid} · {b.bill_no}
              </Text>
              <Text style={text.body} numberOfLines={2}>
                {b.items.map((i) => i.description).join(', ') || (b.kind === 'lab' ? 'Lab tests' : 'Services')}
              </Text>
              <Text style={text.body}>
                Billed {whole(b.total)} · <Text style={{ fontWeight: '700', color: b.due > 0 ? colors.danger : colors.ok }}>{b.due > 0 ? `${whole(b.due)} due` : 'Paid'}</Text>
              </Text>
              <Call mobile={b.patient.mobile} />
            </Card>
          ))}
          <Text style={[text.muted, { textAlign: 'center' }]}>Booking, billing and taking money are done on the web system.</Text>
        </>
      )}
    </>
  )
}
