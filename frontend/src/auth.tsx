import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import type { AxiosError } from 'axios'
import { api, getToken, setToken, USER_KEY } from './api'
import { applyAppearance } from './appearance'
import type { User } from './types'

type AuthState = {
  user: User | null
  loading: boolean
  signIn: (email: string, password: string) => Promise<void>
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

  const signIn = async (email: string, password: string) => {
    const res = await api.post<{ token: string; user: User }>('/login', { email, password })
    setToken(res.data.token)
    setUser(res.data.user)
  }

  const signOut = async () => {
    await api.post('/logout').catch(() => undefined)
    setUser(null)
    setToken(null)
  }

  return <AuthContext.Provider value={{ user, loading, signIn, signOut, refresh: setUser }}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
