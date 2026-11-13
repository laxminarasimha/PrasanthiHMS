import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import type { FormField, FormTemplate } from '../types'

const TYPES: [FormField['type'], string][] = [
  ['heading', 'Heading'],
  ['text', 'Short answer'],
  ['textarea', 'Long answer'],
  ['number', 'Number'],
  ['date', 'Date'],
  ['choice', 'Choice from a list'],
  ['checkbox', 'Tick box'],
  ['signature', 'Signature line'],
]
const ACCESS = [['both', 'Doctor and IPD'], ['ipd', 'IPD only'], ['doctor', 'Doctor only']] as const

const line = (label: string, type: FormField['type'] = 'text', options?: string[]): Omit<FormField, 'id'> => ({ label, type, options: options ?? null })
const yesNo = ['Yes', 'No']

/** Ready-made forms a hospital can start from and then change. */
const STARTERS: { name: string; access: FormTemplate['access']; fields: Omit<FormField, 'id'>[] }[] = [
  {
    name: 'Initial assessment',
    access: 'both',
    fields: [
      line('Presenting complaints', 'textarea'), line('History of present illness', 'textarea'), line('Past medical and surgical history', 'textarea'),
      line('Current medicines', 'textarea'), line('Known allergies'), line('Vitals', 'heading'),
      line('Temperature (°F)', 'number'), line('Pulse (/min)', 'number'), line('Blood pressure'), line('Respiratory rate (/min)', 'number'), line('SpO₂ (%)', 'number'), line('Weight (kg)', 'number'),
      line('Examination', 'heading'), line('General condition', 'choice', ['Good', 'Fair', 'Poor', 'Critical']), line('General examination', 'textarea'), line('Systemic examination', 'textarea'),
      line('Plan', 'heading'), line('Provisional diagnosis', 'textarea'), line('Investigations planned', 'textarea'), line('Treatment plan', 'textarea'), line('Doctor', 'signature'),
    ],
  },
  {
    name: 'Nursing admission assessment',
    access: 'ipd',
    fields: [
      line('Mode of arrival', 'choice', ['Walking', 'Wheelchair', 'Stretcher']), line('Accompanied by'), line('Level of consciousness', 'choice', ['Alert', 'Drowsy', 'Confused', 'Unresponsive']),
      line('Pain score (0 to 10)', 'number'), line('Risk checks', 'heading'), line('At risk of falls', 'choice', yesNo), line('At risk of pressure sores', 'choice', yesNo), line('Needs help to walk', 'choice', yesNo),
      line('Diet', 'choice', ['Normal', 'Soft', 'Liquid', 'Diabetic', 'Nil by mouth']), line('Valuables handed to the attendant', 'checkbox'), line('Identification band put on', 'checkbox'),
      line('Call bell and ward routine explained', 'checkbox'), line('Remarks', 'textarea'), line('Nurse', 'signature'),
    ],
  },
  {
    name: 'Dated progress note',
    access: 'ipd',
    fields: [
      line('Date', 'date'), line('Complaints today', 'textarea'), line('General condition', 'choice', ['Better', 'Same', 'Worse']), line('Vitals'),
      line('Examination', 'textarea'), line('Investigations reviewed', 'textarea'), line('Assessment', 'textarea'), line('Plan and orders', 'textarea'), line('Doctor', 'signature'),
    ],
  },
]

