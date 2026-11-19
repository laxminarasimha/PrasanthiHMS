import { useState } from 'react'
import { Text } from 'react-native'
import { api, ApiError, setToken, type StaffUser } from '../api'
import { Button, Card, Field, Notice, Screen, text } from '../ui'

/** Doctors, owners and the staff of the front desk, lab, pharmacy and wards sign in with the email and password they use on the hospital's web system. */
export function DoctorLogin({ onBack, onSignedIn }: { onBack: () => void; onSignedIn: (token: string, user: StaffUser) => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    setError('')
    setBusy(true)
    try {
      const res = await api.post<{ token: string; user: StaffUser }>('/login', { email: email.trim(), password })
      const u = res.user
      if (!u.can_consult && !u.can_view_reports && !u.can_front_desk && !u.can_run_lab && !u.can_run_pharmacy && !u.can_ipd) {
        // Signed in, but this app has nothing for them yet: give the sign-in back.
        setToken(res.token)
        await api.post('/logout').catch(() => undefined)
        setToken(null)
        setError('This app has nothing for your kind of work yet. Please use the web system.')
        return
      }
      onSignedIn(res.token, res.user)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen title="Doctor and staff sign in" onBack={onBack}>
      <Card>
        <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoComplete="email" />
        <Field label="Password" value={password} onChangeText={setPassword} secureTextEntry autoCapitalize="none" autoComplete="current-password" />
        <Notice text={error} />
        <Button label="Sign in" onPress={submit} busy={busy} disabled={!email.trim() || !password} />
      </Card>
      <Text style={[text.muted, { textAlign: 'center' }]}>Use the same email and password as the hospital web system.</Text>
    </Screen>
  )
}
