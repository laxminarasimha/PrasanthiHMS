/// <reference types="node" />
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

// Staff phones are not all up to date. The build writes the app's own shorthand out the long way for older Chrome
// (vite.config.ts), but it cannot add a browser feature that is missing. So the app's code keeps to what Chrome 73
// (2019) already had. Each entry: a pattern, and what to write instead.
const NEWER: [RegExp, string][] = [
  [/\.at\(/, '.at() needs Chrome 92: use list[list.length - 1] or .slice(-1)[0]'],
  [/\.findLast(Index)?\(/, '.findLast() needs Chrome 97: use [...list].reverse().find()'],
  [/\.to(Sorted|Reversed|Spliced)\(/, '.toSorted() and friends need Chrome 110: copy, then sort'],
  [/\bObject\.hasOwn\b/, 'Object.hasOwn needs Chrome 93: use Object.prototype.hasOwnProperty.call'],
  [/\bstructuredClone\b/, 'structuredClone needs Chrome 98'],
  [/\.replaceAll\(/, '.replaceAll() needs Chrome 85: use .replace() with a /g pattern'],
  [/\b(Object|Map)\.groupBy\b/, 'groupBy needs Chrome 117'],
  [/\bPromise\.(any|withResolvers)\b/, 'Promise.any needs Chrome 85, withResolvers 119'],
  [/\bAbortSignal\.timeout\b/, 'AbortSignal.timeout needs Chrome 103'],
  [/\bcrypto\.randomUUID\b/, 'crypto.randomUUID needs Chrome 92'],
  [/\bURL\.canParse\b/, 'URL.canParse needs Chrome 120'],
  [/\bArray\.fromAsync\b/, 'Array.fromAsync needs Chrome 121'],
]

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return files(path)
    return /\.(ts|tsx)$/.test(name) && !/\.test\.(ts|tsx)$/.test(name) ? [path] : []
  })
}

describe('the app opens on older phones', () => {
  it('is built for older Chrome, not only for browsers from 2023 onwards', () => {
    const config = readFileSync(join(__dirname, '..', 'vite.config.ts'), 'utf8')
    expect(config).toMatch(/target:\s*\[[^\]]*'chrome64'/)
  })

  it('uses no browser feature newer than Chrome 73 in its own code', () => {
    const found = files(join(__dirname)).flatMap((path) => {
      const text = readFileSync(path, 'utf8')
      return NEWER.filter(([pattern]) => pattern.test(text)).map(([, why]) => `${path.replace(__dirname, 'src')}: ${why}`)
    })
    expect(found).toEqual([])
  })

  it('checks for a failed start in a script every phone can read', () => {
    const page = readFileSync(join(__dirname, '..', 'index.html'), 'utf8')
    const check = readFileSync(join(__dirname, '..', 'public', 'boot-check.js'), 'utf8')
    // A classic script, before the app, with nothing a 2015 browser could not read.
    expect(page).toMatch(/<script src="\/boot-check\.js"><\/script>[\s\S]*<script type="module"/)
    expect(check).not.toMatch(/=>|\bconst\b|\blet\b|`|\?\.|\?\?/)
  })
})
