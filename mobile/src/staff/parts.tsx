import { Linking, Pressable, StyleSheet, Text, View } from 'react-native'
import { addDays, niceDate, todayIso } from '../api'
import { colors, space } from '../theme'
import { Button, text } from '../ui'

/** The day being looked at, with a step back and forward and a way home to today. */
export function DayRow({ date, onChange, future = true }: { date: string; onChange: (date: string) => void; future?: boolean }) {
  const today = todayIso()
  return (
    <View style={styles.dayRow}>
      <Button label="‹" kind="ghost" small onPress={() => onChange(addDays(date, -1))} />
      <View style={{ flex: 1, alignItems: 'center' }}>
        <Text style={text.strong}>{date === today ? 'Today' : niceDate(date)}</Text>
        {date === today ? (
          <Text style={text.muted}>{niceDate(date)}</Text>
        ) : (
          <Pressable onPress={() => onChange(today)} accessibilityRole="button">
            <Text style={text.link}>Back to today</Text>
          </Pressable>
        )}
      </View>
      <Button label="›" kind="ghost" small disabled={!future && date >= today} onPress={() => onChange(addDays(date, 1))} />
    </View>
  )
}

/** A small label for where something stands: waiting (amber), done (green), to do (purple), stopped (red). */
export function Tag({ label, tone = 'todo' }: { label: string; tone?: 'todo' | 'waiting' | 'done' | 'stop' }) {
  const look = {
    todo: { bg: '#ece6f3', fg: colors.brandDark },
    waiting: { bg: colors.warnSoft, fg: '#8a4a08' },
    done: { bg: colors.okSoft, fg: colors.ok },
    stop: { bg: colors.dangerSoft, fg: colors.danger },
  }[tone]
  return (
    <View style={[styles.tag, { backgroundColor: look.bg }]}>
      <Text style={{ color: look.fg, fontSize: 12, fontWeight: '700' }}>{label}</Text>
    </View>
  )
}

/** A patient's mobile number that rings them when touched. */
export function Call({ mobile }: { mobile: string | null | undefined }) {
  if (!mobile) return null
  return (
    <Pressable onPress={() => void Linking.openURL(`tel:${mobile}`).catch(() => undefined)} accessibilityRole="link" accessibilityLabel={`Call ${mobile}`} hitSlop={6}>
      <Text style={text.link}>Call {mobile}</Text>
    </Pressable>
  )
}

/** A person's name as the hospital writes it, with the title in front when there is one. */
export const fullName = (p: { title?: string | null; name: string }) => `${p.title ? `${p.title} ` : ''}${p.name}`
export const ageSex = (p: { age_label: string; gender: string }) => `${p.age_label} / ${p.gender.charAt(0).toUpperCase()}`

/** Where a medicine is kept, as it is written on the web: "Rack A · Box 3". */
export const placeLabel = (rack?: string | null, box?: string | null) => (rack ? `Rack ${rack}${box ? ` · Box ${box}` : ''}` : '')

const styles = StyleSheet.create({
  dayRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  tag: { alignSelf: 'flex-start', borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
})
