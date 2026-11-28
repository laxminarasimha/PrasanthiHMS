/** The prescription card a medicine on the bill was added from: its kind and id, when it was written, and whose it is. */
export type CardTag = { kind: 'visit' | 'ward' | 'discharge'; id: number; at: string; patientId: number }

/**
 * A prescribed number of tablets taken from a medicine's batches: the batch that expires first, then the next, never
 * more than is in stock. The batches come soonest expiry first, as the counter is given them.
 */
export function fillFromBatches<B extends { id: number; units_in_stock: number }>(batches: B[], wanted: number): { batch: B; quantity: number }[] {
  const taken: { batch: B; quantity: number }[] = []
  let left = wanted
  for (const batch of batches) {
    if (left <= 0) break
    const quantity = Math.min(left, batch.units_in_stock)
    if (quantity > 0) taken.push({ batch, quantity })
    left -= quantity
  }
  return taken
}

/** What the counter says when there is less in stock than the prescription comes to. */
export const shortOf = (prescribed: number, inStock: number) => `prescribed ${prescribed}, ${inStock} in stock`

/**
 * The visit a bill is made from: the newest visit card that any medicine on it came from, for this patient. Ward and
 * discharge cards are never one, and nor is another patient's card.
 */
export function visitOf(tags: (CardTag | undefined)[], patientId: number): number | null {
  const visits = tags.filter((t): t is CardTag => t !== undefined && t.kind === 'visit' && t.patientId === patientId)
  if (visits.length === 0) return null
  return visits.reduce((newest, t) => (t.at > newest.at ? t : newest)).id
}
