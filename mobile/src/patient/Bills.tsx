import { useEffect, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { api, ApiError, niceDate, rupees, type Bills } from '../api'
import { colors, space } from '../theme'
import { Card, Empty, Loading, Notice, text } from '../ui'

/** What one person has been billed at each counter, and what is still to be paid. */
export function PatientBills({ patientId }: { patientId: number }) {
  const [data, setData] = useState<Bills | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    setData(null)
    api.get<Bills>('/app/bills', { patient_id: String(patientId) }).then(setData).catch((e: ApiError) => setError(e.message))
  }, [patientId])

  if (error) return <Notice text={error} />
  if (!data) return <Loading />
  if (!data.verified) return <Empty text="Bills appear here once the front desk has verified your account." />

  return (
    <>
      <Card style={data.due > 0 ? styles.dueCard : undefined}>
        <Text style={text.muted}>To be paid</Text>
        <Text style={[styles.amount, data.due > 0 ? { color: colors.danger } : { color: colors.ok }]}>{data.due > 0 ? rupees(data.due) : 'Nothing due'}</Text>
        {data.due > 0 && <Text style={text.muted}>Pay at the hospital counter. Online payment is not available yet.</Text>}
      </Card>
      {data.bills.length === 0 && <Empty text="No bills yet." />}
      {data.bills.map((b) => (
        <Card key={`${b.kind}${b.bill_no}`}>
          <View style={styles.row}>
            <View style={{ flex: 1 }}>
              <Text style={text.strong}>{b.kind}</Text>
              <Text style={text.muted}>
                {niceDate(b.date)} · {b.bill_no}
              </Text>
            </View>
            <View style={{ alignItems: 'flex-end' }}>
              <Text style={text.strong}>{rupees(b.total)}</Text>
              <Text style={[text.muted, { color: b.due > 0 ? colors.danger : colors.ok, fontWeight: '700' }]}>{b.due > 0 ? `${rupees(b.due)} due` : b.total > 0 ? 'Paid' : 'No charge'}</Text>
            </View>
          </View>
        </Card>
      ))}
    </>
  )
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md },
  amount: { fontSize: 28, fontWeight: '800' },
  dueCard: { borderColor: colors.danger },
})
