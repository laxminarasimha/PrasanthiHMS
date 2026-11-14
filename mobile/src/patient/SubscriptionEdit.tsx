import { useEffect, useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { api, ApiError, type MedicineName, type Subscription, type SubscriptionItem } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Field, Notice, Screen, text } from '../ui'

const DAYS = [1, 5, 10, 15, 20, 25, 28]

/** Make or change a list of medicines the pharmacy should keep ready every month. */
export function SubscriptionEdit({ existing, onBack, onSaved }: { existing: Subscription | null; onBack: () => void; onSaved: () => void }) {
  const [title, setTitle] = useState(existing?.title ?? '')
  const [day, setDay] = useState(existing?.day_of_month ?? 5)
  const [notes, setNotes] = useState(existing?.notes ?? '')
  const [items, setItems] = useState<(Omit<SubscriptionItem, 'quantity'> & { quantity: string })[]>(existing?.items.map((i) => ({ ...i, quantity: String(i.quantity) })) ?? [])
  const [query, setQuery] = useState('')
  const [matches, setMatches] = useState<MedicineName[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const term = query.trim()
    if (term.length < 2) return setMatches([])
    let current = true
    const timer = setTimeout(() => {
      api.get<MedicineName[]>('/app/medicines', { q: term }).then((list) => current && setMatches(list)).catch(() => undefined)
    }, 300)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [query])

  const add = (name: string, medicineId: number | null) => {
    if (items.some((i) => i.name.toLowerCase() === name.toLowerCase())) return setError('That medicine is already on the list.')
    setItems([...items, { medicine_id: medicineId, name, quantity: '30' }])
    setQuery('')
    setMatches([])
    setError('')
  }

  const save = async () => {
    setError('')
    if (!title.trim()) return setError('Give the list a name, for example "Diabetes medicines".')
    if (items.length === 0) return setError('Add at least one medicine.')
    if (items.some((i) => !(Number(i.quantity) >= 1))) return setError('Enter how many of each medicine you need for a month.')
    setBusy(true)
    const body = { title: title.trim(), day_of_month: day, notes: notes.trim() || null, items: items.map((i) => ({ ...i, quantity: Number(i.quantity) })) }
    try {
      if (existing) await api.put(`/app/subscriptions/${existing.id}`, body)
      else await api.post('/app/subscriptions', body)
      onSaved()
    } catch (e) {
      setError((e as ApiError).message)
      setBusy(false)
    }
  }

  const typed = query.trim()

  return (
    <Screen title={existing ? 'Change monthly medicines' : 'New monthly medicines'} onBack={onBack} footer={<View style={styles.footer}><Notice text={error} /><Button label={existing ? 'Save changes' : 'Start this list'} onPress={save} busy={busy} /></View>}>
      <Card>
        <Field label="Name of this list" value={title} onChangeText={setTitle} maxLength={100} placeholder="e.g. Diabetes medicines, Thyroid" />
        <View style={{ gap: 6 }}>
          <Text style={styles.label}>Keep them ready every month on day</Text>
          <View style={styles.row}>
            {DAYS.map((d) => (
              <Pressable key={d} onPress={() => setDay(d)} accessibilityRole="radio" accessibilityState={{ selected: day === d }} style={[styles.chip, day === d && styles.chipOn]}>
                <Text style={[styles.chipText, day === d && { color: '#fff' }]}>{d}</Text>
              </Pressable>
            ))}
          </View>
        </View>
      </Card>

      <Card>
        <Text style={text.h2}>Medicines</Text>
        {items.length === 0 && <Text style={text.muted}>Search below and add each medicine you take regularly.</Text>}
        {items.map((item, i) => (
          <View key={item.name} style={styles.item}>
            <View style={{ flex: 1 }}>
              <Text style={text.strong}>{item.name}</Text>
              <Pressable onPress={() => setItems(items.filter((_, j) => j !== i))} accessibilityRole="button" accessibilityLabel={`Remove ${item.name}`} hitSlop={8}>
                <Text style={{ color: colors.danger, fontSize: 13, marginTop: 2 }}>Remove</Text>
              </Pressable>
            </View>
            <View style={{ width: 96 }}>
              <Field label="Per month" value={item.quantity} onChangeText={(v) => setItems(items.map((x, j) => (j === i ? { ...x, quantity: v.replace(/\D/g, '') } : x)))} keyboardType="number-pad" maxLength={4} />
            </View>
          </View>
        ))}

        <Field label="Add a medicine" value={query} onChangeText={setQuery} placeholder="Type the name, e.g. Metformin" autoCapitalize="none" />
        {matches.map((m) => (
          <Pressable key={m.id} onPress={() => add(m.name, m.id)} accessibilityRole="button" style={styles.match}>
            <Text style={text.body}>{m.name}</Text>
            <Text style={text.link}>Add</Text>
          </Pressable>
        ))}
        {typed.length >= 2 && !matches.some((m) => m.name.toLowerCase() === typed.toLowerCase()) && (
          <Pressable onPress={() => add(typed, null)} accessibilityRole="button" style={styles.match}>
            <Text style={[text.muted, { flex: 1 }]}>Not in the list? Add “{typed}” as typed. The pharmacy will check it.</Text>
            <Text style={text.link}>Add</Text>
          </Pressable>
        )}
      </Card>

      <Card>
        <Field label="Note for the pharmacy (optional)" value={notes} onChangeText={setNotes} maxLength={500} multiline placeholder="e.g. Any brand is fine. I will collect after 6 PM." style={{ minHeight: 72, paddingTop: 12, textAlignVertical: 'top' }} />
        <Text style={text.muted}>The pharmacy makes up your pack each month. You pay at the pharmacy counter when you collect it. Medicines that need a prescription are given only against a valid one.</Text>
      </Card>
    </Screen>
  )
}

const styles = StyleSheet.create({
  label: { fontSize: 13, fontWeight: '700', color: colors.muted },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  chip: { minWidth: 44, minHeight: 40, alignItems: 'center', justifyContent: 'center', borderRadius: 999, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  chipOn: { backgroundColor: colors.brand, borderColor: colors.brand },
  chipText: { fontSize: 15, fontWeight: '600', color: colors.text },
  item: { flexDirection: 'row', alignItems: 'flex-end', gap: space.md, borderBottomWidth: 1, borderBottomColor: colors.border, paddingBottom: space.sm },
  match: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: space.md, paddingVertical: space.sm, borderBottomWidth: 1, borderBottomColor: colors.border, minHeight: 44 },
  footer: { backgroundColor: colors.surface, padding: space.lg, gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border },
})
