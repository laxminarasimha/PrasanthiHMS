import { useState } from 'react'
import { Text } from 'react-native'
import { api, ApiError, setToken, type StaffUser } from '../api'
import { Button, Card, Field, Notice, Screen, text } from '../ui'

/** Doctors sign in with the same email and password they use on the hospital's web system. */
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
      if (!res.user.can_consult) {
        // Signed in, but this app has nothing for them yet: give the sign-in back.
        setToken(res.token)
        await api.post('/logout').catch(() => undefined)
        setToken(null)
        setError('This app is for doctors at the moment. Please use the web system for your work.')
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
    <Screen title="Doctor sign in" onBack={onBack}>
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
