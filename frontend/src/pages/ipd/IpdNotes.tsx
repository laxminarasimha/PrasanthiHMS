import { useEffect, useState, type FormEvent, type KeyboardEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { clock, longDate, nowLocal, today } from '../../format'
import type { AdmissionNote, NoteKind } from '../../types'

const KINDS: { key: NoteKind; label: string; add: string; tag: string }[] = [
  { key: 'clinical', label: 'Clinical notes', add: 'Clinical note', tag: 'Clinical' },
  { key: 'nursing', label: 'Nursing notes', add: 'Nursing note', tag: 'Nursing' },
  { key: 'ot', label: 'OT notes', add: 'OT note', tag: 'OT' },
]
const TAG_CLASS: Record<NoteKind, string> = { clinical: 'status-ongoing', nursing: 'ok', ot: 'status-arrived' }
const SOAP = 'S: \nO: \nA: \nP: '

/** "Today", "Yesterday" or the date, for the heading over a day's notes. */
const dayLabel = (day: string) => {
  const d = new Date()
  d.setDate(d.getDate() - 1)
  const yesterday = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  return day === today() ? 'Today' : day === yesterday ? `Yesterday · ${longDate(day)}` : longDate(day)
}

/** The extra boxes each kind of note has besides its main text. */
const DETAILS: Record<NoteKind, { key: string; label: string; wide?: boolean }[]> = {
  clinical: [],
  nursing: [
    { key: 'bp', label: 'BP' }, { key: 'pulse', label: 'Pulse (/min)' }, { key: 'temperature', label: 'Temp (°F)' }, { key: 'spo2', label: 'SpO₂ (%)' },
    { key: 'resp_rate', label: 'Resp. rate' }, { key: 'intake', label: 'Intake (ml)' }, { key: 'output', label: 'Output (ml)' },
  ],
  ot: [
    { key: 'procedure', label: 'Procedure', wide: true }, { key: 'surgeon', label: 'Surgeon' }, { key: 'assistant', label: 'Assistant' },
    { key: 'anaesthetist', label: 'Anaesthetist' }, { key: 'anaesthesia', label: 'Anaesthesia' },
    { key: 'findings', label: 'Findings', wide: true }, { key: 'post_op', label: 'Post-operative instructions', wide: true },
  ],
}
const BODY_LABEL: Record<NoteKind, string> = { clinical: 'Note', nursing: 'Nursing note', ot: 'Procedure notes' }

type Draft = { id: number | null; kind: NoteKind; noted_at: string; body: string; details: Record<string, string> }
const blank = (kind: NoteKind): Draft => ({ id: null, kind, noted_at: nowLocal(), body: '', details: {} })

/** Clinical, nursing and operation notes of one stay: together as one story, or one kind at a time. Newest first. */
export function IpdNotes({ admissionId, canEdit }: { admissionId: number; canEdit: boolean }) {
  const [notes, setNotes] = useState<AdmissionNote[] | null>(null)
  const [kind, setKind] = useState<NoteKind | 'all'>('all')
  const [draft, setDraft] = useState<Draft | null>(null)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const url = `/ipd/admissions/${admissionId}/notes`

  useEffect(() => {
    api.get<AdmissionNote[]>(url).then((res) => setNotes(res.data))
  }, [url])

  const send = async () => {
    if (!draft || saving) return
    const body = { kind: draft.kind, noted_at: draft.noted_at.replace('T', ' '), body: draft.body || null, details: draft.details }
    setSaving(true)
    try {
      setNotes((await (draft.id ? api.put<AdmissionNote[]>(`${url}/${draft.id}`, body) : api.post<AdmissionNote[]>(url, body))).data)
      setDraft(null)
      setError('')
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setSaving(false)
    }
  }
  const save = (e: FormEvent) => {
    e.preventDefault()
    send()
  }
  /** Ctrl+Enter (or ⌘+Enter) saves from inside the note. */
  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault()
      send()
    }
  }
  const remove = async (note: AdmissionNote) => {
    if (!window.confirm('Delete this note?')) return
    setNotes((await api.delete<AdmissionNote[]>(`${url}/${note.id}`)).data)
  }
  const start = (next: NoteKind) => {
    setDraft(blank(next))
    setError('')
  }

  const shown = (notes ?? []).filter((n) => kind === 'all' || n.kind === kind)
  const days = [...new Set(shown.map((n) => n.noted_at.slice(0, 10)))]
  const adding = kind === 'all' ? KINDS : KINDS.filter((k) => k.key === kind)

  return (
    <div>
      <div className="notes-bar">
        <div className="tabs sub">
          <button className={kind === 'all' ? 'active' : ''} onClick={() => setKind('all')}>
            All <span>{(notes ?? []).length}</span>
          </button>
          {KINDS.map((k) => (
            <button key={k.key} className={kind === k.key ? 'active' : ''} onClick={() => setKind(k.key)}>
              {k.label} <span>{(notes ?? []).filter((n) => n.kind === k.key).length}</span>
            </button>
          ))}
        </div>
        {canEdit && !draft && (
          <div className="row">
            {adding.map((k, i) => (
              <button key={k.key} className={`btn small-btn ${i === 0 ? 'primary' : ''}`} onClick={() => start(k.key)}>
                + {k.add}
              </button>
            ))}
          </div>
        )}
      </div>

      {draft && (
        <form className="note-form" onSubmit={save} onKeyDown={onKey}>
          <div className="row gap-bottom">
            <strong className="grow">{draft.id ? 'Correct the' : 'New'} {KINDS.find((k) => k.key === draft.kind)!.add.toLowerCase()}</strong>
            {draft.kind === 'clinical' && draft.body.trim() === '' && (
              <button type="button" className="chip" onClick={() => setDraft({ ...draft, body: SOAP })} title="Lays the note out as Subjective, Objective, Assessment, Plan">
                SOAP layout
              </button>
            )}
          </div>
          <div className="grid">
            <label className="field">
              <span className="field-label">Date and time</span>
              <input type="datetime-local" required max={nowLocal()} value={draft.noted_at} onChange={(e) => setDraft({ ...draft, noted_at: e.target.value })} />
            </label>
            {DETAILS[draft.kind].map((d) => (
              <label className={`field ${d.wide ? 'span-2' : ''}`} key={d.key}>
                <span className="field-label">{d.label}</span>
                <input value={draft.details[d.key] ?? ''} onChange={(e) => setDraft({ ...draft, details: { ...draft.details, [d.key]: e.target.value } })} />
              </label>
            ))}
            <label className="field span-2">
              <span className="field-label">{BODY_LABEL[draft.kind]}</span>
              <textarea rows={draft.kind === 'nursing' ? 2 : 6} value={draft.body} onChange={(e) => setDraft({ ...draft, body: e.target.value })} autoFocus={draft.kind !== 'nursing'} />
            </label>
          </div>
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot row">
            <span className="muted small grow">Ctrl + Enter saves</span>
            <button type="button" className="btn ghost" onClick={() => setDraft(null)}>
              Cancel
            </button>
            <button className="btn primary" disabled={saving}>
              {saving ? 'Saving…' : draft.id ? 'Save changes' : 'Save note'}
            </button>
          </div>
        </form>
      )}

      {notes === null ? (
        <p className="loading">Loading notes…</p>
      ) : shown.length === 0 ? (
        <p className="muted">{kind === 'all' ? 'No notes written yet.' : `No ${KINDS.find((k) => k.key === kind)!.label.toLowerCase()} yet.`}</p>
      ) : (
        days.map((day) => (
          <section key={day} className="note-day">
            <h3>{dayLabel(day)}</h3>
            {shown
              .filter((n) => n.noted_at.slice(0, 10) === day)
              .map((n) => (
                <article className={`note ${n.kind}`} key={n.id}>
                  <header>
                    <strong>{clock(n.noted_at)}</strong>
                    {kind === 'all' && <span className={`pill ${TAG_CLASS[n.kind]}`}>{KINDS.find((k) => k.key === n.kind)!.tag}</span>}
                    <span className="muted grow">{n.author?.name ?? 'Unknown'}</span>
                    {canEdit && (
                      <>
                        <button
                          className="link"
                          onClick={() => {
                            setDraft({ id: n.id, kind: n.kind, noted_at: n.noted_at.replace(' ', 'T'), body: n.body ?? '', details: { ...(n.details ?? {}) } })
                            setError('')
                            window.scrollTo({ top: 0, behavior: 'smooth' })
                          }}
                        >
                          Edit
                        </button>
                        <button className="link danger" onClick={() => remove(n)}>
                          Delete
                        </button>
                      </>
                    )}
                  </header>
                  {n.details && DETAILS[n.kind].some((d) => n.details?.[d.key]) && (
                    <p className="note-details">
                      {DETAILS[n.kind]
                        .filter((d) => n.details?.[d.key])
                        .map((d) => (
                          <span key={d.key}>
                            <b>{d.label}:</b> {n.details![d.key]}
                          </span>
                        ))}
                    </p>
                  )}
                  {n.body && <p className="report-text">{n.body}</p>}
                </article>
              ))}
          </section>
        ))
      )}
    </div>
  )
}
