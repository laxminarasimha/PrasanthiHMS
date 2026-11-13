import { useState, type FormEvent } from 'react'
import { api } from '../api'
import type { PrescribedMedicine, RxSettings } from '../types'

type Run = (action: () => Promise<unknown>, done?: string) => Promise<boolean>

/** Common dosing words in Telugu, offered as a starting point for the doctor to check and change. */
const TELUGU_STARTER: [string, string][] = [
  ['After food', 'భోజనం తర్వాత'],
  ['Before food', 'భోజనానికి ముందు'],
  ['Empty stomach', 'ఖాళీ కడుపుతో'],
  ['At bedtime', 'పడుకునే ముందు'],
  ['1-0-0', 'ఉదయం'],
  ['0-1-0', 'మధ్యాహ్నం'],
  ['0-0-1', 'రాత్రి'],
  ['1-0-1', 'ఉదయం, రాత్రి'],
  ['1-1-0', 'ఉదయం, మధ్యాహ్నం'],
  ['0-1-1', 'మధ్యాహ్నం, రాత్రి'],
  ['1-1-1', 'ఉదయం, మధ్యాహ్నం, రాత్రి'],
  ['SOS', 'అవసరమైనప్పుడు'],
  ['Alternate days', 'రోజు విడిచి రోజు'],
  ['Once a week', 'వారానికి ఒకసారి'],
]

const LANGUAGES: [RxSettings['print_language'], string, string][] = [
  ['en', 'As typed', 'The prescription prints exactly as it was written.'],
  ['both', 'As typed, with the translation beside it', 'For example "After food (భోజనం తర్వాత)".'],
  ['local', 'Translation only', 'Words that have a translation are replaced by it; the rest print as typed.'],
]

/** The doctor's own frequency and timing lists, translations, and how the prescription prints. */
export function RxSettingsForm({ settings, doctorId, run }: { settings: RxSettings; doctorId: number; run: Run }) {
  const [frequencies, setFrequencies] = useState(settings.frequencies.join('\n'))
  const [timings, setTimings] = useState(settings.timings.join('\n'))
  const [language, setLanguage] = useState(settings.print_language)
  const [capitalize, setCapitalize] = useState(settings.capitalize_name)
  const [translations, setTranslations] = useState(settings.translations.length ? settings.translations : [{ phrase: '', translation: '' }])

  const lines = (text: string) => [...new Set(text.split('\n').map((l) => l.trim()).filter(Boolean))]
  const setRow = (index: number, key: 'phrase' | 'translation', value: string) => setTranslations((rows) => rows.map((row, i) => (i === index ? { ...row, [key]: value } : row)))
  const addStarter = () =>
    setTranslations((rows) => {
      const have = new Set(rows.map((r) => r.phrase.trim().toLowerCase()))
      return [...rows.filter((r) => r.phrase.trim() || r.translation.trim()), ...TELUGU_STARTER.filter(([phrase]) => !have.has(phrase.toLowerCase())).map(([phrase, translation]) => ({ phrase, translation }))]
    })

  const save = (e: FormEvent) => {
    e.preventDefault()
    run(
      () =>
        api.put('/doctor/rx-settings', {
          doctor_id: doctorId,
          frequencies: lines(frequencies),
          timings: lines(timings),
          print_language: language,
          capitalize_name: capitalize,
          translations: translations.filter((t) => t.phrase.trim() && t.translation.trim()),
        }),
      'Prescription settings saved.',
    )
  }

  return (
    <form onSubmit={save}>
      <h3>Lists offered on the visit pad</h3>
      <p className="muted small">One per line. They are suggestions under Frequency and When; anything else can still be typed.</p>
      <div className="grid">
        <label className="field">
          <span className="field-label">Frequency</span>
          <textarea rows={8} value={frequencies} onChange={(e) => setFrequencies(e.target.value)} />
        </label>
        <label className="field">
          <span className="field-label">When to take</span>
          <textarea rows={8} value={timings} onChange={(e) => setTimings(e.target.value)} />
        </label>
      </div>

      <h3>Printing</h3>
      {LANGUAGES.map(([value, label, hint]) => (
        <label className="check top" key={value}>
          <input type="radio" name="print_language" checked={language === value} onChange={() => setLanguage(value)} />
          <span>
            {label}
            <small className="muted block">{hint}</small>
          </span>
        </label>
      ))}
      <label className="check">
        <input type="checkbox" checked={capitalize} onChange={(e) => setCapitalize(e.target.checked)} />
        Print the patient's name in capital letters
      </label>

      <h3>Translations</h3>
      <p className="muted small">
        When a dose, frequency, timing, duration or instruction on the prescription, or a whole line of advice, matches a phrase here exactly, its translation is printed as chosen above. Capital and small letters do not matter.
      </p>
      {translations.map((row, i) => (
        <div className="inv-row translation-row" key={i}>
          <input placeholder="Phrase as typed, e.g. After food" value={row.phrase} onChange={(e) => setRow(i, 'phrase', e.target.value)} aria-label={`Phrase ${i + 1}`} />
          <input placeholder="Translation" value={row.translation} onChange={(e) => setRow(i, 'translation', e.target.value)} aria-label={`Translation ${i + 1}`} />
          <button type="button" className="link danger" onClick={() => setTranslations((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [{ phrase: '', translation: '' }]))}>
            Remove
          </button>
        </div>
      ))}
      <div className="row gap-top">
        <button type="button" className="link" onClick={() => setTranslations((rows) => [...rows, { phrase: '', translation: '' }])}>
          + Add a translation
        </button>
        <button type="button" className="link" onClick={addStarter}>
          + Add a Telugu starter set
        </button>
      </div>
      <p className="muted small">The Telugu starter set covers the usual timing and frequency words. Please read it through and correct the wording to what you tell your patients.</p>

      <div className="panel-foot">
        <button className="btn primary">Save prescription settings</button>
      </div>
    </form>
  )
}

