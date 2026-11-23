import { useState } from 'react'
import { Text } from 'react-native'
import { api, ApiError, setToken, type StaffUser } from '../api'
import { googleCredential, googleReady } from '../google'
import { Button, Card, Field, Notice, Screen, text } from '../ui'

/** Doctors, owners and the staff of the front desk, lab, pharmacy and wards sign in with the email and password they use on the hospital's web system. */
export function DoctorLogin({ onBack, onSignedIn }: { onBack: () => void; onSignedIn: (token: string, user: StaffUser) => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = () => finish(() => api.post<{ token: string; user: StaffUser }>('/login', { email: email.trim(), password }))
  const google = () =>
    finish(async () => {
      const credential = await googleCredential()
      return credential ? api.post<{ token: string; user: StaffUser }>('/login/google', { credential }) : null
    })

  const finish = async (signIn: () => Promise<{ token: string; user: StaffUser } | null>) => {
    setError('')
    setBusy(true)
    try {
      const res = await signIn()
      if (!res) return
      const u = res.user
      if (!u.can_consult && !u.can_view_reports && !u.can_front_desk && !u.can_run_lab && !u.can_run_pharmacy && !u.can_view_pharmacy && !u.can_ipd && !u.abilities?.['wf:self:view']) {
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
        {googleReady && <Button label="Sign in with Google" kind="ghost" onPress={google} disabled={busy} />}
      </Card>
      <Text style={[text.muted, { textAlign: 'center' }]}>
        Use the same email and password as the hospital web system.{googleReady ? ' Google works when your staff email is your Gmail, or once you join your Google account under My account on the web system.' : ''}
      </Text>
    </Screen>
  )
}
