import { useState } from 'react'
import { Alert, Platform, Text, View } from 'react-native'
import { api, ApiError, type Person } from '../api'
import { Button, Card, Choice, Field, Notice, text } from '../ui'

const RELATIONS = ['Spouse', 'Father', 'Mother', 'Son', 'Daughter', 'Brother', 'Sister', 'Grandparent', 'Other'] as const

/** The family members this login books for and keeps records of. */
export function Family({ people, onChange }: { people: Person[]; onChange: (people: Person[]) => void }) {
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState({ name: '', gender: '' as '' | 'male' | 'female' | 'other', relation: '' as '' | (typeof RELATIONS)[number], day: '', month: '', year: '' })
  const [message, setMessage] = useState<{ text: string; kind: 'ok' | 'error' }>({ text: '', kind: 'ok' })
  const [busy, setBusy] = useState(false)
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const save = async () => {
    const [d, m, y] = [Number(form.day), Number(form.month), Number(form.year)]
    const born = new Date(y, m - 1, d)
    if (form.name.trim().length < 2) return setMessage({ text: 'Enter their full name.', kind: 'error' })
    if (!form.relation) return setMessage({ text: 'Choose how they are related to you.', kind: 'error' })
    if (!form.gender) return setMessage({ text: 'Choose their gender.', kind: 'error' })
    if (!d || !m || y < 1900 || born.getDate() !== d || born.getMonth() !== m - 1 || born > new Date()) return setMessage({ text: 'Enter their date of birth as day, month and year.', kind: 'error' })
    setBusy(true)
    try {
      const res = await api.post<{ people: Person[] }>('/app/family', { name: form.name.trim(), gender: form.gender, relation: form.relation, date_of_birth: `${form.year}-${form.month.padStart(2, '0')}-${form.day.padStart(2, '0')}` })
      onChange(res.people)
      setAdding(false)
      setForm({ name: '', gender: '', relation: '', day: '', month: '', year: '' })
      setMessage({ text: `${form.name.trim()} is added. You can now book appointments for them.`, kind: 'ok' })
    } catch (e) {
      setMessage({ text: (e as ApiError).message, kind: 'error' })
    } finally {
      setBusy(false)
    }
  }

  const remove = (person: Person) => {
    const go = async () => {
      try {
        const res = await api.delete<{ people: Person[] }>(`/app/family/${person.patient_id}`)
        onChange(res.people)
        setMessage({ text: `${person.name} is taken off your account. Their hospital record is kept.`, kind: 'ok' })
      } catch (e) {
        setMessage({ text: (e as ApiError).message, kind: 'error' })
      }
    }
    const question = `${person.name} will no longer show in this app. Their hospital record is kept.`
    if (Platform.OS === 'web') {
      if (globalThis.confirm?.(`Remove from your account?\n\n${question}`)) void go()
      return
    }
    Alert.alert('Remove from your account?', question, [{ text: 'No', style: 'cancel' }, { text: 'Yes, remove', style: 'destructive', onPress: () => void go() }])
  }

  return (
    <Card>
      <Text style={text.h2}>My family</Text>
      <Text style={text.muted}>Add parents, children or your spouse to book and see records for them from this phone.</Text>
      <Notice text={message.text} kind={message.kind} />
      {people.map((p) => (
        <View key={p.patient_id} style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 4 }}>
          <View style={{ flex: 1 }}>
            <Text style={text.strong}>{p.name}</Text>
            <Text style={text.muted}>
              {p.relation === 'Self' ? 'You' : p.relation} · {p.age_label} · {p.uhid}
            </Text>
          </View>
          {p.relation !== 'Self' && <Button label="Remove" kind="danger" small onPress={() => remove(p)} />}
        </View>
      ))}
      {adding ? (
        <>
          <Field label="Full name" value={form.name} onChangeText={set('name')} autoCapitalize="words" />
          <Choice label="They are my" value={form.relation} onChange={set('relation')} options={RELATIONS.map((r) => ({ value: r, label: r }))} />
          <Choice label="Gender" value={form.gender} onChange={set('gender')} options={[{ value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }]} />
          <Text style={{ fontSize: 13, fontWeight: '700', color: '#6f6777' }}>Date of birth</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}>
              <Field label="Day" value={form.day} onChangeText={set('day')} keyboardType="number-pad" maxLength={2} placeholder="DD" />
            </View>
            <View style={{ flex: 1 }}>
              <Field label="Month" value={form.month} onChangeText={set('month')} keyboardType="number-pad" maxLength={2} placeholder="MM" />
            </View>
            <View style={{ flex: 1.4 }}>
              <Field label="Year" value={form.year} onChangeText={set('year')} keyboardType="number-pad" maxLength={4} placeholder="YYYY" />
            </View>
          </View>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}>
              <Button label="Cancel" kind="ghost" onPress={() => { setAdding(false); setMessage({ text: '', kind: 'ok' }) }} />
            </View>
            <View style={{ flex: 1 }}>
              <Button label="Add" onPress={save} busy={busy} />
            </View>
          </View>
        </>
      ) : (
        <Button label="+ Add a family member" kind="ghost" onPress={() => { setAdding(true); setMessage({ text: '', kind: 'ok' }) }} />
      )}
    </Card>
  )
}
