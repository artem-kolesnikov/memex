// Size/shape stats for a markdown note. Everything here is derived client-side
// from the body — nothing is stored, so the numbers can never go stale against
// the text on screen.

/**
 * Token estimate. Deliberately the SAME formula the backend already stores as
 * note_embeddings.token_est (NoteEnricher::storeEmbedding): ceil(chars / 4),
 * OpenAI's English rule of thumb. It is an estimate, not a tokenizer count —
 * shipping a real BPE tokenizer to the browser costs ~1.5 MB for ±5% accuracy.
 * Markdown-heavy or non-Latin text runs denser than 4 chars/token, so treat
 * this as a floor for budgeting.
 */
export const TOKENS_PER_CHAR = 1 / 4
/** Average adult reading speed used for the reading-time estimate. */
const WORDS_PER_MINUTE = 200

export interface NoteStats {
  chars: number
  charsNoSpaces: number
  words: number
  lines: number
  tokens: number
  /** Whole minutes, floor 1 for any non-empty note. */
  readingMinutes: number
  headings: number
  wikiLinks: number
  codeBlocks: number
}

export function noteStats(bodyMd: string): NoteStats {
  const text = bodyMd ?? ''
  const chars = [...text].length
  const trimmed = text.trim()

  return {
    chars,
    charsNoSpaces: [...text.replace(/\s/g, '')].length,
    words: trimmed === '' ? 0 : trimmed.split(/\s+/).length,
    lines: text === '' ? 0 : text.split('\n').length,
    tokens: Math.ceil(chars * TOKENS_PER_CHAR),
    readingMinutes: trimmed === '' ? 0 : Math.max(1, Math.round(trimmed.split(/\s+/).length / WORDS_PER_MINUTE)),
    // ATX headings only (# … ######) at line start — the form the editor writes.
    headings: (text.match(/^ {0,3}#{1,6}\s+\S/gm) ?? []).length,
    wikiLinks: (text.match(/\[\[[^[\]|]+(?:\|[^[\]]*)?\]\]/g) ?? []).length,
    codeBlocks: Math.floor((text.match(/^ {0,3}```/gm) ?? []).length / 2),
  }
}

/** 4182 → "4,182" */
export function formatCount(n: number): string {
  return n.toLocaleString('en-US')
}
