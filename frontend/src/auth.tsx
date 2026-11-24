import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import type { AxiosError } from 'axios'
import { api, getToken, onRefused, setToken, USER_KEY } from './api'
import { setMayExport } from './format'
import { applyAppearance } from './appearance'
import type { User } from './types'

type AuthState = {
  user: User | null
  loading: boolean
  /** `login` is a mobile number or an email; `password` a password, or a PIN for a login opened with one. */
  signIn: (login: string, password: string) => Promise<void>
  /** The starting PIN just typed by someone who must now choose their own, so they are not asked for it twice. Never stored. */
  startingSecret: string | null
  signInWithGoogle: (credential: string) => Promise<void>
  signOut: () => Promise<void>
  /** Replaces the signed-in person's details after they change their own settings. */
  refresh: (user: User) => void
}

const AuthContext = createContext<AuthState | null>(null)

/** The person who was signed in when this browser last heard from the server. */
function remembered(): User | null {
  try {
    return getToken() ? (JSON.parse(localStorage.getItem(USER_KEY) ?? 'null') as User | null) : null
  } catch {
    return null
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUserState] = useState<User | null>(remembered)
  // With someone remembered the screen opens at once; the server is still asked, in the background.
  const [loading, setLoading] = useState(() => Boolean(getToken()) && !remembered())

  const setUser = (next: User | null) => {
    setUserState(next)
    try {
      if (next) localStorage.setItem(USER_KEY, JSON.stringify(next))
      else localStorage.removeItem(USER_KEY)
    } catch {
      // A full or blocked store only means the next load asks the server first, as it used to.
    }
  }

  // Whether lists may be taken away as a file follows the person's permissions, wherever the export button is.
  useEffect(() => {
    setMayExport(user?.can_export ?? true)
  }, [user?.can_export])

  // The look a person chose follows them to whichever computer they sign in on.
  useEffect(() => {
    if (user?.appearance) applyAppearance(user.appearance)
  }, [user?.appearance])

  useEffect(() => {
    if (!getToken()) return
    api
      .get<User>('/me')
      .then((res) => setUser(res.data))
      .catch((error: AxiosError) => {
        // Only the server saying "not signed in" ends the session; a dropped connection does not.
        if (error.response?.status === 401) {
          setToken(null)
          setUserState(null)
        }
      })
      .finally(() => setLoading(false))
  }, [])

  // Coming back to the window after a while, the server is asked again, so a reminder that someone else has
  // dealt with (or a permission that was changed) does not linger until the page is reloaded.
  useEffect(() => {
    let asked = Date.now()
    const onShow = () => {
      if (document.visibilityState !== 'visible' || !getToken() || Date.now() - asked < 120000) return
      asked = Date.now()
      api.get<User>('/me').then((res) => setUser(res.data)).catch(() => undefined)
    }
    document.addEventListener('visibilitychange', onShow)
    window.addEventListener('focus', onShow)
    return () => {
      document.removeEventListener('visibilitychange', onShow)
      window.removeEventListener('focus', onShow)
    }
  }, [])

  // A permission changed by an admin takes hold on the server at once. The screen learns of it here: once a minute
  // while the window is in view, and straight away when the server refuses something as not allowed.
  useEffect(() => {
    let last = 0
    const ask = () => {
      if (!getToken() || Date.now() - last < 5000) return
      last = Date.now()
      api.get<User>('/me').then((res) => setUser(res.data)).catch(() => undefined)
    }
    const stop = onRefused(ask)
    const timer = window.setInterval(() => document.visibilityState === 'visible' && ask(), 60000)
    return () => {
      stop()
      window.clearInterval(timer)
    }
  }, [])

  const [startingSecret, setStartingSecret] = useState<string | null>(null)
  const signIn = async (login: string, password: string) => {
    const res = await api.post<{ token: string; user: User }>('/login', { login, password })
    setStartingSecret(res.data.user.must_change_secret ? password : null)
    setToken(res.data.token)
    setUser(res.data.user)
  }

  const signInWithGoogle = async (credential: string) => {
    const res = await api.post<{ token: string; user: User }>('/login/google', { credential })
    setToken(res.data.token)
    setUser(res.data.user)
  }

  const signOut = async () => {
    await api.post('/logout').catch(() => undefined)
    setUser(null)
    setToken(null)
  }

  return <AuthContext.Provider value={{ user, loading, signIn, startingSecret, signInWithGoogle, signOut, refresh: setUser }}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
