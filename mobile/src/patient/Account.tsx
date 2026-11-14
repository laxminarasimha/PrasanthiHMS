import { useEffect, useState } from 'react'
import { Linking, Text, View } from 'react-native'
import { api, ApiError, type Account, type Hospital } from '../api'
import { Button, Card, Field, Notice, text } from '../ui'

/** Who is signed in, how to reach the hospital, and changing the password. */
export function PatientAccount({ account, onSignOut }: { account: Account; onSignOut: () => void }) {
  const [hospital, setHospital] = useState<Hospital | null>(null)
  const [changing, setChanging] = useState(false)
  const [form, setForm] = useState({ current: '', next: '', again: '' })
  const [message, setMessage] = useState<{ text: string; kind: 'ok' | 'error' }>({ text: '', kind: 'ok' })
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get<Hospital>('/app/hospital').then(setHospital).catch(() => undefined)
  }, [])

  const save = async () => {
    if (form.next.length < 8 || !/[A-Za-z]/.test(form.next) || !/\d/.test(form.next)) return setMessage({ text: 'Use at least 8 characters with both letters and numbers.', kind: 'error' })
    if (form.next !== form.again) return setMessage({ text: 'The two new passwords are not the same.', kind: 'error' })
    setBusy(true)
    try {
      await api.post('/app/password', { current_password: form.current, password: form.next, password_confirmation: form.again })
      setForm({ current: '', next: '', again: '' })
      setChanging(false)
      setMessage({ text: 'Password changed. Any other phone signed in with your account has been signed out.', kind: 'ok' })
    } catch (e) {
      setMessage({ text: (e as ApiError).message, kind: 'error' })
    } finally {
      setBusy(false)
    }
  }

  const phones = [hospital?.phone, hospital?.phone_alt].filter((p): p is string => Boolean(p))

  return (
    <>
      <Card>
        <Text style={text.h2}>{account.name}</Text>
        <Text style={text.body}>Mobile: {account.mobile}</Text>
        <Text style={text.body}>Patient ID: {account.patient.uhid}</Text>
        <Text style={text.muted}>
          {account.patient.age_label} · {account.patient.gender.charAt(0).toUpperCase() + account.patient.gender.slice(1)} · {account.verified ? 'Verified by the hospital' : 'Not yet verified by the hospital'}
        </Text>
      </Card>

      <Notice text={message.text} kind={message.kind} />

      <Card>
        <Text style={text.h2}>Password</Text>
        {changing ? (
          <>
            <Field label="Current password" value={form.current} onChangeText={(v) => setForm({ ...form, current: v })} secureTextEntry autoCapitalize="none" autoComplete="current-password" />
            <Field label="New password" value={form.next} onChangeText={(v) => setForm({ ...form, next: v })} secureTextEntry autoCapitalize="none" autoComplete="new-password" hint="At least 8 characters, with letters and numbers." />
            <Field label="New password again" value={form.again} onChangeText={(v) => setForm({ ...form, again: v })} secureTextEntry autoCapitalize="none" autoComplete="new-password" />
            <View style={{ flexDirection: 'row', gap: 8 }}>
              <View style={{ flex: 1 }}>
                <Button label="Cancel" kind="ghost" onPress={() => { setChanging(false); setMessage({ text: '', kind: 'ok' }) }} />
              </View>
              <View style={{ flex: 1 }}>
                <Button label="Save" onPress={save} busy={busy} disabled={!form.current || !form.next || !form.again} />
              </View>
            </View>
          </>
        ) : (
          <Button label="Change password" kind="ghost" onPress={() => { setChanging(true); setMessage({ text: '', kind: 'ok' }) }} />
        )}
      </Card>

      {hospital && (
        <Card>
          <Text style={text.h2}>{hospital.name}</Text>
          {hospital.address ? <Text style={text.body}>{hospital.address}</Text> : null}
          {phones.map((p) => (
            <Button key={p} label={`Call ${p}`} kind="ghost" onPress={() => void Linking.openURL(`tel:${p.replace(/[^0-9+]/g, '')}`)} />
          ))}
          {phones.length === 0 && <Text style={text.muted}>The hospital's phone number is not set yet.</Text>}
        </Card>
      )}

      <Button label="Sign out" kind="ghost" onPress={onSignOut} />
    </>
  )
}
