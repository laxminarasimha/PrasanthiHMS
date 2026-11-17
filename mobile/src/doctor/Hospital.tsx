import { useCallback, useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { addDays, api, ApiError, niceDate, niceTime, rupees, todayIso, type DayBook, type PharmacyDesk, type StaffUser, type Summary } from '../api'
import { colors, space } from '../theme'
import { Button, Card, Loading, Notice, text } from '../ui'

const COUNTS: [string, string][] = [['new_patients', 'New patients'], ['appointments', 'Appointments'], ['consultations', 'Consultations'], ['lab_orders', 'Lab orders'], ['pharmacy_bills', 'Pharmacy bills'], ['admissions', 'Admissions'], ['discharges', 'Discharges'], ['inpatients_now', 'Inpatients now']]
const MODES: Record<string, string> = { cash: 'Cash', card: 'Card', upi: 'UPI', cheque: 'Cheque', bank_transfer: 'Bank transfer', insurance: 'Insurance' }

/** The owner's view of a day: money by department, whether the day is closed and what is holding it up, and the pharmacy's to-do list. Read-only. */
export function HospitalDay({ user, refreshKey }: { user: StaffUser; refreshKey: number }) {
  const [day, setDay] = useState<string | null>(null)
  const [summary, setSummary] = useState<Summary | null>(null)
  const [book, setBook] = useState<DayBook | null>(null)
  const [desk, setDesk] = useState<PharmacyDesk | null>(null)
  const [error, setError] = useState('')

  const load = useCallback(() => {
    setError('')
    // The hospital's own date comes back with the day book, so a phone set to another time zone still shows the right day.
    api.get<DayBook>('/day-close', day ? { day } : {}).then((b) => {
      setBook(b)
      api.get<Summary>('/reports/summary', { from: b.day, to: b.day }).then(setSummary).catch((e: ApiError) => setError(e.message))
    }).catch((e: ApiError) => setError(e.message))
    if (user.can_run_pharmacy) api.get<PharmacyDesk>('/pharmacy/manager').then(setDesk).catch(() => setDesk(null))
  }, [day, user.can_run_pharmacy])

  useEffect(() => {
    setSummary(null)
    setBook(null)
    load()
  }, [load, refreshKey])

  const shown = book?.day ?? day ?? todayIso()
  const today = book?.today ?? todayIso()
  const departments = summary ? Object.values(summary.departments) : []
  const billed = departments.reduce((sum, d) => sum + d.billed, 0)
  const collected = departments.reduce((sum, d) => sum + d.collected_total, 0)
  const modes = summary ? summary.modes.map((m) => [m, departments.reduce((sum, d) => sum + (d.collected[m] ?? 0), 0)] as const).filter(([, amount]) => amount !== 0) : []
  const open = book?.sections.flatMap((s) => s.checks.filter((c) => c.count > 0).map((c) => ({ ...c, section: s.label }))) ?? []

  return (
    <>
      <View style={styles.dateRow}>
        <Button label="‹" kind="ghost" small onPress={() => setDay(addDays(shown, -1))} />
        <View style={{ flex: 1, alignItems: 'center' }}>
          <Text style={text.strong}>{shown === today ? 'Today' : niceDate(shown)}</Text>
          {shown === today ? <Text style={text.muted}>{niceDate(shown)}</Text> : <Text style={text.link} onPress={() => setDay(null)}>Back to today</Text>}
        </View>
        <Button label="›" kind="ghost" small disabled={shown >= today} onPress={() => setDay(addDays(shown, 1) >= today ? null : addDays(shown, 1))} />
      </View>

      <Notice text={error} />
      {!summary && !error && <Loading />}

      {summary && (
        <>
          <View style={styles.pair}>
            <View style={styles.big}>
              <Text style={styles.bigLabel}>Billed</Text>
              <Text style={styles.bigNumber}>{rupees(billed)}</Text>
            </View>
            <View style={styles.big}>
              <Text style={styles.bigLabel}>Collected</Text>
              <Text style={[styles.bigNumber, { color: colors.ok }]}>{rupees(collected)}</Text>
            </View>
          </View>

          <Card>
            <Text style={text.h2}>By department</Text>
            <View style={styles.row}>
              <Text style={[styles.head, { flex: 1 }]}> </Text>
              <Text style={[styles.head, styles.num]}>Billed</Text>
              <Text style={[styles.head, styles.num]}>Collected</Text>
            </View>
            {departments.map((d) => (
              <View key={d.label} style={styles.row}>
                <Text style={[text.body, { flex: 1 }]}>{d.label}</Text>
                <Text style={[text.body, styles.num]}>{rupees(d.billed)}</Text>
                <Text style={[text.strong, styles.num]}>{rupees(d.collected_total)}</Text>
              </View>
            ))}
            {modes.length > 0 && <Text style={text.muted}>Collected as: {modes.map(([m, amount]) => `${MODES[m] ?? m} ${rupees(amount)}`).join(' · ')}</Text>}
          </Card>

          <Card>
            <Text style={text.h2}>Activity</Text>
            <View style={styles.grid}>
              {COUNTS.map(([key, label]) => (
                <View key={key} style={styles.cell}>
                  <Text style={styles.cellNumber}>{summary.counts[key] ?? 0}</Text>
                  <Text style={styles.cellLabel}>{label}</Text>
                </View>
              ))}
            </View>
          </Card>
        </>
      )}

      {book && (
        <Card style={book.closed ? { borderColor: colors.ok } : book.blocking > 0 ? { borderColor: colors.danger } : undefined}>
          <Text style={text.h2}>Day close</Text>
          {book.closed ? (
            <>
              <Text style={[text.strong, { color: colors.ok }]}>Closed</Text>
              <Text style={text.body}>
                By {book.closed.closed_by_name} at {niceTime(book.closed.closed_at)}. Cash counted {rupees(book.closed.counted_cash)}
                {book.closed.variance !== 0 ? `, ${book.closed.variance < 0 ? 'short' : 'over'} by ${rupees(Math.abs(book.closed.variance))}` : ', matching the books'}.
              </Text>
            </>
          ) : (
            <>
              <Text style={[text.strong, { color: book.blocking > 0 ? colors.danger : colors.text }]}>{book.blocking > 0 ? `Not closed · ${book.blocking} to settle` : 'Not closed · nothing holding it up'}</Text>
              <Text style={text.body}>Cash expected in the drawer: {rupees(book.expected_cash)}</Text>
            </>
          )}
          {open.map((c) => (
            <View key={c.key} style={styles.row}>
              <Text style={[text.body, { flex: 1 }]}>{c.label}</Text>
              <Text style={[text.strong, { color: c.blocking ? colors.danger : colors.muted }]}>{c.count}</Text>
            </View>
          ))}
          {book.recent.some((r) => !r.closed) && <Text style={text.muted}>Not closed in the last week: {book.recent.filter((r) => !r.closed).map((r) => niceDate(r.day).slice(0, -5)).reverse().join(' · ')}</Text>}
          <Text style={text.muted}>Closing and reopening a day is done on the web system.</Text>
        </Card>
      )}

      {desk && (
        <Card>
          <Text style={text.h2}>Pharmacy to do</Text>
          {desk.actions.length === 0 && <Text style={text.body}>Nothing needs doing: stock, expiry and supplier bills are in order.</Text>}
          {desk.actions.map((a, i) => (
            <View key={i} style={[styles.todo, { borderLeftColor: a.tone === 'danger' ? colors.danger : a.tone === 'warn' ? colors.accent : colors.brand }]}>
              <Text style={text.body}>
                <Text style={{ fontWeight: '800' }}>{a.count}</Text> {a.text}
              </Text>
            </View>
          ))}
        </Card>
      )}
    </>
  )
}

const styles = StyleSheet.create({
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  pair: { flexDirection: 'row', gap: space.sm },
  big: { flex: 1, backgroundColor: colors.surface, borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: space.lg },
  bigLabel: { fontSize: 13, color: colors.muted },
  bigNumber: { fontSize: 24, fontWeight: '800', color: colors.brandDark },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm },
  head: { fontSize: 12, fontWeight: '700', color: colors.muted },
  num: { width: 92, textAlign: 'right' },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  cell: { width: '25%', alignItems: 'center', paddingVertical: space.sm },
  cellNumber: { fontSize: 20, fontWeight: '700', color: colors.brandDark },
  cellLabel: { fontSize: 11, color: colors.muted, textAlign: 'center' },
  todo: { borderLeftWidth: 4, paddingLeft: space.md, paddingVertical: 4 },
})
