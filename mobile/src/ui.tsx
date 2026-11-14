import { type ReactNode } from 'react'
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View, type TextInputProps, type ViewStyle } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { colors, space } from './theme'

/** A full page with the hospital's purple bar on top and a scrolling body. */
export function Screen({ title, subtitle, onBack, right, children, footer, refresh }: { title: string; subtitle?: string; onBack?: () => void; right?: ReactNode; children: ReactNode; footer?: ReactNode; refresh?: ReactNode }) {
  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <View style={styles.bar}>
        {onBack && (
          <Pressable onPress={onBack} hitSlop={12} accessibilityRole="button" accessibilityLabel="Back" style={styles.back}>
            <Text style={styles.backText}>‹</Text>
          </Pressable>
        )}
        <View style={{ flex: 1 }}>
          <Text style={styles.barTitle} numberOfLines={1}>
            {title}
          </Text>
          {subtitle ? (
            <Text style={styles.barSub} numberOfLines={1}>
              {subtitle}
            </Text>
          ) : null}
        </View>
        {right}
      </View>
      <View style={styles.accent} />
      {refresh}
      <ScrollView style={styles.body} contentContainerStyle={styles.bodyContent} keyboardShouldPersistTaps="handled">
        {children}
      </ScrollView>
      {footer}
    </SafeAreaView>
  )
}

export function Card({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>
}

export function Button({ label, onPress, kind = 'primary', busy, disabled, small }: { label: string; onPress: () => void; kind?: 'primary' | 'ghost' | 'danger'; busy?: boolean; disabled?: boolean; small?: boolean }) {
  const off = disabled || busy
  return (
    <Pressable
      onPress={onPress}
      disabled={off}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!off, busy: !!busy }}
      style={({ pressed }) => [styles.button, small && styles.buttonSmall, kind === 'ghost' && styles.buttonGhost, kind === 'danger' && styles.buttonDanger, off && styles.buttonOff, pressed && { opacity: 0.8 }]}
    >
      {busy ? <ActivityIndicator color={kind === 'primary' ? '#fff' : colors.brand} /> : <Text style={[styles.buttonText, small && { fontSize: 14 }, kind === 'ghost' && { color: colors.brand }, kind === 'danger' && { color: colors.danger }]}>{label}</Text>}
    </Pressable>
  )
}

export function Field({ label, error, hint, ...input }: { label: string; error?: string; hint?: string } & TextInputProps) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput placeholderTextColor="#a79fb0" accessibilityLabel={label} {...input} style={[styles.input, error ? { borderColor: colors.danger } : null, input.style]} />
      {error ? <Text style={styles.error}>{error}</Text> : hint ? <Text style={styles.hint}>{hint}</Text> : null}
    </View>
  )
}

