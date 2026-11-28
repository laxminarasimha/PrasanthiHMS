import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { DoctorTabs } from '../components/DoctorTabs'
import { FrontDeskTabs } from '../components/FrontDeskTabs'
import { PharmacyTabs } from '../components/PharmacyTabs'
import { RepCatalogue } from '../components/RepCatalogue'
import { RepSamples, type SampleStart } from '../components/RepSamples'
import { RepDrugRows } from '../components/RepDrugRows'
import { emptyDrug, filledDrugs, type CatalogueBrand, type RepDrug } from '../repDrugs'
import { useChoiceInAddress } from '../rangeInAddress'
import { OutsideHours } from '../components/AppointmentForm'
import { SelectField, TextField } from '../components/Field'
import { clock, longDate, today } from '../format'
import { useNotice } from '../notices'
import type { Doctor } from '../types'
import { DoctorLink } from '../components/DoctorLink'

type Company = { id: number; name: string; notes: string | null; reps_count?: number; last_visit_at?: string | null; reps?: Rep[]; files?: CompanyFile[] }
type CompanyFile = { id: number; title: string; original_name: string; size: number; created_at: string; uploader?: { id: number; name: string } | null }
type Rep = {
  id: number
  name: string
  mobile: string | null
  division: string | null
  area: string | null
  manager_name: string | null
  manager_mobile: string | null
  notes: string | null
  is_active: boolean
  company?: { id: number; name: string }
  last_visit?: { arrived_at: string; status: Status; doctor?: { id: number; name: string } | null } | null
}
type Status = 'waiting' | 'met' | 'not_met' | 'come_back'
type Visit = {
  id: number
  arrived_at: string
  status: Status
  purpose: string | null
  discussed: string | null
  offers: string | null
  next_on: string | null
  rep?: Rep
  doctor?: { id: number; name: string } | null
  logger?: { id: number; name: string } | null
  /** The drugs spoken about, one to a line as on a prescription. */
  drugs?: VisitDrug[]
}
type VisitDrug = { id: number; name: string; composition: string | null; uses: string | null; dose: string | null; frequency: string | null; timing: string | null; duration: string | null; note: string | null; mrp: number | null; rate: number | null; scheme: string | null; keep: boolean }
type Hours = Record<string, { from?: string; to?: string; most?: number }>
type Day = { date: string; visits: Visit[]; hours: Hours; can_log: boolean }

const STATUS: Record<Status, { label: string; pill: string }> = {
  waiting: { label: 'Waiting', pill: 'status-arrived' },
  met: { label: 'Met', pill: 'status-reviewed' },
  not_met: { label: 'Not met', pill: 'status-cancelled' },
  come_back: { label: 'Asked to come back', pill: 'status-booked' },
}
const STATUS_CHOICES = (['met', 'come_back', 'not_met', 'waiting'] as const).map((value) => ({ value, label: STATUS[value].label }))
const noRep = { name: '', company: '', mobile: '', division: '', area: '', manager_name: '', manager_mobile: '', notes: '' }
const TABS = ['visits', 'reps', 'companies', 'catalogue', 'samples'] as const

const hm = (time: string) => clock(`2000-01-01 ${time}`)
/** A doctor's own hours for representatives, in words. */
const hoursText = (rule: Hours[string] | undefined) =>
  rule ? [rule.from && rule.to ? `${hm(rule.from)} to ${hm(rule.to)}` : '', rule.most ? `at most ${rule.most} a day` : ''].filter(Boolean).join(', ') : ''

/**
 * Medical representatives who call on the doctors. The front desk keeps the list of companies and their people and
 * logs each one as they arrive; the doctor sees who is waiting and writes what was spoken about. Nothing here is about
 * a patient: a representative has no token and no bill.
 */
