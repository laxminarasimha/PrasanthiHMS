import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { AppointmentForm } from '../components/AppointmentForm'
import { BillPanel } from '../components/BillPanel'
import { ChargePanel } from '../components/ChargePanel'
import { AttachmentsPanel, EditAppointmentPanel, SummaryPanel, VitalsPanel } from '../components/FrontDeskPanels'
import { PatientPicker } from '../components/PatientPicker'
import { STATUS_LABELS, clock, longDate, rupees, settlement, stampTime, today } from '../format'
import type { Appointment, AppointmentStatus, Doctor, FollowUp, LabCatalogueTest, LabPackage, Patient, Service, WalkInBill } from '../types'
import { PatientLink } from '../components/PatientLink'

type DayList = { data: Appointment[]; counts: Partial<Record<AppointmentStatus, number>> }
type Department = 'all' | 'appointment' | 'lab' | 'other'
type Panel =
  | { kind: 'new'; preset?: { patient: Appointment['patient']; doctorId: string; date: string } }
  | { kind: 'followups' }
  | { kind: 'charge'; patient: { id: number; name: string; uhid: string }; appointmentId?: number; doctor?: string; advised?: string }
  | { kind: 'bill'; billId: number }
  | { kind: 'walkin' }
  | { kind: 'vitals' | 'attachments' | 'edit'; appointment: Appointment }
  | { kind: 'summary' }
  | null

const STATUSES = Object.keys(STATUS_LABELS) as AppointmentStatus[]
const DEPARTMENTS: [Department, string][] = [['all', 'All'], ['appointment', 'Appointments'], ['lab', 'Lab'], ['other', 'Others']]

/** A bill amount as the desk reads it at a glance: green when settled, red when money is owed. */
function BillChip({ label, total, due, movedToIpd, onOpen }: { label?: string; total: number; due: number; movedToIpd?: number | null; onOpen: () => void }) {
  return (
    <button className={`bill-chip ${due > 0 ? 'unpaid' : 'paid'}`} onClick={onOpen} title={settlement(due, movedToIpd, total)}>
      {label && <span>{label}</span>}
      {rupees(total)}
    </button>
  )
}

