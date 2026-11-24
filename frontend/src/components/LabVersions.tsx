import { useEffect, useState } from 'react'
import { api } from '../api'
import { clock, longDate } from '../format'
import type { LabParameter, LabRange } from '../types'

/** Who an extra normal range is for: "Female, 12 to 50 years". */
const who = (r: LabRange) => {
  const age = r.age_from !== null && r.age_to !== null ? `${r.age_from} to ${r.age_to} ${r.age_unit}` : r.age_from !== null ? `${r.age_from} ${r.age_unit} and over` : r.age_to !== null ? `up to ${r.age_to} ${r.age_unit}` : ''
  return [r.sex ? r.sex.charAt(0).toUpperCase() + r.sex.slice(1) : '', age].filter(Boolean).join(', ')
}

type Version = {
  version: number
  is_current: boolean
  name: string
  kind: 'values' | 'text'
  default_comment: string | null
  parameters: LabParameter[]
  source: 'saved' | 'start' | 'reports' | 'found'
  change: string | null
  created_at: string | null
  by: string | null
  reports: number
  approved: number
}

/** How a version came to be written down, when no person's name goes with it. */
const HOW: Record<Version['source'], string> = {
  saved: 'Saved on this screen',
  start: 'Written down when versions began to be kept',
  reports: 'Worked out from earlier reports',
  found: 'Changed outside this screen',
}

/**
 * Every version of one test's report template, newest first: when it was made, by whom, what changed, and how many
 * reports were made with it. A report keeps the version it was made with, so this is where to see what an older
 * report's fields were.
 */
export function LabVersions({ testId }: { testId: number }) {
  const [versions, setVersions] = useState<Version[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<number | null>(null)

  useEffect(() => {
    let current = true
    api
      .get<Version[]>(`/lab/tests/${testId}/versions`)
      .then((res) => current && setVersions(res.data))
      .catch(() => current && setFailed(true))
    return () => {
      current = false
    }
  }, [testId])

  const fields = (v: Version) => v.parameters.filter((p) => !p.is_heading).length
  return (
    <section className="lab-versions">
      <h3>Versions of this template</h3>
      <p className="muted small">
        Saving a change to the name, the fields or the default comment makes the next version. A report already made keeps the version it was made with, so it never changes. A change of price,
        department or sample makes no new version.
      </p>
      {!versions && !failed && <p className="loading">Loading the versions…</p>}
      {failed && <p className="muted">The versions could not be loaded. Close this and open the test again.</p>}
      {versions && (
        <ol>
          {versions.map((v) => (
            <li key={v.version} className={v.is_current ? 'current' : ''}>
              <div className="lab-version-head">
                <strong>Version {v.version}</strong>
                {v.is_current && <span className="pill ok">In use now</span>}
                <span className="muted small">
                  {v.kind === 'text' ? 'Written report' : `${fields(v)} ${fields(v) === 1 ? 'field' : 'fields'}`}
                  {v.created_at ? ` · ${longDate(v.created_at)} ${clock(v.created_at)}` : ''}
                  {v.by ? ` · ${v.by}` : ''}
                </span>
              </div>
              {v.change && <p className="small">{v.change}</p>}
              <p className="muted small">
                {!v.by && `${HOW[v.source]}. `}
                {v.reports === 0 ? 'No report made with it yet.' : `${v.reports} ${v.reports === 1 ? 'report' : 'reports'} made with it, ${v.approved} approved.`}{' '}
                {v.kind !== 'text' && v.parameters.length > 0 && (
                  <button type="button" className="link" onClick={() => setOpen(open === v.version ? null : v.version)} aria-expanded={open === v.version}>
                    {open === v.version ? 'Hide its fields' : 'Show its fields'}
                  </button>
                )}
              </p>
              {v.name !== versions[0].name && <p className="muted small">Then named “{v.name}”.</p>}
              {open === v.version && (
                <table className="compact">
                  <thead>
                    <tr>
                      <th>Field</th>
                      <th>Unit</th>
                      <th>Normal range</th>
                    </tr>
                  </thead>
                  <tbody>
                    {v.parameters.map((p, i) =>
                      p.is_heading ? (
                        <tr key={i} className="static">
                          <td colSpan={3}>
                            <strong>{p.name}</strong>
                          </td>
                        </tr>
                      ) : (
                        <tr key={i} className="static">
                          <td className="wrap">
                            {p.name}
                            {p.method && <small className="muted block">{p.method}</small>}
                          </td>
                          <td>{p.unit ?? '—'}</td>
                          <td className="wrap">
                            {p.reference_range ?? '—'}
                            {(p.ranges ?? []).map((r, j) => (
                              <small className="muted block" key={j}>
                                {who(r)}: {r.range}
                              </small>
                            ))}
                          </td>
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
              )}
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}
