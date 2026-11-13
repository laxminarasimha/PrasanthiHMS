import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { DoctorTabs } from '../components/DoctorTabs'
import { MedicineRows, emptyMedicine, filledMedicines } from '../components/MedicineRows'
import { longDate } from '../format'
import type { Doctor, DoctorLibrary as Library, PhraseKind, PrescribedMedicine, RememberedPhrase, RxGroup } from '../types'
import { MedicineLibrary, RxSettingsForm } from './DoctorRxSettings'

const PHRASE_TABS: [PhraseKind, string][] = [['complaint', 'Complaints'], ['diagnosis', 'Diagnoses'], ['advice', 'Advice']]
type Tab = PhraseKind | 'rx' | 'templates' | 'pad' | 'medicines' | 'settings'

const SECTION_LABELS: Record<string, string> = {
  quick_notes: 'Quick notes', history: 'History', past_medication: 'Past medication', general_exam: 'General examination',
  systemic_exam: 'Systemic examination', assessment_plan: 'Assessment / plan', tests: 'Tests prescribed', results: 'Test results', refer_to: 'Refer to',
  vitals: 'Vitals', complaints: 'Complaints', diagnosis: 'Diagnosis', prescription: 'Prescription', advice: 'Advice', follow_up: 'Refer to and next visit date',
}

