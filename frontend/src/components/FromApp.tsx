/**
 * The mark on a visit the patient booked themself on the patient app: "App", with the words in full on hover. It is
 * only a mark; nothing opens from it. `block` puts it on a line of its own, under a time.
 */
export function FromApp({ via, block }: { via: string | null | undefined; block?: boolean }) {
  if (via !== 'app') return null
  return (
    <span className={block ? 'app-tag block' : 'app-tag'} title="Booked from the patient app">
      App
    </span>
  )
}
