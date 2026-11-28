import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { CrashScreen } from './CrashScreen'

afterEach(cleanup)

function Broken(): never {
  throw new Error('Sample failure while drawing')
}

describe('a screen that fails while being drawn', () => {
  it('shows what happened and ways on, instead of a blank page', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    render(
      <CrashScreen>
        <Broken />
      </CrashScreen>,
    )
    expect(screen.getByRole('alert').textContent).toContain('This screen stopped working')
    expect(screen.getByText(/Sample failure while drawing/)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Reset the app on this phone' })).toBeTruthy()
  })

  it('shows the screen itself when nothing goes wrong', () => {
    render(
      <CrashScreen>
        <p>All well</p>
      </CrashScreen>,
    )
    expect(screen.getByText('All well')).toBeTruthy()
  })
})
