import { useCallback, useEffect, useState } from 'react'
import { Alert, Platform, Text, View } from 'react-native'
import { api, ApiError, niceDate, niceTime, type Account, type Appointment, type Subscription } from '../api'
import { Button, Card, Empty, Loading, Notice, Pill, Screen, TabBar, text } from '../ui'
import { BookAppointment } from './Book'
import { SubscriptionEdit } from './SubscriptionEdit'

type Tab = 'appointments' | 'medicines' | 'account'

/** Asks before something that cannot be undone; a browser preview uses its own dialog. */
function confirm(title: string, message: string, yes: string, then: () => void) {
  if (Platform.OS === 'web') {
    if (globalThis.confirm?.(`${title}\n\n${message}`)) then()
    return
  }
  Alert.alert(title, message, [{ text: 'No', style: 'cancel' }, { text: yes, style: 'destructive', onPress: then }])
}

export function PatientHome({ account, onSignOut }: { account: Account; onSignOut: () => void }) {
  const [tab, setTab] = useState<Tab>('appointments')
  const [page, setPage] = useState<{ kind: 'book' } | { kind: 'subscription'; existing: Subscription | null } | null>(null)
  const [appointments, setAppointments] = useState<Appointment[] | null>(null)
  const [subscriptions, setSubscriptions] = useState<Subscription[] | null>(null)
  const [message, setMessage] = useState<{ text: string; kind: 'ok' | 'error' }>({ text: '', kind: 'ok' })

  const load = useCallback(() => {
    api.get<Appointment[]>('/app/appointments').then(setAppointments).catch((e: ApiError) => setMessage({ text: e.message, kind: 'error' }))
    api.get<Subscription[]>('/app/subscriptions').then(setSubscriptions).catch(() => undefined)
  }, [])
  useEffect(load, [load])

  if (page?.kind === 'book') {
    return (
      <BookAppointment
        onBack={() => setPage(null)}
        onBooked={(a) => {
          setPage(null)
          setMessage({ text: `Booked with ${a.doctor?.name} on ${niceDate(a.scheduled_at)} at ${niceTime(a.scheduled_at)}. Your token number is ${a.token_no}.`, kind: 'ok' })
          load()
        }}
      />
    )
  }
  if (page?.kind === 'subscription') {
    return (
      <SubscriptionEdit
        existing={page.existing}
        onBack={() => setPage(null)}
        onSaved={() => {
          setPage(null)
          setMessage({ text: 'Your monthly medicines are saved. The pharmacy will keep them ready.', kind: 'ok' })
          load()
        }}
      />
    )
  }

  const act = async (run: () => Promise<unknown>, done: string) => {
    try {
      await run()
      setMessage({ text: done, kind: 'ok' })
      load()
    } catch (e) {
      setMessage({ text: (e as ApiError).message, kind: 'error' })
    }
  }

  const upcoming = appointments?.filter((a) => a.can_cancel || (a.status !== 'cancelled' && a.status !== 'reviewed' && a.scheduled_at.slice(0, 10) >= new Date().toISOString().slice(0, 10))) ?? []
  const earlier = appointments?.filter((a) => !upcoming.includes(a)) ?? []
  const editBody = (s: Subscription) => ({ title: s.title, day_of_month: s.day_of_month, notes: s.notes, items: s.items })

  return (
    <Screen
      title={tab === 'appointments' ? 'My appointments' : tab === 'medicines' ? 'Monthly medicines' : 'My account'}
      subtitle={account.hospital.name}
      footer={<TabBar tabs={[{ key: 'appointments', label: 'Appointments' }, { key: 'medicines', label: 'Medicines' }, { key: 'account', label: 'Account' }]} value={tab} onChange={(t) => { setTab(t); setMessage({ text: '', kind: 'ok' }) }} />}
    >
      <Notice text={message.text} kind={message.kind} />

      {tab === 'appointments' && (
        <>
          <Button label="+ Book an appointment" onPress={() => setPage({ kind: 'book' })} />
          {!appointments && <Loading />}
          {appointments && upcoming.length === 0 && <Empty text="You have no upcoming appointment." />}
          {upcoming.map((a) => (
            <Card key={a.id}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                <View style={{ flex: 1 }}>
                  <Text style={text.strong}>{niceDate(a.scheduled_at)}</Text>
                  <Text style={text.body}>
                    {niceTime(a.scheduled_at)}
                    {a.token_no ? ` · Token ${a.token_no}` : ''}
                  </Text>
                </View>
                <Pill status={a.status} />
              </View>
              <Text style={text.body}>{a.doctor?.name}</Text>
              {a.doctor?.department ? <Text style={text.muted}>{a.doctor.department}</Text> : null}
              {a.notes ? <Text style={text.muted}>{a.notes}</Text> : null}
              {a.can_cancel && (
                <Button
                  label="Cancel this appointment"
                  kind="danger"
                  small
                  onPress={() => confirm('Cancel appointment?', `${a.doctor?.name}, ${niceDate(a.scheduled_at)} at ${niceTime(a.scheduled_at)}`, 'Yes, cancel', () => act(() => api.delete(`/app/appointments/${a.id}`), 'The appointment is cancelled.'))}
                />
              )}
            </Card>
          ))}
          {earlier.length > 0 && <Text style={[text.h2, { marginTop: 8 }]}>Earlier</Text>}
          {earlier.map((a) => (
            <Card key={a.id}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                <View style={{ flex: 1 }}>
                  <Text style={text.body}>
                    {niceDate(a.scheduled_at)} · {niceTime(a.scheduled_at)}
                  </Text>
                  <Text style={text.muted}>{a.doctor?.name}</Text>
                </View>
                <Pill status={a.status} />
              </View>
            </Card>
          ))}
        </>
      )}

      {tab === 'medicines' && (
        <>
          <Button label="+ New monthly list" onPress={() => setPage({ kind: 'subscription', existing: null })} />
          {!subscriptions && <Loading />}
          {subscriptions?.length === 0 && <Empty text={'Take the same medicines every month, for diabetes, thyroid or blood pressure?\n\nMake a list once and the hospital pharmacy will keep them ready for you each month.'} />}
          {subscriptions?.map((s) => (
            <Card key={s.id}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                <Text style={[text.strong, { flex: 1 }]}>{s.title}</Text>
                <Pill status={s.status} />
              </View>
              {s.items.map((i) => (
                <Text key={i.name} style={text.body}>
                  {i.name} × {i.quantity}
                </Text>
              ))}
              <Text style={text.muted}>
                {s.status === 'active' ? `Next pack ready on ${niceDate(s.next_due_on)}` : 'Paused: the pharmacy is not making this up'} · every month on day {s.day_of_month}
              </Text>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                <Button label="Change" kind="ghost" small onPress={() => setPage({ kind: 'subscription', existing: s })} />
                {s.status === 'active' ? (
                  <Button label="Pause" kind="ghost" small onPress={() => act(() => api.put(`/app/subscriptions/${s.id}`, { ...editBody(s), status: 'paused' }), 'Paused. Start it again whenever you like.')} />
                ) : (
                  <Button label="Start again" kind="ghost" small onPress={() => act(() => api.put(`/app/subscriptions/${s.id}`, { ...editBody(s), status: 'active' }), 'Started again.')} />
                )}
                <Button label="Stop" kind="danger" small onPress={() => confirm('Stop this list?', `The pharmacy will no longer keep “${s.title}” ready.`, 'Yes, stop', () => act(() => api.delete(`/app/subscriptions/${s.id}`), 'The list is stopped.'))} />
              </View>
            </Card>
          ))}
        </>
      )}

      {tab === 'account' && (
        <>
          <Card>
            <Text style={text.h2}>{account.name}</Text>
            <Text style={text.body}>Mobile: {account.mobile}</Text>
            <Text style={text.body}>Patient ID: {account.patient.uhid}</Text>
            <Text style={text.muted}>
              {account.patient.age_label} · {account.patient.gender.charAt(0).toUpperCase() + account.patient.gender.slice(1)}
            </Text>
          </Card>
          {!account.verified && <Notice kind="info" text="If you have visited the hospital before, tell the front desk your Patient ID on your next visit and they will join this account to your earlier records." />}
          <Button label="Sign out" kind="ghost" onPress={onSignOut} />
        </>
      )}
    </Screen>
  )
}
