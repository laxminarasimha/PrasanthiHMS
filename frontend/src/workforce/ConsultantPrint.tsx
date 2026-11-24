import { useEffect, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { PaperBar, PaperHeader } from '../components/PrintPaper'
import { useAutoPrint, usePrintPaper } from '../printPaper'
import { StatementBody, type Statement } from './Consultants'
import { inr } from './pay'
import { thisMonth } from './shared'

/** A consultant's statement for a month, on paper: what they earned and from what, and what was paid to them. */
export function ConsultantPrint() {
  const { id } = useParams()
  const [search] = useSearchParams()
  const month = search.get('month') ?? thisMonth()
  const [s, setS] = useState<Statement | null>(null)
  const [error, setError] = useState('')
  const paper = usePrintPaper()

  useEffect(() => {
    api
      .get<Statement>(`/consultants/${id}`, { params: { month } })
      .then((res) => setS(res.data))
      .catch(() => setError('This statement could not be opened.'))
  }, [id, month])
  useAutoPrint(Boolean(s) && paper.ready)

  if (error) return <p className="form-error">{error}</p>
  if (!s) return <p className="loading">Loading the statement…</p>

  return (
    <div className="receipt">
      <PaperBar paper={paper} />
      <PaperHeader paper={paper}>
        <h2>Consultant's statement for {s.month_name}</h2>
      </PaperHeader>
      <div className="receipt-meta">
        <div>
          <strong>{s.doctor}</strong>
          {s.department && (
            <>
              <br />
              {s.department}
            </>
          )}
        </div>
        <div className="right">
          Earned: <strong>{inr(s.earned)}</strong>
          <br />
          Paid: <strong>{inr(s.paid)}</strong>
          <br />
          {s.due < 0 ? 'Paid ahead' : 'Still due'}: <strong>{inr(Math.abs(s.due))}</strong>
        </div>
      </div>
      {/* No foot here: the hospital's own is a message to patients. */}
      <StatementBody s={s} />
    </div>
  )
}
