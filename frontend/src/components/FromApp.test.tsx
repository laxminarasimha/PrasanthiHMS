import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { FromApp } from './FromApp'

afterEach(cleanup)

describe('the patient app mark', () => {
  it('marks a visit the patient booked on the app, with the words in full on hover', () => {
    render(<FromApp via="app" />)
    const tag = screen.getByText('App')
    expect(tag.getAttribute('title')).toBe('Booked from the patient app')
    expect(tag.className).toBe('app-tag')
    // Only a mark: nothing to press or follow.
    expect(tag.tagName).toBe('SPAN')
    expect(tag.closest('a, button')).toBeNull()
  })

  it('can sit on a line of its own under a time', () => {
    render(<FromApp via="app" block />)
    expect(screen.getByText('App').className).toBe('app-tag block')
  })

  it('shows nothing for a visit booked at the hospital', () => {
    const { container } = render(
      <>
        <FromApp via={null} />
        <FromApp via={undefined} />
        <FromApp via="desk" />
      </>,
    )
    expect(container.innerHTML).toBe('')
  })
})
