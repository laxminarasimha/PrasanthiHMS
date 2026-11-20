import { useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate } from '../api'
import { colors, space } from '../theme'
import { Card, Empty, Loading, Notice, text } from '../ui'

type Point = { date: string; value: number; low?: number; flag?: string | null }
type Series = { key?: string; name?: string; label?: string; unit: string | null; range?: string | null; points: Point[] }
type Health = { verified: boolean; vitals: Series[]; lab: Series[] }

/** 7.20 → "7.2", 150 → "150". */
const num = (n: number) => String(Math.round(n * 100) / 100)
const reading = (p: Point) => (p.low !== undefined ? `${num(p.value)}/${num(p.low)}` : num(p.value))

/**
 * A person's readings over time: blood pressure, weight and the other vitals from their visits, and every lab value
 * measured more than once. Each shows the latest reading, how it moved since the one before, and the last few as bars.
 */
export function MyHealth({ patientId }: { patientId: number }) {
  const [data, setData] = useState<{ for: number; health: Health } | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    let current = true
    api
      .get<Health>('/app/health', { patient_id: String(patientId) })
      .then((health) => current && setData({ for: patientId, health }))
      .catch((e: ApiError) => current && setError(e.message))
    return () => {
      current = false
    }
  }, [patientId])
  const health = data?.for === patientId ? data.health : null

  if (error) return <Notice text={error} />
  if (!health) return <Loading />
  if (!health.verified) return <Empty text="Your readings appear here once the hospital front desk has verified your account." />
  if (health.vitals.length === 0 && health.lab.length === 0) return <Empty text="Nothing to show yet. Blood pressure, weight and lab values appear here as they are recorded at your visits." />

  return (
    <>
      {health.vitals.length > 0 && <Text style={styles.section}>From your visits</Text>}
      {health.vitals.map((s) => (
        <Trend key={s.key} title={s.label ?? ''} series={s} />
      ))}
      {health.lab.length > 0 && <Text style={styles.section}>From your lab reports</Text>}
      {health.lab.map((s) => (
        <Trend key={s.name} title={s.name ?? ''} series={s} />
      ))}
      <Text style={text.muted}>These are the readings as recorded at the hospital. Ask your doctor what they mean for you.</Text>
    </>
  )
}

function Trend({ title, series }: { title: string; series: Series }) {
  const shown = series.points.slice(-8)
  const last = shown[shown.length - 1]
  const before = shown.length > 1 ? shown[shown.length - 2] : null
  // Bars stand on zero, so a small change looks small: 84 kg and 82.5 kg are nearly the same height.
  const top = Math.max(...shown.map((p) => p.value), 0) || 1
  const change = before ? last.value - before.value : 0
  const flagged = last.flag === 'high' || last.flag === 'low'

  return (
    <Card>
      <View style={styles.head}>
        <View style={{ flex: 1 }}>
          <Text style={text.strong}>{title}</Text>
          <Text style={text.muted}>
            {niceDate(last.date)}
            {series.range ? ` · usual range ${series.range}` : ''}
          </Text>
        </View>
        <View style={{ alignItems: 'flex-end' }}>
          <Text style={[styles.value, flagged && { color: colors.danger }]}>
            {reading(last)}
            {series.unit ? <Text style={text.muted}> {series.unit}</Text> : null}
          </Text>
          {before && (
            <Text style={text.muted}>
              {change === 0 ? 'Same as' : change > 0 ? `▲ ${num(change)} from` : `▼ ${num(-change)} from`} {reading(before)} last time
            </Text>
          )}
          {flagged && <Text style={[text.muted, { color: colors.danger }]}>{last.flag === 'high' ? 'Above' : 'Below'} the usual range</Text>}
        </View>
      </View>
      {shown.length > 1 && (
        <View style={styles.bars} accessibilityLabel={`${title}: ${shown.map((p) => `${reading(p)} on ${niceDate(p.date)}`).join(', ')}`}>
          {shown.map((p, i) => (
            <View key={`${p.date}${i}`} style={styles.barCol}>
              <View style={styles.barBox}>
                <View
                  style={[
                    styles.bar,
                    { height: `${Math.max(3, (p.value / top) * 100)}%` },
                    (p.flag === 'high' || p.flag === 'low') && { backgroundColor: colors.danger },
                    i === shown.length - 1 && { opacity: 1 },
                  ]}
                />
              </View>
              <Text style={styles.barLabel}>{reading(p)}</Text>
            </View>
          ))}
        </View>
      )}
    </Card>
  )
}

const styles = StyleSheet.create({
  section: { fontSize: 12, fontWeight: '700', color: colors.muted, textTransform: 'uppercase', letterSpacing: 0.4, marginTop: space.sm },
  head: { flexDirection: 'row', gap: space.md, alignItems: 'flex-start' },
  value: { fontSize: 22, fontWeight: '700', color: colors.text },
  bars: { flexDirection: 'row', alignItems: 'flex-end', gap: 6, height: 96, marginTop: space.md },
  barCol: { flex: 1, alignItems: 'center', height: '100%' },
  barBox: { flex: 1, width: '100%', justifyContent: 'flex-end' },
  bar: { width: '100%', borderRadius: 4, backgroundColor: colors.brand, opacity: 0.55 },
  barLabel: { fontSize: 10, color: colors.muted, marginTop: 3 },
})
