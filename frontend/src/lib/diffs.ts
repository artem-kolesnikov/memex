// Diff-to-HTML helpers for the review inbox: word-level for one-liners,
// tracked changes for bodies. Both escape their inputs.
import { diffArrays, diffLines, diffWordsWithSpace } from 'diff'

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

// Every edit memex shows a person is shown as tracked changes, as a word
// processor shows them: the text as it now reads, with what the edit removed
// struck in red and what it added in green (operator, 2026-10-13). A
// Markdown paragraph is one line, so a line-by-line diff hid a five-word edit
// inside a struck and re-added paragraph. A rewrite marked word by word is
// unreadable, so a passage that keeps less than this share of its text, or
// past this many edited words, is shown removed whole and added whole.
const SHARED_TEXT = 0.4
const MAX_WORD_EDITS = 1000
const CONTEXT_LINES = 3

export interface TrackedPiece {
  kind: 'same' | 'added' | 'removed'
  text: string
}

// Words, runs of whitespace, and single punctuation marks. Words are matched
// on their own, and the whitespace between two matched words is compared
// exactly, so the pieces rebuild both texts to the character: the editor's
// accept and reject act on these offsets.
const TOKEN = /\s+|[\p{L}\p{N}\p{M}]+|[^\s\p{L}\p{N}\p{M}]/gu

function wordsChanged(removed: string, added: string): TrackedPiece[] | null {
  const before = removed.match(TOKEN) ?? []
  const after = added.match(TOKEN) ?? []
  const wordsOf = (tokens: string[]) => tokens.flatMap((token, index) => (/^\s/.test(token) ? [] : [{ token, index }]))
  const wordsBefore = wordsOf(before)
  const wordsAfter = wordsOf(after)
  const parts = diffArrays(wordsBefore.map((w) => w.token), wordsAfter.map((w) => w.token), { maxEditLength: MAX_WORD_EDITS })
  if (parts === undefined) return null

  const pieces: TrackedPiece[] = []
  let gone = ''
  let come = ''
  const same = (text: string) => {
    const last = pieces.at(-1)
    if (text === '') return
    if (last?.kind === 'same') last.text += text
    else pieces.push({ kind: 'same', text })
  }
  const keep = (text: string) => {
    // A replaced word keeps the space in front of it: only the word is struck.
    let shared = 0
    while (gone !== '' && come !== '' && gone[shared] === come[shared] && /\s/.test(gone[shared] ?? '')) shared++
    same(gone.slice(0, shared))
    gone = gone.slice(shared)
    come = come.slice(shared)
    if (gone !== '') pieces.push({ kind: 'removed', text: gone })
    if (come !== '') pieces.push({ kind: 'added', text: come })
    gone = come = ''
    same(text)
  }
  const between = (gapBefore: string, gapAfter: string) => {
    if (gapBefore === gapAfter) return keep(gapBefore)
    gone += gapBefore
    come += gapAfter
  }
  const text = (tokens: string[], from: number, to: number) => tokens.slice(from, to).join('')
  let a = 0
  let b = 0
  let wa = 0
  let wb = 0
  for (const part of parts) {
    for (let n = 0; n < part.count; n++) {
      if (part.removed) {
        const end = wordsBefore[wa++]!.index + 1
        gone += text(before, a, end)
        a = end
      } else if (part.added) {
        const end = wordsAfter[wb++]!.index + 1
        come += text(after, b, end)
        b = end
      } else {
        const ia = wordsBefore[wa++]!.index
        const ib = wordsAfter[wb++]!.index
        between(text(before, a, ia), text(after, b, ib))
        keep(before[ia]!)
        a = ia + 1
        b = ib + 1
      }
    }
  }
  between(text(before, a, before.length), text(after, b, after.length))
  keep('')

  const visible = (value: string) => value.replace(/\s/g, '').length
  if (!pieces.some((piece) => piece.kind !== 'same' && visible(piece.text) > 0)) return null
  const shared = pieces.reduce((sum, piece) => sum + (piece.kind === 'same' ? visible(piece.text) : 0), 0)
  const shorter = Math.min(visible(removed), visible(added))
  if (shorter === 0 || shared / shorter < SHARED_TEXT) return null

  return pieces
}

