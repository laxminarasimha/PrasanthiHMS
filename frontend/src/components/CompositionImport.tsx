import { useState, type ChangeEvent } from 'react'
import { api, fieldErrors } from '../api'
import { downloadCsv, parseCsv, today } from '../format'

type Line = { line: number; medicine: string; typed: string; composition: string | null; drugs: string[]; was: string | null; state: 'change' | 'same' | 'skip' | 'error'; note: string | null }
type Result = { lines: Line[]; totals: { lines: number; change: number; same: number; skip: number; error: number }; saved: number }

/** The names people give the two columns that matter. */
const NAMES = { medicine: ['medicine', 'medicine name', 'name', 'brand', 'brand name', 'item', 'item name', 'product'], composition: ['composition', 'what it contains', 'contains', 'content', 'contents', 'salt', 'generic', 'generic name', 'molecule'] }
const plain = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim()

/**
 * Fills in what each medicine contains from a sheet, so hundreds can be done in a spreadsheet
 * instead of one at a time. The sheet is checked first; nothing is saved until the person agrees.
 */
export function CompositionImport({ medicines, onSaved }: { medicines: { name: string; manufacturer: string | null; type: string | null; composition: string | null }[]; onSaved: (count: number) => void }) {
  const [rows, setRows] = useState<{ medicine: string; composition: string }[]>([])
  const [fileName, setFileName] = useState('')
  const [result, setResult] = useState<Result | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const download = () =>
    downloadCsv(`medicines-to-fill-${today()}.csv`, ['Medicine', 'Maker', 'Type', 'Composition'], medicines.map((m) => [m.name, m.manufacturer, m.type, m.composition]))

  const send = async (list: { medicine: string; composition: string }[], commit: boolean) => {
    setBusy(true)
    setError('')
    try {
      const res = await api.post<Result>('/pharmacy/medicines/compositions/import', { rows: list, commit })
      setResult(res.data)
      if (commit && res.data.saved > 0) onSaved(res.data.saved)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setBusy(false)
    }
  }

  const choose = async (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    setResult(null)
    setError('')
    setFileName(file.name)
    const table = parseCsv(await file.text()).filter((r) => r.some((c) => c.trim()))
    const head = (table[0] ?? []).map(plain)
    const column = (names: string[]) => head.findIndex((h) => names.includes(h))
    const [m, c] = [column(NAMES.medicine), column(NAMES.composition)]
    if (m < 0 || c < 0) {
      setRows([])
      setError('The sheet needs a column headed “Medicine” and one headed “Composition”. Download the list above to get a sheet with the right headings.')
      return
    }
    const list = table.slice(1).map((r) => ({ medicine: (r[m] ?? '').trim(), composition: (r[c] ?? '').trim() }))
    setRows(list)
    if (list.length) await send(list, false)
  }

  const shown = result?.lines.filter((l) => l.state === 'error' || l.state === 'change').slice(0, 150) ?? []

  return (
    <details className="sheet-fill">
      <summary>Fill in many compositions at once, from a sheet</summary>
      <ol>
        <li>
          <button type="button" className="link" onClick={download}>
            Download the medicines list
          </button>{' '}
          and open it in Excel or Google Sheets.
        </li>
        <li>
          In the Composition column, type what each medicine contains. For a combination put a plus between the drugs: <b>Paracetamol 325 mg + Aceclofenac 100 mg</b>. Leave a box empty to leave that medicine as it is.
        </li>
        <li>
          Save the sheet as CSV and choose it here: <input type="file" accept=".csv,text/csv" onChange={choose} aria-label="The filled sheet" />
        </li>
      </ol>
      {error && <p className="form-error">{error}</p>}
      {result && (
        <>
          <p className={result.saved > 0 ? 'notice' : ''}>
            {result.saved > 0 ? (
              <>Saved: {result.saved} {result.saved === 1 ? 'medicine now has' : 'medicines now have'} a composition.</>
            ) : (
              <>
                <b>{fileName}</b>: {result.totals.change} will be set or changed · {result.totals.same} already right · {result.totals.skip} left empty · <span className={result.totals.error ? 'due' : ''}>{result.totals.error} with a problem</span>
              </>
            )}
          </p>
          {result.saved === 0 && shown.length > 0 && (
            <div className="table-wrap tall">
              <table className="compact">
                <thead>
                  <tr>
                    <th>Line</th>
                    <th>Medicine</th>
                    <th>Will be saved as</th>
                    <th>Read as these drugs</th>
                  </tr>
                </thead>
                <tbody>
                  {shown.map((l) => (
                    <tr key={l.line} className="static">
                      <td className="mono">{l.line}</td>
                      <td>{l.medicine || '—'}</td>
                      <td>{l.state === 'error' ? <span className="due">{l.note}</span> : l.composition}</td>
                      <td className="muted">{l.drugs.join(' · ')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {result.saved === 0 && (
            <div className="panel-foot between">
              <small className="muted">
                {result.totals.error > 0 ? 'Lines with a problem are skipped; the rest can still be saved. ' : ''}
                Check a few of the lines above before saving.
              </small>
              <button type="button" className="btn primary" disabled={busy || result.totals.change === 0} onClick={() => send(rows, true)}>
                {busy ? 'Saving…' : `Save ${result.totals.change} ${result.totals.change === 1 ? 'composition' : 'compositions'}`}
              </button>
            </div>
          )}
        </>
      )}
    </details>
  )
}
