import { useState, type ReactNode } from 'react'
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import { addDays, isoDate, niceDate, rupees, shortDay, todayIso } from '../../api'
import { colors, space } from '../../theme'
import { text } from '../../ui'

/** Whole rupees: on a phone the paise only get in the way of the big numbers. */
export const whole = (amount: number) => rupees(Math.round(Number(amount) || 0))

/** A row of small boxes, each one number with its name. They wrap two to a line. */
export function Tiles({ children }: { children: ReactNode }) {
  return <View style={styles.tiles}>{children}</View>
}

export function Tile({ label, value, note, tone }: { label: string; value: string; note?: string; tone?: 'ok' | 'danger' }) {
  return (
    <View style={styles.tile}>
      <Text style={styles.tileLabel}>{label}</Text>
      <Text style={[styles.tileValue, tone === 'ok' && { color: colors.ok }, tone === 'danger' && { color: colors.danger }]} numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </Text>
      {note ? <Text style={styles.tileNote}>{note}</Text> : null}
    </View>
  )
}

/** One line of a small table: a name on the left, with a second line under it if needed, and figures on the right. */
export function Line({ label, sub, value, extra, tone, onPress }: { label: string; sub?: string; value?: string; extra?: string; tone?: 'ok' | 'danger' | 'muted'; onPress?: () => void }) {
  const body = (
    <View style={styles.line}>
      <View style={{ flex: 1 }}>
        <Text style={text.body}>{label}</Text>
        {sub ? <Text style={text.muted}>{sub}</Text> : null}
      </View>
      <View style={{ alignItems: 'flex-end' }}>
        {value ? <Text style={[text.strong, tone === 'ok' && { color: colors.ok }, tone === 'danger' && { color: colors.danger }, tone === 'muted' && { color: colors.muted, fontWeight: '400' }]}>{value}</Text> : null}
        {extra ? <Text style={text.muted}>{extra}</Text> : null}
      </View>
    </View>
  )
  return onPress ? (
    <Pressable onPress={onPress} accessibilityRole="button">
      {body}
    </Pressable>
  ) : (
    body
  )
}

/** How full something is: so many of so many. */
export function Meter({ label, used, of }: { label: string; used: number; of: number }) {
  const share = of > 0 ? Math.min(1, used / of) : 0
  return (
    <View style={{ gap: 4 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
        <Text style={text.body}>{label}</Text>
        <Text style={text.strong}>
          {used} <Text style={text.muted}>of {of}</Text>
        </Text>
      </View>
      <View style={styles.track}>
        <View style={[styles.fill, { width: `${share * 100}%` }]} />
      </View>
    </View>
  )
}

export type Period = { key: string; label: string; from: string; to: string }

/** The stretches of time an owner asks about, worked out from today. */
export const periods = (): Period[] => {
  const today = todayIso()
  const now = new Date()
  const monthStart = isoDate(new Date(now.getFullYear(), now.getMonth(), 1))
  const lastStart = isoDate(new Date(now.getFullYear(), now.getMonth() - 1, 1))
  const lastEnd = isoDate(new Date(now.getFullYear(), now.getMonth(), 0))
  return [
    { key: 'today', label: 'Today', from: today, to: today },
    { key: 'yesterday', label: 'Yesterday', from: addDays(today, -1), to: addDays(today, -1) },
    { key: 'week', label: '7 days', from: addDays(today, -6), to: today },
    { key: 'month', label: 'This month', from: monthStart, to: today },
    { key: 'last', label: 'Last month', from: lastStart, to: lastEnd },
  ]
}

/** The period chips. They scroll sideways so all five fit on a narrow phone. */
export function PeriodPicker({ options, value, onChange }: { options: Period[]; value: string; onChange: (key: string) => void }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: space.sm }}>
      {options.map((o) => (
        <Pressable key={o.key} onPress={() => onChange(o.key)} accessibilityRole="radio" accessibilityState={{ selected: value === o.key }} style={[styles.chip, value === o.key && styles.chipOn]}>
          <Text style={[styles.chipText, value === o.key && { color: '#fff' }]}>{o.label}</Text>
        </Pressable>
      ))}
    </ScrollView>
  )
}

/**
 * One bar a day for the money collected. Touching a bar shows that day's figures underneath,
 * so no number has to be squeezed onto the chart itself.
 */
export function DayBars({ days }: { days: { day: string; billed: number; collected: number; paid_out: number }[] }) {
  const [picked, setPicked] = useState(days.length - 1)
  const top = Math.max(1, ...days.map((d) => d.collected))
  const shown = days[Math.min(picked, days.length - 1)]
  if (!shown) return null
  return (
    <View style={{ gap: space.sm }}>
      <View style={styles.bars} accessibilityRole="adjustable" accessibilityLabel="Money collected each day">
        {days.map((d, i) => {
          const { day, weekday } = shortDay(d.day)
          return (
            <Pressable key={d.day} onPress={() => setPicked(i)} style={styles.barSlot} accessibilityRole="button" accessibilityLabel={`${niceDate(d.day)}: collected ${whole(d.collected)}`}>
              <View style={styles.barArea}>
                <View style={[styles.bar, { height: `${Math.max(d.collected > 0 ? 3 : 0, (d.collected / top) * 100)}%` }, i === picked && { backgroundColor: colors.brandDark }]} />
              </View>
              <Text style={[styles.barDay, i === picked && { color: colors.brandDark, fontWeight: '800' }]}>{day}</Text>
              <Text style={styles.barWeek}>{weekday.charAt(0)}</Text>
            </Pressable>
          )
        })}
      </View>
      <View style={styles.picked}>
        <Text style={text.strong}>{niceDate(shown.day)}</Text>
        <Text style={text.body}>
          Collected <Text style={{ fontWeight: '800' }}>{whole(shown.collected)}</Text> · Billed {whole(shown.billed)}
          {shown.paid_out > 0 ? ` · Paid out ${whole(shown.paid_out)}` : ''}
        </Text>
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  tiles: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  tile: { flexGrow: 1, flexBasis: '45%', backgroundColor: colors.surface, borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: space.md },
  tileLabel: { fontSize: 12, color: colors.muted },
  tileValue: { fontSize: 22, fontWeight: '800', color: colors.brandDark },
  tileNote: { fontSize: 12, color: colors.muted },
  line: { flexDirection: 'row', alignItems: 'center', gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm },
  track: { height: 8, borderRadius: 4, backgroundColor: colors.border, overflow: 'hidden' },
  fill: { height: 8, borderRadius: 4, backgroundColor: colors.brand },
  chip: { borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: space.md, minHeight: 38, justifyContent: 'center' },
  chipOn: { backgroundColor: colors.brand, borderColor: colors.brand },
  chipText: { fontSize: 13, color: colors.text, fontWeight: '600' },
  bars: { flexDirection: 'row', alignItems: 'flex-end', gap: 3 },
  barSlot: { flex: 1, alignItems: 'center' },
  barArea: { height: 110, width: '100%', justifyContent: 'flex-end', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: colors.border },
  bar: { width: '70%', maxWidth: 18, backgroundColor: colors.brand, borderTopLeftRadius: 4, borderTopRightRadius: 4 },
  barDay: { fontSize: 10, color: colors.muted, marginTop: 3 },
  barWeek: { fontSize: 9, color: colors.muted },
  picked: { backgroundColor: colors.bg, borderRadius: 8, padding: space.sm },
})