/** A doctor's own shortcuts for the visit pad. */
export function DoctorLibrary() {
  const { user } = useAuth()
  const isDoctor = user?.role === 'doctor'
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [doctorId, setDoctorId] = useState<number | null>(isDoctor ? user!.id : null)
  const [library, setLibrary] = useState<Library | null>(null)
  const [tab, setTab] = useState<Tab>('complaint')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [suggestions, setSuggestions] = useState<string[]>([])

  useEffect(() => {
    api.get<string[]>('/medicines/suggest').then((res) => setSuggestions(res.data))
    if (!isDoctor) {
      api.get<Doctor[]>('/doctors').then((res) => {
        setDoctors(res.data)
        setDoctorId((current) => current ?? res.data[0]?.id ?? null)
      })
    }
  }, [isDoctor])

  const load = useCallback(() => {
    if (doctorId) api.get<Library>('/doctor/library', { params: { doctor_id: doctorId } }).then((res) => setLibrary(res.data))
  }, [doctorId])
  useEffect(load, [load])

  /** Runs a change, then reloads; shows the server's message if it is refused. */
  const run = async (action: () => Promise<unknown>, done = '') => {
    setError('')
    setNotice('')
    try {
      await action()
      setNotice(done)
      load()
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
      return false
    }
  }

  const phrases = (kind: PhraseKind) => (library && !Array.isArray(library.phrases) ? (library.phrases[kind] ?? []) : [])

  return (
    <>
      <DoctorTabs />
      <section className="card">
        <div className="toolbar">
          <h1>{isDoctor ? 'My library' : 'Doctor library'}</h1>
          {!isDoctor && (
            <select className="narrow" value={doctorId ?? ''} onChange={(e) => setDoctorId(Number(e.target.value))} aria-label="Doctor">
              {doctors.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          )}
          <span className="muted grow">Shortcuts used on the visit pad. They build up on their own as visits are completed.</span>
        </div>

        <div className="tabs">
          {PHRASE_TABS.map(([kind, label]) => (
            <button key={kind} className={tab === kind ? 'active' : ''} onClick={() => setTab(kind)}>
              {label} <span>{phrases(kind).length}</span>
            </button>
          ))}
          <button className={tab === 'rx' ? 'active' : ''} onClick={() => setTab('rx')}>
            Rx groups <span>{library?.rx_groups.length ?? 0}</span>
          </button>
          <button className={tab === 'templates' ? 'active' : ''} onClick={() => setTab('templates')}>
            Templates <span>{library?.templates.length ?? 0}</span>
          </button>
          <button className={tab === 'medicines' ? 'active' : ''} onClick={() => setTab('medicines')}>
            Medicines <span>{library?.medicine_defaults.length ?? 0}</span>
          </button>
          <button className={tab === 'pad' ? 'active' : ''} onClick={() => setTab('pad')}>
            Visit pad sections
          </button>
          <button className={tab === 'settings' ? 'active' : ''} onClick={() => setTab('settings')}>
            Prescription settings
          </button>
        </div>

        {notice && <p className="notice">{notice}</p>}
        {error && <p className="form-error">{error}</p>}

        {!library || !doctorId ? (
          <p className="muted">{doctorId ? 'Loading…' : 'No doctors have been added yet.'}</p>
        ) : tab === 'rx' ? (
          <RxGroups key={doctorId} groups={library.rx_groups} doctorId={doctorId} run={run} names={suggestions} defaults={library.medicine_defaults} />
        ) : tab === 'templates' ? (
          <table>
            <thead>
              <tr>
                <th>Template</th>
                <th>Diagnosis</th>
                <th>Medicines</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {library.templates.map((t) => (
                <tr key={t.id} className="static">
                  <td>
                    <strong>{t.name}</strong>
                  </td>
                  <td className="wrap">{t.content.diagnosis || '—'}</td>
                  <td className="wrap">{(t.content.medicines ?? []).map((m) => m.medicine).join(', ') || '—'}</td>
                  <td className="num">
                    <button className="link danger" onClick={() => window.confirm(`Delete the template "${t.name}"?`) && run(() => api.delete(`/doctor/templates/${t.id}`), 'Template deleted.')}>
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
              {library.templates.length === 0 && (
                <tr className="empty">
                  <td colSpan={4}>No templates yet. On a visit pad, fill in a typical visit and choose "Save as template".</td>
                </tr>
              )}
            </tbody>
          </table>
        ) : tab === 'medicines' ? (
          <MedicineLibrary rows={library.medicine_defaults} doctorId={doctorId} run={run} />
        ) : tab === 'settings' ? (
          <RxSettingsForm key={doctorId} settings={library.rx_settings} doctorId={doctorId} run={run} />
        ) : tab === 'pad' ? (
          <div>
            <p className="muted">Untick the parts of the visit pad this doctor does not use. A hidden part still appears on a visit where it already has something written.</p>
            {library.pad_sections.map((key) => (
              <label className="check" key={key}>
                <input
                  type="checkbox"
                  checked={!library.pad_hidden_sections.includes(key)}
                  onChange={(e) =>
                    run(
                      () => api.put('/doctor/pad-sections', { doctor_id: doctorId, hidden: e.target.checked ? library.pad_hidden_sections.filter((k) => k !== key) : [...library.pad_hidden_sections, key] }),
                      'Visit pad sections saved.',
                    )
                  }
                />
                {SECTION_LABELS[key] ?? key}
              </label>
            ))}
            <p className="muted small gap-top">Vitals, complaints, diagnosis, prescription and advice are always shown.</p>

            <h3>Order of the visit pad</h3>
            <p className="muted">Move a part up or down to change where it sits on this doctor's visit pad. The printed prescription keeps its usual order.</p>
            <ol className="pad-order">
              {library.pad_section_order.map((key, i) => {
                const swap = (to: number) => {
                  const order = [...library.pad_section_order]
                  ;[order[i], order[to]] = [order[to], order[i]]
                  run(() => api.put('/doctor/pad-sections', { doctor_id: doctorId, hidden: library.pad_hidden_sections, order }), 'Visit pad order saved.')
                }
                return (
                  <li key={key} className={library.pad_hidden_sections.includes(key) ? 'off' : ''}>
                    <span className="grow">
                      {SECTION_LABELS[key] ?? key}
                      {library.pad_hidden_sections.includes(key) && <small className="muted"> · hidden</small>}
                    </span>
                    <button type="button" className="btn ghost small-btn" disabled={i === 0} onClick={() => swap(i - 1)} aria-label={`Move ${SECTION_LABELS[key] ?? key} up`}>
                      ↑
                    </button>
                    <button type="button" className="btn ghost small-btn" disabled={i === library.pad_section_order.length - 1} onClick={() => swap(i + 1)} aria-label={`Move ${SECTION_LABELS[key] ?? key} down`}>
                      ↓
                    </button>
                  </li>
                )
              })}
            </ol>
            <button
              type="button"
              className="link"
              onClick={() => run(() => api.put('/doctor/pad-sections', { doctor_id: doctorId, hidden: library.pad_hidden_sections, order: [] }), 'Visit pad put back in the standard order.')}
            >
              Put back the standard order
            </button>
          </div>
        ) : (
          <Phrases key={`${doctorId}-${tab}`} kind={tab} rows={phrases(tab)} doctorId={doctorId} run={run} />
        )}
      </section>
    </>
  )
}

type Run = (action: () => Promise<unknown>, done?: string) => Promise<boolean>

function Phrases({ kind, rows, doctorId, run }: { kind: PhraseKind; rows: RememberedPhrase[]; doctorId: number; run: Run }) {
  const [text, setText] = useState('')
  const [picked, setPicked] = useState<number[]>([])
  const [editing, setEditing] = useState<{ id: number; text: string } | null>(null)

  const add = async (e: FormEvent) => {
    e.preventDefault()
    if (text.trim() && (await run(() => api.post('/doctor/phrases', { doctor_id: doctorId, kind, text })))) setText('')
  }

  return (
    <>
      <form className="row" onSubmit={add}>
        <input className="grow" placeholder={`Add ${kind === 'advice' ? 'a line of advice' : `a ${kind}`} to remember`} value={text} onChange={(e) => setText(e.target.value)} maxLength={255} />
        <button className="btn primary" disabled={!text.trim()}>
          Add
        </button>
        <button
          type="button"
          className="btn ghost"
          disabled={picked.length === 0}
          onClick={async () => {
            if (await run(() => api.delete('/doctor/phrases', { data: { doctor_id: doctorId, ids: picked } }), `${picked.length} deleted.`)) setPicked([])
          }}
        >
          Delete selected{picked.length ? ` (${picked.length})` : ''}
        </button>
      </form>
      <div className="table-wrap gap-top">
        <table>
          <thead>
            <tr>
              <th className="tick">
                <input type="checkbox" className="auto" aria-label="Select all" checked={rows.length > 0 && picked.length === rows.length} onChange={(e) => setPicked(e.target.checked ? rows.map((r) => r.id) : [])} />
              </th>
              <th>Remembered text</th>
              <th className="num">Used</th>
              <th>Last used</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="static">
                <td className="tick">
                  <input type="checkbox" className="auto" aria-label={`Select ${r.text}`} checked={picked.includes(r.id)} onChange={() => setPicked((ids) => (ids.includes(r.id) ? ids.filter((i) => i !== r.id) : [...ids, r.id]))} />
                </td>
                <td className="wrap wide">
                  {editing?.id === r.id ? (
                    <form
                      className="row"
                      onSubmit={async (e) => {
                        e.preventDefault()
                        if (await run(() => api.put(`/doctor/phrases/${r.id}`, { text: editing.text }))) setEditing(null)
                      }}
                    >
                      <input className="grow" autoFocus value={editing.text} onChange={(e) => setEditing({ id: r.id, text: e.target.value })} maxLength={255} aria-label="Remembered text" />
                      <button className="btn primary small-btn">Save</button>
                      <button type="button" className="btn ghost small-btn" onClick={() => setEditing(null)}>
                        Cancel
                      </button>
                    </form>
                  ) : (
                    r.text
                  )}
                </td>
                <td className="num">{r.uses}</td>
                <td>{r.last_used_at ? longDate(r.last_used_at) : 'Never'}</td>
                <td className="num">
                  {editing?.id !== r.id && (
                    <button className="link" onClick={() => setEditing({ id: r.id, text: r.text })}>
                      Edit
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr className="empty">
                <td colSpan={5}>Nothing remembered yet. Each line typed on a completed visit is added here.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}

function RxGroups({ groups, doctorId, run, names, defaults }: { groups: RxGroup[]; doctorId: number; run: Run; names: string[]; defaults: PrescribedMedicine[] }) {
  const [editing, setEditing] = useState<RxGroup | 'new' | null>(null)
  const [name, setName] = useState('')
  const [rows, setRows] = useState<PrescribedMedicine[]>([emptyMedicine()])

  const open = (target: RxGroup | 'new') => {
    setEditing(target)
    setName(target === 'new' ? '' : target.name)
    setRows(target === 'new' ? [emptyMedicine()] : target.medicines.map((m) => ({ ...emptyMedicine(), ...m })))
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    const body = { doctor_id: doctorId, name, medicines: filledMedicines(rows) }
    const ok = await run(() => (editing === 'new' ? api.post('/doctor/rx-groups', body) : api.put(`/doctor/rx-groups/${(editing as RxGroup).id}`, body)), `Rx group "${name}" saved.`)
    if (ok) setEditing(null)
  }

  return (
    <>
      <p className="muted">An Rx group is a set of medicines you often give together. On the visit pad, one click adds the whole set.</p>
      <table>
        <thead>
          <tr>
            <th>Rx group</th>
            <th>Medicines</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {groups.map((g) => (
            <tr key={g.id} className={editing !== 'new' && editing?.id === g.id ? 'selected' : ''} onClick={() => open(g)}>
              <td>
                <strong>{g.name}</strong>
              </td>
              <td className="wrap wide">{g.medicines.map((m) => [m.medicine, m.frequency, m.duration].filter(Boolean).join(' · ')).join('; ')}</td>
              <td className="num">
                <button
                  className="link danger"
                  onClick={(e) => {
                    e.stopPropagation()
                    if (window.confirm(`Delete the Rx group "${g.name}"?`)) run(() => api.delete(`/doctor/rx-groups/${g.id}`), 'Rx group deleted.').then(() => setEditing(null))
                  }}
                >
                  Delete
                </button>
              </td>
            </tr>
          ))}
          {groups.length === 0 && (
            <tr className="empty">
              <td colSpan={3}>No Rx groups yet.</td>
            </tr>
          )}
        </tbody>
      </table>
      {editing ? (
        <form className="lab-pick gap-top" onSubmit={save}>
          <label className="field">
            <span className="field-label">Group name</span>
            <input placeholder="e.g. Fever basic" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
          </label>
          <h3>Medicines</h3>
          <MedicineRows rows={rows} onChange={setRows} names={names} defaults={defaults} />
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Cancel
            </button>
            <button className="btn primary">{editing === 'new' ? 'Add Rx group' : 'Save changes'}</button>
          </div>
        </form>
      ) : (
        <button className="btn primary gap-top" onClick={() => open('new')}>
          + New Rx group
        </button>
      )}
    </>
  )
}
