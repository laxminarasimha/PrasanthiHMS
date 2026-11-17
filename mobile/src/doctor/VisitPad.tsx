import { useCallback, useEffect, useMemo, useState } from 'react'
import { Alert, Platform, Pressable, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { addDays, api, ApiError, niceDate, niceTime, todayIso, type Library, type RxLine, type Visit } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Field, Loading, Notice, Screen, text } from '../ui'
import { Medicines } from './pad/Medicines'
import { Chip, Chips, Row, Section } from './pad/parts'
import { IcdCodes, OrderLabTests } from './pad/Pickers'
import { type PatientRef } from './PatientView'

const VITALS = [
  ['weight_kg', 'Weight (kg)'],
  ['height_cm', 'Height (cm)'],
  ['bp_systolic', 'BP upper'],
  ['bp_diastolic', 'BP lower'],
  ['pulse', 'Pulse (/min)'],
  ['temperature_f', 'Temp (°F)'],
  ['spo2', 'SpO₂ (%)'],
  ['resp_rate', 'Resp. rate'],
] as const
const SIGNS = ['pallor', 'icterus', 'cyanosis', 'clubbing', 'lymphadenopathy', 'edema'] as const
const TEXTS = ['quick_notes', 'complaints', 'history', 'past_medication', 'general_exam_notes', 'findings', 'diagnosis', 'assessment_plan', 'tests_advised', 'advice', 'refer_to'] as const
const TEMPLATE_KEYS = ['complaints', 'findings', 'diagnosis', 'assessment_plan', 'tests_advised', 'advice'] as const
const FOLLOW_UPS: [string, number][] = [['3 days', 3], ['1 week', 7], ['2 weeks', 14], ['1 month', 30], ['3 months', 90]]

type Form = Record<string, string>
type Exam = Record<string, 'present' | 'absent' | ''>
type Investigation = { name: string; result: string }

const tall = { minHeight: 76, paddingTop: 12, textAlignVertical: 'top' as const }

/**
 * The doctor's visit pad on the phone: vitals, notes, diagnosis, tests, prescription and next visit.
 * It saves to the same record as the web pad, so a visit begun on one can be finished on the other.
 */
