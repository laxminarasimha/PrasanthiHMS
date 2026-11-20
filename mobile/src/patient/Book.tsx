import { useEffect, useState } from 'react'
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import { addDays, api, ApiError, niceDate, shortDay, todayIso, type Appointment, type Doctor, type Person, type Slot } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Choice, Empty, Field, Loading, Notice, Screen, text } from '../ui'

/**
 * Choose a doctor, a day and a free time, then confirm. Opened from a "visit due" notice, it starts with that doctor
 * and person chosen. Given an appointment to move, the doctor and person stay as they are and only the time changes.
 */
export function BookAppointment({ people, onBack, onBooked, doctorId, patientId, moving }: {
  people: Person[]
  onBack: () => void
  onBooked: (a: Appointment) => void
  doctorId?: number
  patientId?: number
  moving?: Appointment
}) {
  const [who, setWho] = useState(String(moving?.patient?.id ?? people.find((p) => p.patient_id === patientId)?.patient_id ?? people[0].patient_id))
  const [doctors, setDoctors] = useState<Doctor[] | null>(null)
  const [doctor, setDoctor] = useState<Doctor | null>(null)
  const [date, setDate] = useState(todayIso())
  const [slots, setSlots] = useState<Slot[] | null>(null)
  const [time, setTime] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const days = Array.from({ length: 14 }, (_, i) => addDays(todayIso(), i))

  useEffect(() => {
    api.get<Doctor[]>('/app/doctors').then((list) => {
      setDoctors(list)
      const wanted = list.find((d) => d.id === (moving?.doctor?.id ?? doctorId))
      if (wanted) setDoctor(wanted)
      else if (list.length === 1) setDoctor(list[0])
      if (moving) setDate(moving.scheduled_at.slice(0, 10))
    }).catch((e: ApiError) => setError(e.message))
  }, [doctorId, moving])

  useEffect(() => {
    if (!doctor) return
    let current = true
    setSlots(null)
    setTime('')
    api.get<Slot[]>(`/app/doctors/${doctor.id}/slots`, { date }).then((list) => current && setSlots(list)).catch((e: ApiError) => current && setError(e.message))
    return () => {
      current = false
    }
  }, [doctor, date])

  const book = async () => {
    if (!doctor || !time) return
    setBusy(true)
    setError('')
    try {
      onBooked(
        moving
          ? await api.put<Appointment>(`/app/appointments/${moving.id}`, { scheduled_at: `${date} ${time}` })
          : await api.post<Appointment>('/app/appointments', { doctor_id: doctor.id, scheduled_at: `${date} ${time}`, notes: notes.trim() || null, patient_id: Number(who) }),
      )
    } catch (e) {
      setError((e as ApiError).message)
      // The time may have just been taken: show the day afresh.
      api.get<Slot[]>(`/app/doctors/${doctor.id}/slots`, { date }).then(setSlots).catch(() => undefined)
      setTime('')
    } finally {
      setBusy(false)
    }
  }

  const free = slots?.filter((s) => s.available) ?? []
  const picked = slots?.find((s) => s.time === time)

  return (
    <Screen
      title={moving ? 'Move the appointment' : 'Book an appointment'}
      onBack={onBack}
      footer={
        doctor && time ? (
          <View style={styles.footer}>
            <Text style={text.body}>
              {doctor.name} · {niceDate(date)} · <Text style={{ fontWeight: '700' }}>{picked?.label}</Text>
            </Text>
            <Button label={moving ? 'Move to this time' : 'Confirm booking'} onPress={book} busy={busy} />
          </View>
        ) : undefined
      }
    >
      <Notice text={error} />
      {moving && (
        <Card>
          <Text style={text.strong}>Now booked: {niceDate(moving.scheduled_at)}</Text>
          <Text style={text.muted}>Choose the new day and time below. The old time is let go only once the new one is yours.</Text>
        </Card>
      )}
      {people.length > 1 && !moving && (
        <Card>
          <Text style={text.h2}>Who is it for?</Text>
          <Choice value={who} onChange={setWho} options={people.map((p) => ({ value: String(p.patient_id), label: p.relation === 'Self' ? 'Me' : `${p.name} (${p.relation})` }))} />
        </Card>
      )}
      <Card>
        <Text style={text.h2}>1. Doctor</Text>
        {!doctors && !error && <Loading />}
        {doctors?.length === 0 && <Empty text="No doctor is open for booking yet. Please call the hospital." />}
        {doctors?.filter((d) => !moving || d.id === moving.doctor?.id).map((d) => (
          <Pressable key={d.id} onPress={() => !moving && setDoctor(d)} accessibilityRole="radio" accessibilityState={{ selected: doctor?.id === d.id }} style={[styles.doctor, doctor?.id === d.id && styles.doctorOn]}>
            <Text style={text.strong}>{d.name}</Text>
            {d.department ? <Text style={text.muted}>{d.department}</Text> : null}
          </Pressable>
        ))}
      </Card>

      {doctor && (
        <Card>
          <Text style={text.h2}>2. Day</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: space.sm }}>
            {days.map((d) => {
              const s = shortDay(d)
              const on = d === date
              return (
                <Pressable key={d} onPress={() => setDate(d)} accessibilityRole="radio" accessibilityLabel={niceDate(d)} accessibilityState={{ selected: on }} style={[styles.day, on && styles.dayOn]}>
                  <Text style={[styles.dayWeek, on && { color: '#fff' }]}>{d === todayIso() ? 'Today' : s.weekday}</Text>
                  <Text style={[styles.dayNumber, on && { color: '#fff' }]}>{s.day}</Text>
                  <Text style={[styles.dayWeek, on && { color: '#fff' }]}>{s.month}</Text>
                </Pressable>
              )
            })}
          </ScrollView>
        </Card>
      )}

      {doctor && (
        <Card>
          <Text style={text.h2}>3. Time</Text>
          {!slots && <Loading />}
          {slots && slots.length === 0 && <Empty text="The doctor does not see patients on this day. Try another day." />}
          {slots && slots.length > 0 && free.length === 0 && <Empty text="Every time on this day is taken or has passed. Try another day." />}
          <View style={styles.slots}>
            {slots?.map((s) => (
              <Pressable key={s.time} disabled={!s.available} onPress={() => setTime(s.time)} accessibilityRole="radio" accessibilityState={{ selected: time === s.time, disabled: !s.available }} style={[styles.slot, time === s.time && styles.slotOn, !s.available && styles.slotOff]}>
                <Text style={[styles.slotText, time === s.time && { color: '#fff' }, !s.available && { color: '#b9b2c0', textDecorationLine: 'line-through' }]}>{s.label}</Text>
              </Pressable>
            ))}
          </View>
        </Card>
      )}

      {doctor && time && !moving ? (
        <Card>
          <Field label="Reason for the visit (optional)" value={notes} onChangeText={setNotes} maxLength={255} placeholder="e.g. sugar check, fever for 3 days" />
          <Text style={text.muted}>The consultation fee is paid at the hospital front desk when you arrive.</Text>
        </Card>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  doctor: { borderWidth: 1, borderColor: colors.border, borderRadius: 10, padding: space.md },
  doctorOn: { borderColor: colors.brand, backgroundColor: '#f3ecf8', borderWidth: 2 },
  day: { width: 62, alignItems: 'center', paddingVertical: space.sm, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  dayOn: { backgroundColor: colors.brand, borderColor: colors.brand },
  dayWeek: { fontSize: 12, color: colors.muted },
  dayNumber: { fontSize: 20, fontWeight: '700', color: colors.text },
  slots: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  slot: { minWidth: 92, minHeight: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 8, borderWidth: 1, borderColor: colors.brand, backgroundColor: colors.surface },
  slotOn: { backgroundColor: colors.brand },
  slotOff: { borderColor: colors.border, backgroundColor: '#f4f1f6' },
  slotText: { fontSize: 15, fontWeight: '600', color: colors.brand },
  footer: { backgroundColor: colors.surface, padding: space.lg, gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border },
})
