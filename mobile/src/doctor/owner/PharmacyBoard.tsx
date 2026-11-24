import { useEffect, useMemo, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate, niceTime, type PharmacyDash, type PharmacyDesk, type StaffUser, type StockAudit } from '../../api'
import { colors, space } from '../../theme'
import { Card, Loading, Notice, text } from '../../ui'
import { Line, PeriodPicker, periods, Tile, Tiles, whole } from './parts'

const CLASS: Record<string, string> = { out: 'Out of stock', urgent: 'Will run out before a delivery', low: 'Below the minimum' }

/** The pharmacy as the owner wants to see it: what was sold, what the shelves are worth, what is owed, and what needs doing. Read-only. */
/** "cash ₹1,200 · UPI ₹800 · card ₹300": cash is handed over by the staff, UPI and card reach the bank on their own. */
function collectedAs(dash: PharmacyDash): string {
  const by = dash.collected_by_mode
  if (!by) return [dash.collected_cash ? `cash ${whole(dash.collected_cash)}` : '', dash.collected_card ? `card ${whole(dash.collected_card)}` : '', dash.collected_other ? `other ${whole(dash.collected_other)}` : ''].filter(Boolean).join(' · ')
  const names: Record<string, string> = { cash: 'cash', upi: 'UPI', card: 'card', cheque: 'cheque', bank_transfer: 'bank', insurance: 'insurance' }
  return Object.keys(names).filter((mode) => by[mode]).map((mode) => `${names[mode]} ${whole(by[mode])}`).join(' · ')
}

