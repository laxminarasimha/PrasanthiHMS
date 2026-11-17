import { Linking, Pressable, StyleSheet, Text, View } from 'react-native'
import { type Account, type HomeData, type HomeNotice } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Loading, text } from '../ui'

const TONE = {
  info: { bar: colors.brand, bg: colors.surface },
  ok: { bar: colors.ok, bg: colors.okSoft },
  warn: { bar: colors.accent, bg: colors.warnSoft },
  note: { bar: colors.muted, bg: colors.surface },
} as const

/** The first screen: the few things worth knowing today, and the two things people open the app to do. */
export function PatientToday({ account, home, onOpen, onBook }: { account: Account; home: HomeData | null; onOpen: (notice: HomeNotice) => void; onBook: () => void }) {
  const phone = account.hospital.phone
  return (
    <>
      <View>
        <Text style={text.h2}>Namaste, {account.name.split(' ')[0]}</Text>
        <Text style={text.muted}>Patient ID {account.patient.uhid}</Text>
      </View>

      {!home && <Loading />}
      {home?.notices.length === 0 && (
        <Card>
          <Text style={text.body}>Nothing needs your attention today.</Text>
          <Text style={text.muted}>Appointments, lab reports and medicines that are due will show here.</Text>
        </Card>
      )}
      {home?.notices.map((n, i) => (
        <Pressable key={i} onPress={() => onOpen(n)} accessibilityRole="button" style={[styles.notice, { backgroundColor: TONE[n.tone].bg, borderLeftColor: TONE[n.tone].bar }]}>
          <Text style={text.strong}>{n.title}</Text>
          <Text style={text.body}>{n.text}</Text>
        </Pressable>
      ))}

      <Button label="+ Book an appointment" onPress={onBook} />
      {phone ? <Button label={`Call the hospital: ${phone}`} kind="ghost" onPress={() => void Linking.openURL(`tel:${phone.replace(/[^0-9+]/g, '')}`)} /> : null}
    </>
  )
}

const styles = StyleSheet.create({
  notice: { borderRadius: 12, borderWidth: 1, borderColor: colors.border, borderLeftWidth: 5, padding: space.lg, gap: 4 },
})
