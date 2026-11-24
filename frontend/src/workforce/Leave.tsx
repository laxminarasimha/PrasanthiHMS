import { Popup } from '../components/Popup'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { longDate } from '../format'
import { useChoiceInAddress } from '../rangeInAddress'
import { saveFile } from './sensitiveStep'
import { PART_LABEL, REQUEST_CLASS, REQUEST_LABEL, days, useWorkforce, type LeaveRequest, type LeaveType, type StaffBrief } from './shared'
import { PersonLink } from './PersonLink'
import { WorkforceTabs } from './WorkforceTabs'

type Balances = { year: number; from: string; to: string; types: { id: number; code: string; name: string; is_paid: boolean }[]; rows: (StaffBrief & { balances: Record<string, { left: number; taken: number }> })[] }
type Ledger = { year: number; lines: { id: number; date: string; type: string; kind: string; days: number; balance: number; note: string | null }[]; balances: Record<string, number> }
const KIND: Record<string, string> = { opening: 'Opening balance', accrual: 'Earned', taken: 'Taken', carry_forward: 'Carried', expiry: 'Lapsed', adjustment: 'Correction', reversal: 'Given back' }

/** Leave: requests and their decisions, and every person's leave account. */
export function WorkforceLeave() {
  const { can } = useWorkforce()
  const [view, setView] = useChoiceInAddress<'requests' | 'balances'>('view', 'requests', ['requests', 'balances'] as const)

  return (
    <>
      <WorkforceTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Leave</h1>
          <div className="tabs flush" role="group" aria-label="What to show">
            <button className={view === 'requests' ? 'active' : ''} onClick={() => setView('requests')}>
              Requests
            </button>
            <button className={view === 'balances' ? 'active' : ''} onClick={() => setView('balances')}>
              Balances
            </button>
          </div>
          <span className="grow" />
          {can('wf:leave:export') && (
            <button className="btn ghost" onClick={() => void saveFile('/workforce/leave/export', {}, 'leave.csv')}>
              Export
            </button>
          )}
        </div>
        {view === 'requests' ? <Requests /> : <BalanceSheet />}
      </section>
    </>
  )
}

