import { StatusBar } from 'expo-status-bar'
import { useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context'
import { api, setToken, whenSignedOut, type Account, type StaffUser } from './src/api'
import { DoctorHome } from './src/doctor/Home'
import { DoctorLogin } from './src/doctor/Login'
import { PatientAuth } from './src/patient/Auth'
import { PatientHome } from './src/patient/Home'
import { loadSession, saveSession } from './src/storage'
import { colors, space } from './src/theme'
import { Button, Loading } from './src/ui'

type Session = { kind: 'patient'; account: Account } | { kind: 'doctor'; user: StaffUser } | null
type Entry = 'welcome' | 'patient' | 'doctor'

export default function App() {
  const [ready, setReady] = useState(false)
  const [session, setSession] = useState<Session>(null)
  const [entry, setEntry] = useState<Entry>('welcome')

  const signOutHere = () => {
    setToken(null)
    setSession(null)
    setEntry('welcome')
    void saveSession(null)
  }

  // Pick up where the person left off, if their sign-in is still good.
  useEffect(() => {
    whenSignedOut(signOutHere)
    loadSession()
      .then(async (saved) => {
        if (!saved) return
        setToken(saved.token)
        if (saved.kind === 'patient') setSession({ kind: 'patient', account: await api.get<Account>('/app/me') })
        else setSession({ kind: 'doctor', user: await api.get<StaffUser>('/me') })
      })
      .catch(() => setToken(null))
      .finally(() => setReady(true))
  }, [])

  const signOut = async () => {
    await api.post(session?.kind === 'patient' ? '/app/logout' : '/logout').catch(() => undefined)
    signOutHere()
  }

  let screen
  if (!ready) {
    screen = (
      <View style={styles.center}>
        <Loading />
      </View>
    )
  } else if (session?.kind === 'patient') {
    screen = <PatientHome account={session.account} onSignOut={signOut} />
  } else if (session?.kind === 'doctor') {
    screen = <DoctorHome user={session.user} onSignOut={signOut} />
  } else if (entry === 'patient') {
    screen = (
      <PatientAuth
        onBack={() => setEntry('welcome')}
        onSignedIn={(token, account) => {
          setToken(token)
          void saveSession({ kind: 'patient', token })
          setSession({ kind: 'patient', account })
        }}
      />
    )
  } else if (entry === 'doctor') {
    screen = (
      <DoctorLogin
        onBack={() => setEntry('welcome')}
        onSignedIn={(token, user) => {
          setToken(token)
          void saveSession({ kind: 'doctor', token })
          setSession({ kind: 'doctor', user })
        }}
      />
    )
  } else {
    screen = (
      <SafeAreaView style={styles.welcome}>
        <View style={styles.hero}>
          <Text style={styles.name}>Prasanthi</Text>
          <Text style={styles.sub}>Hospitals</Text>
          <View style={styles.rule} />
          <Text style={styles.line}>Book your appointment and keep your monthly medicines ready.</Text>
        </View>
        <View style={styles.actions}>
          <Button label="I am a patient" onPress={() => setEntry('patient')} />
          <Button label="I am a doctor" kind="ghost" onPress={() => setEntry('doctor')} />
        </View>
      </SafeAreaView>
    )
  }

  return (
    <SafeAreaProvider>
      <StatusBar style={session || entry !== 'welcome' ? 'light' : 'dark'} />
      {screen}
    </SafeAreaProvider>
  )
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg },
  welcome: { flex: 1, backgroundColor: colors.bg, padding: space.xl, justifyContent: 'space-between' },
  hero: { flex: 1, justifyContent: 'center' },
  name: { fontSize: 44, fontWeight: '800', color: colors.brand, letterSpacing: -0.5 },
  sub: { fontSize: 26, color: colors.brandDark, marginTop: -4 },
  rule: { width: 56, height: 4, backgroundColor: colors.accent, borderRadius: 2, marginVertical: space.lg },
  line: { fontSize: 17, color: colors.muted, lineHeight: 25, maxWidth: 320 },
  actions: { gap: space.md, paddingBottom: space.lg },
})
