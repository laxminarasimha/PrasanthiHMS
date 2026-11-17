import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import type { FieldErrors } from '../api'
import { flagFor } from '../components/LabResults'
import { PAPER_KINDS, paperStage } from '../components/PastRecords'
import type { Paper, Reading } from '../components/PastRecords'
import { clock, fullName, longDate, today } from '../format'
import { setUnsaved } from '../unsaved'

type Medicine = { name: string; dose: string; frequency: string; duration: string; instructions: string }
type Result = { test: string; parameter: string; value: string; unit: string; reference_range: string }
type Fact = { kind: string; title: string; since: string }
type Texts = { complaints: string; diagnosis: string; findings: string; advice: string; notes: string }
type Draft = Texts & { kind: string | null; record_date: string | null; doctor_name: string; hospital_name: string; allergies: string; medicines: Medicine[]; results: Result[]; facts: Fact[]; unsure: string[] }

type FullPaper = Paper & {
  read_text: string | null
  draft: Draft | null
  details: (Partial<Record<keyof Texts, string | null>> & { medicines?: Partial<Record<keyof Medicine, string | null>>[] }) | null
  results: (Omit<Result, 'unit' | 'reference_range'> & { unit: string | null; reference_range: string | null; flag: string | null })[]
  facts: { id: number; kind: string; title: string; since: string | null; is_active: boolean }[]
  patient_allergies: string | null
  name_on_paper: string | null
  reading: Reading
  known_tests: { test: string; parameter: string; unit: string | null }[]
}

type Form = Texts & { kind: string; record_date: string; doctor_name: string; hospital_name: string; allergies: string; medicines: Medicine[]; results: Result[]; facts: Fact[] }

const TEXTS: [keyof Texts, string, string][] = [
  ['complaints', 'Complaints and history', 'What the patient came with, as written'],
  ['diagnosis', 'Diagnosis or impression', ''],
  ['findings', 'Examination, vitals or report findings', ''],
  ['advice', 'Advice and follow-up', ''],
  ['notes', 'Anything else worth keeping', 'For a discharge summary: the course in hospital and procedures done'],
]
const FACT_KINDS: [string, string][] = [
  ['condition', 'Ongoing condition'],
  ['medication', 'Regular medicine'],
  ['surgery', 'Past surgery or admission'],
  ['family', 'Family history'],
  ['habit', 'Habit'],
  ['immunisation', 'Vaccine given'],
]
const blankMedicine = (): Medicine => ({ name: '', dose: '', frequency: '', duration: '', instructions: '' })
const blankResult = (): Result => ({ test: '', parameter: '', value: '', unit: '', reference_range: '' })
const blankFact = (): Fact => ({ kind: 'condition', title: '', since: '' })

/** The form as it should open: what was filed, else the draft a machine or a colleague left, else empty. */
function formOf(p: FullPaper): Form {
  const base = { kind: p.kind, record_date: p.record_date ?? '', doctor_name: p.doctor_name ?? '', hospital_name: p.hospital_name ?? '' }
  if (p.status === 'filed') {
    const d = p.details ?? {}
    return {
      ...base,
      complaints: d.complaints ?? '', diagnosis: d.diagnosis ?? '', findings: d.findings ?? '', advice: d.advice ?? '', notes: d.notes ?? '', allergies: '',
      medicines: (d.medicines ?? []).map((m) => ({ name: m.name ?? '', dose: m.dose ?? '', frequency: m.frequency ?? '', duration: m.duration ?? '', instructions: m.instructions ?? '' })),
      results: p.results.map((r) => ({ test: r.test === 'Outside report' ? '' : r.test, parameter: r.parameter, value: r.value, unit: r.unit ?? '', reference_range: r.reference_range ?? '' })),
      facts: [],
    }
  }
  const d = p.draft
  return {
    ...base,
    kind: p.kind !== 'other' ? p.kind : (d?.kind ?? 'other'),
    record_date: base.record_date || d?.record_date || '',
    doctor_name: base.doctor_name || d?.doctor_name || '',
    hospital_name: base.hospital_name || d?.hospital_name || '',
    complaints: d?.complaints ?? '', diagnosis: d?.diagnosis ?? '', findings: d?.findings ?? '', advice: d?.advice ?? '', notes: d?.notes ?? '', allergies: d?.allergies ?? '',
    medicines: d?.medicines ?? [], results: d?.results ?? [], facts: d?.facts ?? [],
  }
}

