import { useEffect, useState } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import type { QueueBoard as Board } from '../types'

/** A screen for the waiting room: who each doctor is seeing and who is next. Shows tokens and names only. */
export function QueueBoard() {
  const { user } = useAuth()
  const [board, setBoard] = useState<Board | null>(null)
  const [now, setNow] = useState(new Date())

  useEffect(() => {
    const load = () => {
      api.get<Board>('/front-desk/queue').then((res) => setBoard(res.data))
      setNow(new Date())
    }
    load()
    const timer = setInterval(load, 15000)
    return () => clearInterval(timer)
  }, [])

  return (
    <div className="queue-board">
      <header>
        <h1>{user?.branch?.name ?? 'Prasanthi Hospitals'}</h1>
        <span>{now.toLocaleString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' })}</span>
      </header>
      <div className="queue-doctors">
        {board?.map((d) => (
          <section key={d.doctor}>
            <h2>
              {d.doctor}
              {d.department && <small>{d.department}</small>}
            </h2>
            <div className="queue-now">
              <span>Now seeing</span>
              {d.now.length ? d.now.map((p) => <strong key={p.token}>Token {p.token} · {p.name}</strong>) : <strong className="idle">—</strong>}
            </div>
            <ol>
              {d.waiting.map((p) => (
                <li key={p.token}>
                  <b>{p.token}</b> {p.name}
                </li>
              ))}
              {d.waiting.length === 0 && <li className="idle">No one waiting</li>}
            </ol>
          </section>
        ))}
        {board && board.length === 0 && <p className="idle big">No patients are waiting right now.</p>}
      </div>
    </div>
  )
}
