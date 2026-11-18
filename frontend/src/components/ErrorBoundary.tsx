import { Component, type ReactNode } from 'react'

/**
 * Keeps one broken screen from taking the whole app with it. Without this, an unexpected fault while drawing a
 * page leaves a blank white window; with it the menu stays and the person is told what to do.
 */
export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  componentDidCatch(error: unknown) {
    console.error('A screen could not be shown:', error)
  }

  render() {
    if (!this.state.failed) return this.props.children
    return (
      <div className="card">
        <h1>This screen could not be shown</h1>
        <p className="muted">Something went wrong while drawing it. Anything already saved is safe.</p>
        <p>
          <button type="button" className="btn primary" onClick={() => window.location.reload()}>
            Reload this page
          </button>
        </p>
        <p className="muted small">If it happens again on the same screen, tell whoever looks after the system which screen it was and what you had just done.</p>
      </div>
    )
  }
}
