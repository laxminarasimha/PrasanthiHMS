/** The forms a medicine comes in, offered when one is added or changed. */
export const MEDICINE_TYPES = ['Tablet', 'Capsule', 'Chewable Tablet', 'Dispersible Tablet', 'Syrup', 'Suspension', 'Solution', 'Injection', 'IV fluid', 'Cream / ointment', 'Ointment', 'Cream', 'Gel', 'Lotion', 'Drops', 'Powder', 'Sachet', 'Inhaler / spray', 'Inhaler', 'Spray', 'Suppository', 'Surgical', 'Other'].map((t) => ({ value: t, label: t }))
export const MEDICINE_CATEGORIES = ['General', 'Scheduled Drug H', 'Scheduled Drug H1', 'Scheduled Drug X', 'Fast Moving', 'Emergency Medicine', 'High Risk Medicine', 'Surgical and consumables']

/** The value a medicine list's last choice carries: "add a new medicine" rather than a medicine. */
export const NEW_MEDICINE = '__new__'