export function MedicalReps() {
  const { user } = useAuth()
  const isDoctor = user?.role === 'doctor'
  const canLog = Boolean(user?.can_front_desk)
  const seesVisits = canLog || Boolean(user?.can_consult)
  // Kept in the address, so a link can open the catalogue straight away. Someone who does not see visits starts there.
  const [tab, setTab] = useChoiceInAddress('tab', seesVisits ? 'visits' : 'catalogue', TABS)
  const [date, setDate] = useState(today())
  const [day, setDay] = useState<Day | null>(null)
  const [reps, setReps] = useState<Rep[] | null>(null)
  const [companies, setCompanies] = useState<Company[] | null>(null)
  const [doctors, setDoctors] = useState<Doctor[]>([])
  // From the top search, a rep's name arrives in the address.
  const [query, setQuery] = useState(() => new URLSearchParams(window.location.search).get('q') ?? '')
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  // Set when samples are being entered straight from a visit.
  const [sampleStart, setSampleStart] = useState<SampleStart | null>(null)
  const [panel, setPanel] = useState<{ kind: 'log' } | { kind: 'close'; visit: Visit } | { kind: 'rep'; rep: Rep | null } | null>(null)

  const loadDay = useCallback(() => {
    if (!seesVisits) return Promise.resolve()
    return api
      .get<Day>('/reps/visits', { params: { date } })
      .then((res) => {
        setDay(res.data)
        setFailed('')
      })
      .catch(() => setFailed('The list could not be loaded. Check the internet connection.'))
  }, [date, seesVisits])
  const loadReps = useCallback(() => api.get<Rep[]>('/reps/people', { params: { q: query || undefined } }).then((res) => setReps(res.data)).catch(() => setReps([])), [query])
  const loadCompanies = useCallback(() => api.get<Company[]>('/reps/companies').then((res) => setCompanies(res.data)).catch(() => setCompanies([])), [])

  // Representatives arrive while the screen is open, so today's list looks again every minute.
  useEffect(() => {
    void loadDay()
    const timer = window.setInterval(loadDay, 60000)
    return () => window.clearInterval(timer)
  }, [loadDay])
  // And at once when this device is told that one has come to see the doctor.
  useNotice(['rep'], loadDay)
  useEffect(() => {
    if (tab !== 'reps') return
    const wait = window.setTimeout(loadReps, 250)
    return () => window.clearTimeout(wait)
  }, [tab, loadReps])
  useEffect(() => {
    if (tab === 'companies') void loadCompanies()
  }, [tab, loadCompanies])
  useEffect(() => {
    if (seesVisits) api.get<Doctor[]>('/doctors').then((res) => setDoctors(res.data)).catch(() => undefined)
  }, [seesVisits])

  const done = (said: string) => {
    setPanel(null)
    setNotice(said)
    void loadDay()
    if (tab === 'reps') void loadReps()
    if (tab === 'companies') void loadCompanies()
  }
  const remove = async (v: Visit) => {
    if (!window.confirm(`Take ${v.rep?.name ?? 'this representative'} off the list? Use this only for a visit logged by mistake.`)) return
    try {
      await api.delete(`/reps/visits/${v.id}`)
      done('Taken off the list.')
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That could not be taken off.')
    }
  }

  const waiting = day?.visits.filter((v) => v.status === 'waiting').length ?? 0

  return (
    <>
      {isDoctor ? <DoctorTabs /> : canLog ? <FrontDeskTabs /> : user?.can_view_pharmacy ? <PharmacyTabs /> : <DoctorTabs />}
      <div className="split">
        <section className="card">
          <div className="toolbar">
            <h1>Medical representatives</h1>
            {tab === 'visits' && <input type="date" className="date" value={date} max={today()} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />}
            {tab === 'visits' && date !== today() && (
              <button className="btn ghost" onClick={() => setDate(today())}>
                Today
              </button>
            )}
            {tab === 'reps' && <input className="search" type="search" placeholder="Find by name, mobile, company or division" value={query} onChange={(e) => setQuery(e.target.value)} />}
            <span className="grow" />
            {canLog && tab === 'visits' && (
              <button className="btn primary" onClick={() => setPanel({ kind: 'log' })}>
                + Log a visit
              </button>
            )}
            {canLog && (tab === 'reps' || tab === 'companies') && (
              <button className="btn primary" onClick={() => setPanel({ kind: 'rep', rep: null })}>
                + New representative
              </button>
            )}
          </div>

          <div className="tabs">
            {seesVisits && (
              <button className={tab === 'visits' ? 'active' : ''} onClick={() => setTab('visits')}>
                Visits {waiting > 0 && <span>{waiting}</span>}
              </button>
            )}
            <button className={tab === 'reps' ? 'active' : ''} onClick={() => setTab('reps')}>
              Representatives
            </button>
            <button className={tab === 'companies' ? 'active' : ''} onClick={() => setTab('companies')}>
              Companies and their files
            </button>
            <button className={tab === 'catalogue' ? 'active' : ''} onClick={() => setTab('catalogue')}>
              Catalogue of brands
            </button>
            {seesVisits && (
              <button
                className={tab === 'samples' ? 'active' : ''}
                onClick={() => {
                  setSampleStart(null)
                  setTab('samples')
                }}
              >
                Samples
              </button>
            )}
          </div>

          {failed && <p className="form-error">{failed}</p>}
          {notice && <p className="notice">{notice}</p>}

          {tab === 'visits' && (
            <>
              <p className="muted small">
                {isDoctor ? 'The representatives who came to meet you.' : 'Log each representative as they arrive. The doctor sees them as waiting and is told on their phone.'} They are not patients: no token, no bill, and they
                are not counted in any patient report.
              </p>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Came at</th>
                      <th>Representative</th>
                      {!isDoctor && <th>To meet</th>}
                      <th>Status</th>
                      <th>What was spoken about</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {day?.visits.map((v) => (
                      <tr key={v.id} className="static">
                        <td>{clock(v.arrived_at)}</td>
                        <td>
                          <strong>{v.rep?.name}</strong>
                          <small className="muted block">
                            {[v.rep?.company?.name, v.rep?.division, v.rep?.mobile].filter(Boolean).join(' · ')}
                            {v.purpose ? ` · ${v.purpose}` : ''}
                          </small>
                        </td>
                        {!isDoctor && <td><DoctorLink id={v.doctor?.id}>{v.doctor?.name}</DoctorLink></td>}
                        <td>
                          <span className={`pill ${STATUS[v.status].pill}`}>{STATUS[v.status].label}</span>
                          {v.next_on && <small className="muted block">{v.status === 'come_back' ? 'Come on' : 'Next'} {longDate(v.next_on)}</small>}
                        </td>
                        <td className="wrap">
                          {(v.drugs ?? []).length > 0 && <strong className="block">{v.drugs!.map((d) => d.name).join(', ')}</strong>}
                          {v.discussed}
                          {v.offers && <small className="muted block">Offer: {v.offers}</small>}
                        </td>
                        <td className="num">
                          <button className="btn ghost small-btn" onClick={() => setPanel({ kind: 'close', visit: v })}>
                            {v.status === 'waiting' ? 'Drugs · met / not met' : 'Change'}
                          </button>
                          {v.status === 'met' && v.rep?.company && (
                            <button
                              className="link"
                              title="Enter the samples this representative left"
                              onClick={() => {
                                setPanel(null)
                                setSampleStart({ companyId: v.rep!.company!.id, repId: v.rep!.id, visitId: v.id, doctorId: v.doctor?.id ?? null })
                                setTab('samples')
                              }}
                            >
                              + Samples
                            </button>
                          )}
                          {canLog && v.status === 'waiting' && !v.discussed && !v.offers && (
                            <button className="link danger" onClick={() => void remove(v)}>
                              Take off
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                    {day && day.visits.length === 0 && (
                      <tr className="empty static">
                        <td colSpan={isDoctor ? 5 : 6}>No medical representative {date === today() ? 'has come today' : `came on ${longDate(date)}`}.</td>
                      </tr>
                    )}
                    {!day && !failed && (
                      <tr className="empty static">
                        <td colSpan={isDoctor ? 5 : 6}>Loading…</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
              {day && (isDoctor || user?.role === 'admin') && <RepHours hours={day.hours} doctors={isDoctor ? doctors.filter((d) => d.id === user?.id) : doctors} own={isDoctor ? user?.id : undefined} onSaved={loadDay} />}
            </>
          )}

          {tab === 'reps' && (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Representative</th>
                    <th>Company</th>
                    <th>Mobile</th>
                    <th>Last came</th>
                  </tr>
                </thead>
                <tbody>
                  {reps?.map((r) => (
                    <tr key={r.id} onClick={() => setPanel({ kind: 'rep', rep: r })} className={r.is_active ? '' : 'faded'}>
                      <td>
                        <strong>{r.name}</strong>
                        {!r.is_active && <span className="pill off">No longer comes</span>}
                        <small className="muted block">{[r.division, r.area].filter(Boolean).join(' · ')}</small>
                      </td>
                      <td>{r.company?.name}</td>
                      <td>{r.mobile}</td>
                      <td>
                        {r.last_visit ? (
                          <>
                            {longDate(r.last_visit.arrived_at)}
                            <small className="muted block">
                              {r.last_visit.doctor?.name} · {STATUS[r.last_visit.status].label}
                            </small>
                          </>
                        ) : (
                          <span className="muted">Not yet</span>
                        )}
                      </td>
                    </tr>
                  ))}
                  {reps && reps.length === 0 && (
                    <tr className="empty static">
                      <td colSpan={4}>{query ? 'Nobody on the list matches.' : 'No representative is on the list yet. They are added as they are logged.'}</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {tab === 'companies' && <Companies companies={companies} canChange={canLog} onChange={loadCompanies} />}
          {tab === 'catalogue' && <RepCatalogue />}
          {tab === 'samples' && seesVisits && <RepSamples key={sampleStart?.visitId ?? 'plain'} doctors={doctors} start={sampleStart} />}
        </section>

        {panel?.kind === 'log' && <LogVisit doctors={doctors} hours={day?.hours ?? {}} onClose={() => setPanel(null)} onLogged={(v) => done(`${v.rep?.name} is on ${v.doctor?.name}’s waiting list.`)} />}
        {panel?.kind === 'close' && <CloseVisit key={panel.visit.id} visit={panel.visit} canKeep={(isDoctor && panel.visit.doctor?.id === user?.id) || user?.role === 'admin'} onClose={() => setPanel(null)} onSaved={() => done('Saved.')} />}
        {panel?.kind === 'rep' && <RepPanel key={panel.rep?.id ?? 'new'} rep={panel.rep} canChange={canLog} showVisits={seesVisits} onClose={() => setPanel(null)} onSaved={() => done('Saved.')} />}
      </div>
    </>
  )
}

/** The desk logging a representative who has just arrived: who they are and which doctor they came to meet. */
function LogVisit({ doctors, hours, onClose, onLogged }: { doctors: Doctor[]; hours: Hours; onClose: () => void; onLogged: (visit: Visit) => void }) {
  const [term, setTerm] = useState('')
  const [found, setFound] = useState<Rep[]>([])
  const [rep, setRep] = useState<Rep | null>(null)
  const [adding, setAdding] = useState(false)
  const [doctorId, setDoctorId] = useState(doctors.length === 1 ? String(doctors[0].id) : '')
  const [purpose, setPurpose] = useState('')
  const [anyway, setAnyway] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (rep || term.trim().length < 2) return
    let live = true
    const wait = window.setTimeout(() => api.get<Rep[]>('/reps/people', { params: { q: term.trim() } }).then((res) => live && setFound(res.data.filter((r) => r.is_active).slice(0, 8))), 200)
    return () => {
      live = false
      window.clearTimeout(wait)
    }
  }, [term, rep])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!rep) return setErrors({ medical_rep_id: 'Choose the representative, or add them.' })
    setSaving(true)
    setErrors({})
    try {
      onLogged((await api.post<Visit>('/reps/visits', { medical_rep_id: rep.id, doctor_id: doctorId || null, purpose: purpose || null, ...(anyway ? { anyway: true } : {}) })).data)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const rule = hoursText(hours[doctorId])

  if (adding) return <RepPanel rep={null} startName={term} canChange showVisits={false} onClose={() => setAdding(false)} onSaved={(saved) => { setRep(saved); setAdding(false) }} />

  return (
    <form className="card panel" onSubmit={submit} noValidate>
      <div className="panel-head">
        <h2>Log a visit</h2>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      {rep ? (
        <div className="rep-chosen">
          <span>
            <strong>{rep.name}</strong>
            <small className="muted block">{[rep.company?.name, rep.division, rep.mobile].filter(Boolean).join(' · ')}</small>
          </span>
          <button type="button" className="link" onClick={() => setRep(null)}>
            Change
          </button>
        </div>
      ) : (
        <>
          <label className="field">
            <span className="field-label">Representative *</span>
            <input type="search" autoFocus placeholder="Type the name, mobile or company" value={term} onChange={(e) => setTerm(e.target.value)} />
          </label>
          {term.trim().length >= 2 && (
            <ul className="picker">
              {found.map((r) => (
                <li key={r.id}>
                  <button type="button" onClick={() => setRep(r)}>
                    <strong>{r.name}</strong>
                    <small className="muted block">{[r.company?.name, r.division, r.mobile].filter(Boolean).join(' · ')}</small>
                  </button>
                </li>
              ))}
              <li>
                <button type="button" onClick={() => setAdding(true)}>
                  + Add “{term.trim()}” as a new representative
                </button>
              </li>
            </ul>
          )}
          {errors.medical_rep_id && <span className="field-error">{errors.medical_rep_id}</span>}
        </>
      )}
      {rep && errors.medical_rep_id && <p className="form-error">{errors.medical_rep_id}</p>}

      <div className="gap-top">
        <SelectField
          label="Came to meet"
          required
          value={doctorId}
          onChange={(v) => {
            setDoctorId(v)
            setAnyway(false)
          }}
          options={doctors.map((d) => ({ value: String(d.id), label: d.name }))}
          placeholder="Select doctor"
          error={errors.doctor_id}
        />
        {rule && <small className="muted">Sees representatives {rule}.</small>}
      </div>
      {errors.outside_hours && (
        <div className="grid gap-top">
          <OutsideHours message={errors.outside_hours} checked={anyway} onChange={setAnyway} />
        </div>
      )}
      <div className="gap-top">
        <TextField label="What for (optional)" value={purpose} onChange={setPurpose} placeholder="New product, regular call…" error={errors.purpose} />
      </div>

      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving || !rep || !doctorId}>
          {saving ? 'Saving…' : 'Put on the waiting list'}
        </button>
      </div>
    </form>
  )
}

/** After the meeting: whether the doctor met them, what was spoken about, and when they come next. */
function CloseVisit({ visit, canKeep, onClose, onSaved }: { visit: Visit; canKeep: boolean; onClose: () => void; onSaved: () => void }) {
  // The desk may write the drugs down while the representative is still waiting; the doctor closes the visit.
  const [status, setStatus] = useState<string>(visit.status === 'waiting' && canKeep ? 'met' : visit.status)
  const [drugs, setDrugs] = useState<RepDrug[]>(() =>
    (visit.drugs ?? []).length > 0
      ? visit.drugs!.map((d) => ({ name: d.name, composition: d.composition ?? '', uses: d.uses ?? '', dose: d.dose ?? '', frequency: d.frequency ?? '', timing: d.timing ?? '', duration: d.duration ?? '', note: d.note ?? '', mrp: d.mrp === null ? '' : String(d.mrp), rate: d.rate === null ? '' : String(d.rate), scheme: d.scheme ?? '', keep: d.keep }))
      : [emptyDrug()],
  )
  const [catalogue, setCatalogue] = useState<CatalogueBrand[]>([])
  const companyId = visit.rep?.company?.id
  useEffect(() => {
    if (!companyId) return
    let live = true
    api.get<{ products: CatalogueBrand[] }>('/reps/products', { params: { company_id: companyId } }).then((res) => live && setCatalogue(res.data.products)).catch(() => undefined)
    return () => {
      live = false
    }
  }, [companyId])
  const [discussed, setDiscussed] = useState(visit.discussed ?? '')
  const [offers, setOffers] = useState(visit.offers ?? '')
  const [nextOn, setNextOn] = useState(visit.next_on ?? '')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      await api.put(`/reps/visits/${visit.id}`, { status, purpose: visit.purpose, discussed: discussed || null, offers: offers || null, next_on: nextOn || null, drugs: filledDrugs(drugs) })
      onSaved()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="card panel wide" onSubmit={submit} noValidate>
      <div className="panel-head">
        <div>
          <h2>{visit.rep?.name}</h2>
          <span className="muted">
            {[visit.rep?.company?.name, visit.rep?.division].filter(Boolean).join(' · ')} · came at {clock(visit.arrived_at)}
            {visit.doctor ? ` to meet ${visit.doctor.name}` : ''}
          </span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      <SelectField label="What happened" required value={status} onChange={setStatus} options={STATUS_CHOICES} error={errors.status} />
      <h3>Their drugs</h3>
      <p className="muted small">One drug to a block, as on a prescription. A brand new to {visit.rep?.company?.name ?? 'the company'}’s catalogue is added to it, with its price and offer.</p>
      <RepDrugRows rows={drugs} onChange={setDrugs} catalogue={catalogue} canKeep={canKeep} />
      {Object.entries(errors).some(([key]) => key.startsWith('drugs')) && <p className="form-error">{Object.entries(errors).find(([key]) => key.startsWith('drugs'))?.[1]}</p>}
      <label className="field gap-top">
        <span className="field-label">Anything else spoken about</span>
        <textarea rows={2} value={discussed} onChange={(e) => setDiscussed(e.target.value)} placeholder="Notes that are not about one drug" />
      </label>
      <label className="field gap-top">
        <span className="field-label">Other offers and schemes</span>
        <textarea rows={2} value={offers} onChange={(e) => setOffers(e.target.value)} placeholder="An offer on the whole order, a camp, and the like" />
      </label>
      <div className="gap-top">
        <TextField label={status === 'come_back' ? 'Come back on' : 'Next visit on (optional)'} required={status === 'come_back'} type="date" value={nextOn} onChange={setNextOn} min={visit.arrived_at.slice(0, 10)} error={errors.next_on} />
      </div>

      {(errors._ ?? errors.discussed ?? errors.offers) && <p className="form-error">{errors._ ?? errors.discussed ?? errors.offers}</p>}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving}>
          {saving ? 'Saving…' : 'Save'}
        </button>
      </div>
    </form>
  )
}

/** One representative: their details, changed by the desk, and every time they came. */
function RepPanel({ rep, startName, canChange, showVisits, onClose, onSaved }: { rep: Rep | null; startName?: string; canChange: boolean; showVisits: boolean; onClose: () => void; onSaved: (rep: Rep) => void }) {
  const [draft, setDraft] = useState(() =>
    rep
      ? { name: rep.name, company: rep.company?.name ?? '', mobile: rep.mobile ?? '', division: rep.division ?? '', area: rep.area ?? '', manager_name: rep.manager_name ?? '', manager_mobile: rep.manager_mobile ?? '', notes: rep.notes ?? '' }
      : { ...noRep, name: startName && !/^\d/.test(startName) ? startName : '', mobile: startName && /^\d/.test(startName) ? startName : '' },
  )
  const [active, setActive] = useState(rep?.is_active ?? true)
  const [names, setNames] = useState<string[]>([])
  const [visits, setVisits] = useState<Visit[] | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const set = (key: keyof typeof noRep) => (value: string) => setDraft((d) => ({ ...d, [key]: value }))

  useEffect(() => {
    api.get<Company[]>('/reps/companies').then((res) => setNames(res.data.map((c) => c.name))).catch(() => undefined)
    if (rep && showVisits) api.get<{ visits: Visit[] }>(`/reps/people/${rep.id}`).then((res) => setVisits(res.data.visits)).catch(() => setVisits([]))
  }, [rep, showVisits])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const body = { ...Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v.trim() || null])), is_active: active }
    try {
      onSaved((await (rep ? api.put<Rep>(`/reps/people/${rep.id}`, body) : api.post<Rep>('/reps/people', body))).data)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="card panel" onSubmit={submit} noValidate>
      <div className="panel-head">
        <h2>{rep ? rep.name : 'New representative'}</h2>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      <fieldset disabled={!canChange}>
        <div className="grid">
          <TextField label="Name" required value={draft.name} onChange={set('name')} error={errors.name} />
          <label className="field">
            <span className="field-label">Company *</span>
            <input list="rep-company-names" value={draft.company} onChange={(e) => set('company')(e.target.value)} placeholder="Choose, or type a new one" aria-invalid={Boolean(errors.company)} />
            <datalist id="rep-company-names">
              {names.map((n) => (
                <option key={n} value={n} />
              ))}
            </datalist>
            {errors.company && <span className="field-error">{errors.company}</span>}
          </label>
          <TextField label="Mobile" value={draft.mobile} onChange={set('mobile')} inputMode="tel" error={errors.mobile} />
          <TextField label="Division" value={draft.division} onChange={set('division')} placeholder="Cardiac, Gastro…" error={errors.division} />
          <TextField label="Area covered" value={draft.area} onChange={set('area')} error={errors.area} />
          <TextField label="Manager’s name" value={draft.manager_name} onChange={set('manager_name')} error={errors.manager_name} />
          <TextField label="Manager’s mobile" value={draft.manager_mobile} onChange={set('manager_mobile')} inputMode="tel" error={errors.manager_mobile} />
          <TextField label="Notes" value={draft.notes} onChange={set('notes')} error={errors.notes} />
        </div>
        {rep && (
          <label className="check">
            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
            Still comes to this hospital
          </label>
        )}
      </fieldset>

      {errors._ && <p className="form-error">{errors._}</p>}
      {canChange && (
        <div className="panel-foot">
          <button className="btn primary" disabled={saving || draft.name.trim().length < 2 || draft.company.trim().length < 2}>
            {saving ? 'Saving…' : rep ? 'Save' : 'Add to the list'}
          </button>
        </div>
      )}

      {rep && showVisits && (
        <>
          <p className="gap-top">
            <a className="btn ghost small-btn" href={`/reps/reference/print?rep=${rep.id}`} target="_blank" rel="noreferrer">
              Print the drugs they spoke about
            </a>
          </p>
          <h3>Visits</h3>
          {visits === null && <p className="loading">Loading…</p>}
          {visits?.length === 0 && <p className="muted">No visit on record yet.</p>}
          <ul className="history">
            {visits?.map((v) => (
              <li key={v.id}>
                <span>
                  {longDate(v.arrived_at)} · <DoctorLink id={v.doctor?.id}>{v.doctor?.name}</DoctorLink>
                  {(v.drugs ?? []).map((d) => (
                    <small className="block" key={d.id}>
                      <strong>{d.name}</strong>
                      {d.composition ? ` · ${d.composition}` : ''}
                      {[d.dose, d.frequency, d.timing, d.duration].filter(Boolean).length > 0 ? ` · ${[d.dose, d.frequency, d.timing, d.duration].filter(Boolean).join(', ')}` : ''}
                    </small>
                  ))}
                  {v.discussed && <small className="block">{v.discussed}</small>}
                  {v.offers && <small className="muted block">Offer: {v.offers}</small>}
                  {v.next_on && <small className="muted block">Next: {longDate(v.next_on)}</small>}
                </span>
                <span className={`pill ${STATUS[v.status].pill}`}>{STATUS[v.status].label}</span>
              </li>
            ))}
          </ul>
        </>
      )}
    </form>
  )
}

/** Each company with its people and the catalogues and price lists they handed over. */
function Companies({ companies, canChange, onChange }: { companies: Company[] | null; canChange: boolean; onChange: () => void }) {
  const [error, setError] = useState('')
  const [busy, setBusy] = useState<number | null>(null)

  const upload = async (company: Company, file: File | undefined) => {
    if (!file) return
    setBusy(company.id)
    setError('')
    const body = new FormData()
    body.append('file', file)
    try {
      await api.post(`/reps/companies/${company.id}/files`, body)
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'The file was not saved.')
    } finally {
      setBusy(null)
    }
  }
  const open = async (f: CompanyFile) => {
    const res = await api.get<Blob>(`/reps/files/${f.id}/download`, { responseType: 'blob' })
    window.open(URL.createObjectURL(res.data), '_blank')
  }
  const remove = async (f: CompanyFile) => {
    if (!window.confirm(`Remove “${f.title}”?`)) return
    await api.delete(`/reps/files/${f.id}`)
    onChange()
  }
  const rename = async (company: Company) => {
    const name = window.prompt('The company’s name', company.name)?.trim()
    if (!name || name === company.name) return
    try {
      await api.put(`/reps/companies/${company.id}`, { name, notes: company.notes })
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'The name was not saved.')
    }
  }

  if (!companies) return <p className="loading">Loading…</p>
  if (companies.length === 0) return <p className="muted">No company is on the list yet. A company is added with its first representative.</p>

  return (
    <>
      {error && <p className="form-error">{error}</p>}
      <p className="muted small">Keep each company’s catalogue or price list here as the file its representative gives: a PDF, a photo or an Excel sheet.</p>
      <div className="rep-companies">
        {companies.map((c) => (
          <div className="rep-company" key={c.id}>
            <div className="row">
              <strong className="grow">{c.name}</strong>
              {canChange && (
                <button type="button" className="link" onClick={() => void rename(c)}>
                  Rename
                </button>
              )}
            </div>
            <small className="muted">
              {c.reps_count ?? 0} {c.reps_count === 1 ? 'representative' : 'representatives'}
              {c.last_visit_at ? ` · last came ${longDate(c.last_visit_at)}` : ''}
            </small>
            {c.reps && c.reps.length > 0 && <p className="rep-names">{c.reps.map((r) => [r.name, r.division, r.mobile].filter(Boolean).join(' · ')).join('  |  ')}</p>}
            <ul className="history">
              {c.files?.map((f) => (
                <li key={f.id}>
                  <span>
                    <button type="button" className="link" onClick={() => void open(f)}>
                      {f.title}
                    </button>
                    <small className="muted block">
                      {longDate(f.created_at)}
                      {f.uploader ? ` · ${f.uploader.name}` : ''}
                    </small>
                  </span>
                  {canChange && (
                    <button type="button" className="link danger" onClick={() => void remove(f)}>
                      Remove
                    </button>
                  )}
                </li>
              ))}
            </ul>
            <a className="link" href={`/reps/reference/print?company=${c.id}`} target="_blank" rel="noreferrer">
              Print its drugs as a reference sheet
            </a>
            {canChange && (
              <label className="btn ghost small-btn rep-attach">
                {busy === c.id ? 'Saving…' : '+ Attach a catalogue or price list'}
                <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.xls,.xlsx,.csv" hidden disabled={busy !== null} onChange={(e) => void upload(c, e.target.files?.[0]).then(() => (e.target.value = ''))} />
              </label>
            )}
          </div>
        ))}
      </div>
    </>
  )
}

/** A doctor's own hours for representatives, and how many in a day. Left empty, they are seen at any time. */
function RepHours({ hours, doctors, own, onSaved }: { hours: Hours; doctors: Doctor[]; own?: number; onSaved: () => void }) {
  const [doctorId, setDoctorId] = useState(own ? String(own) : '')
  const rule = hours[doctorId] ?? {}
  const [draft, setDraft] = useState<{ for: string; from: string; to: string; most: string } | null>(null)
  const shown = draft && draft.for === doctorId ? draft : { for: doctorId, from: rule.from ?? '', to: rule.to ?? '', most: rule.most ? String(rule.most) : '' }
  const [said, setSaid] = useState('')

  const save = async (clear = false) => {
    setSaid('')
    try {
      await api.put('/reps/hours', clear ? { doctor_id: doctorId } : { doctor_id: doctorId, from: shown.from || null, to: shown.to || null, most: shown.most || null })
      setDraft(null)
      setSaid(clear ? 'Cleared: representatives are seen at any time.' : 'Saved.')
      onSaved()
    } catch (err) {
      setSaid(Object.values(fieldErrors(err))[0] ?? 'Not saved.')
    }
  }

  return (
    <details className="rep-hours">
      <summary>{own ? 'The hours I see representatives' : 'The hours each doctor sees representatives'}</summary>
      <p className="muted small">Outside these hours, or past this number in a day, the front desk is told before it logs a representative. It can still log one when you agree.</p>
      <div className="row wrap">
        {!own && (
          <select value={doctorId} onChange={(e) => setDoctorId(e.target.value)} aria-label="Doctor" className="narrow">
            <option value="">Select doctor</option>
            {doctors.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
                {hoursText(hours[String(d.id)]) ? ` (${hoursText(hours[String(d.id)])})` : ''}
              </option>
            ))}
          </select>
        )}
        <label className="field">
          <span className="field-label">From</span>
          <input type="time" value={shown.from} disabled={!doctorId} onChange={(e) => setDraft({ ...shown, from: e.target.value })} />
        </label>
        <label className="field">
          <span className="field-label">To</span>
          <input type="time" value={shown.to} disabled={!doctorId} onChange={(e) => setDraft({ ...shown, to: e.target.value })} />
        </label>
        <label className="field">
          <span className="field-label">At most in a day</span>
          <input type="number" min={1} max={50} value={shown.most} disabled={!doctorId} onChange={(e) => setDraft({ ...shown, most: e.target.value })} />
        </label>
        <button type="button" className="btn primary" disabled={!doctorId} onClick={() => void save()}>
          Save
        </button>
        {hours[doctorId] && (
          <button type="button" className="link" onClick={() => void save(true)}>
            Clear
          </button>
        )}
      </div>
      {said && <p className="muted small">{said}</p>}
    </details>
  )
}
