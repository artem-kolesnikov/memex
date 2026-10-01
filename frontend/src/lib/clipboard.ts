/**
 * Copy, and say whether it happened. `writeText` rejects when the clipboard
 * is denied, and a checkmark drawn before the promise settles is a lie.
 */
export async function copyText(value: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(value)
    return true
  } catch {
    return false
  }
}
