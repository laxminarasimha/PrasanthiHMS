import { useEffect, useState } from 'react'
import { api } from './api'
import { ownRecordOnly } from './access'
import { useNotice } from './notices'
import type { User } from './types'

/** Sent whenever a task is written, ticked or withdrawn, so the number on the menu follows at once. */
export const TODO_CHANGED = 'hms:todo'
export const todoChanged = () => window.dispatchEvent(new Event(TODO_CHANGED))

/** How many of the person's own tasks are due today or late: the number beside "To do" on the menu. */
export function useTodoCount(user: User | null | undefined): number {
  const [count, setCount] = useState(0)
  const id = user && !ownRecordOnly(user) ? user.id : null
  // A task given by someone else has just reached this device: the number follows at once. Only tasks are counted.
  useNotice(['task'], todoChanged)
  useEffect(() => {
    if (id === null) return
    let live = true
    const load = () =>
      api
        .get<{ pressing: number }>('/todo/count')
        .then((res) => live && setCount(res.data.pressing))
        .catch(() => undefined)
    void load()
    const timer = window.setInterval(load, 5 * 60 * 1000)
    window.addEventListener(TODO_CHANGED, load)
    return () => {
      live = false
      window.clearInterval(timer)
      window.removeEventListener(TODO_CHANGED, load)
    }
  }, [id])
  return id === null ? 0 : count
}
