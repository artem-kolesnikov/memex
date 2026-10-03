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

/**
 * Copy text the server has yet to send. Safari lets a click write to the
 * clipboard only while it is still handling that click, so the write starts
 * now with the text promised; a browser without promised items gets it after.
 */
export async function copyFetched(fetchText: () => Promise<string>): Promise<boolean> {
  const text = fetchText()
  if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
    try {
      await navigator.clipboard.write([
        new ClipboardItem({ 'text/plain': text.then((value) => new Blob([value], { type: 'text/plain' })) }),
      ])
      return true
    } catch {
      /* the text is still copied below, or its own failure is thrown there */
    }
  }
  return copyText(await text)
}
