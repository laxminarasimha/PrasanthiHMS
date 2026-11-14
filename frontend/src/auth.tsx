import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, getToken, setToken } from './api'
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

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(Boolean(getToken()))

  // The look a person chose follows them to whichever computer they sign in on.
  useEffect(() => {
    if (user?.appearance) applyAppearance(user.appearance)
  }, [user?.appearance])

  useEffect(() => {
    if (!getToken()) return
    api
      .get<User>('/me')
      .then((res) => setUser(res.data))
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
  }, [])

  const signIn = async (email: string, password: string) => {
    const res = await api.post<{ token: string; user: User }>('/login', { email, password })
    setToken(res.data.token)
    setUser(res.data.user)
  }

  const signOut = async () => {
    await api.post('/logout').catch(() => undefined)
    setToken(null)
    setUser(null)
  }

  return <AuthContext.Provider value={{ user, loading, signIn, signOut, refresh: setUser }}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