const newId = () => `f${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
type Draft = { id: number | null; name: string; access: FormTemplate['access']; is_active: boolean; fields: (FormField & { optionsText: string })[] }
const toDraft = (t: FormTemplate | null): Draft =>
  t
    ? { id: t.id, name: t.name, access: t.access, is_active: t.is_active, fields: t.fields.map((f) => ({ ...f, optionsText: (f.options ?? []).join(', ') })) }
    : { id: null, name: '', access: 'both', is_active: true, fields: [{ id: newId(), label: '', type: 'text', options: null, optionsText: '' }] }

/** Where the hospital designs its own forms: assessments, consents, progress notes. */
export function FormDesigner() {
  const [templates, setTemplates] = useState<FormTemplate[]>([])
  const [draft, setDraft] = useState<Draft | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<FormTemplate[]>('/form-templates', { params: { all: 1 } }).then((res) => setTemplates(res.data))
  }, [])
  useEffect(load, [load])

  const setField = (index: number, change: Partial<Draft['fields'][number]>) => setDraft((d) => (d ? { ...d, fields: d.fields.map((f, i) => (i === index ? { ...f, ...change } : f)) } : d))
  const move = (index: number, to: number) =>
    setDraft((d) => {
      if (!d || to < 0 || to >= d.fields.length) return d
      const fields = [...d.fields]
      fields.splice(to, 0, fields.splice(index, 1)[0])
      return { ...d, fields }
    })

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!draft) return
    const body = {
      name: draft.name,
      access: draft.access,
      is_active: draft.is_active,
      fields: draft.fields.filter((f) => f.label.trim()).map((f) => ({ id: f.id, label: f.label, type: f.type, options: f.type === 'choice' ? f.optionsText.split(',').map((o) => o.trim()).filter(Boolean) : null })),
    }
    try {
      await (draft.id ? api.put(`/form-templates/${draft.id}`, body) : api.post('/form-templates', body))
      setDraft(null)
      setError('')
      setNotice('Form saved.')
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  const addStarters = async () => {
    const have = new Set(templates.map((t) => t.name.toLowerCase()))
    const missing = STARTERS.filter((s) => !have.has(s.name.toLowerCase()))
    for (const starter of missing) {
      await api.post('/form-templates', { ...starter, fields: starter.fields.map((f, i) => ({ ...f, id: `s${i}_${newId()}` })) })
    }
    setNotice(missing.length ? `${missing.length} starter form(s) added. Open one to change it to your own wording.` : 'The starter forms are already in the list.')
    load()
  }

  return (
    <div>
      <div className="row gap-bottom">
        <p className="muted small grow">Forms designed here can be filled in on an IPD case, under its Forms tab. A form already filled in keeps its questions even if the form is changed later.</p>
        <button className="btn ghost" onClick={addStarters}>
          Add starter forms
        </button>
        <button className="btn primary" onClick={() => { setDraft(toDraft(null)); setNotice(''); setError('') }}>
          + New form
        </button>
      </div>
      {notice && <p className="notice">{notice}</p>}

      {draft && (
        <form className="note-form" onSubmit={save} noValidate>
          <div className="grid">
            <label className="field">
              <span className="field-label">Form name</span>
              <input value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} autoFocus />
            </label>
            <label className="field">
              <span className="field-label">Used in</span>
              <select value={draft.access} onChange={(e) => setDraft({ ...draft, access: e.target.value as FormTemplate['access'] })}>
                {ACCESS.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <p className="muted small gap-top">The patient's name, age, sex, IPD number and the date are printed at the top automatically.</p>
          {draft.fields.map((f, i) => (
            <div className="form-line" key={f.id}>
              <input placeholder={f.type === 'heading' ? 'Heading' : 'Question or label'} value={f.label} onChange={(e) => setField(i, { label: e.target.value })} aria-label={`Line ${i + 1} label`} />
              <select value={f.type} onChange={(e) => setField(i, { type: e.target.value as FormField['type'] })} aria-label={`Line ${i + 1} kind`}>
                {TYPES.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
              {f.type === 'choice' ? <input placeholder="Choices, separated by commas" value={f.optionsText} onChange={(e) => setField(i, { optionsText: e.target.value })} aria-label={`Line ${i + 1} choices`} /> : <span />}
              <span className="row param-move">
                <button type="button" className="link" disabled={i === 0} onClick={() => move(i, i - 1)} aria-label={`Move line ${i + 1} up`}>
                  ↑
                </button>
                <button type="button" className="link" disabled={i === draft.fields.length - 1} onClick={() => move(i, i + 1)} aria-label={`Move line ${i + 1} down`}>
                  ↓
                </button>
                <button type="button" className="link danger" onClick={() => setDraft({ ...draft, fields: draft.fields.length > 1 ? draft.fields.filter((_, r) => r !== i) : draft.fields })}>
                  Remove
                </button>
              </span>
            </div>
          ))}
          <button type="button" className="link" onClick={() => setDraft({ ...draft, fields: [...draft.fields, { id: newId(), label: '', type: 'text', options: null, optionsText: '' }] })}>
            + Add a line
          </button>
          {draft.id && (
            <label className="check">
              <input type="checkbox" checked={draft.is_active} onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })} />
              In use (untick to stop new copies being started)
            </label>
          )}
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setDraft(null)}>
              Cancel
            </button>
            <button className="btn primary" disabled={!draft.name.trim()}>
              Save form
            </button>
          </div>
        </form>
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Form</th>
              <th>Used in</th>
              <th className="num">Lines</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {templates.map((t) => (
              <tr key={t.id} onClick={() => { setDraft(toDraft(t)); setNotice(''); setError('') }} className={draft?.id === t.id ? 'selected' : ''}>
                <td>
                  <strong>{t.name}</strong>
                </td>
                <td>{ACCESS.find(([value]) => value === t.access)?.[1]}</td>
                <td className="num">{t.fields.length}</td>
                <td>
                  <span className={t.is_active ? 'pill ok' : 'pill off'}>{t.is_active ? 'In use' : 'Hidden'}</span>
                </td>
              </tr>
            ))}
            {templates.length === 0 && (
              <tr className="empty">
                <td colSpan={4}>No forms yet. Add the starter forms, or make your own.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
