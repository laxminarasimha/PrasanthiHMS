import { MyHealth } from './Health'
import { useEffect, useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate, type Person, type Records } from '../api'
import { colors, space } from '../theme'
import { Card, Choice, Empty, Loading, Notice, Switch, text } from '../ui'
import { PatientBills } from './Bills'

/** The patient's own prescriptions and lab reports, once the hospital has verified the account. */
export function PatientRecords({ people, first, section }: { people: Person[]; /** Whose records to open on, when a notice led here. */ first?: number; section?: 'visits' | 'lab' | 'bills' | 'health' }) {
  const [who, setWho] = useState(String(people.find((p) => p.patient_id === first)?.patient_id ?? people[0].patient_id))
  const [records, setRecords] = useState<Records | null>(null)
  const [show, setShow] = useState<'visits' | 'lab' | 'bills' | 'health'>(section ?? 'visits')
  const [open, setOpen] = useState<string | null>(null)
  const [error, setError] = useState('')
  const person = people.find((p) => String(p.patient_id) === who) ?? people[0]
  const patientId = person.uhid

  useEffect(() => {
    setRecords(null)
    setError('')
    api.get<Records>('/app/records', { patient_id: String(person.patient_id) }).then(setRecords).catch((e: ApiError) => setError(e.message))
  }, [person.patient_id])

  const picker = people.length > 1 ? <Choice value={who} onChange={setWho} options={people.map((p) => ({ value: String(p.patient_id), label: p.relation === 'Self' ? 'Me' : `${p.name.split(' ')[0]} (${p.relation})` }))} /> : null

  if (error) return <>{picker}<Notice text={error} /></>
  if (!records) return <>{picker}<Loading /></>

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
      {picker}
      <Switch value={show} onChange={setShow} options={[{ value: 'visits', label: 'Prescriptions' }, { value: 'lab', label: 'Lab reports' }, { value: 'health', label: 'My health' }, { value: 'bills', label: 'Bills' }]} />
      {show === 'bills' && <PatientBills patientId={person.patient_id} />}
      {show === 'health' && <MyHealth patientId={person.patient_id} />}

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
                      <Text style={text.strong}>
                        {i + 1}. {m.medicine}
                      </Text>
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
  detail: { borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm, gap: space.sm },
  label: { fontSize: 13, fontWeight: '700', color: colors.muted },
  medicine: { backgroundColor: colors.bg, borderRadius: 8, padding: space.sm },
  result: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingVertical: 4, borderBottomWidth: 1, borderBottomColor: colors.border },
})
