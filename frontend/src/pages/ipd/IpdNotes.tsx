import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { clock, longDate, nowLocal } from '../../format'
import type { AdmissionNote, NoteKind } from '../../types'

const KINDS: { key: NoteKind; label: string; add: string }[] = [
  { key: 'clinical', label: 'Clinical notes', add: 'Add clinical note' },
  { key: 'nursing', label: 'Nursing notes', add: 'Add nursing note' },
  { key: 'ot', label: 'OT notes', add: 'Add OT note' },
]

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

type Draft = { id: number | null; noted_at: string; body: string; details: Record<string, string> }
const blank = (): Draft => ({ id: null, noted_at: nowLocal(), body: '', details: {} })

/** Clinical, nursing and operation notes of one stay, newest first. */
export function IpdNotes({ admissionId, canEdit }: { admissionId: number; canEdit: boolean }) {
  const [notes, setNotes] = useState<AdmissionNote[] | null>(null)
  const [kind, setKind] = useState<NoteKind>('clinical')
  const [draft, setDraft] = useState<Draft | null>(null)
  const [error, setError] = useState('')
  const url = `/ipd/admissions/${admissionId}/notes`

  useEffect(() => {
    api.get<AdmissionNote[]>(url).then((res) => setNotes(res.data))
  }, [url])

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!draft) return
    const body = { kind, noted_at: draft.noted_at.replace('T', ' '), body: draft.body || null, details: draft.details }
    try {
      setNotes((await (draft.id ? api.put<AdmissionNote[]>(`${url}/${draft.id}`, body) : api.post<AdmissionNote[]>(url, body))).data)
      setDraft(null)
      setError('')
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }
  const remove = async (note: AdmissionNote) => {
    if (!window.confirm('Delete this note?')) return
    setNotes((await api.delete<AdmissionNote[]>(`${url}/${note.id}`)).data)
  }

  const shown = (notes ?? []).filter((n) => n.kind === kind)
  const current = KINDS.find((k) => k.key === kind)!

  return (
    <div>
      <div className="tabs flush gap-bottom">
        {KINDS.map((k) => (
          <button
            key={k.key}
            className={kind === k.key ? 'active' : ''}
            onClick={() => {
              setKind(k.key)
              setDraft(null)
              setError('')
            }}
          >
            {k.label} ({(notes ?? []).filter((n) => n.kind === k.key).length})
          </button>
        ))}
        <span className="grow" />
        {canEdit && !draft && (
          <button className="btn primary small-btn" onClick={() => setDraft(blank())}>
            + {current.add}
          </button>
        )}
      </div>

      {draft && (
        <form className="note-form" onSubmit={save}>
          <div className="grid">
            <label className="field">
              <span className="field-label">Date and time</span>
              <input type="datetime-local" required max={nowLocal()} value={draft.noted_at} onChange={(e) => setDraft({ ...draft, noted_at: e.target.value })} />
            </label>
            {DETAILS[kind].map((d) => (
              <label className={`field ${d.wide ? 'span-2' : ''}`} key={d.key}>
                <span className="field-label">{d.label}</span>
                <input value={draft.details[d.key] ?? ''} onChange={(e) => setDraft({ ...draft, details: { ...draft.details, [d.key]: e.target.value } })} />
              </label>
            ))}
            <label className="field span-2">
              <span className="field-label">{BODY_LABEL[kind]}</span>
              <textarea rows={kind === 'nursing' ? 2 : 5} value={draft.body} onChange={(e) => setDraft({ ...draft, body: e.target.value })} autoFocus={kind !== 'nursing'} />
            </label>
          </div>
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setDraft(null)}>
              Cancel
            </button>
            <button className="btn primary">{draft.id ? 'Save changes' : 'Save note'}</button>
          </div>
        </form>
      )}

      {notes === null ? (
        <p className="loading">Loading notes…</p>
      ) : shown.length === 0 ? (
        <p className="muted">No {current.label.toLowerCase()} yet.</p>
      ) : (
        shown.map((n) => (
          <article className="note" key={n.id}>
            <header>
              <strong>
                {longDate(n.noted_at)} {clock(n.noted_at)}
              </strong>
              <span className="muted grow"> · {n.author?.name ?? 'Unknown'}</span>
              {canEdit && (
                <>
                  <button
                    className="link"
                    onClick={() => {
                      setDraft({ id: n.id, noted_at: n.noted_at.replace(' ', 'T'), body: n.body ?? '', details: { ...(n.details ?? {}) } })
                      setError('')
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
            {n.details && (
              <p className="note-details">
                {DETAILS[kind]
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
        ))
      )}
    </div>
  )
}
