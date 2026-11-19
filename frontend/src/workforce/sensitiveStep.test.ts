import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { usePasswordAgain } from './sensitiveStep'

const post = vi.fn()
vi.mock('../api', () => ({ api: { post: (...args: unknown[]) => post(...args) }, fieldErrors: (err: { message?: string }) => ({ password: err.message ?? 'That is not your password.' }) }))

const refused = () => Promise.reject({ response: { status: 428 } })

describe('a step that needs the password typed again', () => {
  it('goes straight through when the server does not ask', async () => {
    const { result } = renderHook(() => usePasswordAgain())
    await expect(result.current.run(() => Promise.resolve('done'))).resolves.toBe('done')
    expect(result.current.ask.open).toBe(false)
  })

  it('asks for the password when the server says so, then tries the step once more', async () => {
    const step = vi.fn().mockImplementationOnce(refused).mockResolvedValueOnce('finalised')
    post.mockResolvedValue({})
    const { result } = renderHook(() => usePasswordAgain())

    let outcome: Promise<unknown> = Promise.resolve()
    await act(async () => {
      outcome = result.current.run(step)
      await Promise.resolve()
    })
    expect(result.current.ask.open).toBe(true)
    expect(step).toHaveBeenCalledTimes(1)

    await act(async () => {
      expect(await result.current.ask.confirm('Sample-Passw0rd')).toBe('')
    })
    await expect(outcome).resolves.toBe('finalised')
    expect(post).toHaveBeenCalledWith('/workforce/confirm-password', { password: 'Sample-Passw0rd' })
    expect(step).toHaveBeenCalledTimes(2)
    expect(result.current.ask.open).toBe(false)
  })

  it('keeps asking when the password is wrong, and does not try the step', async () => {
    const step = vi.fn().mockImplementation(refused)
    post.mockImplementation(() => Promise.reject({ message: 'That is not your password.' }))
    const { result } = renderHook(() => usePasswordAgain())

    let waitingStep: Promise<unknown> = Promise.resolve()
    await act(async () => {
      waitingStep = result.current.run(step)
      await Promise.resolve()
    })
    const answers: string[] = []
    await act(async () => {
      answers.push(await result.current.ask.confirm('wrong'))
    })
    expect(answers).toEqual(['That is not your password.'])
    expect(result.current.ask.open).toBe(true)
    expect(step).toHaveBeenCalledTimes(1)

    // Closing the window ends the wait; the step is never tried again.
    act(() => result.current.ask.cancel())
    await expect(waitingStep).rejects.toThrow('Cancelled')
    expect(step).toHaveBeenCalledTimes(1)
  })

  it('gives up cleanly when the person cancels, and passes on any other failure untouched', async () => {
    const { result } = renderHook(() => usePasswordAgain())
    let outcome: Promise<unknown> = Promise.resolve()
    await act(async () => {
      outcome = result.current.run(refused)
      await Promise.resolve()
    })
    act(() => result.current.ask.cancel())
    await expect(outcome).rejects.toThrow('Cancelled')
    expect(result.current.ask.open).toBe(false)

    await expect(result.current.run(() => Promise.reject({ response: { status: 403 } }))).rejects.toEqual({ response: { status: 403 } })
  })
})