export function FrontDesk() {
  const { user } = useAuth()
  const [date, setDate] = useState(today())
  const [status, setStatus] = useState<AppointmentStatus | ''>('')
  const [department, setDepartment] = useState<Department>('all')
  const [doctorId, setDoctorId] = useState('')
  const [query, setQuery] = useState('')
  const [list, setList] = useState<DayList | null>(null)
  const [walkIns, setWalkIns] = useState<WalkInBill[]>([])
  const [followUps, setFollowUps] = useState<FollowUp[]>([])
  const [labTests, setLabTests] = useState<LabCatalogueTest[]>([])
  const [labPackages, setLabPackages] = useState<LabPackage[]>([])
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [services, setServices] = useState<Service[]>([])
  // Coming from a patient's page with "book" or "bill", the matching panel opens for that patient straight away.
  const location = useLocation()
  const navigate = useNavigate()
  const asked = location.state as { book?: Appointment['patient']; bill?: { id: number; name: string; uhid: string } } | null
  const [panel, setPanel] = useState<Panel>(() => (asked?.book ? { kind: 'new', preset: { patient: asked.book, doctorId: '', date: today() } } : asked?.bill ? { kind: 'charge', patient: asked.bill } : null))
  useEffect(() => {
    // Asked once: a refresh or coming back does not open the panel again.
    if (asked) navigate(location.pathname, { replace: true, state: null })
  }, [asked, navigate, location.pathname])
  const [menu, setMenu] = useState<number | null>(null)
  const [menuAt, setMenuAt] = useState({ top: 0, left: 0 })
  const [notice, setNotice] = useState('')
  const [walkInPatient, setWalkInPatient] = useState<Patient | null>(null)
  // Show only the people who still owe money.
  const [duesOnly, setDuesOnly] = useState(false)
  // The time the list was last fetched: waiting times are counted up to it.
  const [asOf, setAsOf] = useState(0)
  const filterBox = useRef<HTMLInputElement>(null)

  const load = useCallback(() => {
    api
      .get<DayList>('/appointments', { params: { date, status: status || undefined, doctor_id: doctorId || undefined, q: query || undefined } })
      .then((res) => {
        setList(res.data)
        setAsOf(Date.now())
      })
    api.get<WalkInBill[]>('/front-desk/bills', { params: { date, q: query || undefined } }).then((res) => setWalkIns(res.data))
    api.get<FollowUp[]>('/front-desk/follow-ups', { params: { date } }).then((res) => setFollowUps(res.data))
  }, [date, status, doctorId, query])

  useEffect(() => {
    const timer = setTimeout(load, 200)
    // Keep in step with the doctor's room: statuses and advice change there, not here.
    const refresh = setInterval(load, 30000)
    return () => {
      clearTimeout(timer)
      clearInterval(refresh)
    }
  }, [load])

  useEffect(() => {
    api.get<Doctor[]>('/doctors').then((res) => setDoctors(res.data))
    api.get<Service[]>('/services').then((res) => setServices(res.data))
    api.get<LabCatalogueTest[]>('/lab/tests', { params: { brief: 1 } }).then((res) => setLabTests(res.data))
    api.get<LabPackage[]>('/lab/packages').then((res) => setLabPackages(res.data))
  }, [])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof Element && e.target.closest('input, textarea, select, [contenteditable]')
      if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) {
        e.preventDefault()
        filterBox.current?.focus()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  // The menu floats over the page, clear of the table's scrolling edge. It opens upward when
  // there is no room below, and closes as soon as the page moves or something else is clicked.
  const toggleMenu = (id: number, button: HTMLElement) => {
    if (menu === id) return setMenu(null)
    const box = button.getBoundingClientRect()
    const height = 250
    setMenuAt({ top: box.bottom + height > window.innerHeight ? Math.max(8, box.top - height) : box.bottom + 4, left: Math.max(8, box.right - 220) })
    setMenu(id)
  }

  useEffect(() => {
    if (menu === null) return
    const close = (e: Event) => {
      if (e.type === 'click' && (e.target as HTMLElement).closest('.menu, .menu-button')) return
      setMenu(null)
    }
    window.addEventListener('click', close)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    return () => {
      window.removeEventListener('click', close)
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
    }
  }, [menu])

  const open = (next: Panel) => {
    setNotice('')
    setMenu(null)
    setPanel(next)
  }

  const changeStatus = async (appointment: Appointment, next: AppointmentStatus) => {
    await api.put(`/appointments/${appointment.id}`, { status: next })
    load()
  }

  // Billing for a visit: services and lab tests chosen in one place. With `advised`, the doctor's tests are put in ready.
  const charge = (a: Appointment, advised?: string) => open({ kind: 'charge', patient: a.patient, appointmentId: a.id, doctor: a.doctor.name, advised })

  const total = list ? Object.values(list.counts).reduce((sum, n) => sum + (n ?? 0), 0) : 0
  // One patient, one row. A bill made without a visit (a lab order raised in the lab, or "+ New bill") joins the
  // patient's visit of the day when there is one, so the consultation and the tests sit side by side.
  const dayVisits = list?.data ?? []
  const joined = new Map<number, WalkInBill[]>()
  const loose = new Map<number, WalkInBill[]>()
  const inDepartment = (b: { kind: string }) => department === 'all' || b.kind === department
  for (const bill of walkIns) {
    const visit = dayVisits.find((a) => a.patient.id === bill.patient.id && a.status !== 'cancelled')
    if (visit) joined.set(visit.id, [...(joined.get(visit.id) ?? []), bill])
    // Someone with no visit today still gets one row for all their bills. Those have no visit status or doctor,
    // so they are hidden while a status tab or doctor filter is on.
    else if (!status && !doctorId && inDepartment(bill)) loose.set(bill.patient.id, [...(loose.get(bill.patient.id) ?? []), bill])
  }
  const billsOf = (a: Appointment) => [...a.bills, ...(joined.get(a.id) ?? [])]
  const visits = dayVisits.filter((a) => department === 'all' || billsOf(a).some(inDepartment) || (department === 'appointment' && a.bills.length === 0))
  const walkInRows = [...loose.values()]

  // Money still to collect on this day's list, and a way to see only those people.
  const owes = (bills: { due: number }[]) => bills.reduce((sum, b) => sum + b.due, 0)
  const owingVisits = visits.filter((a) => owes(billsOf(a)) > 0)
  const owingWalkIns = walkInRows.filter((bills) => owes(bills) > 0)
  const owing = owingVisits.length + owingWalkIns.length
  const owed = owingVisits.reduce((sum, a) => sum + owes(billsOf(a)), 0) + owingWalkIns.reduce((sum, bills) => sum + owes(bills), 0)
  const showDues = duesOnly && owing > 0
  const shownVisits = showDues ? owingVisits : visits
  const shownWalkIns = showDues ? owingWalkIns : walkInRows
  const chipLabel = (b: { kind: string; lab_order: { order_no: string } | null }) => (b.lab_order ? `Lab ${b.lab_order.order_no}` : b.kind === 'other' ? 'Other' : undefined)
  const madeOf = (b: WalkInBill) => (b.lab_order ? `${b.lab_order.items.length} lab test${b.lab_order.items.length === 1 ? '' : 's'} · ${b.lab_order.order_no}` : b.items.map((i) => i.description).join(', ') || 'Other services')
  const isToday = date === today()

  /** How long someone who has arrived has been waiting, in words short enough for a table cell. */
  const waited = (arrivedAt: string | null | undefined) => {
    if (!arrivedAt || !asOf) return null
    const minutes = Math.max(0, Math.round((asOf - new Date(arrivedAt.replace(' ', 'T')).getTime()) / 60000))
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`
  }

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Front Desk</h1>
          <input type="date" className="date" value={date} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          {date !== today() && (
            <button className="btn ghost" onClick={() => setDate(today())}>
              Today
            </button>
          )}
          <input ref={filterBox} className="search" type="search" placeholder="Filter by patient name, mobile or ID (press / )" value={query} onChange={(e) => setQuery(e.target.value)} />
          {doctors.length > 1 && (
            <select className="narrow" value={doctorId} onChange={(e) => setDoctorId(e.target.value)} aria-label="Doctor">
              <option value="">All doctors</option>
              {doctors.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          )}
          <button className="btn primary" onClick={() => open({ kind: 'new' })}>
            + New appointment
          </button>
          <button className="btn ghost" onClick={() => { setWalkInPatient(null); open({ kind: 'walkin' }) }} title="Bill a patient who has no appointment today: services, lab tests, or both">
            + New bill
          </button>
        </div>
        <div className="toolbar minor">
          <button className="btn ghost small-btn" onClick={load} title="Refresh">
            Refresh
          </button>
          <button className="btn ghost small-btn" onClick={() => open({ kind: 'summary' })}>
            Summary
          </button>
          <button className="btn ghost small-btn" onClick={() => open({ kind: 'followups' })} title="Patients asked by a doctor to come back on this day">
            Follow-ups{followUps.some((f) => !f.booked) && <span className="count">{followUps.filter((f) => !f.booked).length}</span>}
          </button>
          <Link className="btn ghost small-btn" to="/queue" target="_blank">
            Patient queue
          </Link>
          <Link className="btn ghost small-btn" to="/front-desk/bills">
            All bills
          </Link>
          <Link className="btn ghost small-btn" to="/front-desk/calendar">
            Calendar
          </Link>
          {user?.can_record_expenses && (
            <Link className="btn ghost small-btn" to="/expenses">
              Expenses
            </Link>
          )}
          {user?.can_file_past_records && (
            <Link className="btn ghost small-btn" to="/past-records">
              Old papers
            </Link>
          )}
        </div>

        <div className="desk-filters">
          <div className="tabs flush" role="group" aria-label="Kind of bill">
            {DEPARTMENTS.map(([key, label]) => (
              <button key={key} className={department === key ? 'active' : ''} onClick={() => setDepartment(key)}>
                {label}
              </button>
            ))}
          </div>
          <div className="tabs flush" role="group" aria-label="Status of the visit">
            <button className={status === '' ? 'active' : ''} onClick={() => setStatus('')}>
              All <span>{total}</span>
            </button>
            {STATUSES.map((s) => (
              <button key={s} className={status === s ? 'active' : ''} onClick={() => setStatus(s)}>
                {STATUS_LABELS[s]} <span>{list?.counts[s] ?? 0}</span>
              </button>
            ))}
          </div>
          {owing > 0 && (
            <button className={`dues-toggle ${showDues ? 'on' : ''}`} onClick={() => setDuesOnly(!showDues)} aria-pressed={showDues} title={showDues ? 'Show everyone again' : 'Show only the people who still owe money'}>
              {rupees(owed)} due from {owing} {owing === 1 ? 'patient' : 'patients'}
              {showDues ? ' · show all' : ''}
            </button>
          )}
        </div>

        {notice && <p className="notice">{notice}</p>}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Patient ID</th>
                <th>Patient</th>
                <th>Bills</th>
                <th>Time</th>
                <th>Status</th>
                <th>Doctor</th>
                <th>Service</th>
                <th>Doctor's advice</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {shownVisits.map((a) => (
                  <tr key={`a${a.id}`} className={`static ${a.status === 'cancelled' ? 'faded' : ''} ${a.status === 'reviewed' ? 'done' : ''}`}>
                    <td className="mono">
                      <Link className="link" to={`/patients/${a.patient.id}`} title="View patient dashboard">
                        {a.patient.uhid}
                      </Link>
                    </td>
                    <td>
                      <strong>
                        <PatientLink id={a.patient.id}>{a.patient.name}</PatientLink>
                      </strong>
                      <small className="muted block">
                        {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()} · {a.patient.mobile}
                      </small>
                    </td>
                    <td className="bills">
                      {billsOf(a)
                        .filter(inDepartment)
                        .map((b) => (
                          <BillChip key={b.id} label={chipLabel(b)} total={b.total} due={b.due} movedToIpd={b.moved_to_ipd} onOpen={() => open({ kind: 'bill', billId: b.id })} />
                        ))}
                      <button className="bill-add" onClick={() => charge(a)} title={`Add services or lab tests for ${a.patient.name}, and collect payment`}>
                        + Bill
                      </button>
                    </td>
                    <td className="mono">
                      {clock(a.scheduled_at)}
                      {a.token_no && <small className="muted block">Token {a.token_no}</small>}
                      {isToday && a.status === 'arrived' && waited(a.arrived_at) && <small className="waiting block">Waiting {waited(a.arrived_at)}</small>}
                    </td>
                    <td className="status-cell">
                      {/* The commonest thing the desk does to a booked patient is mark them as here: one click. */}
                      {isToday && a.status === 'booked' && (
                        <button className="btn primary small-btn" onClick={() => changeStatus(a, 'arrived')} title={`Mark ${a.patient.name} as arrived`}>
                          Arrived
                        </button>
                      )}
                      <select className={`status ${a.status}`} value={a.status} onChange={(e) => changeStatus(a, e.target.value as AppointmentStatus)} aria-label={`Status for ${a.patient.name}`}>
                        {STATUSES.map((s) => (
                          <option key={s} value={s}>
                            {STATUS_LABELS[s]}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td>{a.doctor.name}</td>
                    <td>{a.service?.name ?? '—'}</td>
                    <td className="advice">
                      {a.consultation?.completed_at && (
                        <a className="advice-chip rx" href={`/visits/${a.id}/print`} target="_blank" rel="noreferrer" title="Print the prescription">
                          Rx{a.consultation.medicines_count > 0 ? ` · ${a.consultation.medicines_count}` : ''}
                        </a>
                      )}
                      {a.consultation?.tests_advised && (
                        <button
                          className={`advice-chip tests ${billsOf(a).some((b) => b.lab_order) ? 'done' : ''}`}
                          title={`Tests advised: ${a.consultation.tests_advised}. ${billsOf(a).some((b) => b.lab_order) ? 'A lab order has already been made for this patient today.' : 'Click to make the lab order.'}`}
                          onClick={() => charge(a, a.consultation?.tests_advised ?? undefined)}
                        >
                          Tests{billsOf(a).some((b) => b.lab_order) ? ' ✓' : ''}
                        </button>
                      )}
                      {a.consultation?.follow_up_on && (
                        <button
                          className="advice-chip next"
                          title="Book the next visit"
                          onClick={() => open({ kind: 'new', preset: { patient: a.patient, doctorId: String(a.doctor.id), date: a.consultation!.follow_up_on! } })}
                        >
                          Next {longDate(a.consultation.follow_up_on).slice(0, 6)}
                        </button>
                      )}
                      {a.consultation?.refer_to && (
                        <span className="advice-chip refer" title={`Referred to ${a.consultation.refer_to}`}>
                          Refer
                        </span>
                      )}
                    </td>
                    <td className="num menu-cell">
                      <button className="btn ghost small-btn menu-button" onClick={(e) => toggleMenu(a.id, e.currentTarget)} aria-haspopup="menu" aria-expanded={menu === a.id}>
                        More ▾
                      </button>
                      {menu === a.id && (
                        <div className="menu" role="menu" style={{ top: menuAt.top, left: menuAt.left }}>
                          <button role="menuitem" onClick={() => open({ kind: 'vitals', appointment: a })}>
                            Vitals
                          </button>
                          <Link role="menuitem" to="/lab" onClick={() => setMenu(null)}>
                            Lab tests and results
                          </Link>
                          {a.consultation ? (
                            <a role="menuitem" href={`/visits/${a.id}/print`} target="_blank" rel="noreferrer" onClick={() => setMenu(null)}>
                              Prescription
                            </a>
                          ) : (
                            <button role="menuitem" disabled title="The doctor has not written one yet">
                              Prescription (not written yet)
                            </button>
                          )}
                          <button role="menuitem" onClick={() => open({ kind: 'attachments', appointment: a })}>
                            Attachments
                          </button>
                          <button role="menuitem" onClick={() => open({ kind: 'edit', appointment: a })}>
                            Edit appointment
                          </button>
                          <button role="menuitem" onClick={() => charge(a)}>
                            Add services or lab tests
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              {shownWalkIns.map((bills) => {
                // Everything billed today to someone who has no visit: one row, a chip for each bill.
                const { patient } = bills[0]
                return (
                  <tr key={`p${patient.id}`} className="static walkin">
                    <td className="mono">
                      <Link className="link" to={`/patients/${patient.id}`} title="View patient dashboard">
                        {patient.uhid}
                      </Link>
                    </td>
                    <td>
                      <strong>
                        <PatientLink id={patient.id} bills>
                          {patient.name}
                        </PatientLink>
                      </strong>
                      <small className="muted block">
                        {patient.age_label} / {patient.gender.charAt(0).toUpperCase()} · {patient.mobile}
                      </small>
                    </td>
                    <td className="bills">
                      {bills.map((b) => (
                        <BillChip key={b.id} label={bills.length > 1 ? chipLabel(b) : undefined} total={b.total} due={b.due} movedToIpd={b.moved_to_ipd} onOpen={() => open({ kind: 'bill', billId: b.id })} />
                      ))}
                    </td>
                    <td className="mono">{stampTime(bills[0].created_at)}</td>
                    <td colSpan={4} className="muted">
                      {bills.map(madeOf).join(' · ')}
                    </td>
                    <td />
                  </tr>
                )
              })}
              {list && shownVisits.length + shownWalkIns.length === 0 && (
                <tr className="empty">
                  <td colSpan={9}>
                    {query || status || doctorId || department !== 'all' ? (
                      <>
                        Nobody matches this filter on {longDate(date)}.{' '}
                        <button className="link" onClick={() => { setQuery(''); setStatus(''); setDoctorId(''); setDepartment('all') }}>
                          Clear the filter
                        </button>
                      </>
                    ) : (
                      <>
                        No appointments or bills on {longDate(date)} yet.{' '}
                        <button className="link" onClick={() => open({ kind: 'new' })}>
                          Book the first appointment
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <p className="muted small legend">
          <span className="bill-chip paid">₹</span> paid <span className="bill-chip unpaid">₹</span> money due. Click a patient ID for their full record.
        </p>
      </section>

      {panel?.kind === 'new' && (
        <AppointmentForm
          key={panel.preset ? `${panel.preset.patient.id} ${panel.preset.date} ${doctors.length}` : 'new'}
          date={panel.preset?.date ?? date}
          patient={panel.preset?.patient}
          doctorId={panel.preset?.doctorId}
          status={panel.preset && panel.preset.date > today() ? 'booked' : undefined}
          time={panel.preset && panel.preset.date > today() ? '10:30' : undefined}
          doctors={doctors}
          services={services}
          onClose={() => setPanel(null)}
          onBooked={(a) => {
            setDate(a.scheduled_at.slice(0, 10))
            setStatus('')
            setNotice(`Appointment saved for ${a.patient.name} at ${clock(a.scheduled_at)}, token ${a.token_no}.`)
            // Go straight to the bill when money is still to be collected.
            const unpaid = a.bills.find((b) => b.due > 0)
            setPanel(unpaid ? { kind: 'bill', billId: unpaid.id } : null)
            load()
          }}
        />
      )}
      {panel?.kind === 'bill' && (
        <BillPanel
          key={panel.billId}
          billId={panel.billId}
          services={services}
          onClose={() => setPanel(null)}
          onChange={load}
          onAnotherBill={(bill) => open({ kind: 'charge', patient: bill.patient, appointmentId: bill.appointment?.id, doctor: bill.appointment?.doctor?.name })}
        />
      )}
      {panel?.kind === 'charge' && (
        <ChargePanel
          key={`${panel.patient.id}-${panel.appointmentId ?? 0}-${panel.advised ?? ''}`}
          patient={panel.patient}
          appointmentId={panel.appointmentId}
          doctor={panel.doctor}
          advised={panel.advised}
          services={services}
          tests={labTests.filter((t) => t.is_active !== false)}
          packages={labPackages.filter((p) => p.is_active !== false)}
          onClose={() => setPanel(null)}
          onChange={load}
          onOpenBill={(billId) => open({ kind: 'bill', billId })}
        />
      )}
      {panel?.kind === 'followups' && (
        <aside className="card panel">
          <div className="panel-head">
            <h2>Follow-ups due {longDate(date)}</h2>
            <button className="btn ghost" onClick={() => setPanel(null)}>
              Close
            </button>
          </div>
          <p className="muted small">Patients a doctor asked to come back on this day. Change the date at the top to see another day.</p>
          <ul className="history">
            {followUps.map((f) => (
              <li key={f.id}>
                <span>
                  <strong>
                    <PatientLink id={f.patient.id}>{f.patient.name}</PatientLink>
                  </strong>
                  <small className="muted block">
                    {f.patient.uhid} · {f.patient.mobile} · {f.doctor.name}
                  </small>
                  <small className="muted block">Seen {longDate(f.completed_at)}</small>
                </span>
                {f.booked ? (
                  <span className="pill ok">Booked</span>
                ) : (
                  <button className="btn ghost small-btn" onClick={() => open({ kind: 'new', preset: { patient: f.patient, doctorId: String(f.doctor.id), date: f.follow_up_on } })}>
                    Book
                  </button>
                )}
              </li>
            ))}
            {followUps.length === 0 && <li className="muted">No one is due on this day.</li>}
          </ul>
        </aside>
      )}
      {panel?.kind === 'walkin' && (
        <aside className="card panel">
          <div className="panel-head">
            <h2>New bill</h2>
            <button className="btn ghost" onClick={() => setPanel(null)}>
              Close
            </button>
          </div>
          <p className="muted small">For someone who has no appointment today: a dressing, an ECG, an injection, lab tests, or all of them on one visit to the desk.</p>
          <PatientPicker patient={walkInPatient} onChange={setWalkInPatient} />
          <div className="panel-foot">
            <button className="btn primary" disabled={!walkInPatient} onClick={() => walkInPatient && open({ kind: 'charge', patient: walkInPatient })}>
              Choose services and tests
            </button>
          </div>
        </aside>
      )}
      {panel?.kind === 'vitals' && <VitalsPanel key={panel.appointment.id} appointment={panel.appointment} onClose={() => setPanel(null)} />}
      {panel?.kind === 'attachments' && <AttachmentsPanel key={panel.appointment.id} patient={panel.appointment.patient} appointmentId={panel.appointment.id} onClose={() => setPanel(null)} />}
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
      {panel?.kind === 'summary' && <SummaryPanel date={date} onClose={() => setPanel(null)} />}
    </div>
  )
}