const blank = { medicine: '', dose: '', frequency: '', timing: '', duration: '', instructions: '' }

/** How this doctor usually gives each medicine. It builds up as visits are completed and can be corrected here. */
export function MedicineLibrary({ rows, doctorId, run }: { rows: PrescribedMedicine[]; doctorId: number; run: Run }) {
  const [filter, setFilter] = useState('')
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const [form, setForm] = useState(blank)
  const shown = rows.filter((r) => r.medicine.toLowerCase().includes(filter.trim().toLowerCase()))
  const set = (key: keyof typeof blank) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const open = (row: PrescribedMedicine | null) => {
    setEditing(row?.id ?? 'new')
    setForm(row ? { medicine: row.medicine, dose: row.dose ?? '', frequency: row.frequency ?? '', timing: row.timing ?? '', duration: row.duration ?? '', instructions: row.instructions ?? '' } : blank)
  }
  const save = async (e: FormEvent) => {
    e.preventDefault()
    const body = { ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() || null])), doctor_id: doctorId }
    if (await run(() => (editing === 'new' ? api.post('/doctor/medicine-defaults', body) : api.put(`/doctor/medicine-defaults/${editing}`, body)), 'Medicine saved.')) setEditing(null)
  }

  return (
    <div>
      <div className="row gap-bottom">
        <input className="search" type="search" placeholder="Filter medicines" value={filter} onChange={(e) => setFilter(e.target.value)} />
        <span className="muted small grow">Choosing one of these on the visit pad fills in the rest of the row.</span>
        <button className="btn primary" onClick={() => open(null)}>
          + Add medicine
        </button>
      </div>

      {editing && (
        <form className="note-form" onSubmit={save}>
          <div className="grid three">
            {(
              [
                ['medicine', 'Medicine name and strength'],
                ['dose', 'Dose'],
                ['frequency', 'Frequency'],
                ['timing', 'When'],
                ['duration', 'Duration'],
                ['instructions', 'Instructions'],
              ] as const
            ).map(([key, label]) => (
              <label className="field" key={key}>
                <span className="field-label">{label}</span>
                <input required={key === 'medicine'} value={form[key]} onChange={(e) => set(key)(e.target.value)} autoFocus={key === 'medicine'} />
              </label>
            ))}
          </div>
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Cancel
            </button>
            <button className="btn primary" disabled={!form.medicine.trim()}>
              Save
            </button>
          </div>
        </form>
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Medicine</th>
              <th>Dose</th>
              <th>Frequency</th>
              <th>When</th>
              <th>Duration</th>
              <th>Instructions</th>
              <th className="num">Used</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {shown.map((r) => (
              <tr key={r.id} className="static">
                <td>
                  <strong>{r.medicine}</strong>
                </td>
                <td>{r.dose ?? '—'}</td>
                <td>{r.frequency ?? '—'}</td>
                <td>{r.timing ?? '—'}</td>
                <td>{r.duration ?? '—'}</td>
                <td className="wrap">{r.instructions ?? '—'}</td>
                <td className="num">{r.uses ?? 0}</td>
                <td className="num actions">
                  <button className="link" onClick={() => open(r)}>
                    Edit
                  </button>
                  <button className="link danger" onClick={() => window.confirm(`Remove ${r.medicine} from the library?`) && run(() => api.delete(`/doctor/medicine-defaults/${r.id}`), 'Medicine removed from the library.')}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
            {shown.length === 0 && (
              <tr className="empty">
                <td colSpan={8}>{rows.length === 0 ? 'No medicines yet. They are added as visits are completed, or add one here.' : 'No medicines match.'}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
