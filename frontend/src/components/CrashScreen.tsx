import { Component, type ErrorInfo, type ReactNode } from 'react'
import { resetThisPhone } from '../resetPhone'

type State = { error: Error | null }

/**
 * If a screen fails while it is being drawn, the person sees what happened and two ways on, instead of a blank
 * white page. The words of the problem are shown small, so they can be read out or sent as a photo.
 */
export class CrashScreen extends Component<{ children: ReactNode }, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('A screen stopped working', error, info.componentStack)
  }

  render() {
    const { error } = this.state
    if (!error) return this.props.children
    return (
      <main className="crash-screen" role="alert">
        <h1>This screen stopped working</h1>
        <p>Nothing you saved is lost. Try opening it again; if it keeps happening, send a photo of this page to the office.</p>
        <p className="crash-buttons">
          <button type="button" className="btn primary" onClick={() => window.location.reload()}>
            Try again
          </button>
          <button type="button" className="btn" onClick={() => window.location.assign('/')}>
            Go to the first screen
          </button>
          <button type="button" className="btn ghost" onClick={() => void resetThisPhone()} title="Takes away what the app keeps on this phone. You sign in again afterwards.">
            Reset the app on this phone
          </button>
        </p>
        <p className="crash-detail">
          {error.name}: {error.message}
          <br />
          {navigator.userAgent}
        </p>
      </main>
    )
  }
}
