#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: what the editor toolbar does to the text.
 *
 * The fourth of these scripts, and it exists because the two defects a review
 * found on 2026-08-28 were both invisible to `npm run build` and to a person
 * clicking around: pressing italic with the caret between the two stars of
 * **bold** deleted both of them and left `bold**`, and a selection dragged to
 * the start of the next line bulleted a line nobody had touched.
 *
 * The transforms are pure `EditorState → TransactionSpec`, so this runs them
 * headlessly rather than reasoning about a browser. Node 22 strips the types on
 * the way in, as it does for the popover guard.
 *
 * Usage: node --experimental-strip-types scripts/check-editor-commands.mjs
 */
import { EditorSelection, EditorState } from '@codemirror/state'
import {
  headingSpec,
  linkSpec,
  listSpec,
  tableSpec,
  wikiLinkSpec,
  wrapSpec,
} from '../src/lib/editorCommands.ts'

const failures = []

function stateOf(doc, anchor, head = anchor) {
  return EditorState.create({ doc, selection: EditorSelection.single(anchor, head) })
}

/** The document and the primary selection after applying one command. */
function after(state, spec) {
  const next = state.update(spec).state
  const { from, to } = next.selection.main
  return { doc: next.doc.toString(), selected: next.doc.sliceString(from, to), from, to }
}

function check(what, actual, expected) {
  if (actual === expected) return
  failures.push(`${what}\n     expected ${JSON.stringify(expected)}\n     got      ${JSON.stringify(actual)}`)
}

// --- Inline marks -----------------------------------------------------------
{
  const s = stateOf('one two', 0, 3)
  check('bold wraps a selection', after(s, wrapSpec(s, '**')).doc, '**one** two')
}
{
  const s = stateOf('**one** two', 2, 5)
  check('bold unwraps its own marks', after(s, wrapSpec(s, '**')).doc, 'one two')
}
{
  const s = stateOf('**one** two', 0, 7)
  check('bold unwraps a selection that includes the marks', after(s, wrapSpec(s, '**')).doc, 'one two')
}
{
  // The defect: the caret sits between the two opening stars, and an italic
  // press used to remove both of them, leaving `bold**`. A caret inserts and
  // never deletes, so the bold survives whatever else it is nested in.
  const s = stateOf('**bold**', 1)
  const r = after(s, wrapSpec(s, '*'))
  check('italic at a caret inside the bold marker deletes nothing', r.doc, '****bold**')
  check('italic at a caret leaves the caret between its own marks', r.from, 2)
}
{
  const s = stateOf('**bold**', 2, 6)
  check('italic on bold TEXT adds, never demotes', after(s, wrapSpec(s, '*')).doc, '***bold***')
}
{
  const s = stateOf('**bold**', 0, 8)
  check('italic on a whole bold SPAN adds, never demotes', after(s, wrapSpec(s, '*')).doc, '***bold***')
}
{
  const s = stateOf('*one* two', 1, 4)
  check('italic still unwraps a real italic', after(s, wrapSpec(s, '*')).doc, 'one two')
}
{
  const s = stateOf('word', 4)
  const r = after(s, wrapSpec(s, '`'))
  check('code at a caret inserts a pair', r.doc, 'word``')
  check('code at a caret leaves the caret inside', r.from, 5)
}
{
  const s = stateOf('one two', 0, 3)
  check('strikethrough wraps', after(s, wrapSpec(s, '~~')).doc, '~~one~~ two')
}

// --- Lines: headings and lists ---------------------------------------------
{
  const s = stateOf('one\ntwo\nthree', 0, 13)
  check('numbering counts the selected lines', after(s, listSpec(s, 'ordered')).doc, '1. one\n2. two\n3. three')
}
{
  // The defect: `to` is the START of line 2, which is not a line the user
  // selected.
  const s = stateOf('one\ntwo', 0, 4)
  check('a selection ending at column 0 stops at the line above', after(s, listSpec(s, 'bullet')).doc, '- one\ntwo')
  check('…and so does a heading', after(s, headingSpec(s, 2)).doc, '## one\ntwo')
}
{
  const s = stateOf('- one\n- two', 0, 11)
  check('a list toggles off', after(s, listSpec(s, 'bullet')).doc, 'one\ntwo')
}
{
  const s = stateOf('1. one\n2. two', 0, 13)
  check('one list kind converts to another', after(s, listSpec(s, 'task')).doc, '- [ ] one\n- [ ] two')
}
{
  const s = stateOf('  - one', 4)
  check('indentation survives a toggle', after(s, listSpec(s, 'bullet')).doc, '  one')
}
{
  const s = stateOf('### one', 4)
  check('a heading replaces the level it had', after(s, headingSpec(s, 1)).doc, '# one')
  check('normal text removes it', after(s, headingSpec(s, 0)).doc, 'one')
}

// --- Insertions -------------------------------------------------------------
{
  const s = stateOf('see docs', 4, 8)
  const r = after(s, linkSpec(s))
  check('a link wraps the selection', r.doc, 'see [docs](url)')
  check('…and selects the url to type over', r.selected, 'url')
}
{
  const s = stateOf('', 0)
  const r = after(s, linkSpec(s))
  check('an empty link is offered whole', r.doc, '[text](url)')
  check('…with the label selected', r.selected, 'text')
}
{
  const s = stateOf('see ', 4)
  const r = after(s, wikiLinkSpec(s))
  check('a wiki-link opens empty', r.doc, 'see [[]]')
  check('…with the caret between the brackets', r.from, 6)
}
{
  const s = stateOf('note', 0, 4)
  check('a wiki-link takes the selection as its target', after(s, wikiLinkSpec(s)).doc, '[[note]]')
}
{
  const s = stateOf('one\ntwo', 0, 7)
  const r = after(s, wikiLinkSpec(s))
  check('a multi-line selection is not a note title', r.doc, 'one\ntwo[[]]')
  check('…and the caret waits inside the empty link', r.from, 9)
}
{
  const s = stateOf('', 0)
  const r = after(s, tableSpec(s))
  check(
    'a table on an empty document needs no blank line',
    r.doc,
    '| Column | Column | Column |\n| --- | --- | --- |\n|  |  |  |\n',
  )
  check('…and offers its first heading', r.selected, 'Column')
}
{
  const s = stateOf('text', 4)
  const r = after(s, tableSpec(s))
  check(
    'a table after a line of text is separated from it',
    r.doc,
    'text\n\n| Column | Column | Column |\n| --- | --- | --- |\n|  |  |  |\n',
  )
  check('…and still offers its first heading', r.selected, 'Column')
}

if (failures.length > 0) {
  console.error('Editor command check FAILED:\n')
  for (const f of failures) console.error(`  ✗ ${f}\n`)
  process.exit(1)
}
console.log('Editor commands passed: inline marks, line prefixes and insertions.')
