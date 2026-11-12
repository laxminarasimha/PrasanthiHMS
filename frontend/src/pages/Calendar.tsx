import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { AppointmentForm } from '../components/AppointmentForm'
import { BillPanel } from '../components/BillPanel'
import { SelectField, TextField } from '../components/Field'
import { EditAppointmentPanel } from '../components/FrontDeskPanels'
import { STATUS_LABELS, clock, today } from '../format'
import type { Appointment, CalendarSettings, Doctor, Service } from '../types'

type View = 'day' | 'week'
type Column = { key: string; title: string; sub?: string; date: string; doctorId: string; today: boolean }
type Panel =
  | { kind: 'new'; date: string; time: string; doctorId: string }
  | { kind: 'edit'; appointment: Appointment }
  | { kind: 'bill'; billId: number }
  | { kind: 'hours' }
  | null

const pad = (n: number) => String(n).padStart(2, '0')
const minutes = (time: string) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5))
const timeOf = (mins: number) => `${pad(Math.floor(mins / 60))}:${pad(mins % 60)}`
const label = (time: string) => clock(`0000-00-00 ${time}`)
const dateOf = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const addDays = (date: string, days: number) => {
  const d = new Date(date + 'T00:00:00')
  d.setDate(d.getDate() + days)
  return dateOf(d)
}
/** The Monday of the week a date falls in. */
const weekStart = (date: string) => addDays(date, -((new Date(date + 'T00:00:00').getDay() + 6) % 7))
const dayTitle = (date: string) => new Date(date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })

const SLOT_LENGTHS = [5, 10, 15, 20, 30, 60].map((m) => ({ value: String(m), label: m === 60 ? '1 hour' : `${m} minutes` }))

/** OPD hours and slot length; changing them only redraws the calendar, appointments stay where they are. */
function HoursPanel({ settings, onClose, onSaved }: { settings: CalendarSettings; onClose: () => void; onSaved: (s: CalendarSettings) => void }) {
  const [form, setForm] = useState(settings)
  const [errors, setErrors] = useState<FieldErrors>({})
  const time = (key: 'morning_start' | 'morning_end' | 'evening_start' | 'evening_end') => ({
    type: 'time',
    required: true,
    value: form[key],
    onChange: (value: string) => setForm({ ...form, [key]: value }),
    error: errors[key],
  })

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      onSaved((await api.put<CalendarSettings>('/appointments/calendar-settings', form)).data)
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }

  return (
    <form className="card panel" onSubmit={save} noValidate>
      <div className="panel-head">
        <h2>OPD hours</h2>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>
      <div className="grid">
        <TextField label="Morning from" {...time('morning_start')} />
        <TextField label="Morning to" {...time('morning_end')} />
        <TextField label="Evening from" {...time('evening_start')} />
        <TextField label="Evening to" {...time('evening_end')} />
        <SelectField label="Slot length" value={String(form.slot_minutes)} onChange={(v) => setForm({ ...form, slot_minutes: Number(v) })} options={SLOT_LENGTHS} error={errors.slot_minutes} />
      </div>
      <p className="muted small">Appointments already booked are not moved. Any that fall outside these hours still show on the calendar.</p>
      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary">Save hours</button>
      </div>
    </form>
  )
}

