import { useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate, niceTime, nowStamp, type BedsideVital, type HealthSummary, type PastVisit, type Trends, type WardNote } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Choice, Empty, Field, Loading, Notice, Screen, text } from '../ui'

export type PatientRef = { id: number; uhid: string; name: string; title?: string | null; gender: string; age_label: string }

/** What a doctor wants at a glance before seeing someone: lasting health facts, recent visits, recent results. */
const KINDS: [string, string][] = [['condition', 'Condition'], ['medication', 'Regular medicine'], ['surgery', 'Surgery'], ['family', 'Family history'], ['habit', 'Habit'], ['immunisation', 'Vaccine']]

export function PatientView({ patient, admissionId, subtitle, onBack, canWrite, onStartVisit }: { patient: PatientRef; admissionId?: number; subtitle?: string; onBack: () => void; /** May add to the health summary. */ canWrite?: boolean; /** Opens a visit pad for this patient today. */ onStartVisit?: (appointmentId: number) => void }) {
  const [health, setHealth] = useState<HealthSummary | null>(null)
  const [visits, setVisits] = useState<PastVisit[] | null>(null)
  const [trends, setTrends] = useState<Trends | null>(null)
  const [vitals, setVitals] = useState<BedsideVital[] | null>(null)
  const [notes, setNotes] = useState<WardNote[] | null>(null)
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)
  const [adding, setAdding] = useState(false)
  const [item, setItem] = useState({ kind: 'condition', title: '', since: '' })
  const [healthMessage, setHealthMessage] = useState('')
  const [starting, setStarting] = useState(false)
  const [startError, setStartError] = useState('')

  const addItem = async () => {
    setSaving(true)
    setHealthMessage('')
    try {
      await api.post(`/patients/${patient.id}/health`, { kind: item.kind, title: item.title.trim(), since: item.since.trim() || null })
      setHealth(await api.get<HealthSummary>(`/patients/${patient.id}/health`))
      setItem({ kind: item.kind, title: '', since: '' })
      setAdding(false)
    } catch (e) {
      setHealthMessage((e as ApiError).message)
    } finally {
      setSaving(false)
    }
  }

  const startVisit = async () => {
    setStarting(true)
    setStartError('')
    try {
      const made = await api.post<{ id: number }>('/doctor/visits', { patient_id: patient.id })
      onStartVisit?.(made.id)
    } catch (e) {
      setStartError((e as ApiError).message)
      setStarting(false)
    }
  }
  const [noteMessage, setNoteMessage] = useState<{ text: string; kind: 'ok' | 'error' }>({ text: '', kind: 'ok' })

  const saveNote = async () => {
    setSaving(true)
    try {
      setNotes(await api.post<WardNote[]>(`/ipd/admissions/${admissionId}/notes`, { kind: 'clinical', noted_at: nowStamp(), body: note.trim() }))
      setNote('')
      setNoteMessage({ text: 'The note is saved to the case sheet.', kind: 'ok' })
    } catch (e) {
      setNoteMessage({ text: (e as ApiError).message, kind: 'error' })
    } finally {
      setSaving(false)
    }
  }

  useEffect(() => {
    api.get<HealthSummary>(`/patients/${patient.id}/health`).then(setHealth).catch(() => setHealth({ allergies: null, sections: [] }))
    api.get<PastVisit[]>(`/patients/${patient.id}/consultations`).then(setVisits).catch(() => setVisits([]))
    api.get<Trends>(`/patients/${patient.id}/trends`).then(setTrends).catch(() => setTrends({ tests: [] }))
    if (admissionId) {
      api.get<{ vitals: BedsideVital[] }>(`/ipd/admissions/${admissionId}/chart`).then((c) => setVitals(c.vitals)).catch(() => setVitals([]))
      api.get<WardNote[]>(`/ipd/admissions/${admissionId}/notes`).then(setNotes).catch(() => setNotes([]))
    }
  }, [patient.id, admissionId])

  const filled = health?.sections.filter((s) => s.items.some((i) => i.is_active)) ?? []
  const latest = vitals?.[0]
  const reading = (label: string, value: string | number | null | undefined, unit = '') => (value === null || value === undefined ? null : `${label} ${value}${unit}`)

  return (
    <Screen title={`${patient.title ? `${patient.title} ` : ''}${patient.name}`} subtitle={`${patient.age_label} / ${patient.gender.charAt(0).toUpperCase()} · ${patient.uhid}${subtitle ? ` · ${subtitle}` : ''}`} onBack={onBack}>
      {health?.allergies ? (
        <View style={styles.allergy} accessibilityRole="alert">
          <Text style={styles.allergyText}>Allergic to {health.allergies}</Text>
        </View>
      ) : null}

      {onStartVisit && !admissionId ? (
        <>
          <Button label="Start a visit now" onPress={startVisit} busy={starting} />
          <Notice text={startError} />
        </>
      ) : null}

      {admissionId ? (
        <Card>
          <Text style={text.h2}>Latest observations</Text>
          {!vitals && <Loading />}
          {vitals && !latest && <Text style={text.muted}>None recorded yet.</Text>}
          {latest && (
            <>
              <Text style={text.body}>
                {[reading('Temp', latest.temperature_f, '°F'), reading('Pulse', latest.pulse), latest.bp_systolic ? `BP ${latest.bp_systolic}/${latest.bp_diastolic}` : null, reading('SpO₂', latest.spo2, '%'), reading('Resp', latest.resp_rate), reading('Sugar', latest.blood_sugar)].filter(Boolean).join(' · ')}
              </Text>
              <Text style={text.muted}>
                {niceDate(latest.recorded_at)} at {niceTime(latest.recorded_at)} · {vitals!.length} set{vitals!.length === 1 ? '' : 's'} recorded
              </Text>
            </>
          )}
        </Card>
      ) : null}

      {admissionId ? (
        <Card>
          <Text style={text.h2}>Round notes</Text>
          <Field label="Add a note from this round" value={note} onChangeText={setNote} multiline placeholder="Condition, plan, orders to follow" style={{ minHeight: 88, paddingTop: 12, textAlignVertical: 'top' }} />
          <Notice text={noteMessage.text} kind={noteMessage.kind} />
          <Button label="Save note" onPress={saveNote} busy={saving} disabled={note.trim().length < 2} />
          {!notes && <Loading />}
          {/* The three latest, newest first, whichever order they arrive in. */}
          {[...(notes ?? [])]
            .filter((n) => n.body)
            .sort((a, b) => b.noted_at.localeCompare(a.noted_at))
            .slice(0, 3)
            .map((n) => (
              <View key={n.id} style={styles.visit}>
                <Text style={text.muted}>
                  {niceDate(n.noted_at)} {niceTime(n.noted_at)}
                  {n.author ? ` · ${n.author.name}` : ''} · {n.kind === 'clinical' ? 'Doctor' : n.kind === 'nursing' ? 'Nursing' : 'Theatre'}
                </Text>
                <Text style={text.body}>{n.body}</Text>
              </View>
            ))}
        </Card>
      ) : null}

      <Card>
        <Text style={text.h2}>Health summary</Text>
        {!health && <Loading />}
        {health && filled.length === 0 && <Text style={text.muted}>Nothing recorded yet.</Text>}
        {filled.map((s) => (
          <View key={s.kind} style={{ gap: 2 }}>
            <Text style={styles.label}>{s.label}</Text>
            {s.items.filter((i) => i.is_active).map((i) => (
              <Text key={i.id} style={text.body}>
                {i.title}
                {i.since ? <Text style={text.muted}> · {i.since}</Text> : null}
                {i.detail ? <Text style={text.muted}> · {i.detail}</Text> : null}
              </Text>
            ))}
          </View>
        ))}
        {canWrite &&
          (adding ? (
            <>
              <Choice value={item.kind} onChange={(kind) => setItem({ ...item, kind })} options={KINDS.map(([value, label]) => ({ value, label }))} />
              <Field label="What to add" value={item.title} onChangeText={(title) => setItem({ ...item, title })} placeholder="Type 2 diabetes, Metformin 500, Appendicectomy" />
              <Field label="Since (optional)" value={item.since} onChangeText={(since) => setItem({ ...item, since })} placeholder="2019, or 5 years" />
              <Notice text={healthMessage} />
              <View style={{ flexDirection: 'row', gap: 8 }}>
                <View style={{ flex: 1 }}>
                  <Button label="Cancel" kind="ghost" onPress={() => setAdding(false)} />
                </View>
                <View style={{ flex: 1 }}>
                  <Button label="Add" onPress={addItem} busy={saving} disabled={item.title.trim().length < 2} />
                </View>
              </View>
            </>
          ) : (
            <Button label="+ Add to the health summary" kind="ghost" small onPress={() => setAdding(true)} />
          ))}
      </Card>

      <Card>
        <Text style={text.h2}>Recent visits</Text>
        {!visits && <Loading />}
        {visits?.length === 0 && <Text style={text.muted}>No finished visits yet.</Text>}
        {visits?.slice(0, 5).map((v) => (
          <View key={v.id} style={styles.visit}>
            <Text style={text.strong}>{v.completed_at ? niceDate(v.completed_at) : 'Visit'}</Text>
            <Text style={text.body}>{v.diagnosis?.split('\n')[0] || 'No diagnosis noted'}</Text>
            {v.medicines.length > 0 && <Text style={text.muted}>{v.medicines.map((m) => [m.medicine, m.frequency, m.duration].filter(Boolean).join(' ')).join('; ')}</Text>}
            {v.bp_systolic ? <Text style={text.muted}>BP {v.bp_systolic}/{v.bp_diastolic}{v.weight_kg ? ` · ${v.weight_kg} kg` : ''}</Text> : null}
          </View>
        ))}
      </Card>

      <Card>
        <Text style={text.h2}>Lab results</Text>
        {!trends && <Loading />}
        {trends?.tests.length === 0 && <Empty text="No lab results reported yet." />}
        {trends?.tests.map((t) => {
          const points = t.points.slice(-3)
          const last = points[points.length - 1]
          return (
            <View key={`${t.test}|${t.parameter}`} style={styles.result}>
              <View style={{ flex: 1 }}>
                <Text style={text.body}>{t.parameter}</Text>
                <Text style={text.muted}>
                  {points.length > 1 ? `${points.slice(0, -1).map((p) => p.value).join(' → ')} → ` : ''}
                  {niceDate(last.date)}
                </Text>
              </View>
              <Text style={[text.strong, last.flag ? { color: colors.danger } : null]}>
                {last.value} {t.unit ?? ''}
                {last.flag === 'high' ? ' ▲' : last.flag === 'low' ? ' ▼' : ''}
              </Text>
            </View>
          )
        })}
      </Card>
      <Text style={[text.muted, { textAlign: 'center' }]}>To write notes and prescribe, open the patient's visit pad from Today, or start a visit above.</Text>
    </Screen>
  )
}

const styles = StyleSheet.create({
  allergy: { backgroundColor: colors.dangerSoft, borderRadius: 10, padding: space.md, borderWidth: 1, borderColor: colors.danger },
  allergyText: { color: colors.danger, fontWeight: '700', fontSize: 15 },
  label: { fontSize: 12, fontWeight: '700', color: colors.muted, textTransform: 'uppercase', letterSpacing: 0.4, marginTop: 4 },
  visit: { borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm, gap: 2 },
  result: { flexDirection: 'row', alignItems: 'center', gap: space.md, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm },
})
