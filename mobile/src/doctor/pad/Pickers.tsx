import { useEffect, useState } from 'react'
import { Pressable, Text, View } from 'react-native'
import { api, ApiError, rupees, type LabTestName } from '../../api'
import { colors } from '../../theme'
import { Button, Field, Notice, text } from '../../ui'
import { Chip, Chips, Row } from './parts'

type Code = { code: string; name: string }

/** Coded diagnoses from the ICD-10 list: type a few letters or a code, tap to add. */
export function IcdCodes({ codes, onChange, readOnly }: { codes: Code[]; onChange: (codes: Code[]) => void; readOnly?: boolean }) {
  const [term, setTerm] = useState('')
  const [found, setFound] = useState<Code[]>([])

  useEffect(() => {
    const q = term.trim()
    if (q.length < 2) {
      setFound([])
      return
    }
    let current = true
    const timer = setTimeout(() => {
      api.get<Code[]>('/icd-codes', { q }).then((list) => current && setFound(list.slice(0, 12))).catch(() => undefined)
    }, 300)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [term])

  const chosen = (code: string) => codes.some((c) => c.code === code)

  return (
    <View style={{ gap: 8 }}>
      {codes.map((c) => (
        <Row key={c.code} onRemove={readOnly ? undefined : () => onChange(codes.filter((x) => x.code !== c.code))}>
          <Text style={text.body}>
            <Text style={{ fontWeight: '700' }}>{c.code}</Text> {c.name}
          </Text>
        </Row>
      ))}
      {!readOnly && (
        <>
          <Field label="Add a diagnosis code (ICD-10)" value={term} onChangeText={setTerm} placeholder="diabetes, or E11" autoCapitalize="none" autoCorrect={false} />
          {found
            .filter((c) => !chosen(c.code))
            .map((c) => (
              <Pressable
                key={c.code}
                onPress={() => {
                  onChange([...codes, c])
                  setTerm('')
                }}
                accessibilityRole="button"
                style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.border }}
              >
                <Text style={text.body}>
                  <Text style={{ fontWeight: '700', color: colors.brand }}>{c.code}</Text> {c.name}
                </Text>
              </Pressable>
            ))}
        </>
      )}
    </View>
  )
}

/** Lab tests to send to the hospital lab for this visit. The patient pays at the lab or the front desk. */
export function OrderLabTests({ patientId, appointmentId, doctorName }: { patientId: number; appointmentId: number; doctorName: string }) {
  const [open, setOpen] = useState(false)
  const [tests, setTests] = useState<LabTestName[] | null>(null)
  const [term, setTerm] = useState('')
  const [chosen, setChosen] = useState<number[]>([])
  const [message, setMessage] = useState<{ text: string; kind: 'ok' | 'error' }>({ text: '', kind: 'ok' })
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (open && !tests) api.get<LabTestName[]>('/lab/tests', { brief: '1' }).then(setTests).catch((e: ApiError) => setMessage({ text: e.message, kind: 'error' }))
  }, [open, tests])

  const send = async () => {
    setBusy(true)
    try {
      const order = await api.post<{ order_no: string }>('/lab/orders', { patient_id: patientId, appointment_id: appointmentId, referred_by: doctorName, test_ids: chosen })
      setMessage({ text: `Lab order ${order.order_no} is sent to the lab. The patient pays at the lab or the front desk.`, kind: 'ok' })
      setChosen([])
      setOpen(false)
    } catch (e) {
      setMessage({ text: (e as ApiError).message, kind: 'error' })
    } finally {
      setBusy(false)
    }
  }

  const q = term.trim().toLowerCase()
  // What is ticked stays on screen whatever is typed, so it cannot be sent unseen.
  const shown = (tests ?? []).filter((t) => chosen.includes(t.id) || (q ? t.name.toLowerCase().includes(q) : true)).slice(0, 30)
  const total = (tests ?? []).filter((t) => chosen.includes(t.id)).reduce((sum, t) => sum + Number(t.price), 0)

  return (
    <View style={{ gap: 8 }}>
      <Notice text={message.text} kind={message.kind} />
      {!open ? (
        <Button label="Order tests from the hospital lab" kind="ghost" small onPress={() => { setOpen(true); setMessage({ text: '', kind: 'ok' }) }} />
      ) : (
        <>
          <Field label="Find a test" value={term} onChangeText={setTerm} placeholder="CBP, sugar, thyroid" autoCapitalize="none" autoCorrect={false} />
          <Chips>
            {shown.map((t) => (
              <Chip key={t.id} label={`${t.name} · ${rupees(Number(t.price))}`} on={chosen.includes(t.id)} onPress={() => setChosen((ids) => (ids.includes(t.id) ? ids.filter((id) => id !== t.id) : [...ids, t.id]))} />
            ))}
          </Chips>
          {tests && shown.length === 0 && <Text style={text.muted}>No test matches that.</Text>}
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}>
              <Button label="Cancel" kind="ghost" onPress={() => { setOpen(false); setChosen([]) }} />
            </View>
            <View style={{ flex: 1.4 }}>
              <Button label={chosen.length ? `Send ${chosen.length} to the lab · ${rupees(total)}` : 'Tick the tests'} onPress={send} busy={busy} disabled={chosen.length === 0} />
            </View>
          </View>
        </>
      )}
    </View>
  )
}
