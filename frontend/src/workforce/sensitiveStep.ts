import { useCallback, useRef, useState } from 'react'
import type { AxiosError } from 'axios'
import { api, fieldErrors } from '../api'

export type PasswordAsk = { open: boolean; confirm: (password: string) => Promise<string>; cancel: () => void }

/**
 * For the few steps the server will not do from a desk left signed in. `run` tries the step; if the server answers
 * "type your password again" (428), `ask.open` turns true so the screen can show the PasswordAgain window, and the
 * step is tried once more after the password is accepted. The caller only ever sees the step succeed or fail.
 */
export function usePasswordAgain(): { run: <T>(step: () => Promise<T>) => Promise<T>; ask: PasswordAsk } {
  const [open, setOpen] = useState(false)
  const waiting = useRef<{ go: () => void; stop: (reason: unknown) => void } | null>(null)

  const run = useCallback(<T>(step: () => Promise<T>): Promise<T> => {
    return step().catch((err: AxiosError) => {
      if (err.response?.status !== 428) throw err
      return new Promise<T>((resolve, reject) => {
        waiting.current = { go: () => step().then(resolve, reject), stop: reject }
        setOpen(true)
      })
    })
  }, [])

  /** Sends the password. Returns '' when accepted (and the waiting step goes ahead), or the message to show. */
  const confirm = useCallback(async (password: string) => {
    try {
      await api.post('/workforce/confirm-password', { password })
    } catch (err) {
      return Object.values(fieldErrors(err))[0] ?? 'That did not work. Try again.'
    }
    setOpen(false)
    waiting.current?.go()
    waiting.current = null
    return ''
  }, [])

  const cancel = useCallback(() => {
    setOpen(false)
    waiting.current?.stop(new Error('Cancelled'))
    waiting.current = null
  }, [])

  return { run, ask: { open, confirm, cancel } }
}

/** Fetches a file the server makes (an export) with the sign-in attached, and hands it to the browser to save. */
export async function saveFile(path: string, params: Record<string, string | number | undefined>, name: string) {
  const res = await api.get<Blob>(path, { params, responseType: 'blob' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(res.data)
  link.download = name
  link.click()
  URL.revokeObjectURL(link.href)
}
