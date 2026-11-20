import { MyRecord } from '../staff/MyRecord'
import { useCallback, useEffect, useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { addDays, api, ApiError, niceDate, niceTime, todayIso, type Inpatient, type QueueEntry, type StaffUser } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Empty, Loading, Notice, Pill, Screen, Switch, TabBar, text } from '../ui'
import { AbnormalResults } from './Alerts'
import { FindPatient } from './Find'
import { HospitalDay } from './Hospital'
import { IpdBoard } from './owner/IpdBoard'
import { PharmacyBoard } from './owner/PharmacyBoard'
import { ReportsBoard } from './owner/ReportsBoard'
import { PatientView, type PatientRef } from './PatientView'
import { VisitPad } from './VisitPad'
import { Work, workSections } from '../staff/Work'

type Tab = 'appointments' | 'inpatients' | 'alerts' | 'find' | 'work' | 'hospital' | 'me'
const TITLES: Record<Tab, string> = { appointments: 'My appointments', inpatients: 'My inpatients', alerts: 'Results to see', find: 'Find a patient', work: 'Today’s work', hospital: 'Hospital', me: 'My record' }
/** The owner's side of the app: the day, the wards, the pharmacy and the money. */
type Section = 'day' | 'ipd' | 'pharmacy' | 'reports'

/** The doctor's appointments for a day, refreshed on its own while the screen is open. */
export function DoctorHome({ user, onSignOut }: { user: StaffUser; onSignOut: () => void }) {
  const [date, setDate] = useState(todayIso())
  const [queue, setQueue] = useState<QueueEntry[] | null>(null)
  const [error, setError] = useState('')
  // A doctor starts on their appointments; an owner or manager who does not consult starts on the hospital's day.
  const tabs: { key: Tab; label: string }[] = [
    ...(user.can_consult ? [{ key: 'appointments' as const, label: 'Today' }, { key: 'inpatients' as const, label: 'Inpatients' }, { key: 'alerts' as const, label: 'Results' }, { key: 'find' as const, label: 'Find' }] : []),
    // The front desk, the lab and the pharmacy each get their working screens; a person who does several gets them all.
    ...(workSections(user).length > 0 ? [{ key: 'work' as const, label: 'Work' }] : []),
    // The wards are open to those who work in IPD; the day's figures and the reports to those who see reports.
    ...(user.can_view_reports || user.can_ipd || user.can_view_pharmacy ? [{ key: 'hospital' as const, label: 'Hospital' }] : []),
    // Every member of staff with a staff record: duties, attendance, leave and payslips.
    ...(user.abilities?.['wf:self:view'] ? [{ key: 'me' as const, label: 'Me' }] : []),
  ]
  const [tab, setTab] = useState<Tab>(tabs[0]?.key ?? 'hospital')
  const [refreshKey, setRefreshKey] = useState(0)
  // What the Hospital tab offers this person. The choice is kept while a patient is opened from it and closed again.
  const sections: { value: Section; label: string }[] = [
    ...(user.can_view_reports ? [{ value: 'day' as const, label: 'Today' }] : []),
    ...(user.can_view_reports || user.can_ipd ? [{ value: 'ipd' as const, label: 'IPD' }] : []),
    ...(user.can_run_pharmacy || user.can_view_pharmacy ? [{ value: 'pharmacy' as const, label: 'Pharmacy' }] : []),
    ...(user.can_view_reports ? [{ value: 'reports' as const, label: 'Reports' }] : []),
  ]
  const [section, setSection] = useState<Section>(sections[0]?.value ?? 'ipd')
  const [inpatients, setInpatients] = useState<Inpatient[] | null>(null)
  const [viewing, setViewing] = useState<{ patient: PatientRef; admissionId?: number; subtitle?: string } | null>(null)
  // The appointment whose visit pad is open. A patient's history can be opened on top of it.
  const [pad, setPad] = useState<number | null>(null)

  const load = useCallback(() => {
    if (!user.can_consult) return Promise.resolve()
    return api.get<QueueEntry[]>('/doctor/queue', { date }).then((list) => {
      setQueue(list)
      setError('')
    }).catch((e: ApiError) => setError(e.message))
  }, [date, user.can_consult])

  useEffect(() => {
    setQueue(null)
    void load()
    const timer = setInterval(() => void load(), 30000)
    return () => clearInterval(timer)
  }, [load])

  // Patients in a bed under this doctor's name.
  useEffect(() => {
    if (tab !== 'inpatients') return
    setInpatients(null)
    api.get<Inpatient[]>('/ipd/admissions', { status: 'admitted' }).then((list) => setInpatients(list.filter((a) => a.doctor.id === user.id))).catch((e: ApiError) => setError(e.message))
  }, [tab, user.id, refreshKey])

  if (viewing) {
    return (
      <PatientView
        patient={viewing.patient}
        admissionId={viewing.admissionId}
        subtitle={viewing.subtitle}
        onBack={() => setViewing(null)}
        canWrite={Boolean(user.can_consult)}
        // Only a doctor starts a visit from here: it is put in their own name. Not while a pad is already open underneath.
        onStartVisit={user.role === 'doctor' && pad === null ? (id) => { setViewing(null); setPad(id) } : undefined}
      />
    )
  }
  if (pad !== null) {
    return (
      <VisitPad
        appointmentId={pad}
        onBack={() => {
          setPad(null)
          void load()
        }}
        onHistory={(patient) => setViewing({ patient })}
      />
    )
  }

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
      title={TITLES[tab]}
      subtitle={user.name}
      onRefresh={async () => {
        setRefreshKey((k) => k + 1)
        await load()
      }}
      right={
        <Pressable onPress={onSignOut} accessibilityRole="button" hitSlop={8}>
          <Text style={{ color: '#fff', fontSize: 14, fontWeight: '600' }}>Sign out</Text>
        </Pressable>
      }
      footer={tabs.length > 1 ? <TabBar tabs={tabs} value={tab} onChange={(t) => { setTab(t); setError('') }} /> : undefined}
    >
      {tab === 'alerts' && <AbnormalResults key={refreshKey} onOpen={(patient) => setViewing({ patient })} />}
      {tab === 'find' && <FindPatient onOpen={(patient) => setViewing({ patient })} />}
      {tab === 'work' && <Work user={user} refreshKey={refreshKey} />}
      {tab === 'me' && <MyRecord refreshKey={refreshKey} />}
      {tab === 'hospital' && (
        <>
          {sections.length > 1 && <Switch options={sections} value={section} onChange={setSection} />}
          {section === 'day' && <HospitalDay user={user} refreshKey={refreshKey} />}
          {section === 'ipd' && <IpdBoard refreshKey={refreshKey} onOpen={(patient, admissionId, subtitle) => setViewing({ patient, admissionId, subtitle })} />}
          {section === 'pharmacy' && <PharmacyBoard user={user} refreshKey={refreshKey} />}
          {section === 'reports' && <ReportsBoard refreshKey={refreshKey} />}
        </>
      )}
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
        <Pressable key={q.id} onPress={() => (q.status === 'cancelled' ? setViewing({ patient: q.patient }) : setPad(q.id))} accessibilityRole="button">
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
          <Text style={text.link}>{q.status === 'reviewed' ? 'Open the visit' : q.status === 'cancelled' ? 'See the patient' : 'Open the visit pad'}</Text>
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