export function Calendar() {
  const { user } = useAuth()
  const [view, setView] = useState<View>('day')
  const [date, setDate] = useState(today())
  const [doctorId, setDoctorId] = useState('')
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [services, setServices] = useState<Service[]>([])
  const [settings, setSettings] = useState<CalendarSettings | null>(null)
  const [appointments, setAppointments] = useState<Appointment[]>([])
  const [panel, setPanel] = useState<Panel>(null)
  const [notice, setNotice] = useState('')

  const from = view === 'week' ? weekStart(date) : date

  const load = useCallback(() => {
    api
      .get<{ settings: CalendarSettings; data: Appointment[] }>('/appointments/calendar', { params: { from, days: view === 'week' ? 7 : 1, doctor_id: doctorId || undefined } })
      .then((res) => {
        setSettings(res.data.settings)
        setAppointments(res.data.data)
      })
  }, [from, view, doctorId])

  useEffect(load, [load])
  useEffect(() => {
    api.get<Doctor[]>('/doctors').then((res) => setDoctors(res.data))
    api.get<Service[]>('/services').then((res) => setServices(res.data))
  }, [])

  const columns: Column[] = useMemo(() => {
    if (view === 'week') {
      return Array.from({ length: 7 }, (_, i) => addDays(from, i)).map((d) => ({ key: d, title: dayTitle(d), date: d, doctorId, today: d === today() }))
    }
    return doctors
      .filter((d) => !doctorId || String(d.id) === doctorId)
      .map((d) => ({ key: String(d.id), title: d.name, sub: d.department ?? undefined, date, doctorId: String(d.id), today: false }))
  }, [view, from, date, doctors, doctorId])

  // Each appointment sits in the slot its time falls in, under its day (week) or its doctor (day).
  const { cells, rows, counts } = useMemo(() => {
    const cells = new Map<string, Appointment[]>()
    const counts = new Map<string, number>()
    if (!settings) return { cells, counts, rows: [] as { session?: string; slot?: string }[] }
    const step = settings.slot_minutes
    const slotOf = (a: Appointment) => timeOf(Math.floor(minutes(a.scheduled_at.slice(11, 16)) / step) * step)
    const used = new Set<string>()
    for (const a of appointments) {
      const column = view === 'week' ? a.scheduled_at.slice(0, 10) : String(a.doctor.id)
      const slot = slotOf(a)
      used.add(slot)
      cells.set(`${column}|${slot}`, [...(cells.get(`${column}|${slot}`) ?? []), a])
      if (a.status !== 'cancelled') counts.set(column, (counts.get(column) ?? 0) + 1)
    }

    const range = (start: string, end: string) => {
      const out: string[] = []
      for (let m = minutes(start); m < minutes(end); m += step) out.push(timeOf(m))
      return out
    }
    const morning = range(settings.morning_start, settings.morning_end)
    const evening = range(settings.evening_start, settings.evening_end)
    const inHours = new Set([...morning, ...evening])
    const extra = [...used].filter((s) => !inHours.has(s)).sort()
    const before = extra.filter((s) => s < settings.morning_start)
    const between = extra.filter((s) => s >= settings.morning_end && s < settings.evening_start)
    const after = extra.filter((s) => s >= settings.evening_end)

    const rows: { session?: string; slot?: string }[] = []
    const add = (session: string, slots: string[]) => {
      if (!slots.length) return
      rows.push({ session })
      slots.forEach((slot) => rows.push({ slot }))
    }
    add('Before OPD hours', before)
    add(`Morning OPD · ${label(settings.morning_start)} – ${label(settings.morning_end)}`, morning)
    add('Between sessions', between)
    add(`Evening OPD · ${label(settings.evening_start)} – ${label(settings.evening_end)}`, evening)
    add('After OPD hours', after)
    return { cells, rows, counts }
  }, [appointments, settings, view])

  const move = (days: number) => setDate(addDays(date, days * (view === 'week' ? 7 : 1)))
  const openSlot = (column: Column, slot: string) => {
    setNotice('')
    setPanel({ kind: 'new', date: column.date, time: slot, doctorId: column.doctorId })
  }
  const nowSlot = settings ? timeOf(Math.floor((new Date().getHours() * 60 + new Date().getMinutes()) / settings.slot_minutes) * settings.slot_minutes) : ''
  const heading = view === 'week' ? `${dayTitle(from)} – ${dayTitle(addDays(from, 6))}` : dayTitle(date)

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Calendar</h1>
          <div className="tabs flush">
            <button className={view === 'day' ? 'active' : ''} onClick={() => setView('day')}>
              Day
            </button>
            <button className={view === 'week' ? 'active' : ''} onClick={() => setView('week')}>
              Week
            </button>
          </div>
          <div className="row">
            <button className="btn ghost small-btn" onClick={() => move(-1)} aria-label="Earlier">
              ‹
            </button>
            <button className="btn ghost small-btn" onClick={() => setDate(today())}>
              Today
            </button>
            <button className="btn ghost small-btn" onClick={() => move(1)} aria-label="Later">
              ›
            </button>
          </div>
          <input className="date" type="date" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} />
          <select className="narrow" value={doctorId} onChange={(e) => setDoctorId(e.target.value)} aria-label="Doctor">
            <option value="">All doctors</option>
            {doctors.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </select>
          <span className="grow" />
          {user?.can_manage_setup && (
            <button className="btn ghost" onClick={() => setPanel({ kind: 'hours' })}>
              OPD hours
            </button>
          )}
          <Link className="btn ghost" to="/front-desk">
            Day list
          </Link>
        </div>

        <p className="cal-heading">
          <strong>{heading}</strong>
          <span className="muted"> · {appointments.filter((a) => a.status !== 'cancelled').length} appointments. Tap an empty slot to book; tap a name to change or move it.</span>
        </p>
        {notice && <p className="notice">{notice}</p>}

        {!settings ? (
          <p className="loading">Loading…</p>
        ) : columns.length === 0 ? (
          <p className="loading">No doctors have been added yet. Add them under Staff.</p>
        ) : (
          <div className="table-wrap cal">
            <table>
              <thead>
                <tr>
                  <th className="cal-time">Time</th>
                  {columns.map((c) => (
                    <th key={c.key} className={c.today ? 'today' : ''}>
                      {c.title}
                      <small>
                        {c.sub ? `${c.sub} · ` : ''}
                        {counts.get(c.key) ?? 0} booked
                      </small>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((row) =>
                  row.session ? (
                    <tr key={row.session} className="session">
                      <td colSpan={columns.length + 1}>{row.session}</td>
                    </tr>
                  ) : (
                    <tr key={row.slot}>
                      <td className="cal-time">{label(row.slot!)}</td>
                      {columns.map((c) => (
                        <td key={c.key} className={`slot ${c.date === today() && row.slot === nowSlot ? 'now' : ''}`} onClick={() => openSlot(c, row.slot!)}>
                          {(cells.get(`${c.key}|${row.slot}`) ?? []).map((a) => (
                            <button
                              key={a.id}
                              className={`cal-chip ${a.status}`}
                              title={`${clock(a.scheduled_at)} · ${a.patient.name} · ${STATUS_LABELS[a.status]}`}
                              onClick={(e) => {
                                e.stopPropagation()
                                setNotice('')
                                setPanel({ kind: 'edit', appointment: a })
                              }}
                            >
                              <b>{a.token_no ?? '–'}</b> {a.patient.name}
                              {view === 'week' && !doctorId && <small>{a.doctor.name}</small>}
                            </button>
                          ))}
                        </td>
                      ))}
                    </tr>
                  ),
                )}
              </tbody>
            </table>
          </div>
        )}
        <p className="muted small legend">
          <span className="cal-chip booked">Booked</span> <span className="cal-chip arrived">Arrived</span> <span className="cal-chip ongoing">Ongoing</span> <span className="cal-chip reviewed">Reviewed</span>{' '}
          <span className="cal-chip cancelled">Cancelled</span>
        </p>
      </section>

      {panel?.kind === 'new' && (
        <AppointmentForm
          key={`${panel.date} ${panel.time} ${panel.doctorId}`}
          date={panel.date}
          time={panel.time}
          doctorId={panel.doctorId}
          // A slot that has not started yet is a booking; an earlier one is a patient who is already here.
          status={`${panel.date} ${panel.time}` > `${today()} ${nowSlot}` ? 'booked' : 'arrived'}
          doctors={doctors}
          services={services}
          onClose={() => setPanel(null)}
          onBooked={(a) => {
            setNotice(`Appointment saved for ${a.patient.name} on ${dayTitle(a.scheduled_at.slice(0, 10))} at ${clock(a.scheduled_at)}, token ${a.token_no}.`)
            const unpaid = a.bills.find((b) => b.due > 0)
            setPanel(unpaid ? { kind: 'bill', billId: unpaid.id } : null)
            load()
          }}
        />
      )}
      {panel?.kind === 'edit' && (
        <EditAppointmentPanel
          key={panel.appointment.id}
          appointment={panel.appointment}
          doctors={doctors}
          services={services}
          onClose={() => setPanel(null)}
          onSaved={() => {
            setNotice('Appointment updated.')
            setPanel(null)
            load()
          }}
        />
      )}
      {panel?.kind === 'bill' && <BillPanel key={panel.billId} billId={panel.billId} services={services} onClose={() => setPanel(null)} onChange={load} onAnotherBill={() => setPanel(null)} />}
      {panel?.kind === 'hours' && settings && (
        <HoursPanel
          settings={settings}
          onClose={() => setPanel(null)}
          onSaved={(s) => {
            setSettings(s)
            setNotice('OPD hours saved.')
            setPanel(null)
          }}
        />
      )}
    </div>
  )
}
