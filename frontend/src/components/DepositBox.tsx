import { useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, modeLabel, rupees } from '../format'
import type { PatientDeposit } from '../types'

type Props = {
  patientId: number
  balance: number
  /** The deposit's history, shown when given. */
  history?: PatientDeposit[]
  /** Called with the new balance and history after money is taken or handed back. */
  onChange: (balance: number, history: PatientDeposit[]) => void
}

const LABEL = { deposit: 'Deposit', used: 'Used', refund: 'Refund' } as const

/** A patient's deposit: what is held, and taking more or handing some back. */
export function DepositBox({ patientId, balance, history, onChange }: Props) {
  const [type, setType] = useState<'deposit' | 'refund' | null>(null)
  const [amount, setAmount] = useState('')
  const [mode, setMode] = useState('cash')
  const [error, setError] = useState('')

  const save = async (e: FormEvent) => {
    e.preventDefault()
    e.stopPropagation()
    try {
      const res = await api.post<{ deposit_balance: number; deposits: PatientDeposit[] }>(`/patients/${patientId}/deposits`, { type, amount, mode })
      onChange(res.data.deposit_balance, res.data.deposits)
      setType(null)
      setAmount('')
      setError('')
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  return (
    <div className="deposit-box">
      <div className="row">
        <span className="grow">
          On deposit <strong className={balance > 0 ? 'settled' : ''}>{rupees(balance)}</strong>
        </span>
        <button type="button" className="link" onClick={() => setType(type === 'deposit' ? null : 'deposit')}>
          + Add deposit
        </button>
        {balance > 0 && (
          <button type="button" className="link" onClick={() => setType(type === 'refund' ? null : 'refund')}>
            Refund deposit
          </button>
        )}
      </div>
      {type && (
        <form className="row gap-top" onSubmit={save}>
          <input type="number" min={0} step="0.01" max={type === 'refund' ? balance : undefined} placeholder={type === 'refund' ? 'Amount to hand back ₹' : 'Deposit amount ₹'} value={amount} onChange={(e) => setAmount(e.target.value)} aria-label="Amount" autoFocus />
          <select value={mode} onChange={(e) => setMode(e.target.value)} aria-label="Mode">
            {PAYMENT_MODES.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </select>
          <button className="btn primary" disabled={!amount || Number(amount) <= 0}>
            {type === 'refund' ? 'Refund' : 'Take deposit'}
          </button>
        </form>
      )}
      {error && <p className="form-error">{error}</p>}
      {history && history.length > 0 && (
        <ul className="history gap-top">
          {history.map((d) => (
            <li key={d.id}>
              <span>
                {LABEL[d.type]}
                {d.mode ? ` · ${modeLabel(d.mode)}` : ''}
                {d.reference ? ` · ${d.reference}` : ''}
                <small className="muted">
                  {' '}
                  {new Date(d.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' })}
                  {d.receiver ? ` by ${d.receiver.name}` : ''}
                </small>
              </span>
              <strong className={d.type === 'deposit' ? '' : 'due'}>
                {d.type === 'deposit' ? '' : '−'}
                {rupees(d.amount)}
              </strong>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
