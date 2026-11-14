import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { downloadCsv, longDate, today } from '../format'

type Column = { key: string; label: string; type: string }
type Found = { columns: Column[]; rows: (Record<string, string | number | null> & { id: number })[]; total: number; all_patients: number }

const blank = { age_from: '', age_to: '', gender: '', visit_from: '', visit_to: '', diagnosis: '', icd: '', medicine: '', test: '', value_from: '', value_to: '' }

/** Finds patients by age, gender, visit dates, diagnosis, medicine or a lab value: for recalls, camps and audits. */
export function FindPatients() {
  const [form, setForm] = useState(blank)
  const [found, setFound] = useState<Found | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (key: keyof typeof blank) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const search = async (e?: FormEvent) => {
    e?.preventDefault()
    setBusy(true)
    setError('')
    try {
      const params = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''))
      setFound((await api.get<Found>('/reports/find-patients', { params })).data)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not search. Check the values and try again.')
    } finally {
      setBusy(false)
    }
  }

  const show = (column: Column, value: string | number | null) => (value === null || value === '' ? '—' : column.key === 'last_visit' ? longDate(String(value)) : String(value))

  return (
    <section className="card">
      <div className="toolbar no-print">
        <h2>Find patients</h2>
        <span className="muted grow">Fill in only what matters; every box left empty is ignored.</span>
      </div>
      <form className="finder no-print" onSubmit={search}>
        <label>
          Age from
          <input type="number" min={0} max={130} value={form.age_from} onChange={set('age_from')} />
        </label>
        <label>
          Age to
          <input type="number" min={0} max={130} value={form.age_to} onChange={set('age_to')} />
        </label>
        <label>
          Gender
          <select value={form.gender} onChange={set('gender')}>
            <option value="">Any</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label>
          Seen from
          <input type="date" max={today()} value={form.visit_from} onChange={set('visit_from')} />
        </label>
        <label>
          Seen to
          <input type="date" max={today()} value={form.visit_to} onChange={set('visit_to')} />
        </label>
        <label className="wide">
          Diagnosis contains
          <input placeholder="e.g. diabetes" maxLength={100} value={form.diagnosis} onChange={set('diagnosis')} />
        </label>
        <label>
          ICD-10 code
          <input placeholder="e.g. E11" maxLength={10} value={form.icd} onChange={set('icd')} />
        </label>
        <label className="wide">
          Was given a medicine containing
          <input placeholder="e.g. metformin" maxLength={100} value={form.medicine} onChange={set('medicine')} />
        </label>
        <label className="wide">
          Lab test or parameter contains
          <input placeholder="e.g. HbA1c" maxLength={100} value={form.test} onChange={set('test')} />
        </label>
        <label>
          Value from
          <input type="number" step="any" disabled={!form.test} value={form.value_from} onChange={set('value_from')} />
        </label>
        <label>
          Value to
          <input type="number" step="any" disabled={!form.test} value={form.value_to} onChange={set('value_to')} />
        </label>
        <div className="finder-actions">
          <button className="btn primary" disabled={busy}>
            {busy ? 'Searching…' : 'Find'}
          </button>
          <button
            type="button"
            className="btn ghost"
            onClick={() => {
              setForm(blank)
              setFound(null)
              setError('')
            }}
          >
            Clear
          </button>
        </div>
      </form>
      {error && <p className="form-error">{error}</p>}

      {found && (
        <>
          <div className="toolbar gap-top">
            <span className="grow">
              <strong>{found.total}</strong> of {found.all_patients} patients match
              {found.total > found.rows.length && <span className="muted"> · showing the first {found.rows.length}; narrow the search to see the rest</span>}
            </span>
            <button className="btn ghost no-print" disabled={found.rows.length === 0} onClick={() => downloadCsv(`patients-found-${today()}.csv`, found.columns.map((c) => c.label), found.rows.map((row) => found.columns.map((c) => row[c.key])))}>
              Export
            </button>
            <button className="btn ghost no-print" onClick={() => window.print()}>
              Print
            </button>
          </div>
          <div className="table-wrap">
            <table className="compact">
              <thead>
                <tr>
                  {found.columns.map((c) => (
                    <th key={c.key} className={c.type === 'number' ? 'num' : ''}>
                      {c.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {found.rows.map((row) => (
                  <tr key={row.id} className="static">
                    {found.columns.map((c) => (
                      <td key={c.key} className={c.type === 'number' ? 'num' : c.key === 'uhid' ? 'mono' : ''}>
                        {c.key === 'uhid' ? (
                          <Link className="link" to={`/patients/${row.id}`}>
                            {row.uhid}
                          </Link>
                        ) : (
                          show(c, row[c.key])
                        )}
                      </td>
                    ))}
                  </tr>
                ))}
                {found.rows.length === 0 && (
                  <tr className="empty">
                    <td colSpan={found.columns.length}>No patient matches. Loosen one of the conditions.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}
