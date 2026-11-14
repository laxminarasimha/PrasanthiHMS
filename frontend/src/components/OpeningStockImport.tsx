import { useState, type ChangeEvent } from 'react'
import { api, fieldErrors } from '../api'
import { downloadCsv, longDate, parseCsv, rupees } from '../format'

type Field = 'medicine' | 'batch_no' | 'expiry' | 'units_per_pack' | 'quantity' | 'cost_price' | 'mrp' | 'gst_percent' | 'hsn_code' | 'rack_no' | 'box_no'
type Checked = {
  line: number
  medicine: string
  batch_no: string
  expiry: string | null
  quantity: number | null
  at_cost: number
  errors: string[]
  warnings: string[]
}
type Totals = { lines: number; with_errors: number; with_warnings: number; new_medicines: number; units: number; at_cost: number; at_mrp: number }
type Result = { rows: Checked[]; totals: Totals }

/** The sheet's column headings, and the other names people give the same columns. */
const COLUMNS: [Field, string, string[]][] = [
  ['medicine', 'Medicine', ['medicine name', 'item', 'item name', 'product', 'name', 'drug']],
  ['batch_no', 'Batch', ['batch no', 'batch number', 'batch no.']],
  ['expiry', 'Expiry', ['exp', 'expiry date', 'exp date', 'exp.']],
  ['units_per_pack', 'Units in a pack', ['pack', 'pack size', 'units per pack', 'packing']],
  ['quantity', 'On the shelf (units)', ['quantity', 'qty', 'units', 'stock', 'closing stock', 'on the shelf']],
  ['cost_price', 'Purchase rate per pack', ['purchase rate', 'cost', 'cost price', 'rate', 'ptr', 'purchase price']],
  ['mrp', 'MRP per pack', ['mrp']],
  ['gst_percent', 'GST %', ['gst', 'tax', 'tax %', 'gst%']],
  ['hsn_code', 'HSN code', ['hsn']],
  ['rack_no', 'Rack', ['rack no']],
  ['box_no', 'Box', ['box no']],
]
const NEEDED: Field[] = ['medicine', 'batch_no', 'expiry', 'quantity', 'cost_price', 'mrp']
const SHOWN = 200

const plain = (s: string) => s.toLowerCase().replace(/[^a-z0-9%]+/g, ' ').trim()

/**
 * Opening stock from a sheet. The sheet is checked first and nothing is added until every line is
 * right and the totals have been compared with the pharmacy's own count.
 */
