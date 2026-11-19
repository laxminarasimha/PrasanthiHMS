import { useEffect, useState } from 'react'
import { Text, View } from 'react-native'
import { addDays, api, ApiError, niceDate, todayIso, type IpdCase, type WardBed } from '../../api'
import { colors } from '../../theme'
import { Card, Empty, Loading, Notice, text } from '../../ui'
import type { PatientRef } from '../PatientView'
import { Line, Meter, Tile, Tiles, whole } from './parts'

const LEFT: Record<string, string> = { discharged: 'Discharged', lama: 'Left against advice', dama: 'Discharged against advice', absconded: 'Absconded', died: 'Died' }
const dayOfStay = (admittedAt: string) => Math.max(1, Math.ceil((Date.now() - new Date(admittedAt.replace(' ', 'T')).getTime()) / 86400000))
const fullName = (p: { title?: string | null; name: string }) => `${p.title ? `${p.title} ` : ''}${p.name}`

/** The wards as the owner wants to see them: how full, who is in, and what each bill has come to. Read-only. */
export function IpdBoard({ refreshKey, onOpen }: { refreshKey: number; onOpen: (patient: PatientRef, admissionId: number, subtitle: string) => void }) {
  const [cases, setCases] = useState<IpdCase[] | null>(null)
  const [beds, setBeds] = useState<WardBed[]>([])
  const [left, setLeft] = useState<IpdCase[]>([])
  const [error, setError] = useState('')

  useEffect(() => {
    setError('')
    api.get<IpdCase[]>('/ipd/admissions', { status: 'admitted' }).then(setCases).catch((e: ApiError) => setError(e.message))
    api.get<WardBed[]>('/ipd/beds').then(setBeds).catch(() => setBeds([]))
    api.get<IpdCase[]>('/ipd/admissions', { status: 'all', left_from: addDays(todayIso(), -7) }).then((rows) => setLeft(rows.filter((c) => c.status !== 'admitted'))).catch(() => setLeft([]))
  }, [refreshKey])

  if (error) return <Notice text={error} />
  if (!cases) return <Loading />

  const inUse = beds.filter((b) => b.is_active)
  const wards = [...new Set(inUse.map((b) => b.ward))].map((ward) => {
    const mine = inUse.filter((b) => b.ward === ward)
    return { ward, beds: mine.length, taken: mine.filter((b) => b.current_admission).length }
  })
  const taken = inUse.filter((b) => b.current_admission).length
  const owed = cases.reduce((sum, c) => sum + Math.max(0, Number(c.due)), 0)
  const advance = cases.reduce((sum, c) => sum + Math.max(0, -Number(c.due)), 0)
  const billed = cases.reduce((sum, c) => sum + Number(c.net), 0)
  // The bills that most need attention come first.
  const sorted = [...cases].sort((a, b) => Number(b.due) - Number(a.due))

  return (
    <>
      <Tiles>
        <Tile label="Patients admitted" value={String(cases.length)} note={cases.length === 1 ? 'in a bed now' : 'in beds now'} />
        <Tile label="Beds free" value={String(Math.max(0, inUse.length - taken))} note={`of ${inUse.length} beds`} />
        <Tile label="Owed by inpatients" value={whole(owed)} tone={owed > 0 ? 'danger' : undefined} note={`bills so far ${whole(billed)}`} />
        <Tile label="Advance held" value={whole(advance)} tone={advance > 0 ? 'ok' : undefined} note="paid ahead of the bill" />
      </Tiles>

      {wards.length > 0 && (
        <Card>
          <Text style={text.h2}>Wards</Text>
          {wards.map((w) => (
            <Meter key={w.ward} label={w.ward} used={w.taken} of={w.beds} />
          ))}
        </Card>
      )}

      <Card>
        <Text style={text.h2}>Admitted now</Text>
        {cases.length === 0 && <Empty text="No patient is admitted right now." />}
        {sorted.map((c) => {
          const due = Number(c.due)
          return (
            <Line
              key={c.id}
              label={fullName(c.patient)}
              sub={`${c.bed ? `${c.bed.ward}, bed ${c.bed.bed_no}` : 'No bed'} · Day ${dayOfStay(c.admitted_at)} · ${c.doctor.name}`}
              value={due > 0 ? `${whole(due)} due` : due < 0 ? `${whole(-due)} ahead` : 'Settled'}
              tone={due > 0 ? 'danger' : due < 0 ? 'ok' : 'muted'}
              extra={`Bill ${whole(Number(c.net))}`}
              onPress={() => onOpen(c.patient, c.id, c.ipd_no)}
            />
          )
        })}
        {cases.length > 0 && <Text style={text.muted}>Touch a patient to see the notes, vitals and reports. Bills are changed on the web system.</Text>}
      </Card>

      <Card>
        <Text style={text.h2}>Left in the last 7 days</Text>
        {left.length === 0 && <Text style={text.body}>Nobody was discharged in the last week.</Text>}
        {left.map((c) => {
          const due = Number(c.due)
          return (
            <Line
              key={c.id}
              label={fullName(c.patient)}
              sub={`${LEFT[c.status] ?? c.status}${c.discharged_at ? ` · ${niceDate(c.discharged_at).slice(0, -5)}` : ''} · ${c.doctor.name}`}
              value={due > 0 ? `${whole(due)} due` : 'Settled'}
              tone={due > 0 ? 'danger' : 'muted'}
              extra={`Bill ${whole(Number(c.net))}`}
              onPress={() => onOpen(c.patient, c.id, c.ipd_no)}
            />
          )
        })}
        {left.some((c) => Number(c.due) > 0) && (
          <View>
            <Text style={[text.muted, { color: colors.danger }]}>Money is still owed on a case that has already been closed.</Text>
          </View>
        )}
      </Card>
    </>
  )
}
