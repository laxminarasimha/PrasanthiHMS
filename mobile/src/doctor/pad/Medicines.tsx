import { useEffect, useState } from 'react'
import { Pressable, Text, View } from 'react-native'
import { api, type Library, type RxLine } from '../../api'
import { colors } from '../../theme'
import { Button, Card, Field, text } from '../../ui'
import { Chip, Chips, Row, Section } from './parts'

const FREQUENCIES = ['1-0-0', '0-1-0', '0-0-1', '1-0-1', '1-1-0', '0-1-1', '1-1-1', '1-1-1-1', 'SOS', 'Once a week']
const TIMINGS = ['After food', 'Before food', 'Empty stomach', 'At bedtime']
const DURATIONS = ['3 days', '5 days', '7 days', '10 days', '15 days', '1 month', '3 months']

export const emptyLine = (): RxLine => ({ medicine: '', dose: null, frequency: null, timing: null, duration: null, instructions: null })
const summary = (m: RxLine) => [m.dose, m.frequency, m.timing, m.duration, m.instructions].filter(Boolean).join(' · ')

/** The prescription: the medicines so far, and a small form to add or change one. */
export function Medicines({ rows, onChange, library, readOnly }: { rows: RxLine[]; onChange: (rows: RxLine[]) => void; library: Library | null; readOnly?: boolean }) {
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const [line, setLine] = useState<RxLine>(emptyLine())
  const [names, setNames] = useState<string[]>([])
  const [groups, setGroups] = useState(false)
  const frequencies = library?.rx_settings.frequencies?.length ? library.rx_settings.frequencies : FREQUENCIES
  const timings = library?.rx_settings.timings?.length ? library.rx_settings.timings : TIMINGS

  // Names to choose from while typing: the pharmacy's list first, then names prescribed before.
  useEffect(() => {
    const q = line.medicine.trim()
    if (editing === null || q.length < 2) {
      setNames([])
      return
    }
    let current = true
    const timer = setTimeout(() => {
      api.get<string[]>('/medicines/suggest', { q }).then((list) => current && setNames(list.filter((n) => n.toLowerCase() !== q.toLowerCase()).slice(0, 8))).catch(() => undefined)
    }, 300)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [line.medicine, editing])

  const open = (index: number | 'new') => {
    setEditing(index)
    setLine(index === 'new' ? emptyLine() : { ...rows[index] })
  }

  /** Choosing a name fills in the way this doctor usually gives it, where nothing is typed yet. */
  const pick = (name: string) => {
    const usual = library?.medicine_defaults.find((d) => d.medicine.toLowerCase() === name.toLowerCase())
    setLine((l) => ({ ...l, medicine: name, dose: l.dose ?? usual?.dose ?? null, frequency: l.frequency ?? usual?.frequency ?? null, timing: l.timing ?? usual?.timing ?? null, duration: l.duration ?? usual?.duration ?? null, instructions: l.instructions ?? usual?.instructions ?? null }))
    setNames([])
  }

  const done = () => {
    const clean = { ...line, medicine: line.medicine.trim() }
    onChange(editing === 'new' ? [...rows, clean] : rows.map((r, i) => (i === editing ? clean : r)))
    setEditing(null)
  }

  const toggle = (key: 'frequency' | 'timing' | 'duration', value: string) => setLine((l) => ({ ...l, [key]: l[key] === value ? null : value }))

  return (
    <Card>
      <Section title={`Prescription${rows.length ? ` (${rows.length})` : ''}`} />
      {rows.length === 0 && editing === null && <Text style={text.muted}>No medicines yet.</Text>}
      {rows.map((m, i) =>
        editing === i ? null : (
          <Row key={i} onRemove={readOnly ? undefined : () => onChange(rows.filter((_, r) => r !== i))}>
            <Pressable onPress={readOnly ? undefined : () => open(i)} accessibilityRole="button">
              <Text style={text.strong}>{m.medicine}</Text>
              {summary(m) ? <Text style={text.muted}>{summary(m)}</Text> : null}
            </Pressable>
          </Row>
        ),
      )}

      {editing !== null && (
        <View style={{ gap: 10, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 10 }}>
          <Field label="Medicine" value={line.medicine} onChangeText={(v) => setLine((l) => ({ ...l, medicine: v }))} autoCapitalize="words" autoCorrect={false} placeholder="Start typing the name" />
          {names.length > 0 && (
            <Chips>
              {names.map((n) => (
                <Chip key={n} label={n} onPress={() => pick(n)} />
              ))}
            </Chips>
          )}
          <Field label="Dose" value={line.dose ?? ''} onChangeText={(v) => setLine((l) => ({ ...l, dose: v || null }))} placeholder="1 tablet, 5 ml" />
          <Text style={text.muted}>How often</Text>
          <Chips>
            {frequencies.map((f) => (
              <Chip key={f} label={f} on={line.frequency === f} onPress={() => toggle('frequency', f)} />
            ))}
          </Chips>
          <Text style={text.muted}>When</Text>
          <Chips>
            {timings.map((t) => (
              <Chip key={t} label={t} on={line.timing === t} onPress={() => toggle('timing', t)} />
            ))}
          </Chips>
          <Text style={text.muted}>For how long</Text>
          <Chips>
            {DURATIONS.map((d) => (
              <Chip key={d} label={d} on={line.duration === d} onPress={() => toggle('duration', d)} />
            ))}
          </Chips>
          <Field label="Or type the duration" value={line.duration ?? ''} onChangeText={(v) => setLine((l) => ({ ...l, duration: v || null }))} placeholder="e.g. 6 weeks" />
          <Field label="Instructions" value={line.instructions ?? ''} onChangeText={(v) => setLine((l) => ({ ...l, instructions: v || null }))} placeholder="optional" />
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}>
              <Button label="Cancel" kind="ghost" onPress={() => setEditing(null)} />
            </View>
            <View style={{ flex: 1 }}>
              <Button label={editing === 'new' ? 'Add' : 'Done'} onPress={done} disabled={line.medicine.trim().length < 2} />
            </View>
          </View>
        </View>
      )}

      {!readOnly && editing === null && (
        <>
          <Button label="+ Add a medicine" kind="ghost" onPress={() => open('new')} />
          {library && library.rx_groups.length > 0 && <Button label={groups ? 'Hide my medicine groups' : 'Add one of my medicine groups'} kind="ghost" small onPress={() => setGroups(!groups)} />}
          {groups && (
            <Chips>
              {library?.rx_groups.map((g) => (
                <Chip
                  key={g.id}
                  label={`${g.name} (${g.medicines.length})`}
                  onPress={() => {
                    onChange([...rows, ...g.medicines.filter((m) => m.medicine).map((m) => ({ ...emptyLine(), ...m }) as RxLine)])
                    setGroups(false)
                  }}
                />
              ))}
            </Chips>
          )}
        </>
      )}
    </Card>
  )
}
