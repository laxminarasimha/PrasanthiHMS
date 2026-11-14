import { useState } from 'react'
import { Pressable, Text, View } from 'react-native'
import { api, ApiError, type Account } from '../api'
import { Button, Card, Choice, Field, Notice, Screen, text } from '../ui'

type Props = { onBack: () => void; onSignedIn: (token: string, account: Account) => void }

/** A patient signs in, or makes their account the first time. */
export function PatientAuth({ onBack, onSignedIn }: Props) {
  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [form, setForm] = useState({ name: '', mobile: '', gender: '' as '' | 'male' | 'female' | 'other', day: '', month: '', year: '', password: '', again: '' })
  const [fields, setFields] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const submit = async () => {
    setError('')
    setFields({})
    const mobile = form.mobile.replace(/\D/g, '')

    if (mode === 'register') {
      const problems: Record<string, string> = {}
      if (form.name.trim().length < 2) problems.name = 'Enter your full name.'
      if (!/^[6-9]\d{9}$/.test(mobile)) problems.mobile = 'Enter a 10-digit mobile number.'
      if (!form.gender) problems.gender = 'Choose one.'
      const [d, m, y] = [Number(form.day), Number(form.month), Number(form.year)]
      const born = new Date(y, m - 1, d)
      if (!d || !m || y < 1900 || born.getDate() !== d || born.getMonth() !== m - 1 || born > new Date()) problems.date_of_birth = 'Enter your date of birth as day, month and year.'
      if (form.password.length < 8 || !/[A-Za-z]/.test(form.password) || !/\d/.test(form.password)) problems.password = 'Use at least 8 characters with both letters and numbers.'
      else if (form.password !== form.again) problems.again = 'The two passwords are not the same.'
      if (Object.keys(problems).length) return setFields(problems)
    }

    setBusy(true)
    try {
      const res =
        mode === 'login'
          ? await api.post<{ token: string; account: Account }>('/app/login', { mobile, password: form.password })
          : await api.post<{ token: string; account: Account }>('/app/register', {
              name: form.name.trim(), mobile, gender: form.gender,
              date_of_birth: `${form.year}-${form.month.padStart(2, '0')}-${form.day.padStart(2, '0')}`,
              password: form.password, password_confirmation: form.again,
            })
      onSignedIn(res.token, res.account)
    } catch (e) {
      const err = e as ApiError
      setFields(err.fields ?? {})
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen title={mode === 'login' ? 'Patient sign in' : 'Create your account'} onBack={onBack}>
      <Card>
        {mode === 'register' && <Field label="Full name" value={form.name} onChangeText={set('name')} error={fields.name} autoCapitalize="words" autoComplete="name" />}
        <Field label="Mobile number" value={form.mobile} onChangeText={set('mobile')} error={fields.mobile} keyboardType="phone-pad" maxLength={14} autoComplete="tel" placeholder="10-digit number" />
        {mode === 'register' && (
          <>
            <Choice label="Gender" value={form.gender} onChange={set('gender')} options={[{ value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }]} />
            {fields.gender ? <Text style={{ color: '#b3261e', fontSize: 13 }}>{fields.gender}</Text> : null}
            <View style={{ gap: 6 }}>
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
              {fields.date_of_birth ? <Text style={{ color: '#b3261e', fontSize: 13 }}>{fields.date_of_birth}</Text> : null}
            </View>
          </>
        )}
        <Field label="Password" value={form.password} onChangeText={set('password')} error={fields.password} secureTextEntry autoCapitalize="none" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} hint={mode === 'register' ? 'At least 8 characters, with letters and numbers.' : undefined} />
        {mode === 'register' && <Field label="Password again" value={form.again} onChangeText={set('again')} error={fields.again} secureTextEntry autoCapitalize="none" autoComplete="new-password" />}
        <Notice text={Object.keys(fields).length ? '' : error} />
        <Button label={mode === 'login' ? 'Sign in' : 'Create account'} onPress={submit} busy={busy} />
      </Card>

      <Pressable onPress={() => { setMode(mode === 'login' ? 'register' : 'login'); setFields({}); setError('') }} accessibilityRole="button" style={{ alignItems: 'center', padding: 12 }}>
        <Text style={text.link}>{mode === 'login' ? 'New here? Create an account' : 'Already have an account? Sign in'}</Text>
      </Pressable>
      {mode === 'login' && <Text style={[text.muted, { textAlign: 'center' }]}>Forgot your password? Please call the hospital and we will help you.</Text>}
    </Screen>
  )
}
