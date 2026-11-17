import { useEffect, useState } from 'react'
import { Pressable, Text } from 'react-native'
import { api, ApiError, type FoundPatient } from '../api'
import { Card, Empty, Field, Loading, Notice, text } from '../ui'
import { type PatientRef } from './PatientView'

/** Any patient of the hospital, found by name, mobile number or patient ID. */
export function FindPatient({ onOpen }: { onOpen: (patient: PatientRef) => void }) {
  const [term, setTerm] = useState('')
  const [found, setFound] = useState<FoundPatient[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  // Wait for a pause in typing, and let only the latest search answer.
  useEffect(() => {
    const q = term.trim()
    if (q.length < 2) {
      setFound(null)
      return
    }
    let current = true
    setBusy(true)
    const timer = setTimeout(() => {
      api
        .get<FoundPatient[] | { data: FoundPatient[] }>('/patients', { q })
        .then((res) => current && (setFound(Array.isArray(res) ? res : res.data), setError('')))
        .catch((e: ApiError) => current && setError(e.message))
        .finally(() => current && setBusy(false))
    }, 350)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [term])

  return (
    <>
      <Field label="Find a patient" value={term} onChangeText={setTerm} placeholder="Name, mobile number or patient ID" autoCapitalize="none" autoCorrect={false} returnKeyType="search" />
      <Notice text={error} />
      {busy && !found && <Loading />}
      {term.trim().length < 2 && <Empty text="Type at least two letters or digits." />}
      {found?.length === 0 && <Empty text="No patient matches that." />}
      {found?.slice(0, 40).map((p) => (
        <Pressable key={p.id} onPress={() => onOpen(p)} accessibilityRole="button">
          <Card>
            <Text style={text.strong}>
              {p.title ? `${p.title} ` : ''}
              {p.name}
            </Text>
            <Text style={text.muted}>
              {p.age_label} / {p.gender.charAt(0).toUpperCase()} · {p.uhid}
              {p.mobile ? ` · ${p.mobile}` : ''}
            </Text>
          </Card>
        </Pressable>
      ))}
    </>
  )
}