export function VisitPad({ appointmentId, onBack, onHistory }: { appointmentId: number; onBack: () => void; onHistory: (patient: PatientRef) => void }) {
  const [visit, setVisit] = useState<Visit | null>(null)
  const [library, setLibrary] = useState<Library | null>(null)
  const [form, setForm] = useState<Form>({})
  const [exam, setExam] = useState<Exam>({})
  const [codes, setCodes] = useState<{ code: string; name: string }[]>([])
  const [medicines, setMedicines] = useState<RxLine[]>([])
  const [results, setResults] = useState<Investigation[]>([])
  const [saved, setSaved] = useState('')
  const [more, setMore] = useState(false)
  const [templates, setTemplates] = useState(false)
  const [message, setMessage] = useState<{ text: string; kind: 'ok' | 'error' | 'info' }>({ text: '', kind: 'ok' })
  const [warnings, setWarnings] = useState<string[] | null>(null)
  const [busy, setBusy] = useState<'save' | 'finish' | 'amend' | null>(null)
  const [amendment, setAmendment] = useState('')

  /** Puts a visit as the server holds it onto the screen. */
  const show = useCallback((v: Visit) => {
    const c = v.consultation
    const next: Form = { allergies: v.patient.allergies ?? '', follow_up_on: c?.follow_up_on ?? '' }
    for (const [key] of VITALS) next[key] = c?.[key] === null || c?.[key] === undefined ? '' : String(c[key])
    for (const key of TEXTS) next[key] = c?.[key] ?? ''
    setVisit(v)
    setForm(next)
    setExam(Object.fromEntries(SIGNS.map((s) => [s, c?.general_exam?.[s] ?? ''])))
    setCodes(c?.diagnosis_codes ?? [])
    setMedicines((c?.medicines ?? []).map(({ medicine, dose, frequency, timing, duration, instructions }) => ({ medicine, dose, frequency, timing, duration, instructions })))
    setResults((c?.investigations ?? []).map((i) => ({ name: i.name, result: i.result ?? '' })))
    // More sections open by themselves when the visit already has something in them.
    setMore(Boolean(c?.history || c?.past_medication || c?.general_exam || c?.general_exam_notes || c?.findings || c?.assessment_plan || c?.refer_to || c?.quick_notes || c?.investigations?.length))
  }, [])

  useEffect(() => {
    api.get<Visit>(`/appointments/${appointmentId}/consultation`).then(show).catch((e: ApiError) => setMessage({ text: e.message, kind: 'error' }))
    api.get<Library>('/doctor/library').then(setLibrary).catch(() => undefined)
  }, [appointmentId, show])

  const body = useMemo(() => {
    const number = (key: string) => (form[key]?.trim() ? Number(form[key]) : null)
    const words = (key: string) => form[key]?.trim() || null
    const marked = Object.fromEntries(Object.entries(exam).filter(([, value]) => value))
    return {
      ...Object.fromEntries(VITALS.map(([key]) => [key, number(key)])),
      ...Object.fromEntries(TEXTS.map((key) => [key, words(key)])),
      allergies: words('allergies'),
      general_exam: Object.keys(marked).length ? marked : null,
      diagnosis_codes: codes.map((c) => ({ code: c.code })),
      investigations: results.filter((r) => r.name.trim()).map((r) => ({ name: r.name.trim(), result: r.result.trim() || null, date: null })),
      follow_up_on: form.follow_up_on || null,
      medicines: medicines.filter((m) => m.medicine.trim()),
    }
  }, [form, exam, codes, results, medicines])

  // What was last saved, to know whether anything has been typed since.
  const snapshot = JSON.stringify(body)
  useEffect(() => {
    if (visit) setSaved(JSON.stringify(body))
    // Only when a visit arrives from the server: `body` then already holds that visit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visit])
  const dirty = Boolean(visit) && saved !== '' && snapshot !== saved

  const set = (key: string) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const locked = Boolean(visit?.locked)
  const finished = Boolean(visit?.consultation?.completed_at)
  const phrases = (kind: 'complaint' | 'diagnosis' | 'advice') => (library && !Array.isArray(library.phrases) ? (library.phrases[kind] ?? []).slice(0, 8) : [])
  const addPhrase = (key: string, phrase: string) => setForm((f) => (f[key]?.toLowerCase().includes(phrase.toLowerCase()) ? f : { ...f, [key]: f[key]?.trim() ? `${f[key].trim()}\n${phrase}` : phrase }))

  const save = async (complete: boolean) => {
    setMessage({ text: '', kind: 'ok' })
    if (complete && warnings === null && body.medicines.length) {
      // Look the prescription over for allergies and repeats before the visit is finished.
      try {
        const check = await api.post<{ warnings: string[] }>(`/patients/${visit!.patient.id}/prescription-check`, { medicines: body.medicines.map((m) => m.medicine), allergies: body.allergies })
        if (check.warnings.length) return setWarnings(check.warnings)
      } catch {
        // The check is a help, not a gate: if it cannot be reached the doctor's own judgement stands.
      }
    }
    setBusy(complete ? 'finish' : 'save')
    try {
      const next = await api.put<Visit>(`/appointments/${appointmentId}/consultation`, { ...body, ...(complete ? { complete: true } : {}) })
      show(next)
      setWarnings(null)
      setMessage({ text: complete ? 'The visit is finished. The prescription can be printed at the front desk, and the patient sees it in the app.' : 'Saved.', kind: 'ok' })
    } catch (e) {
      setMessage({ text: (e as ApiError).message, kind: 'error' })
    } finally {
      setBusy(null)
    }
  }

  const amend = async () => {
    setBusy('amend')
    try {
      show(await api.post<Visit>(`/appointments/${appointmentId}/consultation/amendments`, { body: amendment.trim() }))
      setAmendment('')
      setMessage({ text: 'The amendment is added. The original notes are unchanged.', kind: 'ok' })
    } catch (e) {
      setMessage({ text: (e as ApiError).message, kind: 'error' })
    } finally {
      setBusy(null)
    }
  }

  const leave = () => {
    if (!dirty) return onBack()
    const question = 'What you typed since the last save will be lost.'
    if (Platform.OS === 'web') {
      if (globalThis.confirm?.(`Leave without saving?\n\n${question}`)) onBack()
      return
    }
    Alert.alert('Leave without saving?', question, [{ text: 'Stay', style: 'cancel' }, { text: 'Leave', style: 'destructive', onPress: onBack }])
  }

  if (!visit) {
    return (
      <Screen title="Visit pad" onBack={onBack}>
        <Notice text={message.text} kind={message.kind} />
        {!message.text && <Loading />}
      </Screen>
    )
  }

  const p = visit.patient
  const note = (key: (typeof TEXTS)[number], label: string, placeholder?: string) => <Field label={label} value={form[key] ?? ''} onChangeText={set(key)} multiline editable={!locked} placeholder={placeholder} style={tall} />

  return (
    <Screen
      title={`${p.title ? `${p.title} ` : ''}${p.name}`}
      subtitle={`${p.age_label} / ${p.gender.charAt(0).toUpperCase()} · ${p.uhid}`}
      onBack={leave}
      right={
        <Pressable onPress={() => onHistory(p)} accessibilityRole="button" hitSlop={8}>
          <Text style={{ color: '#fff', fontSize: 14, fontWeight: '600' }}>History</Text>
        </Pressable>
      }
      footer={
        locked ? undefined : (
          <SafeAreaView edges={['bottom']} style={styles.footer}>
            <Notice text={message.text} kind={message.kind} />
            {warnings && warnings.length > 0 && (
              <View style={styles.warning} accessibilityRole="alert">
                {warnings.map((w) => (
                  <Text key={w} style={styles.warningText}>
                    {w}
                  </Text>
                ))}
                <Text style={text.muted}>Change the prescription, or finish if this is what you intend.</Text>
              </View>
            )}
            <View style={{ flexDirection: 'row', gap: 8 }}>
              <View style={{ flex: 1 }}>
                <Button label={finished ? 'Save changes' : 'Save'} kind="ghost" onPress={() => save(false)} busy={busy === 'save'} disabled={busy !== null || !dirty} />
              </View>
              {!finished && (
                <View style={{ flex: 1.3 }}>
                  <Button label={warnings?.length ? 'Finish anyway' : 'Finish visit'} onPress={() => save(true)} busy={busy === 'finish'} disabled={busy !== null} />
                </View>
              )}
            </View>
          </SafeAreaView>
        )
      }
    >
      {locked && <Notice text={message.text} kind={message.kind} />}
      <Text style={text.muted}>
        {niceDate(visit.appointment.scheduled_at)} · {niceTime(visit.appointment.scheduled_at)}
        {visit.appointment.notes ? ` · ${visit.appointment.notes}` : ''}
      </Text>
      {locked && <Notice kind="info" text={`This visit was finished on ${niceDate(visit.consultation!.completed_at!)} and is locked. To correct or add something, write an amendment at the bottom.`} />}
      {finished && !locked && <Notice kind="info" text="This visit is finished. It can still be changed today; from tomorrow corrections go in as amendments." />}

      {!locked && library && library.templates.length > 0 && (
        <Card>
          <Button label={templates ? 'Hide my templates' : 'Apply one of my templates'} kind="ghost" small onPress={() => setTemplates(!templates)} />
          {templates && (
            <Chips>
              {library.templates.map((t) => (
                <Chip
                  key={t.id}
                  label={t.name}
                  onPress={() => {
                    // Only the parts the template has are replaced; anything else already typed stays.
                    setForm((f) => ({ ...f, ...Object.fromEntries(TEMPLATE_KEYS.filter((key) => t.content[key]).map((key) => [key, t.content[key] as string])) }))
                    if (t.content.medicines?.length) setMedicines((rows) => [...rows, ...t.content.medicines!.filter((m) => m.medicine).map((m) => ({ medicine: '', dose: null, frequency: null, timing: null, duration: null, instructions: null, ...m }) as RxLine)])
                    setTemplates(false)
                    setMessage({ text: `Template “${t.name}” applied. Check it before finishing the visit.`, kind: 'info' })
                  }}
                />
              ))}
            </Chips>
          )}
        </Card>
      )}

      <Card style={form.allergies ? { borderColor: colors.danger } : undefined}>
        <Field label="Allergies" value={form.allergies ?? ''} onChangeText={set('allergies')} editable={!locked} placeholder="None known" style={form.allergies ? { color: colors.danger, fontWeight: '700' } : undefined} />
      </Card>

      <Card>
        <Section title="Vitals" />
        <View style={styles.grid}>
          {VITALS.map(([key, label]) => (
            <View key={key} style={styles.half}>
              <Field label={label} value={form[key] ?? ''} onChangeText={set(key)} keyboardType="decimal-pad" editable={!locked} maxLength={6} />
            </View>
          ))}
        </View>
      </Card>

      <Card>
        <Section title="Complaints" />
        {note('complaints', 'What the patient says', 'Fever for 3 days, cough')}
        {!locked && phrases('complaint').length > 0 && (
          <Chips>
            {phrases('complaint').map((ph) => (
              <Chip key={ph.id} label={ph.text} onPress={() => addPhrase('complaints', ph.text)} />
            ))}
          </Chips>
        )}
      </Card>

      {!more ? (
        <Button label="More: history, examination, plan, referral" kind="ghost" small onPress={() => setMore(true)} />
      ) : (
        <>
          <Card>
            <Section title="History" />
            {note('history', 'History of the illness')}
            {note('past_medication', 'Medicines taken so far')}
          </Card>
          <Card>
            <Section title="Examination" />
            {SIGNS.map((sign) => (
              <View key={sign} style={styles.sign}>
                <Text style={[text.body, { flex: 1, textTransform: 'capitalize' }]}>{sign}</Text>
                <Chip label="Present" on={exam[sign] === 'present'} tone="danger" onPress={() => !locked && setExam((e) => ({ ...e, [sign]: e[sign] === 'present' ? '' : 'present' }))} />
                <Chip label="Absent" on={exam[sign] === 'absent'} onPress={() => !locked && setExam((e) => ({ ...e, [sign]: e[sign] === 'absent' ? '' : 'absent' }))} />
              </View>
            ))}
            {note('general_exam_notes', 'General examination notes')}
            {note('findings', 'Systemic examination')}
          </Card>
          <Card>
            <Section title="Results the patient brought" />
            {results.map((r, i) => (
              <Row key={i} onRemove={locked ? undefined : () => setResults((rows) => rows.filter((_, x) => x !== i))}>
                <View style={{ flexDirection: 'row', gap: 8 }}>
                  <View style={{ flex: 1.3 }}>
                    <Field label="Test" value={r.name} editable={!locked} onChangeText={(v) => setResults((rows) => rows.map((row, x) => (x === i ? { ...row, name: v } : row)))} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Field label="Result" value={r.result} editable={!locked} onChangeText={(v) => setResults((rows) => rows.map((row, x) => (x === i ? { ...row, result: v } : row)))} />
                  </View>
                </View>
              </Row>
            ))}
            {!locked && <Button label="+ Add a result" kind="ghost" small onPress={() => setResults((rows) => [...rows, { name: '', result: '' }])} />}
          </Card>
          <Card>
            <Section title="Plan and referral" />
            {note('assessment_plan', 'Assessment and plan')}
            {note('refer_to', 'Refer to', 'Doctor or hospital, and why')}
            {note('quick_notes', 'My private notes', 'Not printed, not shown to the patient')}
          </Card>
        </>
      )}

      <Card>
        <Section title="Diagnosis" />
        {note('diagnosis', 'Diagnosis', 'Type 2 diabetes')}
        {!locked && phrases('diagnosis').length > 0 && (
          <Chips>
            {phrases('diagnosis').map((ph) => (
              <Chip key={ph.id} label={ph.text} onPress={() => addPhrase('diagnosis', ph.text)} />
            ))}
          </Chips>
        )}
        <IcdCodes codes={codes} onChange={setCodes} readOnly={locked} />
      </Card>

      <Card>
        <Section title="Tests" />
        {note('tests_advised', 'Tests advised', 'HbA1c, lipid profile')}
        {!locked && <OrderLabTests patientId={p.id} appointmentId={visit.appointment.id} doctorName={visit.doctor?.name ?? ''} />}
      </Card>

      <Medicines rows={medicines} onChange={(rows) => { setMedicines(rows); setWarnings(null) }} library={library} readOnly={locked} />

      <Card>
        <Section title="Advice and next visit" />
        {note('advice', 'Advice', 'Diet, rest, when to come back sooner')}
        {!locked && phrases('advice').length > 0 && (
          <Chips>
            {phrases('advice').map((ph) => (
              <Chip key={ph.id} label={ph.text} onPress={() => addPhrase('advice', ph.text)} />
            ))}
          </Chips>
        )}
        <Text style={text.muted}>Next visit{form.follow_up_on ? `: ${niceDate(form.follow_up_on)}` : ': not set'}</Text>
        {!locked && (
          <Chips>
            {FOLLOW_UPS.map(([label, days]) => (
              <Chip key={label} label={label} on={form.follow_up_on === addDays(todayIso(), days)} onPress={() => set('follow_up_on')(addDays(todayIso(), days))} />
            ))}
            {form.follow_up_on ? <Chip label="Clear" onPress={() => set('follow_up_on')('')} /> : null}
          </Chips>
        )}
      </Card>

      {(finished || (visit.consultation?.amendments?.length ?? 0) > 0) && (
        <Card>
          <Section title="Amendments" />
          {visit.consultation?.amendments?.length ? null : <Text style={text.muted}>None.</Text>}
          {visit.consultation?.amendments?.map((a) => (
            <View key={a.id} style={styles.amendment}>
              <Text style={text.muted}>
                {niceDate(a.created_at)} {niceTime(a.created_at)}
                {a.author ? ` · ${a.author.name}` : ''}
              </Text>
              <Text style={text.body}>{a.body}</Text>
            </View>
          ))}
          {locked && (
            <>
              <Field label="Add an amendment" value={amendment} onChangeText={setAmendment} multiline placeholder="What needs correcting or adding" style={tall} />
              <Button label="Add amendment" onPress={amend} busy={busy === 'amend'} disabled={amendment.trim().length < 2} />
            </>
          )}
        </Card>
      )}
    </Screen>
  )
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  half: { width: '48%', flexGrow: 1 },
  sign: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  footer: { backgroundColor: colors.surface, padding: space.md, gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border },
  warning: { backgroundColor: colors.dangerSoft, borderRadius: 10, padding: space.md, gap: 4 },
  warningText: { color: colors.danger, fontSize: 14, fontWeight: '700' },
  amendment: { borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm, gap: 2 },
})
