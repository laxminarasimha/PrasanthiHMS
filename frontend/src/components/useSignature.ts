import { useEffect, useState } from 'react'
import { api } from '../api'

/** The picture of a staff member's signature, for prints. Null while loading or when they have none. */
export function useSignature(userId: number | null | undefined, version = 0): string | null {
  const [found, setFound] = useState<{ key: string; url: string } | null>(null)
  const key = `${userId}:${version}`

  useEffect(() => {
    if (!userId) return
    let current = true
    api
      .get<Blob>(`/users/${userId}/signature`, { responseType: 'blob' })
      .then((res) => current && setFound({ key: `${userId}:${version}`, url: URL.createObjectURL(res.data) }))
      .catch(() => current && setFound(null))
    return () => {
      current = false
    }
  }, [userId, version])

  return found?.key === key ? found.url : null
}
