// "You mention a note you already have — link it?" Pure string work against the
// title vocabulary: no AI, no request, no cost. Runs as part of Analyze so the
// graph gets built at capture time instead of by a later curation pass.

export interface LinkSuggestion {
  /** The existing note this would link to. */
  id: number
  /** The existing note's title. */
  title: string
  /** The text as it appears in the body (may differ in case). */
  matched: string
  /** Offset of `matched` in the body. */
  index: number
  /** How many linkable times the title appears — one passing mention reads
   *  very differently from six, and only the first gets linked. */
  occurrences: number
  /** Prose immediately before/after the first hit, so the mention can be judged
   *  in context instead of as a bare word. Ellipsis included when clipped. */
  before: string
  after: string
}

/** A note the draft could link to. */
export interface LinkCandidate {
  id: number
  title: string
}

/** Characters of surrounding prose shown on each side of a mention. */
const CONTEXT_CHARS = 70

/** Titles shorter than this match too much prose to be worth offering. */
const MIN_TITLE_LENGTH = 4
const MAX_SUGGESTIONS = 10

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Spans where a match must NOT be linkified: code (a title inside a command is
 * not a reference), existing links of any kind, and bare URLs.
 */
function protectedRanges(body: string): [number, number][] {
  const ranges: [number, number][] = []
  const patterns = [
    /^[ \t]{0,3}```[\s\S]*?(?:^[ \t]{0,3}```|$)/gm, // fenced code
    /`[^`\n]*`/g, // inline code
    /\[\[[^[\]]*\]\]/g, // existing wiki-links
    /!?\[[^\]\n]*\]\([^)\n]*\)/g, // markdown links/images
    /\bhttps?:\/\/\S+/g, // bare URLs
  ]
  for (const pattern of patterns) {
    for (const match of body.matchAll(pattern)) {
      const start = match.index ?? 0
      ranges.push([start, start + match[0].length])
    }
  }
  return ranges
}

function isProtected(ranges: [number, number][], from: number, to: number): boolean {
  return ranges.some(([start, end]) => from < end && to > start)
}

/** Every linkable occurrence of `title` in `body`, in document order. */
function occurrencesOf(
  body: string,
  title: string,
  ranges: [number, number][],
): { index: number; matched: string }[] {
  // Whole-word match: "Rust" must not fire inside "Rusty", and the boundary
  // has to allow titles that end in punctuation ("memex.tools").
  const pattern = new RegExp(`(?<![\\w-])${escapeRegExp(title)}(?![\\w-])`, 'gi')
  const hits: { index: number; matched: string }[] = []
  for (const match of body.matchAll(pattern)) {
    const index = match.index ?? 0
    if (!isProtected(ranges, index, index + match[0].length)) {
      hits.push({ index, matched: match[0] })
    }
  }
  return hits
}

/** First linkable occurrence of `title` in `body`, or null. */
function firstOccurrence(
  body: string,
  title: string,
  ranges: [number, number][],
): { index: number; matched: string } | null {
  return occurrencesOf(body, title, ranges)[0] ?? null
}

/**
 * Prose either side of a hit, clipped to whole words so the snippet doesn't
 * start mid-syllable, with markdown noise flattened to keep one line readable.
 */
function contextAround(body: string, index: number, length: number): { before: string; after: string } {
  const flatten = (s: string) => s.replace(/\s+/g, ' ').replace(/[#*_>`]/g, '')

  let before = flatten(body.slice(Math.max(0, index - CONTEXT_CHARS), index))
  let after = flatten(body.slice(index + length, index + length + CONTEXT_CHARS))

  if (index > CONTEXT_CHARS) {
    before = '…' + before.replace(/^\S*\s/, '')
  }
  if (index + length + CONTEXT_CHARS < body.length) {
    after = after.replace(/\s\S*$/, '') + '…'
  }
  return { before, after }
}

/**
 * @param candidates every note in the team's vault (id + title)
 * @param ownTitle the draft's own title — a note never links to itself
 */
export function suggestWikiLinks(
  body: string,
  candidates: LinkCandidate[],
  ownTitle = '',
): LinkSuggestion[] {
  if (body.trim() === '') return []
  const ranges = protectedRanges(body)
  const own = ownTitle.trim().toLowerCase()

  const suggestions: LinkSuggestion[] = []
  for (const { id, title } of candidates) {
    if (title.length < MIN_TITLE_LENGTH || title.trim().toLowerCase() === own) continue
    // Already linked somewhere? Then this is not a missing link.
    if (new RegExp(`\\[\\[\\s*${escapeRegExp(title)}\\s*(\\||\\]\\])`, 'i').test(body)) continue
    const hits = occurrencesOf(body, title, ranges)
    const hit = hits[0]
    if (hit) {
      suggestions.push({
        id,
        title,
        matched: hit.matched,
        index: hit.index,
        occurrences: hits.length,
        ...contextAround(body, hit.index, hit.matched.length),
      })
    }
  }

  // Longest title first at the same spot (prefer "memex.tools deploy" over
  // "memex.tools"), then document order.
  suggestions.sort((a, b) => a.index - b.index || b.title.length - a.title.length)
  return suggestions.slice(0, MAX_SUGGESTIONS)
}

/**
 * Wraps the first linkable occurrence of `title` in [[…]], preserving the
 * casing found in the prose (link resolution is case-insensitive server-side).
 * Returns the body unchanged when there is nothing left to link.
 */
export function linkifyTitle(body: string, title: string): string {
  const hit = firstOccurrence(body, title, protectedRanges(body))
  if (!hit) return body

  return body.slice(0, hit.index) + `[[${hit.matched}]]` + body.slice(hit.index + hit.matched.length)
}