/** The new text in order, each piece of it kept, added, or removed from the old. */
export function trackedChanges(oldText: string, newText: string): TrackedPiece[] {
  const pieces: TrackedPiece[] = []
  // Windows line breaks are not a change a person can see.
  const lf = (text: string) => text.replace(/\r\n?/g, '\n')
  const parts = diffLines(lf(oldText), lf(newText))
  let paired = -1
  for (const [index, part] of parts.entries()) {
    if (index === paired) continue
    const next = parts[index + 1]
    if (part.removed && next?.added) {
      const ending = part.value.endsWith('\n') && next.value.endsWith('\n') ? '\n' : ''
      const words = wordsChanged(part.value.slice(0, part.value.length - ending.length), next.value.slice(0, next.value.length - ending.length))
      if (words !== null) {
        pieces.push(...words, ...(ending ? [{ kind: 'same' as const, text: ending }] : []))
        paired = index + 1
        continue
      }
    }
    pieces.push({ kind: part.added ? 'added' : part.removed ? 'removed' : 'same', text: part.value })
  }

  return pieces
}

/** Tracked changes as HTML, one block per line; long unchanged stretches collapse to a count. */
export function trackedChangesHtml(oldText: string, newText: string): string {
  // A line remembers whether its own line break was kept, added or removed:
  // an added or removed blank line, or a paragraph split or joined, has no
  // words to mark, so it is marked with a paragraph sign, as a word processor
  // shows one.
  let line: { pieces: TrackedPiece[]; end: TrackedPiece['kind'] } = { pieces: [], end: 'same' }
  const breaks = [line]
  for (const piece of trackedChanges(oldText, newText)) {
    piece.text.split('\n').forEach((text, index) => {
      if (index > 0) {
        line.end = piece.kind
        breaks.push(line = { pieces: [], end: 'same' })
      }
      if (text !== '') line.pieces.push({ kind: piece.kind, text })
    })
  }
  if (breaks.length > 1 && line.pieces.length === 0 && line.end === 'same') breaks.pop()
  const lines = breaks.map(({ pieces, end }) => end !== 'same' && !(pieces.length > 0 && pieces.every((p) => p.kind === end))
    ? [...pieces, { kind: end, text: '¶' }]
    : pieces)

  const kindOf = (line: TrackedPiece[]) => line.length > 0 && line.every((p) => p.kind === 'removed') ? ' del'
    : line.length > 0 && line.every((p) => p.kind === 'added') ? ' ins'
    : line.some((p) => p.kind !== 'same') ? ' chg' : ''
  const html = (line: TrackedPiece[]) => `<div class="mm-diff-line${kindOf(line)}">${line.map((p) => {
    const text = escapeHtml(p.text)
    return p.kind === 'added' ? `<ins>${text}</ins>` : p.kind === 'removed' ? `<del>${text}</del>` : text
  }).join('')}</div>`

  const out: string[] = []
  let run: TrackedPiece[][] = []
  const flush = (last: boolean) => {
    const head = out.length === 0 ? 0 : CONTEXT_LINES
    const tail = last ? 0 : CONTEXT_LINES
    if (run.length > head + tail + 1) {
      out.push(...run.slice(0, head).map(html))
      out.push(`<div class="mm-diff-line skip">⋯ ${run.length - head - tail} unchanged line(s)</div>`)
      out.push(...run.slice(run.length - tail).map(html))
    } else {
      out.push(...run.map(html))
    }
    run = []
  }
  for (const line of lines) {
    if (kindOf(line) === '') {
      run.push(line)
      continue
    }
    flush(false)
    out.push(html(line))
  }
  flush(true)

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
