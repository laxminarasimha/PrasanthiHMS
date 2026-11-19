import { useEffect, useMemo, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate, type OwnerOverview, type Summary } from '../../api'
import { colors, space } from '../../theme'
import { Card, Loading, Notice, text } from '../../ui'
import { DayBars, Line, PeriodPicker, periods, Tile, Tiles, whole } from './parts'

const COUNTS: [string, string][] = [['new_patients', 'New patients'], ['appointments', 'Appointments'], ['consultations', 'Seen by a doctor'], ['lab_orders', 'Lab orders'], ['pharmacy_bills', 'Pharmacy bills'], ['admissions', 'Admissions'], ['discharges', 'Discharges'], ['inpatients_now', 'In beds now']]
const MODES: Record<string, string> = { cash: 'Cash', card: 'Card', upi: 'UPI', cheque: 'Cheque', bank_transfer: 'Bank transfer', insurance: 'Insurance' }
const CATEGORY = (key: string) => key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ')

/** The money for a period: what came in, what went out, how the days compare, what is still owed, and each doctor's work. Read-only. */
export function ReportsBoard({ refreshKey }: { refreshKey: number }) {
  const options = useMemo(periods, [])
  const [period, setPeriod] = useState('month')
  const [summary, setSummary] = useState<Summary | null>(null)
  const [owner, setOwner] = useState<OwnerOverview | null>(null)
  const [error, setError] = useState('')

  const range = options.find((p) => p.key === period) ?? options[0]
  useEffect(() => {
    setSummary(null)
    setOwner(null)
    setError('')
    api.get<Summary>('/reports/summary', { from: range.from, to: range.to }).then(setSummary).catch((e: ApiError) => setError(e.message))
    // Older servers do not have this yet: the rest of the page still shows without it.
    api.get<OwnerOverview>('/reports/owner', { from: range.from, to: range.to, days: '14' }).then(setOwner).catch(() => setOwner(null))
  }, [range.from, range.to, refreshKey])

  const departments = summary ? Object.values(summary.departments) : []
  const billed = departments.reduce((sum, d) => sum + d.billed, 0)
  const collected = departments.reduce((sum, d) => sum + d.collected_total, 0)
  const paidOut = summary?.paid_out?.total ?? 0
  const modes = summary ? summary.modes.map((m) => [m, departments.reduce((sum, d) => sum + (d.collected[m] ?? 0), 0)] as const).filter(([, amount]) => amount !== 0) : []
  const spent = summary?.paid_out ? [...Object.entries(Array.isArray(summary.paid_out.by_category) ? {} : summary.paid_out.by_category).map(([k, v]) => [CATEGORY(k), v] as const), ...(summary.paid_out.suppliers > 0 ? [['Medicine suppliers', summary.paid_out.suppliers] as const] : [])] : []

  return (
    <>
      <PeriodPicker options={options} value={period} onChange={setPeriod} />
      <Text style={text.muted}>{range.from === range.to ? niceDate(range.from) : `${niceDate(range.from).slice(5)} to ${niceDate(range.to).slice(5)}`}</Text>
      <Notice text={error} />
      {!summary && !error && <Loading />}

      {summary && (
        <>
          <Tiles>
            <Tile label="Billed" value={whole(billed)} />
            <Tile label="Collected" value={whole(collected)} tone="ok" />
            <Tile label="Paid out" value={whole(paidOut)} note="expenses and suppliers" />
            <Tile label="Left after paying out" value={whole(collected - paidOut)} tone={collected - paidOut < 0 ? 'danger' : undefined} />
          </Tiles>

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
                <Text style={[text.body, styles.num]}>{whole(d.billed)}</Text>
                <Text style={[text.strong, styles.num]}>{whole(d.collected_total)}</Text>
              </View>
            ))}
            {modes.length > 0 && <Text style={text.muted}>Collected as: {modes.map(([m, amount]) => `${MODES[m] ?? m} ${whole(amount)}`).join(' · ')}</Text>}
          </Card>

          {spent.length > 0 && (
            <Card>
              <Text style={text.h2}>Where the money went</Text>
              {spent.map(([label, amount]) => (
                <Line key={label} label={label} value={whole(amount)} />
              ))}
            </Card>
          )}
        </>
      )}

      {owner && owner.trend.length > 0 && (
        <Card>
          <Text style={text.h2}>Collected each day</Text>
          <Text style={text.muted}>The last {owner.trend.length} days. Touch a day to see its figures.</Text>
          <DayBars days={owner.trend} />
        </Card>
      )}

      {owner && (
        <Card>
          <Text style={text.h2}>Money still to come in</Text>
          <Text style={[styles.owed, owner.owed_total > 0 && { color: colors.danger }]}>{whole(owner.owed_total)}</Text>
          <Text style={text.muted}>Everything patients owe the hospital today, whenever the bill was made.</Text>
          {owner.owed.filter((o) => o.owed > 0).map((o) => (
            <Line key={o.key} label={o.label} sub={`${o.count} ${o.count === 1 ? 'bill' : 'bills'}`} value={whole(o.owed)} />
          ))}
        </Card>
      )}

      {owner && owner.doctors.length > 0 && (
        <Card>
          <Text style={text.h2}>Doctors</Text>
          <Text style={text.muted}>Appointments in this period, and patients in a bed under each doctor now.</Text>
          {owner.doctors.map((d) => (
            <Line
              key={d.id}
              label={d.name}
              sub={[`${d.seen} seen of ${d.booked} booked`, d.inpatients ? `${d.inpatients} admitted now` : ''].filter(Boolean).join(' · ')}
              value={whole(d.billed)}
              extra="consultations billed"
            />
          ))}
        </Card>
      )}

      {summary && (
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
          <Text style={text.muted}>The detailed reports, with every bill and patient, are on the web system under Reports.</Text>
        </Card>
      )}
    </>
  )
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm },
  head: { fontSize: 12, fontWeight: '700', color: colors.muted },
  num: { width: 96, textAlign: 'right' },
  owed: { fontSize: 28, fontWeight: '800', color: colors.brandDark },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  cell: { width: '25%', alignItems: 'center', paddingVertical: space.sm },
  cellNumber: { fontSize: 20, fontWeight: '700', color: colors.brandDark },
  cellLabel: { fontSize: 11, color: colors.muted, textAlign: 'center' },
})
