/** Why a discount is given and who allowed it, as typed at the desk. */
export type Why = { reason: string; by: string }
export const noWhy: Why = { reason: '', by: '' }
/** What the server expects for a discount's reason and the person who allowed it. */
export const whyFields = (why: Why) => ({ discount_reason: why.reason.trim() || null, discount_allowed_by: why.by.trim() || null })
