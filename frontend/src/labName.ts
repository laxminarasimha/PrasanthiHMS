/** "1st", "2nd", "3rd", "4th"… */
export function nth(n: number): string {
  const tens = n % 100
  const th = tens >= 11 && tens <= 13 ? 'th' : (['th', 'st', 'nd', 'rd'][n % 10] ?? 'th')
  return `${n}${th}`
}

/**
 * A lab test's name as the lab reads it. When the same test is on one order more than once (a CBP
 * repeated the same day) each is numbered, so the samples and reports are told apart: "CBP (2nd)".
 */
export function testLabel(item: { test_name: string; repeat_no?: number | null }): string {
  return item.repeat_no ? `${item.test_name} (${nth(item.repeat_no)})` : item.test_name
}