/** A row of choices where one is picked, e.g. gender. */
export function Choice<T extends string>({ label, value, options, onChange }: { label?: string; value: T | ''; options: { value: T; label: string }[]; onChange: (value: T) => void }) {
  return (
    <View style={styles.field}>
      {label ? <Text style={styles.label}>{label}</Text> : null}
      <View style={styles.choiceRow}>
        {options.map((o) => (
          <Pressable key={o.value} onPress={() => onChange(o.value)} accessibilityRole="radio" accessibilityState={{ selected: value === o.value }} style={[styles.choice, value === o.value && styles.choiceOn]}>
            <Text style={[styles.choiceText, value === o.value && { color: '#fff' }]}>{o.label}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  )
}

export function Notice({ text, kind = 'error' }: { text: string; kind?: 'error' | 'ok' | 'info' }) {
  if (!text) return null
  const bg = kind === 'ok' ? colors.okSoft : kind === 'info' ? colors.warnSoft : colors.dangerSoft
  const fg = kind === 'ok' ? colors.ok : kind === 'info' ? '#8a4a08' : colors.danger
  return (
    <View style={[styles.notice, { backgroundColor: bg }]} accessibilityRole="alert">
      <Text style={{ color: fg, fontSize: 14, lineHeight: 20 }}>{text}</Text>
    </View>
  )
}

const STATUS: Record<string, { label: string; bg: string; fg: string }> = {
  booked: { label: 'Booked', bg: '#ece6f3', fg: colors.brandDark },
  arrived: { label: 'Arrived', bg: colors.warnSoft, fg: '#8a4a08' },
  ongoing: { label: 'With doctor', bg: '#e1ecfa', fg: '#1d4f91' },
  reviewed: { label: 'Seen', bg: colors.okSoft, fg: colors.ok },
  cancelled: { label: 'Cancelled', bg: colors.dangerSoft, fg: colors.danger },
  active: { label: 'Active', bg: colors.okSoft, fg: colors.ok },
  paused: { label: 'Paused', bg: colors.warnSoft, fg: '#8a4a08' },
}

export function Pill({ status }: { status: string }) {
  const s = STATUS[status] ?? { label: status, bg: colors.border, fg: colors.text }
  return (
    <View style={[styles.pill, { backgroundColor: s.bg }]}>
      <Text style={{ color: s.fg, fontSize: 12, fontWeight: '700' }}>{s.label}</Text>
    </View>
  )
}

export function Loading() {
  return (
    <View style={{ padding: space.xl, alignItems: 'center' }}>
      <ActivityIndicator color={colors.brand} size="large" />
    </View>
  )
}

export function Empty({ text }: { text: string }) {
  return <Text style={styles.empty}>{text}</Text>
}

/** The bar at the bottom that switches between the main sections. */
export function TabBar<T extends string>({ tabs, value, onChange }: { tabs: { key: T; label: string }[]; value: T; onChange: (key: T) => void }) {
  return (
    <SafeAreaView edges={['bottom']} style={styles.tabBar}>
      {tabs.map((t) => (
        <Pressable key={t.key} onPress={() => onChange(t.key)} accessibilityRole="tab" accessibilityState={{ selected: value === t.key }} style={styles.tab}>
          <View style={[styles.tabDot, value === t.key && { backgroundColor: colors.accent }]} />
          <Text style={[styles.tabText, value === t.key && { color: colors.brand, fontWeight: '700' }]}>{t.label}</Text>
        </Pressable>
      ))}
    </SafeAreaView>
  )
}

export const text = StyleSheet.create({
  h2: { fontSize: 18, fontWeight: '700', color: colors.brandDark },
  strong: { fontSize: 16, fontWeight: '700', color: colors.text },
  body: { fontSize: 15, color: colors.text, lineHeight: 21 },
  muted: { fontSize: 13, color: colors.muted, lineHeight: 18 },
  link: { fontSize: 15, color: colors.brand, fontWeight: '600' },
})

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.brand },
  bar: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.lg, paddingVertical: space.md, backgroundColor: colors.brand, gap: space.sm, minHeight: 56 },
  back: { width: 32, height: 32, alignItems: 'center', justifyContent: 'center' },
  backText: { color: '#fff', fontSize: 30, lineHeight: 32 },
  barTitle: { color: '#fff', fontSize: 19, fontWeight: '700' },
  barSub: { color: '#e6daef', fontSize: 13 },
  accent: { height: 3, backgroundColor: colors.accent },
  body: { flex: 1, backgroundColor: colors.bg },
  bodyContent: { padding: space.lg, gap: space.md, paddingBottom: space.xl * 2 },
  card: { backgroundColor: colors.surface, borderRadius: 12, padding: space.lg, borderWidth: 1, borderColor: colors.border, gap: space.sm },
  button: { backgroundColor: colors.brand, borderRadius: 10, minHeight: 48, paddingHorizontal: space.lg, alignItems: 'center', justifyContent: 'center' },
  buttonSmall: { minHeight: 38, paddingHorizontal: space.md },
  buttonGhost: { backgroundColor: 'transparent', borderWidth: 1, borderColor: colors.brand },
  buttonDanger: { backgroundColor: 'transparent', borderWidth: 1, borderColor: colors.danger },
  buttonOff: { opacity: 0.5 },
  buttonText: { color: '#fff', fontSize: 16, fontWeight: '700' },
  field: { gap: 6 },
  label: { fontSize: 13, fontWeight: '700', color: colors.muted },
  input: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: space.md, minHeight: 48, fontSize: 16, color: colors.text },
  error: { color: colors.danger, fontSize: 13 },
  hint: { color: colors.muted, fontSize: 12 },
  choiceRow: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  choice: { borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: space.lg, minHeight: 40, justifyContent: 'center' },
  choiceOn: { backgroundColor: colors.brand, borderColor: colors.brand },
  choiceText: { fontSize: 15, color: colors.text, fontWeight: '600' },
  notice: { borderRadius: 10, padding: space.md },
  pill: { alignSelf: 'flex-start', borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  empty: { textAlign: 'center', color: colors.muted, paddingVertical: space.xl, fontSize: 15, lineHeight: 22 },
  tabBar: { flexDirection: 'row', backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
  tab: { flex: 1, alignItems: 'center', paddingVertical: space.sm, minHeight: 54, justifyContent: 'center', gap: 4 },
  tabDot: { width: 22, height: 3, borderRadius: 2, backgroundColor: 'transparent' },
  tabText: { fontSize: 13, color: colors.muted },
})
