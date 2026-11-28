/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * A printed page has to match what is shown before printing. To the browser a sheet of paper is as narrow as a
 * phone (an A5 sheet is about 480px across), so a rule written for phones also applies on paper unless it says
 * "screen". One such rule once dropped the bill number and date under the patient's name on printed bills.
 */
describe('the rules that rearrange a screen for a phone', () => {
  // Read from the folder the tests are run in, which is the app's own.
  const css = readFileSync(resolve(process.cwd(), 'src/index.css'), 'utf8')
  const queries = [...css.matchAll(/@media ([^{]+)\{/g)].map((m) => m[1].trim())

  it('never apply to a printed page', () => {
    const onPaperToo = queries.filter((q) => {
      const narrow = /^\(max-width: (\d+)px\)$/.exec(q)
      return narrow !== null && Number(narrow[1]) <= 768
    })
    expect(onPaperToo).toEqual([])
  })

  it('are still there for screens', () => {
    expect(queries.filter((q) => /^screen and \(max-width: \d+px\)$/.test(q)).length).toBeGreaterThan(30)
  })
})
