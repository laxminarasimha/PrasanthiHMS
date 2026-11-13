import { useEffect, useState } from 'react'
import { api } from '../api'

/** The picture of a staff member's signature, for prints. Null while loading or when they have none. */
export function useSignature(userId: number | null | undefined, version = 0, kind: 'signature' | 'seal' = 'signature'): string | null {
  const [found, setFound] = useState<{ key: string; url: string } | null>(null)
  const key = `${kind}:${userId}:${version}`

  useEffect(() => {
    if (!userId) return
    let current = true
    api
      .get<Blob>(`/users/${userId}/${kind}`, { responseType: 'blob' })
      .then((res) => current && setFound({ key: `${kind}:${userId}:${version}`, url: URL.createObjectURL(res.data) }))
      .catch(() => current && setFound(null))
    return () => {
      current = false
    }
  }, [userId, version, kind])

  return found?.key === key ? found.url : null
}
