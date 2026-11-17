import { useEffect, useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate, niceTime, type AbnormalOrder } from '../api'
import { colors, space } from '../theme'
import { Card, Empty, Loading, Notice, text } from '../ui'
import { type PatientRef } from './PatientView'

/** Lab results outside the normal range that came back in the last week, for this doctor's patients. */
export function AbnormalResults({ onOpen }: { onOpen: (patient: PatientRef) => void }) {
  const [orders, setOrders] = useState<AbnormalOrder[] | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api.get<AbnormalOrder[]>('/doctor/abnormal-results').then(setOrders).catch((e: ApiError) => setError(e.message))
  }, [])

  if (error) return <Notice text={error} />
  if (!orders) return <Loading />
  if (orders.length === 0) return <Empty text={'No result outside the normal range has come back in the last 7 days for your patients.'} />

  return (
    <>
      <Text style={text.muted}>Results outside the normal range, reported in the last 7 days. Tap a patient to see their history.</Text>
      {orders.map((o) => (
        <Pressable key={o.order_id} onPress={() => onOpen(o.patient)} accessibilityRole="button">
          <Card>
            <Text style={text.strong}>
              {o.patient.title ? `${o.patient.title} ` : ''}
              {o.patient.name}
            </Text>
            <Text style={text.muted}>
              {o.patient.age_label} / {o.patient.gender.charAt(0).toUpperCase()} · {o.patient.uhid} · {niceDate(o.reported_at)} {niceTime(o.reported_at)}
            </Text>
            {o.results.map((r, i) => (
              <View key={i} style={styles.result}>
                <View style={{ flex: 1 }}>
                  <Text style={text.body}>{r.parameter}</Text>
                  <Text style={text.muted}>
                    {r.test}
                    {r.range ? ` · normal ${r.range}` : ''}
                  </Text>
                </View>
                <Text style={[text.strong, { color: colors.danger }]}>
                  {r.value} {r.unit ?? ''} {r.flag === 'high' ? '▲' : '▼'}
                </Text>
              </View>
            ))}
          </Card>
        </Pressable>
      ))}
    </>
  )
}

const styles = StyleSheet.create({
  result: { flexDirection: 'row', alignItems: 'center', gap: space.md, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm },
})
