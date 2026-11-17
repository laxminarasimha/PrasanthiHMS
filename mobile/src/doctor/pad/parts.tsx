import { type ReactNode } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { colors, space } from '../../theme'
import { text } from '../../ui'

/** A small rounded button: a suggestion to tap in, or a choice that can be on or off. */
export function Chip({ label, on, onPress, tone }: { label: string; on?: boolean; onPress: () => void; tone?: 'danger' }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="button" accessibilityState={{ selected: !!on }} style={[styles.chip, on && styles.chipOn, tone === 'danger' && on && { backgroundColor: colors.danger, borderColor: colors.danger }]}>
      <Text style={[styles.chipText, on && { color: '#fff' }]} numberOfLines={1}>
        {label}
      </Text>
    </Pressable>
  )
}

export function Chips({ children }: { children: ReactNode }) {
  return <View style={styles.chips}>{children}</View>
}

/** A heading for one part of the pad, with something to tap on the right if needed. */
export function Section({ title, right }: { title: string; right?: ReactNode }) {
  return (
    <View style={styles.section}>
      <Text style={[text.h2, { flex: 1 }]}>{title}</Text>
      {right}
    </View>
  )
}

/** A line with words on the left and a small "remove" on the right. */
export function Row({ children, onRemove }: { children: ReactNode; onRemove?: () => void }) {
  return (
    <View style={styles.row}>
      <View style={{ flex: 1 }}>{children}</View>
      {onRemove && (
        <Pressable onPress={onRemove} hitSlop={10} accessibilityRole="button" accessibilityLabel="Remove">
          <Text style={styles.remove}>Remove</Text>
        </Pressable>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: { borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 12, minHeight: 34, justifyContent: 'center', maxWidth: '100%' },
  chipOn: { backgroundColor: colors.brand, borderColor: colors.brand },
  chipText: { fontSize: 14, color: colors.text, fontWeight: '600' },
  section: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.md, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm },
  remove: { color: colors.danger, fontSize: 13, fontWeight: '700' },
})
