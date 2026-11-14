import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { AppointmentForm } from '../components/AppointmentForm'
import { BillPanel } from '../components/BillPanel'
import { LabOrderForm } from '../components/LabOrderForm'
import { AttachmentsPanel, EditAppointmentPanel, SummaryPanel, VitalsPanel } from '../components/FrontDeskPanels'
import { PatientPicker } from '../components/PatientPicker'
import { STATUS_LABELS, clock, longDate, rupees, settlement, stampTime, today } from '../format'
import type { Appointment, AppointmentStatus, Bill, Doctor, FollowUp, LabCatalogueTest, LabPackage, Patient, Service, WalkInBill } from '../types'

type DayList = { data: Appointment[]; counts: Partial<Record<AppointmentStatus, number>> }
type Department = 'all' | 'appointment' | 'lab' | 'other'
type Panel =
  | { kind: 'new'; preset?: { patient: Appointment['patient']; doctorId: string; date: string } }
  | { kind: 'followups' }
  | { kind: 'lab'; appointment: Appointment }
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
  const [panel, setPanel] = useState<Panel>(null)
  const [menu, setMenu] = useState<number | null>(null)
  const [menuAt, setMenuAt] = useState({ top: 0, left: 0 })
  const [notice, setNotice] = useState('')
  const [walkInPatient, setWalkInPatient] = useState<Patient | null>(null)

  const load = useCallback(() => {
    api
      .get<DayList>('/appointments', { params: { date, status: status || undefined, doctor_id: doctorId || undefined, q: query || undefined } })
      .then((res) => setList(res.data))
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

  // Starts an empty bill: for a visit that has none, or as one more bill for the same patient.
  const newBill = async (patientId: number, appointmentId?: number) => {
    const res = await api.post<Bill>('/bills', { patient_id: patientId, appointment_id: appointmentId ?? null })
    open({ kind: 'bill', billId: res.data.id })
    load()
  }

  const total = list ? Object.values(list.counts).reduce((sum, n) => sum + (n ?? 0), 0) : 0
  const visits = (list?.data ?? []).filter((a) => department === 'all' || a.bills.some((b) => b.kind === department) || (department === 'appointment' && a.bills.length === 0))
  // Walk-in bills have no visit status, so they are hidden while a status tab or doctor filter is on.
  const walkInRows = status || doctorId ? [] : walkIns.filter((b) => department === 'all' || b.kind === department)
  const hideVisits = department === 'other'

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
          <input className="search" type="search" placeholder="Filter by patient name, mobile or ID" value={query} onChange={(e) => setQuery(e.target.value)} />
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
          <button className="btn ghost" onClick={() => { setWalkInPatient(null); open({ kind: 'walkin' }) }}>
            + Other bill
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
        </div>

        <div className="tabs">
          {DEPARTMENTS.map(([key, label]) => (
            <button key={key} className={`square ${department === key ? 'active' : ''}`} onClick={() => setDepartment(key)}>
              {label}
            </button>
          ))}
          <span className="tab-gap" />
          <button className={status === '' ? 'active' : ''} onClick={() => setStatus('')}>
            All <span>{total}</span>
          </button>
          {STATUSES.map((s) => (
            <button key={s} className={status === s ? 'active' : ''} onClick={() => setStatus(s)}>
              {STATUS_LABELS[s]} <span>{list?.counts[s] ?? 0}</span>
            </button>
          ))}
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
              {!hideVisits &&
                visits.map((a) => (
                  <tr key={`a${a.id}`} className={`static ${a.status === 'cancelled' ? 'faded' : ''}`}>
                    <td className="mono">
                      <Link className="link" to={`/patients/${a.patient.id}`} title="View patient dashboard">
                        {a.patient.uhid}
                      </Link>
                    </td>
                    <td>
                      <strong>{a.patient.name}</strong>
                      <small className="muted block">
                        {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()} · {a.patient.mobile}
                      </small>
                    </td>
                    <td className="bills">
                      {a.bills
                        .filter((b) => department === 'all' || b.kind === department)
                        .map((b) => (
                          <BillChip key={b.id} label={b.lab_order ? `Lab ${b.lab_order.order_no}` : b.kind === 'other' ? 'Other' : undefined} total={b.total} due={b.due} movedToIpd={b.moved_to_ipd} onOpen={() => open({ kind: 'bill', billId: b.id })} />
                        ))}
                      {a.bills.length === 0 && (
                        <button className="link" onClick={() => newBill(a.patient.id, a.id)}>
                          Create bill
                        </button>
                      )}
                    </td>
                    <td className="mono">
                      {clock(a.scheduled_at)}
                      {a.token_no && <small className="muted block">Token {a.token_no}</small>}
                    </td>
                    <td>
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
                          className={`advice-chip tests ${a.bills.some((b) => b.lab_order) ? 'done' : ''}`}
                          title={`Tests advised: ${a.consultation.tests_advised}. ${a.bills.some((b) => b.lab_order) ? 'A lab order has already been made for this visit.' : 'Click to make the lab order.'}`}
                          onClick={() => open({ kind: 'lab', appointment: a })}
                        >
                          Tests{a.bills.some((b) => b.lab_order) ? ' ✓' : ''}
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
                          <button role="menuitem" onClick={() => { setMenu(null); newBill(a.patient.id, a.id) }}>
                            Add another bill
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              {walkInRows.map((b) => (
                <tr key={`b${b.id}`} className="static walkin">
                  <td className="mono">
                    <Link className="link" to={`/patients/${b.patient.id}`} title="View patient dashboard">
                      {b.patient.uhid}
                    </Link>
                  </td>
                  <td>
                    <strong>{b.patient.name}</strong>
                    <small className="muted block">
                      {b.patient.age_label} / {b.patient.gender.charAt(0).toUpperCase()} · {b.patient.mobile}
                    </small>
                  </td>
                  <td className="bills">
                    <BillChip total={b.total} due={b.due} movedToIpd={b.moved_to_ipd} onOpen={() => open({ kind: 'bill', billId: b.id })} />
                  </td>
                  <td className="mono">{stampTime(b.created_at)}</td>
                  <td colSpan={4} className="muted">
                    {b.lab_order ? `${b.lab_order.items.length} lab test${b.lab_order.items.length === 1 ? '' : 's'} · ${b.lab_order.order_no}` : b.items.map((i) => i.description).join(', ') || 'Other services'}
                  </td>
                  <td />
                </tr>
              ))}
              {list && (hideVisits ? 0 : visits.length) + walkInRows.length === 0 && (
                <tr className="empty">
                  <td colSpan={9}>Nothing to show for this day and filter.</td>
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
          key={panel.preset ? `${panel.preset.patient.id} ${panel.preset.date}` : 'new'}
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
      {panel?.kind === 'bill' && <BillPanel key={panel.billId} billId={panel.billId} services={services} onClose={() => setPanel(null)} onChange={load} onAnotherBill={(bill) => newBill(bill.patient.id, bill.appointment?.id)} />}
      {panel?.kind === 'lab' && (
        <LabOrderForm
          key={panel.appointment.id}
          tests={labTests.filter((t) => t.is_active !== false)}
          packages={labPackages.filter((p) => p.is_active !== false)}
          visit={{ patient: panel.appointment.patient, appointmentId: panel.appointment.id, doctor: panel.appointment.doctor.name, advised: panel.appointment.consultation?.tests_advised ?? '' }}
          onClose={() => setPanel(null)}
          onCreated={(order) => {
            setNotice(`Lab order ${order.order_no} created for ${order.patient.name}.`)
            // Go straight to the bill so payment can be collected before the sample is taken.
            setPanel(order.bill ? { kind: 'bill', billId: order.bill.id } : null)
            load()
          }}
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
                  <strong>{f.patient.name}</strong>
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
            <h2>Bill for other services</h2>
            <button className="btn ghost" onClick={() => setPanel(null)}>
              Close
            </button>
          </div>
          <p className="muted small">For someone who is not seeing a doctor today: a dressing, an ECG, an injection and the like.</p>
          <PatientPicker patient={walkInPatient} onChange={setWalkInPatient} />
          <div className="panel-foot">
            <button className="btn primary" disabled={!walkInPatient} onClick={() => walkInPatient && newBill(walkInPatient.id)}>
              Start bill
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