export function OpeningStockImport({ canSave, onAdded }: { canSave: boolean; onAdded: (message: string) => void }) {
  const [fileName, setFileName] = useState('')
  const [rows, setRows] = useState<Record<string, string>[]>([])
  const [addMissing, setAddMissing] = useState(false)
  const [result, setResult] = useState<Result | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [sheetTotal, setSheetTotal] = useState('')
  const [agreed, setAgreed] = useState(false)
  const [onlyProblems, setOnlyProblems] = useState(true)

  const reset = () => {
    setResult(null)
    setError('')
    setSheetTotal('')
    setAgreed(false)
  }

  const template = () =>
    downloadCsv(
      'opening-stock-sheet.csv',
      COLUMNS.map(([, label]) => label),
      [['Paracetamol 500mg Tablet', 'PX2301', '11/2027', 10, 250, 15.5, 28, 12, '3004', 'A2', '']],
    )

  const choose = async (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    reset()
    setRows([])
    setFileName(file.name)
    if (/\.(xlsx?|ods)$/i.test(file.name)) return setError('This is an Excel file. In Excel choose File → Save As → CSV (Comma delimited), then choose that file here.')
    const table = parseCsv(await file.text())
    if (table.length < 2) return setError('The sheet has no lines under its headings.')
    const fields = table[0].map((heading) => COLUMNS.find(([, label, others]) => [plain(label), ...others].includes(plain(heading)))?.[0])
    const missing = NEEDED.filter((f) => !fields.includes(f)).map((f) => COLUMNS.find(([field]) => field === f)![1])
    if (missing.length) return setError(`The sheet has no column for: ${missing.join(', ')}. Use the headings of the blank sheet.`)
    setRows(table.slice(1).map((cells) => Object.fromEntries(fields.flatMap((field, i) => (field ? [[field, (cells[i] ?? '').trim()]] : [])))))
  }

  const send = (commit: boolean) => {
    setBusy(true)
    setError('')
    const body = commit && result ? { commit: true, confirm: result.totals, sheet_total: sheetTotal === '' ? null : Number(sheetTotal) } : {}
    api
      .post('/pharmacy/opening-stock/import', { rows, add_missing: addMissing, ...body })
      .then((res) => {
        if (commit) {
          onAdded(`${res.data.added} batches added to stock from ${fileName}: ${res.data.totals.units.toLocaleString('en-IN')} units, worth ${rupees(res.data.totals.at_cost)} at cost.`)
          setRows([])
          setFileName('')
          reset()
        } else {
          setResult(res.data)
          setAgreed(false)
        }
      })
      .catch((e) => {
        const messages = fieldErrors(e)
        setError(Object.values(messages)[0] ?? 'Could not read the sheet.')
        if (commit) setResult(null)
      })
      .finally(() => setBusy(false))
  }

  const totals = result?.totals
  const gap = totals && sheetTotal !== '' ? Math.round((Number(sheetTotal) - totals.at_cost) * 100) / 100 : null
  const matches = gap === null || Math.abs(gap) <= 1
  const problems = result?.rows.filter((r) => r.errors.length || r.warnings.length) ?? []
  const listed = (onlyProblems ? problems : (result?.rows ?? [])).slice(0, SHOWN)

  return (
    <section className="card">
      <div className="toolbar">
        <h1 className="grow">Opening stock from a sheet</h1>
        <button type="button" className="btn ghost" onClick={template}>
          Download the blank sheet
        </button>
      </div>
      <p className="muted small">
        Fill the blank sheet in Excel, one line for each batch on the shelf, and save it as CSV. Count in single units (tablets, bottles); the purchase rate and MRP are per pack. The sheet is checked first: nothing is added to stock until every
        line is right and you have agreed the totals.
      </p>

      <div className="toolbar import-pick">
        <label className="btn primary file-btn">
          Choose the sheet
          <input type="file" accept=".csv,text/csv,.txt" onChange={choose} hidden />
        </label>
        {fileName && (
          <span>
            <strong>{fileName}</strong>
            {rows.length > 0 && ` · ${rows.length.toLocaleString('en-IN')} lines`}
          </span>
        )}
        <label className="check">
          <input
            type="checkbox"
            checked={addMissing}
            onChange={(e) => {
              setAddMissing(e.target.checked)
              reset()
            }}
          />
          Add medicines that are not in the list
        </label>
        <span className="grow" />
        <button type="button" className="btn ghost" disabled={busy || rows.length === 0} onClick={() => send(false)}>
          {busy && !result ? 'Checking…' : 'Check the sheet'}
        </button>
      </div>

      {error && <p className="form-error">{error}</p>}

      {result && totals && (
        <>
          <div className="tiles small">
            <div className="tile">
              <span>Lines</span>
              <strong>{totals.lines.toLocaleString('en-IN')}</strong>
            </div>
            <div className="tile">
              <span>Lines with mistakes</span>
              <strong className={totals.with_errors ? 'due' : 'settled'}>{totals.with_errors}</strong>
            </div>
            <div className="tile">
              <span>Lines to look at</span>
              <strong>{totals.with_warnings}</strong>
            </div>
            <div className="tile">
              <span>Units</span>
              <strong>{totals.units.toLocaleString('en-IN')}</strong>
            </div>
            <div className="tile">
              <span>Worth at cost</span>
              <strong>{rupees(totals.at_cost)}</strong>
            </div>
            <div className="tile">
              <span>Worth at MRP</span>
              <strong>{rupees(totals.at_mrp)}</strong>
            </div>
            {totals.new_medicines > 0 && (
              <div className="tile">
                <span>Medicines to be added</span>
                <strong>{totals.new_medicines}</strong>
              </div>
            )}
          </div>

          {totals.with_errors > 0 ? (
            <p className="form-error">
              {totals.with_errors} {totals.with_errors === 1 ? 'line has a mistake' : 'lines have mistakes'}. Nothing has been added. Correct the sheet, choose it again and check it again.{' '}
              <button
                type="button"
                className="link"
                onClick={() => downloadCsv('opening-stock-mistakes.csv', ['Line', 'Medicine', 'Batch', 'What is wrong'], result.rows.filter((r) => r.errors.length).map((r) => [r.line + 1, r.medicine, r.batch_no, r.errors.join(' ')]))}
              >
                Download the list of mistakes
              </button>
            </p>
          ) : (
            <div className="day-form import-agree">
              <p className="notice">Every line can be added. Compare the totals above with your own stock sheet before adding.</p>
              <label className="field">
                <span className="field-label">Total stock value at cost on your own sheet (leave empty if you do not have one)</span>
                <input type="number" min="0" step="0.01" inputMode="decimal" value={sheetTotal} onChange={(e) => setSheetTotal(e.target.value)} />
                {gap !== null && !matches && <span className="field-error">Your sheet and these lines differ by {rupees(Math.abs(gap))}. Find the difference before adding.</span>}
                {gap !== null && matches && <span className="settled small">Matches.</span>}
              </label>
              <label className="check">
                <input type="checkbox" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} />
                I have compared the lines, units and value with our own count, and they are right
              </label>
              <div className="actions">
                <button type="button" className="btn primary" disabled={busy || !agreed || !matches || !canSave} onClick={() => send(true)}>
                  {busy ? 'Adding…' : `Add ${totals.lines.toLocaleString('en-IN')} batches to stock`}
                </button>
              </div>
            </div>
          )}

          {result.rows.length > 0 && (
            <>
              <div className="toolbar minor import-list-head">
                <h3 className="flush grow">{onlyProblems ? `Lines with something to look at (${problems.length})` : `All lines (${result.rows.length})`}</h3>
                <button type="button" className="link" onClick={() => setOnlyProblems((v) => !v)}>
                  {onlyProblems ? 'Show all lines' : 'Show only lines with something to look at'}
                </button>
              </div>
              {listed.length === 0 ? (
                <p className="muted">Nothing to look at: every line is clean.</p>
              ) : (
                <div className="table-wrap tall">
                  <table className="compact">
                    <thead>
                      <tr>
                        <th>Sheet row</th>
                        <th>Medicine</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th className="num">Units</th>
                        <th className="num">At cost</th>
                        <th>What to look at</th>
                      </tr>
                    </thead>
                    <tbody>
                      {listed.map((r) => (
                        <tr key={r.line} className="static">
                          <td>{r.line + 1}</td>
                          <td>{r.medicine || '—'}</td>
                          <td>{r.batch_no || '—'}</td>
                          <td>{r.expiry ? longDate(r.expiry).slice(3) : '—'}</td>
                          <td className="num">{r.quantity?.toLocaleString('en-IN') ?? '—'}</td>
                          <td className="num">{r.at_cost ? rupees(r.at_cost) : '—'}</td>
                          <td>
                            {r.errors.map((m) => (
                              <div key={m} className="due">
                                {m}
                              </div>
                            ))}
                            {r.warnings.map((m) => (
                              <div key={m} className="import-warn">
                                {m}
                              </div>
                            ))}
                            {!r.errors.length && !r.warnings.length && <span className="settled">Good</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {(onlyProblems ? problems.length : result.rows.length) > SHOWN && <p className="muted small">Showing the first {SHOWN}. Download the list of mistakes to see them all.</p>}
            </>
          )}
        </>
      )}
    </section>
  )
}
