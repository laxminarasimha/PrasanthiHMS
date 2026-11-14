import { useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { api, niceDate, niceTime, type BedsideVital, type HealthSummary, type PastVisit, type Trends } from '../api'
import { colors, space } from '../theme'
import { Card, Empty, Loading, Screen, text } from '../ui'

export type PatientRef = { id: number; uhid: string; name: string; title?: string | null; gender: string; age_label: string }

/** What a doctor wants at a glance before seeing someone: lasting health facts, recent visits, recent results. */
export function PatientView({ patient, admissionId, subtitle, onBack }: { patient: PatientRef; admissionId?: number; subtitle?: string; onBack: () => void }) {
  const [health, setHealth] = useState<HealthSummary | null>(null)
  const [visits, setVisits] = useState<PastVisit[] | null>(null)
  const [trends, setTrends] = useState<Trends | null>(null)
  const [vitals, setVitals] = useState<BedsideVital[] | null>(null)

  useEffect(() => {
    api.get<HealthSummary>(`/patients/${patient.id}/health`).then(setHealth).catch(() => setHealth({ allergies: null, sections: [] }))
    api.get<PastVisit[]>(`/patients/${patient.id}/consultations`).then(setVisits).catch(() => setVisits([]))
    api.get<Trends>(`/patients/${patient.id}/trends`).then(setTrends).catch(() => setTrends({ tests: [] }))
    if (admissionId) api.get<{ vitals: BedsideVital[] }>(`/ipd/admissions/${admissionId}/chart`).then((c) => setVitals(c.vitals)).catch(() => setVitals([]))
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
      <Text style={[text.muted, { textAlign: 'center' }]}>To write notes or prescribe, use the web system.</Text>
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
