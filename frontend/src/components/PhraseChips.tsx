import type { RememberedPhrase } from '../types'

type Props = { phrases: RememberedPhrase[]; text: string; onPick: (text: string) => void }

/**
 * Phrases the doctor has used before, most used first. While a line is being typed the chips
 * narrow to those that match it, and picking one completes that line.
 */
export function PhraseChips({ phrases, text, onPick }: Props) {
  const lines = text.split('\n')
  const typing = lines[lines.length - 1].trim().toLowerCase()
  const used = new Set(lines.map((line) => line.trim().toLowerCase()))
  const unused = phrases.filter((p) => !used.has(p.text.toLowerCase()))
  const matching = typing ? unused.filter((p) => p.text.toLowerCase().includes(typing)) : unused
  const completing = typing !== '' && matching.length > 0
  const options = (completing || !typing ? matching : unused).slice(0, 8)
  if (options.length === 0) return null

  return (
    <div className="chips phrase-chips">
      {options.map((p) => (
        <button key={p.id} type="button" className="chip" title={`Used ${p.uses} time${p.uses === 1 ? '' : 's'}`} onClick={() => onPick([...(completing || !typing ? lines.slice(0, -1) : lines), p.text].join('\n'))}>
          {p.text}
        </button>
      ))}
    </div>
  )
}
