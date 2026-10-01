import MarkdownIt from 'markdown-it'

const md = new MarkdownIt({ linkify: true, html: false })

/**
 * Renders note markdown. [[wiki-links]] become in-app links using the
 * link-resolution data the API returns with the note; unresolved targets render
 * as dotted no-href anchors.
 *
 * The TEXT of a link has its whitespace collapsed; the lookup key does not. A
 * target broken over a line wrap is stored and matched exactly (the server
 * repairs the wrap where it resolves), but left in the link text the newline
 * and the next line's indentation are markdown in their own right — `[[Budget
 * - 2026]]` renders as a list item and no anchor at all. Collapsing only the
 * text keeps the two sides identical where it matters and legible where it
 * does not.
 *
 * Both go through a placeholder URL scheme so the
 * markup survives markdown-it's escaping (html: false — the only HTML is ours,
 * produced by the post-render rewrites).
 */
const linkText = (t: string) => t.replace(/[ \t\r\n]+/g, ' ').trim()

export function renderNote(
  bodyMd: string,
  links: { target: string; note_id: number | null }[],
  /**
   * The reader's own knowledge base, so a wiki-link renders as the address a
   * note actually has. These anchors are raw HTML rather than RouterLink, so
   * the router's own handle-filling never sees them — and a body is the thing
   * most likely to be copied out of memex and pasted somewhere else.
   *
   * Omitted (or null mid-migration) falls back to the handle-less form, which
   * the router rewrites on click but which names no knowledge base.
   */
  handle?: string | null,
): string {
  const byTarget = new Map(links.map((l) => [l.target.toLowerCase(), l.note_id]))
  const withLinks = bodyMd.replace(
    /\[\[([^[\]|]+)(?:\|([^[\]]*))?\]\]/g,
    (_match, target: string, label?: string) => {
      const text = (linkText(label ?? '') || linkText(target)).replace(/([\\`*_[\]])/g, '\\$1')
      const noteId = byTarget.get(target.trim().toLowerCase())
      return `[${text}](wikilink:${noteId ?? 'x'})`
    },
  )

  return md
    .render(withLinks)
    .replaceAll('href="wikilink:x"', 'class="wiki-link unresolved"')
    .replaceAll('href="wikilink:', `class="wiki-link" href="${handle ? `/${handle}` : ''}/notes/`)
}

const reason = new MarkdownIt({ linkify: true, html: false })
reason.disable(['image'])
// A heading an agent wrote is not a heading in a feed card: it renders as bold
// text rather than at display size.
reason.renderer.rules.heading_open = () => '<p><strong>'
reason.renderer.rules.heading_close = () => '</strong></p>'

/**
 * Renders a proposal's comment — the reason the review inbox shows. Block
 * markdown, because the shape asked of an agent is one short sentence per
 * change and a bullet per change where there is more than one; flattening
 * those to a line is what made the essays unreadable.
 *
 * `block` says whether the result is more than a single paragraph, which is
 * what decides whether the card can keep the label and the reason on one line:
 * one paragraph comes back unwrapped, as inline content.
 */
export function renderReason(text: string): { html: string; block: boolean } {
  const rendered = reason.render(text).trim()
  const inner = /^<p>([\s\S]*)<\/p>$/.exec(rendered)?.[1]
  const inlineOnly = inner !== undefined && !inner.includes('<p>')
  const html = (inlineOnly ? inner : rendered).replaceAll(
    '<a href="',
    '<a target="_blank" rel="noopener noreferrer" href="',
  )

  return { html, block: !inlineOnly }
}

/**
 * Renders a journal row's description. Full block markdown, because an agent
 * writing a run-summary writes lists and emphasis and the journal used to show
 * the asterisks. No wiki-links: a log row names notes through its own list of
 * affected notes, which carries real ids rather than titles that may since
 * have changed.
 */
export function renderLogEntry(text: string): string {
  return md
    .render(text)
    .replaceAll('<a href="', '<a target="_blank" rel="noopener noreferrer" href="')
}
