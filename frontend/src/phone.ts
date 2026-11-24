import { useEffect, useState } from 'react'

/** The width up to which the app is laid out as a phone app: a bar at the foot of the screen, a slim bar at the top. The stylesheet uses the same number. */
const PHONE = '(max-width: 768px)'

/** Whether the screen is as narrow as a phone or a small tablet right now. */
export function usePhone(): boolean {
  const [phone, setPhone] = useState(() => window.matchMedia(PHONE).matches)
  useEffect(() => {
    const query = window.matchMedia(PHONE)
    const changed = () => setPhone(query.matches)
    query.addEventListener('change', changed)
    return () => query.removeEventListener('change', changed)
  }, [])
  return phone
}
