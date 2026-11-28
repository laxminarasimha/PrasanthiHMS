import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { PaperBar, PaperHeader } from '../components/PrintPaper'
import { paperClass, useAutoPrint, usePrintPaper } from '../printPaper'
import { longDate, rupees } from '../format'

type Drug = {
  id: number
  name: string
  composition: string | null
  form: string | null
  pack: string | null
  mrp: number | null
  rate: number | null
  scheme: string | null
  uses: string | null
  dose: string | null
  frequency: string | null
  timing: string | null
  duration: string | null
  note: string | null
}
type Sheet = { company: { id: number; name: string }; rep: { id: number; name: string; mobile: string | null; division: string | null } | null; made_on: string; drugs: Drug[] }

/**
 * A reference sheet of a medicine company's drugs, or of those one representative spoke about: each brand with what
 * it contains, what it is for, how it is given, and what it costs. Laid out to be printed, like a prescription.
 */
export function RepReferencePrint() {
  const [params] = useSearchParams()
  const [sheet, setSheet] = useState<Sheet | null>(null)
  const [failed, setFailed] = useState(false)
  const paper = usePrintPaper(['a4', 'a5'])
  const rep = params.get('rep')
  const company = params.get('company')

  useEffect(() => {
    api
      .get<Sheet>('/reps/reference', { params: rep ? { rep_id: rep } : { company_id: company } })
      .then((res) => setSheet(res.data))
      .catch(() => setFailed(true))
  }, [rep, company])
  useAutoPrint(Boolean(sheet) && paper.ready)

  if (failed) return <p className="form-error">This reference sheet could not be opened.</p>
  if (!sheet) return <p className="loading">Loading the reference sheet…</p>

  return (
    <div className={`receipt ${paperClass(paper)}`}>
      <PaperBar paper={paper} />
      <PaperHeader paper={paper}>
        <h2>Drug reference</h2>
      </PaperHeader>

      <div className="receipt-meta">
        <div>
          <strong>{sheet.company.name}</strong>
          {sheet.rep && (
            <>
              <br />
              Representative: {sheet.rep.name}
              {sheet.rep.division ? ` · ${sheet.rep.division}` : ''}
              {sheet.rep.mobile ? ` · ${sheet.rep.mobile}` : ''}
            </>
          )}
        </div>
        <div className="right">
          {sheet.drugs.length} {sheet.drugs.length === 1 ? 'drug' : 'drugs'}
          <br />
          As on {longDate(sheet.made_on)}
        </div>
      </div>

      {sheet.drugs.length === 0 ? (
        <p className="muted">{sheet.rep ? 'No drug has been written on this representative’s visits yet.' : 'Nothing is in this company’s catalogue yet.'}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th className="col-extra">#</th>
              <th className="col-name">Brand and what it contains</th>
              <th>Used for</th>
              <th>How it is given</th>
              <th className="num">MRP</th>
              <th className="num">Our price</th>
            </tr>
          </thead>
          <tbody>
            {sheet.drugs.map((d, i) => {
              const given = [d.dose, d.frequency, d.timing, d.duration].filter(Boolean).join(', ')
              return (
                <tr key={d.id}>
                  <td className="col-extra">{i + 1}</td>
                  <td className="col-name">
                    <strong>{d.name}</strong>
                    {[d.form, d.pack].filter(Boolean).length > 0 && <small> · {[d.form, d.pack].filter(Boolean).join(', ')}</small>}
                    {d.composition && <small className="block">{d.composition}</small>}
                  </td>
                  <td>{d.uses}</td>
                  <td>
                    {given}
                    {d.note && <small className="block">{d.note}</small>}
                  </td>
                  <td className="num">{d.mrp !== null ? rupees(d.mrp) : ''}</td>
                  <td className="num">
                    {d.rate !== null ? rupees(d.rate) : ''}
                    {d.scheme && <small className="block">{d.scheme}</small>}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}

      {/* No footer: the hospital's is written for a patient's bill, and this sheet is for the doctor's own use. */}
    </div>
  )
}