/** One scanned page, fetched with the signed-in session because scans are private. */
function Page({ page, zoom, turn }: { page: { id: number; original_name: string; mime: string }; zoom: number; turn: number }) {
  const [url, setUrl] = useState('')
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    let made = ''
    let gone = false
    api.get<Blob>(`/attachments/${page.id}/download`, { responseType: 'blob' }).then(
      (res) => {
        if (gone) return
        made = URL.createObjectURL(res.data)
        setUrl(made)
      },
      () => setFailed(true),
    )
    return () => {
      gone = true
      if (made) URL.revokeObjectURL(made)
    }
  }, [page.id])

  if (failed) return <p className="form-error">Could not open {page.original_name}.</p>
  if (!url) return <p className="muted">Opening {page.original_name}…</p>
  if (page.mime === 'application/pdf') {
    return (
      <div className="scan-pdf">
        <iframe title={page.original_name} src={url} />
        <a className="link" href={url} target="_blank" rel="noreferrer">
          Open {page.original_name} in a new tab
        </a>
      </div>
    )
  }
  // A page turned on its side is shown in a square box, so the turned picture has room.
  return (
    <div className={`scan-img ${turn % 2 ? 'sideways' : ''}`} style={{ width: `${zoom}%` }}>
      <img src={url} alt={`Scan: ${page.original_name}`} style={{ transform: `rotate(${turn * 90}deg)` }} />
    </div>
  )
}

/**
 * The checking screen for one old paper: the scan on one side, what goes into the record on the other.
 * A machine may have filled the form in; a person makes it right against the scan and files it.
 */
