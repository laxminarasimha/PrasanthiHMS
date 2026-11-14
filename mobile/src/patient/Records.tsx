import { useEffect, useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate, type Records } from '../api'
import { colors, space } from '../theme'
import { Card, Empty, Loading, Notice, text } from '../ui'

/** The patient's own prescriptions and lab reports, once the hospital has verified the account. */
export function PatientRecords({ patientId }: { patientId: string }) {
  const [records, setRecords] = useState<Records | null>(null)
  const [show, setShow] = useState<'visits' | 'lab'>('visits')
  const [open, setOpen] = useState<string | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api.get<Records>('/app/records').then(setRecords).catch((e: ApiError) => setError(e.message))
  }, [])

  if (error) return <Notice text={error} />
  if (!records) return <Loading />

  if (!records.verified) {
    return (
      <Card>
        <Text style={text.h2}>Your records are not open yet</Text>
        <Text style={text.body}>To keep your medical records private, the hospital first confirms that this account is yours.</Text>
        <Text style={text.body}>On your next visit, show this screen at the front desk and tell them your Patient ID:</Text>
        <Text style={styles.id}>{patientId}</Text>
        <Text style={text.muted}>After that, your prescriptions and lab reports will appear here. Booking appointments and monthly medicines work already.</Text>
      </Card>
    )
  }

  const toggle = (key: string) => setOpen(open === key ? null : key)

  return (
    <>
      <View style={styles.switch}>
        {([['visits', `Prescriptions (${records.visits.length})`], ['lab', `Lab reports (${records.lab.length})`]] as const).map(([key, label]) => (
          <Pressable key={key} onPress={() => setShow(key)} accessibilityRole="tab" accessibilityState={{ selected: show === key }} style={[styles.switchItem, show === key && styles.switchOn]}>
            <Text style={[styles.switchText, show === key && { color: '#fff' }]}>{label}</Text>
          </Pressable>
        ))}
      </View>

      {show === 'visits' && records.visits.length === 0 && <Empty text="No prescriptions yet. They appear here after a doctor finishes your visit." />}
      {show === 'visits' &&
        records.visits.map((v) => {
          const key = `v${v.id}`
          return (
            <Card key={key}>
              <Pressable onPress={() => toggle(key)} accessibilityRole="button" accessibilityState={{ expanded: open === key }}>
                <Text style={text.strong}>{niceDate(v.date)}</Text>
                <Text style={text.body}>{v.diagnosis?.split('\n')[0] || 'Visit'}</Text>
                <Text style={text.muted}>
                  {v.doctor?.name} · {v.medicines.length} medicine{v.medicines.length === 1 ? '' : 's'} · {open === key ? 'Tap to close' : 'Tap to open'}
                </Text>
              </Pressable>
              {open === key && (
                <View style={styles.detail}>
                  {v.diagnosis ? <Text style={text.body}><Text style={styles.label}>Diagnosis: </Text>{v.diagnosis}</Text> : null}
                  {v.medicines.map((m, i) => (
                    <View key={i} style={styles.medicine}>
                      <Text style={text.strong}>{m.medicine}</Text>
                      {m.directions ? <Text style={text.muted}>{m.directions}</Text> : null}
                    </View>
                  ))}
                  {v.tests_advised ? <Text style={text.body}><Text style={styles.label}>Tests advised: </Text>{v.tests_advised}</Text> : null}
                  {v.advice ? <Text style={text.body}><Text style={styles.label}>Advice: </Text>{v.advice}</Text> : null}
                  {v.follow_up_on ? <Text style={text.body}><Text style={styles.label}>Next visit: </Text>{niceDate(v.follow_up_on)}</Text> : null}
                </View>
              )}
            </Card>
          )
        })}

      {show === 'lab' && records.lab.length === 0 && <Empty text="No lab reports yet. A report appears here once the lab has finished it." />}
      {show === 'lab' &&
        records.lab.map((o) => {
          const key = `l${o.id}`
          return (
            <Card key={key}>
              <Pressable onPress={() => toggle(key)} accessibilityRole="button" accessibilityState={{ expanded: open === key }}>
                <Text style={text.strong}>{niceDate(o.date)}</Text>
                <Text style={text.body}>{o.tests.map((t) => t.name).join(', ')}</Text>
                <Text style={text.muted}>
                  {o.order_no} · {open === key ? 'Tap to close' : 'Tap to open'}
                </Text>
              </Pressable>
              {open === key &&
                o.tests.map((t) => (
                  <View key={t.name} style={styles.detail}>
                    <Text style={styles.label}>{t.name}</Text>
                    {t.results.map((r) => (
                      <View key={r.parameter} style={styles.result}>
                        <Text style={[text.body, { flex: 1 }]}>{r.parameter}</Text>
                        <View style={{ alignItems: 'flex-end' }}>
                          <Text style={[text.strong, r.flag ? { color: colors.danger } : null]}>
                            {r.value} {r.unit ?? ''}
                            {r.flag === 'high' ? ' ▲' : r.flag === 'low' ? ' ▼' : ''}
                          </Text>
                          {r.range ? <Text style={text.muted}>Normal {r.range}</Text> : null}
                        </View>
                      </View>
                    ))}
                    {t.report ? <Text style={text.body}>{t.report}</Text> : null}
                    {t.remarks ? <Text style={text.muted}>{t.remarks}</Text> : null}
                  </View>
                ))}
              {open === key && <Text style={text.muted}>▲ above and ▼ below the normal range. Please discuss your results with your doctor.</Text>}
            </Card>
          )
        })}
    </>
  )
}

const styles = StyleSheet.create({
  id: { fontSize: 26, fontWeight: '800', color: colors.brandDark, letterSpacing: 1, textAlign: 'center', paddingVertical: space.sm },
  switch: { flexDirection: 'row', backgroundColor: colors.surface, borderRadius: 10, borderWidth: 1, borderColor: colors.border, padding: 3 },
  switchItem: { flex: 1, alignItems: 'center', paddingVertical: 9, borderRadius: 8 },
  switchOn: { backgroundColor: colors.brand },
  switchText: { fontSize: 14, fontWeight: '700', color: colors.text },
  detail: { borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm, gap: space.sm },
  label: { fontSize: 13, fontWeight: '700', color: colors.muted },
  medicine: { backgroundColor: colors.bg, borderRadius: 8, padding: space.sm },
  result: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingVertical: 4, borderBottomWidth: 1, borderBottomColor: colors.border },
})
