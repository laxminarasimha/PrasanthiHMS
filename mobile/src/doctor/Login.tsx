import { useState } from 'react'
import { Text } from 'react-native'
import { api, ApiError, setToken, type StaffUser } from '../api'
import { googleCredential, googleReady } from '../google'
import { Button, Card, Field, Notice, Screen, text } from '../ui'

/**
 * Doctors, owners and all the hospital's staff sign in as on the web system: with their mobile number or email, and
 * their password or, for a login that opens only their own record, their PIN.
 */
export function DoctorLogin({ onBack, onSignedIn }: { onBack: () => void; onSignedIn: (token: string, user: StaffUser) => void }) {
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  // Someone signed in with a starting PIN the hospital handed out: they choose their own before anything opens.
  const [choosing, setChoosing] = useState<{ token: string; user: StaffUser } | null>(null)
  const [next, setNext] = useState('')
  const [again, setAgain] = useState('')

  const submit = () => finish(() => api.post<{ token: string; user: StaffUser }>('/login', { login: login.trim(), password }))
  const choose = async () => {
    if (!choosing) return
    setError('')
    setBusy(true)
    try {
      setToken(choosing.token)
      await api.post('/me/password', { current_password: password, password: next, password_confirmation: again })
      onSignedIn(choosing.token, await api.get<StaffUser>('/me'))
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy(false)
    }
  }
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
      if (u.must_change_secret) {
        setChoosing(res)
        return
      }
      onSignedIn(res.token, res.user)
    } catch (e) {
      setError((e as ApiError).message)
    } finally {
      setBusy(false)
    }
  }

  if (choosing) {
    const pin = choosing.user.sign_in_with === 'pin'
    const word = pin ? 'PIN' : 'password'
    return (
      <Screen title={`Choose your own ${word}`} onBack={onBack}>
        <Card>
          <Text style={text.muted}>
            Welcome, {choosing.user.name}. The {word} you were given was only to get you started. Choose your own now{pin ? ': six digits that you will remember and nobody else knows' : ''}.
          </Text>
          <Field label={`New ${word}`} value={next} onChangeText={(v) => setNext(pin ? v.replace(/\D/g, '').slice(0, 6) : v)} secureTextEntry keyboardType={pin ? 'number-pad' : 'default'} autoCapitalize="none" autoComplete="new-password" />
          <Field label={`New ${word} again`} value={again} onChangeText={(v) => setAgain(pin ? v.replace(/\D/g, '').slice(0, 6) : v)} secureTextEntry keyboardType={pin ? 'number-pad' : 'default'} autoCapitalize="none" autoComplete="new-password" />
          <Notice text={error} />
          <Button label={`Save my ${word}`} onPress={choose} busy={busy} disabled={!next || !again} />
        </Card>
      </Screen>
    )
  }

  return (
    <Screen title="Doctor and staff sign in" onBack={onBack}>
      <Card>
        <Field label="Mobile number or email" value={login} onChangeText={setLogin} keyboardType="email-address" autoCapitalize="none" autoComplete="username" />
        <Field label="Password or PIN" value={password} onChangeText={setPassword} secureTextEntry autoCapitalize="none" autoComplete="current-password" />
        <Notice text={error} />
        <Button label="Sign in" onPress={submit} busy={busy} disabled={!login.trim() || !password} />
        {googleReady && <Button label="Sign in with Google" kind="ghost" onPress={google} disabled={busy} />}
      </Card>
      <Text style={[text.muted, { textAlign: 'center' }]}>
        Use the same mobile number or email, and the same password or PIN, as the hospital web system.{googleReady ? ' Google works when your staff email is your Gmail, or once you join your Google account under My account on the web system.' : ''}
      </Text>
    </Screen>
  )
}