export function PastRecordCheck() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [paper, setPaper] = useState<FullPaper | null>(null)
  const [form, setForm] = useState<Form | null>(null)
  const [dirty, setDirty] = useState(false)
  const [arrived, setArrived] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState('')
  const [zoom, setZoom] = useState(100)
  const [turn, setTurn] = useState(0)
  // The timer below reads this, so a reading that arrives late does not wipe what was typed meanwhile.
  const dirtyNow = useRef(false)
  useEffect(() => {
    dirtyNow.current = dirty
  }, [dirty])

  const take = useCallback((p: FullPaper, replaceForm: boolean) => {
    setPaper(p)
    if (replaceForm) {
      setForm(formOf(p))
      setDirty(false)
      setArrived(false)
    }
  }, [])

  useEffect(() => {
    api.get<FullPaper>(`/past-records/${id}`).then(
      (res) => take(res.data, true),
      (e) => setErrors({ _: e.response?.status === 404 ? 'This paper is no longer here.' : (fieldErrors(e)._ ?? 'Could not open this paper.') }),
    )
  }, [id, take])

  // While the reading computer has the paper, look again now and then; a reading that arrives fills an untouched form.
  const waiting = paper?.status === 'to_check' && (paper.read_state === 'queued' || paper.read_state === 'reading')
  useEffect(() => {
    if (!waiting) return
    const timer = window.setInterval(() => {
      api.get<FullPaper>(`/past-records/${id}`).then((res) => {
        const done = res.data.read_state === 'done'
        take(res.data, done && !dirtyNow.current)
        if (done && dirtyNow.current) setArrived(true)
      })
    }, 8000)
    return () => window.clearInterval(timer)
  }, [waiting, id, take])

  useEffect(() => {
    setUnsaved(dirty ? 'What you typed for this paper is not saved yet. Leave anyway?' : null)
    return () => setUnsaved(null)
  }, [dirty])

  const known = useMemo(() => new Map((paper?.known_tests ?? []).map((t) => [t.parameter.toLowerCase(), t])), [paper?.known_tests])

  if (!paper || !form) return errors._ ? <p className="form-error">{errors._}</p> : <p className="loading">Opening the paper…</p>

  const filed = paper.status === 'filed'
  const stage = paperStage(paper, paper.reading)
  const set = (patch: Partial<Form>) => {
    setForm({ ...form, ...patch })
    setDirty(true)
    setNotice('')
  }
  const setRow = <K extends 'medicines' | 'results' | 'facts'>(list: K, index: number, patch: Partial<Form[K][number]>) =>
    set({ [list]: form[list].map((row, i) => (i === index ? { ...row, ...patch } : row)) } as Partial<Form>)
  const dropRow = (list: 'medicines' | 'results' | 'facts', index: number) => set({ [list]: form[list].filter((_, i) => i !== index) } as Partial<Form>)

  // Lines left completely empty are not sent; a half-filled line is, so the person is told what is missing.
  const payload = () => ({
    kind: form.kind, record_date: form.record_date || null, doctor_name: form.doctor_name.trim() || null, hospital_name: form.hospital_name.trim() || null,
    complaints: form.complaints, diagnosis: form.diagnosis, findings: form.findings, advice: form.advice, notes: form.notes, allergies: form.allergies.trim() || null,
    medicines: form.medicines.filter((m) => Object.values(m).some((v) => v.trim())),
    results: form.results.filter((r) => Object.values(r).some((v) => v.trim())).map((r) => ({ ...r, flag: flagFor(r.value.replace(/,/g, ''), r.reference_range || null) })),
    facts: form.facts.filter((f) => f.title.trim() || f.since.trim()),
  })

  const run = async (what: string, call: () => Promise<{ data: FullPaper }>, done: string, replaceForm = true) => {
    setBusy(what)
    setErrors({})
    setNotice('')
    try {
      const res = await call()
      take(res.data, replaceForm)
      setNotice(done)
      return true
    } catch (e) {
      setErrors(fieldErrors(e))
      return false
    } finally {
      setBusy('')
    }
  }

  const saveForLater = () => {
    const body = payload()
    return run('save', () => api.put(`/past-records/${paper.id}`, { kind: body.kind, record_date: body.record_date, doctor_name: body.doctor_name, hospital_name: body.hospital_name, draft: body }), 'Saved. It is still waiting to be filed.')
  }
  const file = () => run('file', () => api.post(`/past-records/${paper.id}/file`, payload()), filed ? 'Corrections saved.' : 'Filed in the patient’s record.')
  const readHere = () => run('marker', () => api.post(`/past-records/${paper.id}/read`, { engine: 'marker' }), 'Sent to the reading computer.', false)
  const readWithAi = () => {
    const pages = paper.pages.length
    if (!window.confirm(`Send ${pages === 1 ? 'this page' : `these ${pages} pages`} outside the hospital to the AI reading service?\n\nUse this for handwriting and pages the reading computer could not manage. The page is sent as it is, with whatever is written on it. The send is noted in the record log.`)) return
    if (dirty && !window.confirm('The new reading will replace what is typed in the form now. Go on?')) return
    void run('ai', () => api.post(`/past-records/${paper.id}/read`, { engine: 'ai' }), 'Read by AI. Check every line against the scan before filing.')
  }
  const remove = async () => {
    if (!window.confirm(filed ? 'Remove this paper, its scan, and the test values filed from it? Lines it added to the health summary stay. This cannot be undone.' : 'Remove this paper and its scan? Use this only for a paper scanned by mistake.')) return
    setBusy('remove')
    try {
      await api.delete(`/past-records/${paper.id}`)
      setUnsaved(null)
      navigate(`/patients/${paper.patient_id}`)
    } catch (e) {
      setErrors(fieldErrors(e))
      setBusy('')
    }
  }

  const firstError = errors._ ?? Object.values(errors)[0]
  const unsure = !filed ? (paper.draft?.unsure ?? []) : []

  return (
    <div className="stack">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to={`/patients/${paper.patient_id}`}>
            ← Patient
          </Link>
          <div className="grow">
            <h1>Old paper: {paper.patient ? fullName(paper.patient) : ''}</h1>
            <span className="muted">
              {paper.patient?.uhid} · {paper.patient?.age_label} / {paper.patient?.gender.charAt(0).toUpperCase()} · scanned {longDate(paper.created_at)}
              {paper.creator ? ` by ${paper.creator.name}` : ''}
              {filed && paper.checked_at ? ` · filed ${longDate(paper.checked_at)} ${clock(paper.checked_at)}${paper.checker ? ` by ${paper.checker.name}` : ''}` : ''}
            </span>
          </div>
          <span className={`pill ${stage.pill}`}>{stage.words}</span>
          <Link className="btn ghost small-btn" to="/past-records">
            All papers to check
          </Link>
        </div>
        {paper.name_on_paper && (
          <p className="form-error">
            The name on this paper reads “{paper.name_on_paper}”, not {paper.patient?.name}. Make sure it belongs to this patient before filing it.
          </p>
        )}
        {!filed && (
          <div className="reading-bar">
            <span className="grow">
              {paper.read_state === 'done' && `Read by ${paper.read_engine === 'ai' ? 'AI' : 'the reading computer'}${paper.read_at ? ` on ${longDate(paper.read_at)} at ${clock(paper.read_at)}` : ''}. The form below is its draft: check every line against the scan.`}
              {paper.read_state === 'failed' && `The reading computer could not read this scan: ${paper.read_error ?? 'no reason given'}`}
              {paper.read_state === 'queued' && (paper.reading.marker.online ? 'Waiting for the reading computer. You can also start typing.' : 'The reading computer is switched off, so this paper is waiting. You can type it in instead.')}
              {paper.read_state === 'reading' && 'The reading computer is reading this paper now…'}
              {paper.read_state === 'none' && 'Not read by a machine. Type what matters from the scan into the form.'}
            </span>
            {paper.reading.marker.set_up && ['none', 'failed', 'done'].includes(paper.read_state) && (
              <button className="btn ghost small-btn" onClick={readHere} disabled={Boolean(busy)}>
                {busy === 'marker' ? 'Sending…' : paper.read_state === 'done' ? 'Read again here' : 'Read on the reading computer'}
              </button>
            )}
            {paper.reading.ai.set_up && (
              <button className="btn ghost small-btn" onClick={readWithAi} disabled={Boolean(busy)}>
                {busy === 'ai' ? 'Reading… this can take a minute' : 'Hard to read? Send to AI reading'}
              </button>
            )}
          </div>
        )}
        {arrived && (
          <p className="notice">
            The reading has arrived.{' '}
            <button type="button" className="link" onClick={() => window.confirm('Replace what is typed in the form with the reading?') && take(paper, true)}>
              Use it in the form
            </button>
          </p>
        )}
      </section>

      <div className="check-desk">
        <section className="card scan-side">
          <div className="toolbar">
            <h2>The scan</h2>
            <span className="muted grow">
              {paper.pages.length} {paper.pages.length === 1 ? 'file' : 'files'}
            </span>
            <button type="button" className="btn ghost small-btn" onClick={() => setZoom(Math.max(60, zoom - 40))} aria-label="Make the scan smaller">
              −
            </button>
            <button type="button" className="btn ghost small-btn" onClick={() => setZoom(Math.min(300, zoom + 40))} aria-label="Make the scan larger">
              +
            </button>
            <button type="button" className="btn ghost small-btn" onClick={() => setTurn((turn + 1) % 4)}>
              Turn
            </button>
          </div>
          <div className="scan-pages">
            {paper.pages.map((page) => (
              <Page key={page.id} page={page} zoom={zoom} turn={turn} />
            ))}
          </div>
          {paper.read_text && (
            <details className="read-text">
              <summary>What the machine read, word for word</summary>
              <pre>{paper.read_text}</pre>
            </details>
          )}
        </section>

        <section className="card form-side">
          <h2>{filed ? 'What is filed' : 'What goes into the record'}</h2>
          {unsure.length > 0 && (
            <div className="notice warn">
              <b>The reading was not sure about:</b>
              <ul>
                {unsure.map((line, i) => (
                  <li key={i}>{line}</li>
                ))}
              </ul>
              Anything marked [?] must be settled from the scan, or removed, before filing.
            </div>
          )}
          <div className="grid gap-top">
            <label className="field">
              <span className="field-label">
                What it is<span className="req"> *</span>
              </span>
              <select value={form.kind} onChange={(e) => set({ kind: e.target.value })}>
                {PAPER_KINDS.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span className="field-label">
                Date on the paper<span className="req"> *</span>
              </span>
              <input type="date" value={form.record_date} max={today()} onChange={(e) => set({ record_date: e.target.value })} aria-invalid={Boolean(errors.record_date)} />
              {errors.record_date && <span className="field-error">{errors.record_date}</span>}
            </label>
            <label className="field">
              <span className="field-label">Doctor</span>
              <input value={form.doctor_name} onChange={(e) => set({ doctor_name: e.target.value })} />
            </label>
            <label className="field">
              <span className="field-label">Hospital, clinic or lab</span>
              <input value={form.hospital_name} onChange={(e) => set({ hospital_name: e.target.value })} />
            </label>
            {TEXTS.map(([key, label, hint]) => (
              <label key={key} className="field span-2">
                <span className="field-label">{label}</span>
                <textarea rows={form[key].split('\n').length > 2 || form[key].length > 160 ? 4 : 2} value={form[key]} placeholder={hint} onChange={(e) => set({ [key]: e.target.value } as Partial<Form>)} />
              </label>
            ))}
          </div>

          <h3>Medicines on the paper</h3>
          <div className="table-wrap">
            <table className="compact rows-edit">
              <tbody>
                {form.medicines.map((m, i) => (
                  <tr key={i} className="static">
                    <td className="wide">
                      <input value={m.name} placeholder="Medicine, as written" aria-label={`Medicine ${i + 1}`} onChange={(e) => setRow('medicines', i, { name: e.target.value })} className={m.name.includes('[?]') ? 'doubt' : ''} />
                    </td>
                    <td>
                      <input value={m.dose} placeholder="Dose" aria-label={`Dose of medicine ${i + 1}`} onChange={(e) => setRow('medicines', i, { dose: e.target.value })} className={m.dose.includes('[?]') ? 'doubt' : ''} />
                    </td>
                    <td>
                      <input value={m.frequency} placeholder="1-0-1" aria-label={`Frequency of medicine ${i + 1}`} onChange={(e) => setRow('medicines', i, { frequency: e.target.value })} className={m.frequency.includes('[?]') ? 'doubt' : ''} />
                    </td>
                    <td>
                      <input value={m.duration} placeholder="Days" aria-label={`Duration of medicine ${i + 1}`} onChange={(e) => setRow('medicines', i, { duration: e.target.value })} className={m.duration.includes('[?]') ? 'doubt' : ''} />
                    </td>
                    <td>
                      <input value={m.instructions} placeholder="After food…" aria-label={`Instructions for medicine ${i + 1}`} onChange={(e) => setRow('medicines', i, { instructions: e.target.value })} />
                    </td>
                    <td className="num">
                      <button type="button" className="link danger" onClick={() => dropRow('medicines', i)} aria-label={`Remove medicine ${i + 1}`}>
                        Remove
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <button type="button" className="link" onClick={() => set({ medicines: [...form.medicines, blankMedicine()] })}>
            + Add a medicine
          </button>

          <h3>Test values on the paper</h3>
          <p className="muted small">These join the patient’s results over time. Pick the hospital’s own name for a test where it is offered, so old and new values sit on one line.</p>
          <div className="table-wrap">
            <table className="compact rows-edit">
              <tbody>
                {form.results.map((r, i) => {
                  const flag = flagFor(r.value.replace(/,/g, ''), r.reference_range || null)
                  return (
                    <tr key={i} className="static">
                      <td className="wide">
                        <input
                          value={r.parameter}
                          list="known-tests"
                          placeholder="What was measured"
                          aria-label={`Test ${i + 1}`}
                          className={r.parameter.includes('[?]') ? 'doubt' : ''}
                          onChange={(e) => {
                            const match = known.get(e.target.value.toLowerCase())
                            setRow('results', i, { parameter: e.target.value, ...(match && !r.unit ? { unit: match.unit ?? '' } : {}), ...(match && !r.test ? { test: match.test } : {}) })
                          }}
                        />
                        <input value={r.test} placeholder="Under which test (optional)" aria-label={`Heading of test ${i + 1}`} className="under" onChange={(e) => setRow('results', i, { test: e.target.value })} />
                      </td>
                      <td>
                        <input value={r.value} placeholder="Value" aria-label={`Value of test ${i + 1}`} className={`${flag ? `result ${flag}` : ''} ${r.value.includes('[?]') ? 'doubt' : ''}`} onChange={(e) => setRow('results', i, { value: e.target.value })} />
                        {flag && <span className={`pill ${flag === 'high' ? 'off' : 'status-ongoing'}`}>{flag === 'high' ? 'High' : 'Low'}</span>}
                      </td>
                      <td>
                        <input value={r.unit} placeholder="Unit" aria-label={`Unit of test ${i + 1}`} onChange={(e) => setRow('results', i, { unit: e.target.value })} />
                      </td>
                      <td>
                        <input value={r.reference_range} placeholder="Normal range" aria-label={`Normal range of test ${i + 1}`} onChange={(e) => setRow('results', i, { reference_range: e.target.value })} />
                      </td>
                      <td className="num">
                        <button type="button" className="link danger" onClick={() => dropRow('results', i)} aria-label={`Remove test ${i + 1}`}>
                          Remove
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <datalist id="known-tests">
            {paper.known_tests.map((t, i) => (
              <option key={i} value={t.parameter}>
                {t.test}
              </option>
            ))}
          </datalist>
          <button type="button" className="link" onClick={() => set({ results: [...form.results, blankResult()] })}>
            + Add a test value
          </button>

          <h3>For the health summary</h3>
          <p className="muted small">Only lasting facts the paper states plainly: a long-term condition, a regular medicine, a past operation. Anything already on the summary is not added twice.</p>
          {paper.facts.length > 0 && <p className="small">Added from this paper earlier: {paper.facts.map((f) => f.title).join('; ')}.</p>}
          <div className="table-wrap">
            <table className="compact rows-edit">
              <tbody>
                {form.facts.map((f, i) => (
                  <tr key={i} className="static">
                    <td>
                      <select value={f.kind} aria-label={`Kind of fact ${i + 1}`} onChange={(e) => setRow('facts', i, { kind: e.target.value })}>
                        {FACT_KINDS.map(([value, label]) => (
                          <option key={value} value={value}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="wide">
                      <input value={f.title} placeholder="Type 2 diabetes" aria-label={`Fact ${i + 1}`} onChange={(e) => setRow('facts', i, { title: e.target.value })} />
                    </td>
                    <td>
                      <input value={f.since} placeholder="Since" aria-label={`Since when, fact ${i + 1}`} onChange={(e) => setRow('facts', i, { since: e.target.value })} />
                    </td>
                    <td className="num">
                      <button type="button" className="link danger" onClick={() => dropRow('facts', i)} aria-label={`Remove fact ${i + 1}`}>
                        Remove
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <button type="button" className="link" onClick={() => set({ facts: [...form.facts, blankFact()] })}>
            + Add to the health summary
          </button>
          <label className="field gap-top">
            <span className="field-label">An allergy named on the paper{paper.patient_allergies ? ` (already recorded: ${paper.patient_allergies})` : ''}</span>
            <input value={form.allergies} placeholder="Leave empty if the paper names none" onChange={(e) => set({ allergies: e.target.value })} />
          </label>

          {firstError && <p className="form-error">{firstError}</p>}
          {notice && <p className="notice gap-top">{notice}</p>}
          <div className="panel-foot between">
            <button type="button" className="link danger" onClick={remove} disabled={Boolean(busy)}>
              Remove this paper
            </button>
            <span className="foot-buttons">
              {!filed && (
                <button type="button" className="btn ghost" onClick={saveForLater} disabled={Boolean(busy)}>
                  {busy === 'save' ? 'Saving…' : 'Save for later'}
                </button>
              )}
              <button type="button" className="btn primary" onClick={file} disabled={Boolean(busy) || (filed && !dirty)}>
                {busy === 'file' ? 'Filing…' : filed ? 'Save corrections' : 'Checked: file in the record'}
              </button>
            </span>
          </div>
          {!filed && <p className="muted small">Filing puts this paper in the case history marked as an old paper, with your name as the person who checked it. The scan stays attached.</p>}
        </section>
      </div>
    </div>
  )
}
