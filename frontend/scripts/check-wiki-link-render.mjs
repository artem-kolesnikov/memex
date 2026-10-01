#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: a wiki-link the server resolved renders as a link.
 *
 * The target is stored and matched exactly, newline and all — the server
 * repairs a line wrap where it resolves. So the renderer must key its lookup
 * on the same exact text, and must NOT leave that text inside the markdown it
 * emits: a target wrapped onto a line beginning `- ` becomes a list item and
 * the anchor disappears, which no type-check and no reading of the note body
 * would catch.
 *
 * Usage: node --experimental-strip-types scripts/check-wiki-link-render.mjs
 */
import { renderNote } from '../src/lib/markdown.ts'

const failures = []

function check(name, body, links, expect, expectedAnchors, handle) {
  const html = renderNote(body, links, handle)
  const anchors = html.match(/<a\b[^>]*>[\s\S]*?<\/a>/g) ?? []
  if (!expect(html) || anchors.length !== expectedAnchors.length
    || anchors.some((anchor, index) => anchor !== expectedAnchors[index])) {
    failures.push(`${name}\n    got: ${html.trim().replace(/\n/g, '\\n')}`)
  }
}

const RESOLVED = /<a class="wiki-link" href="\/notes\/41"/

check(
  'a target broken over a line wrap is a live link',
  'See [[The operations\n  runbook]] first.',
  [{ target: 'The operations\n  runbook', note_id: 41 }],
  (html) => RESOLVED.test(html),
  ['<a class="wiki-link" href="/notes/41">The operations runbook</a>'],
)

check(
  'a wrap onto a line that starts a list still renders one anchor',
  'See [[Budget\n- 2026]] first.',
  [{ target: 'Budget\n- 2026', note_id: 41 }],
  (html) => RESOLVED.test(html) && !html.includes('<li>'),
  ['<a class="wiki-link" href="/notes/41">Budget - 2026</a>'],
)

check(
  'a wrap across a blank line does not split the paragraph',
  'See [[Damp\n\nsurvey]] first.',
  [{ target: 'Damp\n\nsurvey', note_id: 41 }],
  (html) => RESOLVED.test(html),
  ['<a class="wiki-link" href="/notes/41">Damp survey</a>'],
)

check(
  'the collapsed text is what the reader sees',
  'See [[The operations\n  runbook]].',
  [{ target: 'The operations\n  runbook', note_id: 41 }],
  (html) => html.includes('>The operations runbook</a>'),
  ['<a class="wiki-link" href="/notes/41">The operations runbook</a>'],
)

check(
  'a link carries the knowledge base when the reader has one',
  'See [[The operations runbook]].',
  [{ target: 'The operations runbook', note_id: 41 }],
  (html) => html.includes('href="/teal9parrot4/notes/41"'),
  ['<a class="wiki-link" href="/teal9parrot4/notes/41">The operations runbook</a>'],
  'teal9parrot4',
)

check(
  'an unresolved target is still a dotted anchor, not a link',
  'See [[Nothing here]].',
  [{ target: 'Nothing here', note_id: null }],
  (html) => html.includes('class="wiki-link unresolved"') && !html.includes('href="/notes/'),
  ['<a class="wiki-link unresolved">Nothing here</a>'],
)

check(
  'two targets differing only in whitespace keep their own destinations',
  'See [[Damp  survey]] and [[Damp survey]].',
  [
    { target: 'Damp  survey', note_id: 41 },
    { target: 'Damp survey', note_id: 42 },
  ],
  (html) => RESOLVED.test(html) && html.includes('href="/notes/42"'),
  [
    '<a class="wiki-link" href="/notes/41">Damp survey</a>',
    '<a class="wiki-link" href="/notes/42">Damp survey</a>',
  ],
)

if (failures.length > 0) {
  console.error(`Wiki-link rendering check FAILED (${failures.length}):`)
  for (const f of failures) console.error(`  ✗ ${f}`)
  process.exit(1)
}

console.log('Wiki-link rendering check passed: wrapped targets resolve, render once, and keep distinct destinations.')
