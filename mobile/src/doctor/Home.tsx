import { useCallback, useEffect, useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { addDays, api, ApiError, niceDate, niceTime, todayIso, type Inpatient, type QueueEntry, type StaffUser } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Empty, Loading, Notice, Pill, Screen, TabBar, text } from '../ui'
import { PatientView, type PatientRef } from './PatientView'

/** The doctor's appointments for a day, refreshed on its own while the screen is open. */
export function DoctorHome({ user, onSignOut }: { user: StaffUser; onSignOut: () => void }) {
  const [date, setDate] = useState(todayIso())
  const [queue, setQueue] = useState<QueueEntry[] | null>(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useState<'appointments' | 'inpatients'>('appointments')
  const [inpatients, setInpatients] = useState<Inpatient[] | null>(null)
  const [viewing, setViewing] = useState<{ patient: PatientRef; admissionId?: number; subtitle?: string } | null>(null)

  const load = useCallback(() => {
    api.get<QueueEntry[]>('/doctor/queue', { date }).then((list) => {
      setQueue(list)
      setError('')
    }).catch((e: ApiError) => setError(e.message))
  }, [date])

  useEffect(() => {
    setQueue(null)
    load()
    const timer = setInterval(load, 30000)
    return () => clearInterval(timer)
  }, [load])

  // Patients in a bed under this doctor's name.
  useEffect(() => {
    if (tab !== 'inpatients') return
    api.get<Inpatient[]>('/ipd/admissions', { status: 'admitted' }).then((list) => setInpatients(list.filter((a) => a.doctor.id === user.id))).catch((e: ApiError) => setError(e.message))
  }, [tab, user.id])

  if (viewing) return <PatientView patient={viewing.patient} admissionId={viewing.admissionId} subtitle={viewing.subtitle} onBack={() => setViewing(null)} />

  const stay = (admittedAt: string) => {
    const days = Math.max(1, Math.ceil((Date.now() - new Date(admittedAt.replace(' ', 'T')).getTime()) / 86400000))
    return `Day ${days}`
  }

  const count = (status: QueueEntry['status']) => queue?.filter((q) => q.status === status).length ?? 0
  const waiting = (q: QueueEntry) => {
    if (q.status !== 'arrived' || !q.arrived_at) return ''
    const minutes = Math.max(0, Math.round((Date.now() - new Date(q.arrived_at.replace(' ', 'T')).getTime()) / 60000))
    return minutes < 60 ? `Waiting ${minutes} min` : `Waiting ${Math.floor(minutes / 60)}h ${minutes % 60}m`
  }

  return (
    <Screen
      title={tab === 'appointments' ? 'My appointments' : 'My inpatients'}
      subtitle={user.name}
      right={
        <Pressable onPress={onSignOut} accessibilityRole="button" hitSlop={8}>
          <Text style={{ color: '#fff', fontSize: 14, fontWeight: '600' }}>Sign out</Text>
        </Pressable>
      }
      footer={<TabBar tabs={[{ key: 'appointments', label: 'Appointments' }, { key: 'inpatients', label: 'Inpatients' }]} value={tab} onChange={(t) => { setTab(t); setError('') }} />}
    >
      {tab === 'inpatients' && (
        <>
          <Notice text={error} />
          {!inpatients && !error && <Loading />}
          {inpatients?.length === 0 && <Empty text="You have no patient admitted right now." />}
          {inpatients?.map((a) => (
            <Pressable key={a.id} onPress={() => setViewing({ patient: a.patient, admissionId: a.id, subtitle: a.ipd_no })} accessibilityRole="button">
              <Card>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
                  <View style={{ flex: 1 }}>
                    <Text style={text.strong}>
                      {a.patient.title ? `${a.patient.title} ` : ''}
                      {a.patient.name}
                    </Text>
                    <Text style={text.muted}>
                      {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()} · {a.ipd_no}
                    </Text>
                  </View>
                  <Text style={text.strong}>{stay(a.admitted_at)}</Text>
                </View>
                <Text style={text.body}>{a.bed ? `${a.bed.ward}, bed ${a.bed.bed_no}` : 'No bed assigned'}</Text>
                {a.reason ? <Text style={text.muted}>{a.reason}</Text> : null}
              </Card>
            </Pressable>
          ))}
        </>
      )}
      {tab === 'appointments' && (
        <>
      <View style={styles.dateRow}>
        <Button label="‹" kind="ghost" small onPress={() => setDate(addDays(date, -1))} />
        <View style={{ flex: 1, alignItems: 'center' }}>
          <Text style={text.strong}>{date === todayIso() ? 'Today' : niceDate(date)}</Text>
          {date === todayIso() ? <Text style={text.muted}>{niceDate(date)}</Text> : (
            <Pressable onPress={() => setDate(todayIso())} accessibilityRole="button">
              <Text style={text.link}>Back to today</Text>
            </Pressable>
          )}
        </View>
        <Button label="›" kind="ghost" small onPress={() => setDate(addDays(date, 1))} />
      </View>

      <View style={styles.stats}>
        {([['booked', 'Yet to arrive'], ['arrived', 'Waiting'], ['ongoing', 'With me'], ['reviewed', 'Seen']] as const).map(([status, label]) => (
          <View key={status} style={styles.stat}>
            <Text style={styles.statNumber}>{count(status)}</Text>
            <Text style={styles.statLabel}>{label}</Text>
          </View>
        ))}
      </View>

      <Notice text={error} />
      {!queue && !error && <Loading />}
      {queue?.length === 0 && <Empty text="No appointments for this day." />}
      {queue?.map((q) => (
        <Pressable key={q.id} onPress={() => setViewing({ patient: q.patient })} accessibilityRole="button">
        <Card>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
            <View style={{ flex: 1 }}>
              <Text style={text.strong}>
                {q.patient.title ? `${q.patient.title} ` : ''}
                {q.patient.name}
              </Text>
              <Text style={text.muted}>
                {q.patient.age_label} / {q.patient.gender.charAt(0).toUpperCase()} · {q.patient.uhid}
              </Text>
            </View>
            <Pill status={q.status} />
          </View>
          <Text style={text.body}>
            {niceTime(q.scheduled_at)}
            {q.token_no ? ` · Token ${q.token_no}` : ''}
            {q.service ? ` · ${q.service.name}` : ''}
          </Text>
          <Text style={text.muted}>
            {[q.visits ? `${q.visits} earlier visit${q.visits === 1 ? '' : 's'}` : 'First visit', waiting(q), q.vitals_ready && q.status !== 'reviewed' ? 'Vitals taken' : '', q.booked_via === 'app' ? 'Booked from the app' : ''].filter(Boolean).join(' · ')}
          </Text>
        </Card>
        </Pressable>
      ))}
        </>
      )}
    </Screen>
  )
}

const styles = StyleSheet.create({
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  stats: { flexDirection: 'row', gap: space.sm },
  stat: { flex: 1, backgroundColor: colors.surface, borderRadius: 10, borderWidth: 1, borderColor: colors.border, paddingVertical: space.sm, alignItems: 'center' },
  statNumber: { fontSize: 22, fontWeight: '700', color: colors.brandDark },
  statLabel: { fontSize: 11, color: colors.muted, textAlign: 'center' },
})