function Requests() {
  const { can } = useWorkforce()
  const [status, setStatus] = useChoiceInAddress<string>('show', 'pending')
  const [rows, setRows] = useState<{ status: string; list: LeaveRequest[] } | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(0)
  const [acting, setActing] = useState<{ id: number; what: 'refuse' | 'cancel' } | null>(null)
  const [note, setNote] = useState('')
  const [adding, setAdding] = useState(false)

  const load = useCallback(() => {
    api
      .get<LeaveRequest[]>('/workforce/leave', { params: { status } })
      .then((res) => {
        setRows({ status, list: res.data })
        setFailed('')
      })
      .catch(() => setFailed('Could not load the leave requests. Check the connection and try again.'))
  }, [status])
  useEffect(load, [load])
  const shown = rows?.status === status ? rows.list : null

  const send = async (r: LeaveRequest, path: string, body: Record<string, unknown>, done: string) => {
    setBusy(r.id)
    setNotice('')
    try {
      await api.post(`/workforce/leave/${r.id}/${path}`, body)
      setNotice(done)
      setActing(null)
      setNote('')
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(0)
    }
  }
  const span = (r: LeaveRequest) => {
    const part = (p: string) => (p === 'full' ? '' : ` (${PART_LABEL[p].toLowerCase()})`)
    return r.from_date === r.to_date ? `${longDate(r.from_date)}${part(r.from_part)}` : `${longDate(r.from_date)}${part(r.from_part)} to ${longDate(r.to_date)}${part(r.to_part)}`
  }

  return (
    <>
      <div className="toolbar minor">
        <div className="tabs flush" role="group" aria-label="Which requests">
          {([['pending', 'Waiting'], ['approved', 'Approved'], ['all', 'All']] as const).map(([value, label]) => (
            <button key={value} className={status === value ? 'active' : ''} onClick={() => setStatus(value)}>
              {label}
            </button>
          ))}
        </div>
        <span className="grow" />
        {can('wf:leave:create') && (
          <button className="btn primary" onClick={() => setAdding(true)}>
            + Enter leave for staff
          </button>
        )}
      </div>
      {notice && <p className="notice">{notice}</p>}
      {failed && <div className="form-error">{failed}</div>}
      <div className={adding ? 'split' : ''}>
        <div className="table-wrap grow">
          <table>
            <thead>
              <tr>
                <th>Who</th>
                <th>Leave</th>
                <th>Dates</th>
                <th>Reason</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {shown?.map((r) => (
                <tr key={r.id} className="static">
                  <td className="fit-wrap">
                    <strong>
                      <PersonLink id={r.employee?.id}>{r.employee?.name ?? '—'}</PersonLink>
                    </strong>
                    <small className="muted block">{[r.employee?.employee_no, r.employee?.department?.name].filter(Boolean).join(' · ')}</small>
                  </td>
                  <td className="wrap">
                    {r.type?.name ?? '—'}
                    <small className="muted block">
                      {days(r.days)}
                      {r.type && !r.type.is_paid ? ' · without pay' : ''}
                    </small>
                  </td>
                  <td>{span(r)}</td>
                  <td className="wrap">
                    {r.reason ?? <span className="muted">—</span>}
                    {r.decision_note && <small className="muted block">Decision: {r.decision_note}</small>}
                  </td>
                  <td className="num">
                    {acting?.id === r.id ? (
                      <span className="row end wrap">
                        <input value={note} onChange={(e) => setNote(e.target.value)} placeholder={acting.what === 'refuse' ? 'Why it is refused' : 'Why it is cancelled'} aria-label="Reason" />
                        <button
                          className="btn small-btn"
                          disabled={busy === r.id || !note.trim()}
                          onClick={() => void (acting.what === 'refuse' ? send(r, 'decide', { approve: false, note }, 'Refused.') : send(r, 'cancel', { reason: note }, 'Cancelled. Any days taken have gone back into the account.'))}
                        >
                          {acting.what === 'refuse' ? 'Refuse' : 'Cancel the leave'}
                        </button>
                      </span>
                    ) : r.status === 'pending' && r.can_decide ? (
                      <span className="row end wrap">
                        <button className="btn primary small-btn" disabled={busy === r.id} onClick={() => void send(r, 'decide', { approve: true }, `Approved: ${days(r.days)} taken from ${r.employee?.name ?? 'their'} ${r.type?.name ?? 'leave'}.`)}>
                          Approve
                        </button>
                        <button className="link" onClick={() => setActing({ id: r.id, what: 'refuse' })}>
                          Refuse
                        </button>
                      </span>
                    ) : (
                      <span className="row end wrap">
                        <span className={`pill ${REQUEST_CLASS[r.status]}`}>{REQUEST_LABEL[r.status]}</span>
                        {r.status === 'approved' && can('wf:leave:approve') && (
                          <button className="link" onClick={() => setActing({ id: r.id, what: 'cancel' })}>
                            Cancel
                          </button>
                        )}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
              {shown && shown.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>{status === 'pending' ? 'No leave request is waiting for a decision.' : 'No leave here.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {adding && (
          <LeaveForm
            onClose={() => setAdding(false)}
            onSaved={(message) => {
              setAdding(false)
              setNotice(message)
              load()
            }}
          />
        )}
      </div>
    </>
  )
}

/** The leave request form, used for a member of staff by someone who may, and by a person for themself. */
export function LeaveForm({ own, types: given, onClose, onSaved }: { own?: boolean; types?: LeaveType[]; onClose: () => void; onSaved: (message: string) => void }) {
  const [staff, setStaff] = useState<StaffBrief[]>([])
  const [types, setTypes] = useState<LeaveType[]>(given ?? [])
  const [form, setForm] = useState({ employee_id: '', leave_type_id: '', from_date: '', to_date: '', from_part: 'full', to_part: 'full', reason: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    if (!own) api.get<{ data: StaffBrief[] }>('/workforce/employees').then((res) => setStaff(res.data.data)).catch(() => undefined)
    if (!given) api.get<LeaveType[]>('/workforce/leave/types').then((res) => setTypes(res.data)).catch(() => undefined)
  }, [own, given])
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const type = types.find((t) => String(t.id) === form.leave_type_id)
  const oneDay = form.from_date !== '' && form.from_date === (form.to_date || form.from_date)
  const parts = Object.entries(PART_LABEL).map(([value, label]) => ({ value, label }))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      const body = { ...form, to_date: form.to_date || form.from_date, to_part: oneDay ? form.from_part : form.to_part, reason: form.reason || null }
      const res = await api.post<LeaveRequest>(own ? '/workforce/me/leave' : '/workforce/leave', body)
      onSaved(res.data.status === 'approved' ? `${days(res.data.days)} of ${res.data.type?.name ?? 'leave'} granted.` : `Leave for ${days(res.data.days)} was sent for approval.`)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const other = Object.entries(errors).filter(([key]) => !(key in form))

  return (
    <Popup title={own ? 'Ask for leave' : 'Enter leave for staff'} onClose={onClose} onSubmit={save}>
      {!own && <SelectField label="Member of staff" required value={form.employee_id} onChange={set('employee_id')} options={staff.map((s) => ({ value: String(s.id), label: `${s.name} (${s.employee_no})` }))} placeholder="Choose" error={errors.employee_id} />}
      <SelectField
        label="Kind of leave"
        required
        value={form.leave_type_id}
        onChange={set('leave_type_id')}
        options={types.map((t) => ({ value: String(t.id), label: `${t.name}${t.is_paid ? (t.left !== undefined ? ` · ${t.left} left` : '') : ' (without pay)'}` }))}
        placeholder="Choose"
        error={errors.leave_type_id}
      />
      <div className="grid">
        <TextField label="First day" required type="date" value={form.from_date} onChange={set('from_date')} error={errors.from_date} />
        <TextField label="Last day" type="date" value={form.to_date} min={form.from_date} onChange={set('to_date')} error={errors.to_date} />
        {type?.allow_half_day !== false && (
          <>
            <SelectField label={oneDay ? 'How much of the day' : 'On the first day'} value={form.from_part} onChange={set('from_part')} options={parts} error={errors.from_part} />
            {!oneDay && <SelectField label="On the last day" value={form.to_part} onChange={set('to_part')} options={parts} />}
          </>
        )}
      </div>
      <TextField label="Reason" value={form.reason} onChange={set('reason')} />
      <p className="muted small">Weekly offs and holidays inside the dates are not counted as leave, unless the hospital has chosen to count them.</p>
      {other.length > 0 && (
        <div className="form-error">
          {other.map(([key, message]) => (
            <div key={key}>{message}</div>
          ))}
        </div>
      )}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving || !form.leave_type_id || !form.from_date || (!own && !form.employee_id)}>
          {saving ? 'Saving…' : 'Send'}
        </button>
      </div>
    </Popup>
  )
}

function BalanceSheet() {
  const { can } = useWorkforce()
  const [yearText, setYear] = useChoiceInAddress<string>('year', '')
  const [sheet, setSheet] = useState<Balances | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [open, setOpen] = useState<StaffBrief | null>(null)
  const [busy, setBusy] = useState('')

  const load = useCallback(() => {
    api
      .get<Balances>('/workforce/leave/balances', { params: { year: yearText || undefined } })
      .then((res) => {
        setSheet(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the balances. Check the connection and try again.'))
  }, [yearText])
  useEffect(load, [load])
  const shown = sheet && (yearText === '' || String(sheet.year) === yearText) ? sheet : null

  const housekeeping = async (step: 'accrue' | 'close_year' | 'expire_carried', ask: string, done: (lines: number) => string) => {
    if (!shown || !window.confirm(ask)) return
    setBusy(step)
    setNotice('')
    try {
      const res = await api.post<{ lines_written: number }>('/workforce/leave/housekeeping', { step, year: shown.year })
      setNotice(done(res.data.lines_written))
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not run. Try again.')
    } finally {
      setBusy('')
    }
  }

  return (
    <>
      <div className="toolbar minor">
        <button className="btn ghost" disabled={!shown} onClick={() => shown && setYear(String(shown.year - 1))} aria-label="The year before">
          ‹
        </button>
        <strong>{shown ? `Leave year ${longDate(shown.from)} to ${longDate(shown.to)}` : 'Leave year'}</strong>
        <button className="btn ghost" disabled={!shown} onClick={() => shown && setYear(String(shown.year + 1))} aria-label="The year after">
          ›
        </button>
        <span className="grow" />
        {can('wf:leave:edit') && shown && (
          <>
            <button className="btn ghost" disabled={busy !== ''} onClick={() => void housekeeping('accrue', `Give everyone the leave they have earned so far in this leave year? Anything already given is not given again.`, (n) => (n > 0 ? `${n} lines of earned leave were added.` : 'Everyone already had what they have earned.'))}>
              {busy === 'accrue' ? 'Working…' : 'Give out earned leave'}
            </button>
            <button className="btn ghost" disabled={busy !== ''} onClick={() => void housekeeping('close_year', `Close this leave year? What is left of each kind of leave is carried into next year up to its limit, and the rest lapses. This cannot be undone, though it can be corrected line by line.`, (n) => (n > 0 ? `The year was closed: ${n} lines written.` : 'Nothing was left to carry or lapse.'))}>
              {busy === 'close_year' ? 'Working…' : 'Close the year'}
            </button>
          </>
        )}
      </div>
      {notice && <p className="notice">{notice}</p>}
      {failed && <div className="form-error">{failed}</div>}
      {!shown && !failed && <p className="loading">Loading the balances…</p>}
      {shown && shown.types.length === 0 && <p className="muted">No kinds of leave have been set up yet. Add them under Setup.</p>}
      {shown && shown.types.length > 0 && (
        <div className={open ? 'split' : ''}>
          <div className="table-wrap grow">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  {shown.types.map((t) => (
                    <th key={t.id} className="num" title={t.name}>
                      {t.code}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {shown.rows.map((r) => (
                  <tr key={r.id} onClick={() => setOpen(r)} className={open?.id === r.id ? 'selected' : ''} title="Open the leave account">
                    <td className="fit-wrap">
                      <strong>
                        <PersonLink id={r.id}>{r.name}</PersonLink>
                      </strong>
                      <small className="muted block">{[r.employee_no, r.department?.name].filter(Boolean).join(' · ')}</small>
                    </td>
                    {shown.types.map((t) => {
                      const b = r.balances[t.id] ?? { left: 0, taken: 0 }
                      return (
                        <td key={t.id} className="num">
                          <strong className={b.left < 0 ? 'due' : ''}>{b.left}</strong>
                          {b.taken > 0 && <small className="muted block">{b.taken} taken</small>}
                        </td>
                      )
                    })}
                  </tr>
                ))}
                {shown.rows.length === 0 && (
                  <tr className="empty">
                    <td colSpan={shown.types.length + 1}>No staff to show.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {open && <LedgerPanel key={`${open.id}-${shown.year}`} person={open} year={shown.year} types={shown.types} onClose={() => setOpen(null)} onChanged={load} />}
        </div>
      )}
    </>
  )
}

/** One person's leave account, line by line, with a way to add a correcting line. */
function LedgerPanel({ person, year, types, onClose, onChanged }: { person: StaffBrief; year: number; types: Balances['types']; onClose: () => void; onChanged: () => void }) {
  const { can } = useWorkforce()
  const [ledger, setLedger] = useState<Ledger | null>(null)
  const [form, setForm] = useState({ leave_type_id: '', days: '', kind: 'adjustment', reason: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    api.get<Ledger>(`/workforce/leave/ledger/${person.id}`, { params: { year } }).then((res) => setLedger(res.data)).catch(() => setLedger({ year, lines: [], balances: {} }))
  }, [person.id, year])

  const adjust = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      setLedger((await api.post<Ledger>('/workforce/leave/adjust', { employee_id: person.id, year, ...form })).data)
      setForm({ leave_type_id: '', days: '', kind: 'adjustment', reason: '' })
      onChanged()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <aside className="card panel">
      <div className="panel-head">
        <div>
          <h2>{person.name}</h2>
          <span className="muted">Leave account · {person.employee_no}</span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>
      {!ledger ? (
        <p className="loading">Loading the account…</p>
      ) : ledger.lines.length === 0 ? (
        <p className="muted">Nothing has been written to this account for the year yet.</p>
      ) : (
        <table className="compact">
          <thead>
            <tr>
              <th>Date</th>
              <th>What</th>
              <th className="num">Days</th>
              <th className="num">Left</th>
            </tr>
          </thead>
          <tbody>
            {ledger.lines.map((l) => (
              <tr key={l.id} className="static">
                <td>{longDate(l.date)}</td>
                <td className="wrap">
                  {l.type} · {KIND[l.kind] ?? l.kind}
                  {l.note && <small className="muted block">{l.note}</small>}
                </td>
                <td className={`num ${l.days < 0 ? 'due' : 'settled'}`}>{l.days > 0 ? `+${l.days}` : l.days}</td>
                <td className="num">{l.balance}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {can('wf:leave:edit') && (
        <form onSubmit={adjust} noValidate className="gap-top">
          <h3>Add a correcting line</h3>
          <p className="muted small">A balance is never typed over. To put it right, add the days to give (5) or take away (−2), and say why.</p>
          <div className="grid">
            <SelectField label="Kind of leave" required value={form.leave_type_id} onChange={(v) => setForm((f) => ({ ...f, leave_type_id: v }))} options={types.map((t) => ({ value: String(t.id), label: t.name }))} placeholder="Choose" error={errors.leave_type_id} />
            <TextField label="Days (+ or −)" required type="number" step="0.5" value={form.days} onChange={(v) => setForm((f) => ({ ...f, days: v }))} error={errors.days} />
          </div>
          <SelectField label="This is" value={form.kind} onChange={(v) => setForm((f) => ({ ...f, kind: v }))} options={[{ value: 'adjustment', label: 'A correction' }, { value: 'opening', label: 'An opening balance from before this system' }]} />
          <TextField label="Reason" required value={form.reason} onChange={(v) => setForm((f) => ({ ...f, reason: v }))} error={errors.reason} />
          <div className="panel-foot">
            <button className="btn primary" disabled={saving || !form.leave_type_id || !form.days || !form.reason.trim()}>
              {saving ? 'Saving…' : 'Add the line'}
            </button>
          </div>
        </form>
      )}
    </aside>
  )
}
