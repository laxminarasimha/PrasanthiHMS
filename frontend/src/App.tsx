import { useEffect, useState } from 'react'
import { api } from './api'
import './App.css'

type Health = {
  app: string
  status: string
  database: string
  php: string
  laravel: string
}

const modules = [
  'Patients',
  'Doctors',
  'Front Desk',
  'Lab',
  'Inpatient',
  'Pharmacy',
]

function App() {
  const [health, setHealth] = useState<Health | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    api
      .get<Health>('/health')
      .then((res) => setHealth(res.data))
      .catch(() => setError(true))
  }, [])

  return (
    <main className="shell">
      <h1>Prasanthi HMS</h1>
      <p className="subtitle">Hospital Management System</p>

      <section className="status">
        <h2>System status</h2>
        {error && <p className="bad">API is not reachable. Is the backend running?</p>}
        {!error && !health && <p>Checking API…</p>}
        {health && (
          <ul>
            <li>
              API: <strong className="good">{health.status}</strong>
            </li>
            <li>
              Database:{' '}
              <strong className={health.database === 'connected' ? 'good' : 'bad'}>
                {health.database}
              </strong>
            </li>
            <li>
              PHP {health.php} · Laravel {health.laravel}
            </li>
          </ul>
        )}
      </section>

      <section>
        <h2>Planned modules</h2>
        <ul className="modules">
          {modules.map((name) => (
            <li key={name}>{name}</li>
          ))}
        </ul>
      </section>
    </main>
  )
}

export default App
