// Diff-to-HTML helpers for the review inbox (word-level for one-liners,
// git-style line diffs for bodies). Both escape their inputs.
import { diffLines, diffWordsWithSpace } from 'diff'

export function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

// Word-level diff → ins/del markup. Used for one-liners (the title).
export function diffHtml(oldText: string, newText: string): string {
  return diffWordsWithSpace(oldText, newText)
    .map((part) => {
      const text = escapeHtml(part.value)
      if (part.added) return `<ins>${text}</ins>`
      if (part.removed) return `<del>${text}</del>`
      return text
    })
    .join('')
}

// Git-style line diff for the body: added lines are whole-line green, removed
// whole-line red; long unchanged stretches collapse to a "⋯ N unchanged lines"
// marker with a few context lines on each side.
const DIFF_CONTEXT = 3

export function lineDiffHtml(oldText: string, newText: string): string {
  const out: string[] = []
  const line = (cls: string, marker: string, text: string) =>
    `<div class="mm-diff-line${cls}">${marker} ${escapeHtml(text)}</div>`
  const parts = diffLines(oldText, newText)
  parts.forEach((part, index) => {
    const lines = part.value.split('\n')
    if (lines[lines.length - 1] === '') lines.pop()
    if (part.added) {
      for (const l of lines) out.push(line(' ins', '+', l))
    } else if (part.removed) {
      for (const l of lines) out.push(line(' del', '−', l))
    } else {
      // Context: keep the edges, collapse the middle.
      const head = index === 0 ? 0 : DIFF_CONTEXT
      const tail = index === parts.length - 1 ? 0 : DIFF_CONTEXT
      if (lines.length > head + tail + 1) {
        for (const l of lines.slice(0, head)) out.push(line('', ' ', l))
        out.push(`<div class="mm-diff-line skip">⋯ ${lines.length - head - tail} unchanged line(s)</div>`)
        if (tail > 0) for (const l of lines.slice(-tail)) out.push(line('', ' ', l))
      } else {
        for (const l of lines) out.push(line('', ' ', l))
      }
    }
  })
  return out.join('')
}

export function tagDiff(
  current: string[] | undefined,
  proposed: string[] | null,
): { added: string[]; removed: string[] } {
  const cur = new Set(current ?? [])
  const next = new Set(proposed ?? [])
  return {
    added: [...next].filter((t) => !cur.has(t)),
    removed: [...cur].filter((t) => !next.has(t)),
  }
}

/**
 * How many lines an edit added and removed — the shape of a change, without
 * opening it.
 *
 * Exists for the note history, where most rows will never carry a written
 * headline: every revision from before 2026-08-23 has none, and so does every
 * edit made in the web editor. A date on its own says nothing about whether a
 * row is worth expanding, and memex writes no prose of its own to fill the gap. A count is not prose — it is a fact about the two texts, and
 * it distinguishes a typo fix from a rewrite at a glance.
 */
export function lineDiffCounts(oldText: string, newText: string): { added: number; removed: number } {
  let added = 0
  let removed = 0
  for (const part of diffLines(oldText, newText)) {
    if (!part.added && !part.removed) continue
    const lines = part.value.split('\n')
    // A trailing newline splits into a final empty element that is not a line.
    if (lines[lines.length - 1] === '') lines.pop()
    if (part.added) added += lines.length
    else removed += lines.length
  }

  return { added, removed }
}

/**
 * What an anchored edit's body becomes, or why it cannot be applied.
 *
 * One copy, because both the review card and the evidence panel answer this
 * question and a second implementation is a second answer. The two rules are
 * the server's, in `App\Service\NotePatch::apply()`:
 *
 * - every anchor must occur exactly once, in the body as the previous
 *   operations left it;
 * - the result may not be blank, because emptying a note is a deletion and
 *   deletions are `propose_delete`, which keeps the note restorable. Blank
 *   means what PHP's `trim()` means and not what JavaScript's does: PHP strips
 *   exactly " \t\n\r\0\x0B", while `String.prototype.trim` also strips every
 *   Unicode space. A body of one non-breaking space is blank to JS and not to
 *   the server, so on `trim()` this card would refuse an edit the server
 *   accepts — and say so in words.
 *
 * Stated here rather than left to the server's refusal so the operator reads
 * it before the click, not after.
 */
export type PatchResult =
  | { ok: true; body: string }
  | { ok: false; reason: 'stale' | 'empties' }

export function resolvePatch(
  currentBody: string,
  operations: { find: string; replace: string }[] | null,
): PatchResult {
  let body = currentBody
  for (const { find, replace } of operations ?? []) {
    if (body.split(find).length - 1 !== 1) return { ok: false, reason: 'stale' }
    // A function replacement, because `$&` and friends in a replacement string
    // are substitution patterns to String.replace and would corrupt the text.
    body = body.replace(find, () => replace)
  }
  if (body.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '') === '') {
    return { ok: false, reason: 'empties' }
  }
  return { ok: true, body }
}
