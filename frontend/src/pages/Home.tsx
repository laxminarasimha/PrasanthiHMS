import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { rupees } from '../format'

type Dashboard = {
  date: string
  appointments?: { mine: boolean; booked: number; arrived: number; ongoing: number; reviewed: number; cancelled: number }
  follow_ups?: { due: number; unbooked: number }
  unpaid_bills?: number
  lab?: { to_collect: number; to_report: number; to_approve: number }
  pharmacy?: { bills_today: number; expiring: number; expired: number }
  ipd?: { inpatients: number; beds: number; free_beds: number; admitted_today: number; discharged_today: number }
  money?: { collected: number; new_patients: number }
}

function Tile({ to, label, value, alert, note }: { to: string; label: string; value: ReactNode; alert?: boolean; note?: string }) {
  return (
    <Link className={`tile link-tile ${alert ? 'alert' : ''}`} to={to}>
      <span>{label}</span>
      <strong>{value}</strong>
      {note && <small className="muted">{note}</small>}
    </Link>
  )
}

/** Today at a glance. Each number opens the screen where that work is done. */
export function Home() {
  const { user } = useAuth()
  const [data, setData] = useState<Dashboard | null>(null)

  useEffect(() => {
    const load = () => api.get<Dashboard>('/dashboard').then((res) => setData(res.data))
    load()
    const timer = setInterval(load, 60000)
    return () => clearInterval(timer)
  }, [])

  const day = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  const visits = data?.appointments
  const visitsTo = user?.can_front_desk && !visits?.mine ? '/front-desk' : '/doctor'

  return (
    <section className="card">
      <div className="toolbar">
        <div className="grow">
          <h1>Hello, {user?.name}</h1>
          <span className="muted">{day}</span>
        </div>
        <Link className="btn ghost" to="/account">
          My account
        </Link>
      </div>

      {!data ? (
        <p className="loading">Loading today's figures…</p>
      ) : (
        <>
          {visits && (
            <>
              <h3>{visits.mine ? 'My patients today' : 'Appointments today'}</h3>
              <div className="tiles">
                <Tile to={visitsTo} label="Waiting (arrived)" value={visits.arrived} alert={visits.arrived > 0} />
                <Tile to={visitsTo} label="With the doctor" value={visits.ongoing} />
                <Tile to={visitsTo} label="Yet to arrive" value={visits.booked} />
                <Tile to={visitsTo} label="Seen" value={visits.reviewed} />
                {data.follow_ups && <Tile to="/front-desk" label="Follow-ups due today" value={data.follow_ups.due} alert={data.follow_ups.unbooked > 0} note={data.follow_ups.unbooked > 0 ? `${data.follow_ups.unbooked} not booked yet` : undefined} />}
                {data.unpaid_bills !== undefined && <Tile to="/front-desk/bills" label="Today's bills with money due" value={data.unpaid_bills} alert={data.unpaid_bills > 0} />}
              </div>
            </>
          )}

          {data.ipd && (
            <>
              <h3>IPD</h3>
              <div className="tiles">
                <Tile to="/ipd" label="Inpatients now" value={data.ipd.inpatients} />
                <Tile to="/ipd" label="Free beds" value={`${data.ipd.free_beds} of ${data.ipd.beds}`} />
                <Tile to="/ipd" label="Admitted today" value={data.ipd.admitted_today} />
                <Tile to="/ipd" label="Discharged today" value={data.ipd.discharged_today} />
              </div>
            </>
          )}

          {data.lab && (
            <>
              <h3>Lab</h3>
              <div className="tiles">
                <Tile to="/lab" label="Samples to collect" value={data.lab.to_collect} alert={data.lab.to_collect > 0} note="Ordered in the last 7 days" />
                <Tile to="/lab" label="Results to enter" value={data.lab.to_report} alert={data.lab.to_report > 0} />
                <Tile to="/lab" label="Results to approve" value={data.lab.to_approve} />
              </div>
            </>
          )}

          {data.pharmacy && (
            <>
              <h3>Pharmacy</h3>
              <div className="tiles">
                <Tile to="/pharmacy/bills" label="Bills today" value={data.pharmacy.bills_today} />
                <Tile to="/pharmacy/inventory" label="Batches expiring in 90 days" value={data.pharmacy.expiring} alert={data.pharmacy.expiring > 0} />
                <Tile to="/pharmacy/inventory" label="Expired batches in stock" value={data.pharmacy.expired} alert={data.pharmacy.expired > 0} />
              </div>
            </>
          )}

          {data.money && (
            <>
              <h3>Hospital today</h3>
              <div className="tiles">
                <Tile to="/reports" label="Collected today, all counters" value={rupees(data.money.collected)} note="Net of refunds" />
                <Tile to="/patients" label="New patients today" value={data.money.new_patients} />
              </div>
            </>
          )}
        </>
      )}
    </section>
  )
}