export function PharmacyBoard({ user, refreshKey }: { user: StaffUser; refreshKey: number }) {
  const options = useMemo(() => periods().filter((p) => p.key !== 'last'), [])
  const [period, setPeriod] = useState('today')
  const [dash, setDash] = useState<PharmacyDash | null>(null)
  const [desk, setDesk] = useState<PharmacyDesk | null>(null)
  const [audit, setAudit] = useState<StockAudit | null>(null)
  const [error, setError] = useState('')

  const range = options.find((p) => p.key === period) ?? options[0]
  useEffect(() => {
    setDash(null)
    setError('')
    api.get<PharmacyDash>('/pharmacy/dashboard', { from: range.from, to: range.to }).then(setDash).catch((e: ApiError) => setError(e.message))
  }, [range.from, range.to, refreshKey])

  // The shelves, the suppliers and the stock check do not depend on the period chosen.
  useEffect(() => {
    api.get<PharmacyDesk>('/pharmacy/manager').then(setDesk).catch(() => setDesk(null))
    if (user.can_audit_pharmacy) api.get<StockAudit>('/pharmacy/audit/overview').then(setAudit).catch(() => setAudit(null))
  }, [refreshKey, user.can_audit_pharmacy])

  const reorder = desk?.reorder ?? []
  const expiring = (desk?.expiring ?? []).filter((b) => b.days_left <= 30)
  const expired = desk?.expired ?? []
  const totals = desk?.payables?.totals
  const overdue = totals ? totals.d1_30 + totals.d31_60 + totals.d61_90 + totals.over_90 : 0

  return (
    <>
      <PeriodPicker options={options} value={period} onChange={setPeriod} />
      <Notice text={error} />
      {!dash && !error && <Loading />}

      {dash && (
        <>
          <Tiles>
            <Tile label="Bills" value={String(dash.sales)} note={range.label.toLowerCase()} />
            <Tile label="Billed" value={whole(dash.billed)} note="less returns" />
            <Tile label="Collected" value={whole(dash.collected)} tone="ok" note={collectedAs(dash) || undefined} />
            <Tile label="Stock on the shelves" value={whole(dash.statistics.inventory_cost)} note={`at cost · ${whole(dash.statistics.inventory_mrp)} at MRP`} />
          </Tiles>

          {desk && (
            <Card>
              <Text style={text.h2}>To do</Text>
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

          {totals && (
            <Card>
              <Text style={text.h2}>Owed to suppliers</Text>
              <Tiles>
                <Tile label="In all" value={whole(totals.owed)} />
                <Tile label="Past the due date" value={whole(overdue)} tone={overdue > 0 ? 'danger' : undefined} />
              </Tiles>
              {(desk?.payables?.suppliers ?? []).slice(0, 6).map((s) => (
                <Line key={s.stockist_id} label={s.stockist ?? 'Supplier'} sub={`${s.invoices} unpaid ${s.invoices === 1 ? 'invoice' : 'invoices'}`} value={whole(s.owed)} />
              ))}
            </Card>
          )}

          {desk && (
            <Card>
              <Text style={text.h2}>Running short</Text>
              {reorder.length === 0 && <Text style={text.body}>No medicine is at or below its minimum.</Text>}
              {reorder.slice(0, 8).map((m) => (
                <Line key={m.medicine_id} label={m.name} sub={CLASS[m.class] ?? m.class} value={`${m.usable} left`} tone={m.class === 'low' ? undefined : 'danger'} extra={m.days_cover !== null ? `${m.days_cover} days' stock` : undefined} />
              ))}
              {reorder.length > 8 && <Text style={text.muted}>And {reorder.length - 8} more. The full list and the suggested orders are on the web system, under Pharmacy → Manager.</Text>}
            </Card>
          )}

          {desk && (
            <Card>
              <Text style={text.h2}>Expiry</Text>
              {expired.length === 0 && expiring.length === 0 && <Text style={text.body}>Nothing has expired and nothing expires in the next 30 days.</Text>}
              {expired.length > 0 && (
                <Text style={[text.strong, { color: colors.danger }]}>
                  {expired.length} expired {expired.length === 1 ? 'batch' : 'batches'} still on the shelf, worth {whole(expired.reduce((sum, b) => sum + b.value, 0))}
                </Text>
              )}
              {expiring.slice(0, 8).map((b) => (
                <Line key={b.batch_id} label={b.medicine ?? 'Medicine'} sub={`Batch ${b.batch_no} · ${b.units} units`} value={b.days_left === 0 ? 'Today' : `${b.days_left} days`} tone="danger" extra={whole(b.value)} />
              ))}
              {expiring.length > 8 && <Text style={text.muted}>And {expiring.length - 8} more batches expire within 30 days.</Text>}
            </Card>
          )}

          {audit && (
            <Card>
              <Text style={text.h2}>Stock check</Text>
              <Line label="Stock lost this month" sub={`${audit.this_month.corrections} ${audit.this_month.corrections === 1 ? 'correction' : 'corrections'} to the count`} value={whole(audit.this_month.loss_value)} tone={audit.this_month.loss_value > 0 ? 'danger' : 'muted'} />
              <Line label="Of that, missing with no reason" value={whole(audit.this_month.unexplained_value)} tone={audit.this_month.unexplained_value > 0 ? 'danger' : 'muted'} />
              <Line label="Stock lost last month" value={whole(audit.last_month.loss_value)} tone="muted" />
              {audit.waiting.length > 0 && (
                <Text style={[text.body, { color: colors.danger }]}>
                  {audit.waiting.length} stock {audit.waiting.length === 1 ? 'count is' : 'counts are'} waiting for your approval on the web system.
                </Text>
              )}
              {audit.adrift.length > 0 && (
                <Text style={[text.body, { color: colors.danger }]}>
                  {audit.adrift.length} {audit.adrift.length === 1 ? 'batch does' : 'batches do'} not add up with the stock record. Look at Pharmacy → Manager → Stock audit.
                </Text>
              )}
            </Card>
          )}

          <Card>
            <Text style={text.h2}>Latest bills</Text>
            {dash.latest_sales.length === 0 && <Text style={text.body}>No pharmacy bill in this period.</Text>}
            {dash.latest_sales.map((s) => {
              const due = Math.round((Number(s.total) - Number(s.returned) - Number(s.paid) - Number(s.ipd_transfer)) * 100) / 100
              return (
                <Line
                  key={s.id}
                  label={s.customer_name}
                  sub={`${s.bill_no} · ${range.from === range.to ? niceTime(s.sold_at) : niceDate(s.sold_at).slice(0, -5)}`}
                  value={whole(Number(s.total))}
                  extra={due > 0 ? `${whole(due)} due` : Number(s.ipd_transfer) > 0 ? 'On IPD bill' : 'Paid'}
                  tone={due > 0 ? 'danger' : undefined}
                />
              )
            })}
          </Card>

          {dash.fast_moving.length > 0 && (
            <Card>
              <Text style={text.h2}>Sold most</Text>
              {dash.fast_moving.slice(0, 8).map((m) => (
                <Line key={m.medicine_name} label={m.medicine_name} value={`${Number(m.sold)} units`} tone="muted" />
              ))}
            </Card>
          )}
        </>
      )}
    </>
  )
}

const styles = StyleSheet.create({
  todo: { borderLeftWidth: 4, paddingLeft: space.md, paddingVertical: 4 },
})
