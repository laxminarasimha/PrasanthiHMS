import { useEffect, useRef, useState } from 'react'
import { api } from '../api'

export type IcdCode = { code: string; name: string }

/** Coded diagnoses for a visit: search the ICD-10 list by code or words and add what fits. */
export function IcdPicker({ value, onChange, disabled }: { value: IcdCode[]; onChange: (codes: IcdCode[]) => void; disabled?: boolean }) {
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ query: string; codes: IcdCode[] } | null>(null)
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const term = query.trim()

  useEffect(() => {
    if (term.length < 2) return
    let current = true
    const timer = setTimeout(() => {
      api.get<IcdCode[]>('/icd-codes', { params: { q: term } }).then((res) => current && setFound({ query: term, codes: res.data }))
    }, 250)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [term])

  useEffect(() => {
    const close = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const matches = term.length >= 2 && found?.query === term ? found.codes.filter((c) => !value.some((v) => v.code === c.code)) : null
  const add = (code: IcdCode) => {
    onChange([...value, code])
    setQuery('')
    setOpen(false)
  }

  return (
    <div className="icd-picker" ref={box}>
      {value.length > 0 && (
        <ul className="icd-chosen">
          {value.map((c) => (
            <li key={c.code}>
              <b>{c.code}</b> {c.name}
              {!disabled && (
                <button type="button" className="link danger" onClick={() => onChange(value.filter((v) => v.code !== c.code))} aria-label={`Remove ${c.code}`}>
                  ×
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {!disabled && (
        <input
          type="search"
          placeholder="Add an ICD-10 code: type a code (E11) or words (diabetes)"
          value={query}
          onFocus={() => setOpen(true)}
          onChange={(e) => {
            setQuery(e.target.value)
            setOpen(true)
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              if (matches?.length) add(matches[0])
            }
            if (e.key === 'Escape') setOpen(false)
          }}
          aria-label="Add an ICD-10 diagnosis code"
        />
      )}
      {open && matches && (
        <ul className="picker">
          {matches.map((c) => (
            <li key={c.code}>
              <button type="button" onClick={() => add(c)}>
                <strong>{c.code}</strong>
                <small>{c.name}</small>
              </button>
            </li>
          ))}
          {matches.length === 0 && <li className="none">No diagnosis matches. Try fewer or different words.</li>}
        </ul>
      )}
    </div>
  )
}
