import { api } from '../api'
import { useAuth } from '../auth'
import { BrandMark } from '../components/Brand'
import { SecretForm } from '../components/SecretForm'
import type { User } from '../types'

/**
 * Shown in place of everything else to someone signed in with a starting PIN the hospital handed them: they choose
 * their own before anything opens. The server holds the same rule, so there is no way round this screen.
 */
export function ChooseSecret() {
  const { user, refresh, signOut, startingSecret } = useAuth()
  if (!user) return null
  const pin = user.sign_in_with === 'pin'
  const word = pin ? 'PIN' : 'password'

  return (
    <div className="login">
      <div className="card login-card">
        <BrandMark large />
        <div className="brand large">Choose your own {word}</div>
        <p className="muted">
          Welcome, {user.name}. The {word} you were given was only to get you started. Choose your own now{pin ? ': six digits that you will remember and nobody else knows' : ''}. You will sign in with it from next time.
        </p>
        <SecretForm pin={pin} current={startingSecret ?? undefined} action={`Save my ${word}`} onDone={() => void api.get<User>('/me').then((res) => refresh(res.data))} />
        <button type="button" className="link" onClick={() => void signOut()}>
          Sign out
        </button>
      </div>
    </div>
  )
}
