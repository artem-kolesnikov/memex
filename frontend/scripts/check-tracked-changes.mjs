#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: every edit is shown to a person as tracked changes.
 *
 * A Markdown paragraph is one line. With a line-by-line diff, an assistant's
 * edit that adds a sentence to a long paragraph, or corrects one fact in it,
 * showed the whole paragraph struck and the whole paragraph added, and the
 * operator could not find what had changed (2026-10-13). The docs promise
 * tracked changes, as in a word processor, so this also fails if any other
 * diff renderer appears in the app. trackedChangesHtml is pure, so this runs
 * it with no browser.
 *
 * Usage: node --experimental-strip-types scripts/check-tracked-changes.mjs
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { register } from 'node:module'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { trackedChanges, trackedChangesHtml } from '../src/lib/diffs.ts'

// The editor's module imports '@/lib/diffs' as the app does; resolve it the way Vite would.
const srcUrl = new URL('../src/', import.meta.url).href
register(`data:text/javascript,${encodeURIComponent(`
export async function resolve(specifier, context, next) {
  return next(specifier.startsWith('@/') ? ${JSON.stringify(srcUrl)} + specifier.slice(2) + '.ts' : specifier, context)
}`)}`)
const { EditorSelection, EditorState } = await import('@codemirror/state')
const { showTooltip } = await import('@codemirror/view')
const { acceptSpec, changeAt, rejectSpec, trackChanges, trackedChangesIn } = await import('../src/lib/trackChanges.ts')

const failures = []
const check = (name, ok, html) => { if (!ok) failures.push(`${name}\n    ${html}`) }
const lines = (html) => html.match(/<div class="mm-diff-line[^"]*">/g) ?? []
const count = (html, cls) => lines(html).filter((tag) => tag === `<div class="mm-diff-line ${cls}">`).length

const paragraph = '- **2026-03-02, interface:** the team chose the compact layout over the spacious one, with a cool canvas, '
  + 'dense rows and one accent colour for both the editor and the reader; the reader moves to it in its own later stage. '
  + 'The concept, tokens and screen atlas are in the design folder.'
const note = `# Garden club\n\n## Decided\n\n${paragraph}\n- Meetings are on the first Tuesday.\n\n## Open\n\n- Where the spring sale is held.\n`

{
  const added = ' The implementation keeps the palette and spacing in one tokens file.'
  const html = trackedChangesHtml(note, note.replace(paragraph, paragraph + added))
  check('a sentence added to a paragraph is one changed line', count(html, 'chg') === 1 && count(html, 'ins') === 0 && count(html, 'del') === 0, html)
  check('only the added sentence is marked', html.includes(`<ins>${added}</ins>`) && !html.includes('<del>'), html)
  check('the paragraph it was added to is shown as it was', html.includes('the team chose the compact layout over the spacious one'), html)
}

{
  const html = trackedChangesHtml(note, note.replace('first Tuesday', 'first Thursday'))
  check('a word replaced in a line marks that word alone', html.includes('<del>Tuesday</del><ins>Thursday</ins>'), html)
  check('the rest of that line is plain', html.includes('<div class="mm-diff-line chg">- Meetings are on the first <del>'), html)
}

{
  const html = trackedChangesHtml(
    '- [ ] Venue for the sale. The hall is booked that weekend; looking at the library across the square\n',
    '- [x] Venue for the sale: the reading room at the library across the square, free on Saturdays\n',
  )
  check('a line half rewritten keeps the words it kept', count(html, 'chg') === 1 && html.includes('the library across the square<ins>, free on Saturdays</ins>'), html)
}

{
  const html = trackedChangesHtml('- Who keeps the seed library.\n', '  - Who keeps the seed library.\n')
  check('a line whose only change is spacing is shown old and new', count(html, 'del') === 1 && count(html, 'ins') === 1, html)
}

{
  const before = 'Alpha one\nBravo two\n'
  const html = trackedChangesHtml(`${before}Charlie\n`, 'Alpha uno\nBravo dos\nCharlie\n')
  check('consecutive changed lines stay separate lines', count(html, 'chg') === 2, html)
}

{
  const html = trackedChangesHtml(note, note.replace(paragraph, '- Nothing is decided about the layout yet; ask Morgan before choosing.'))
  check('a rewritten line is shown whole, old and new', count(html, 'del') === 1 && count(html, 'ins') === 1 && count(html, 'chg') === 0, html)
  check('a rewritten line is struck and its replacement marked', html.includes('<div class="mm-diff-line del"><del>- **2026-03-02') && html.includes('<div class="mm-diff-line ins"><ins>- Nothing is decided'), html)
}

{
  const html = trackedChangesHtml(note, `${note}- Who keeps the seed library.\n`)
  check('an added line is still an added line', count(html, 'ins') === 1 && count(html, 'chg') === 0, html)
}

{
  const long = Array.from({ length: 20 }, (_, i) => `Line ${i} of the record stays as it was.`).join('\n')
  const html = trackedChangesHtml(`${long}\n`, `${long.replace('Line 10 of', 'Line ten of')}\n`)
  check('unchanged stretches around a changed line still collapse', (html.match(/unchanged line/g) ?? []).length === 2 && count(html, 'chg') === 1, html)
}

{
  const html = trackedChangesHtml('Plan <b>one</b> & two\n', 'Plan <b>one</b> & three\n')
  check('a changed line is escaped', html.includes('&lt;b&gt;one&lt;/b&gt; &amp; <del>two</del><ins>three</ins>'), html)
}

{
  // The editor's accept and reject act on these pieces' offsets, so they must
  // rebuild both texts to the character, whitespace included.
  const pairs = [
    ['Hello world.', 'Hello world. More information.'],
    ['Hello  world.\n', 'Hello world, again.\n'],
    ['- Who keeps the seed library.\n', '  - Who keeps the seed library.\n'],
    [note, note.replace(paragraph, paragraph + ' Then a sentence.').replace('first Tuesday', 'first  Thursday')],
    ['First paragraph.\nSecond paragraph.\n', 'First paragraph.\n\nSecond paragraph.\n'],
    ['Line one\tends here.\n', 'Line one ends\there, now.\n'],
  ]
  for (const [before, after] of pairs) {
    const pieces = trackedChanges(before, after)
    const old = pieces.filter((p) => p.kind !== 'added').map((p) => p.text).join('')
    const next = pieces.filter((p) => p.kind !== 'removed').map((p) => p.text).join('')
    check(`the pieces rebuild both texts exactly: ${JSON.stringify(after.slice(0, 30))}`, old === before && next === after, JSON.stringify(pieces))
  }
}

{
  const html = trackedChangesHtml('Hello world.\r\nSecond line.\r\n', 'Hello everyone.\nSecond line.\n')
  check('a note saved with Windows line breaks shows only the word that changed', count(html, 'chg') === 1 && html.includes('<del>world</del><ins>everyone</ins>') && count(html, 'del') === 0, html)
}

{
  const html = trackedChangesHtml('First paragraph.\nSecond paragraph.\n', 'First paragraph.\n\nSecond paragraph.\n')
  check('an added blank line is marked, not counted as unchanged', count(html, 'ins') === 1 && html.includes('<ins>¶</ins>') && !html.includes('unchanged'), html)
  const joined = trackedChangesHtml('First paragraph.\n\nSecond paragraph.\n', 'First paragraph.\nSecond paragraph.\n')
  check('a removed blank line is marked', count(joined, 'del') === 1 && joined.includes('<del>¶</del>'), joined)
}

{
  const html = trackedChangesHtml(note, note.replace('first Tuesday', 'first Thursday').replace('- Where the spring sale is held.\n', ''))
  check('no line carries a line-diff marker', !/<div class="mm-diff-line[^"]*">[+−~] /.test(html), html)
}

{
  const added = ' The implementation keeps the palette and spacing in one tokens file.'
  const proposed = note.replace(paragraph, paragraph + added).replace('first Tuesday', 'first Thursday')
  const labels = { accept: 'Accept', reject: 'Reject' }
  const editor = (doc, cursor = 0) => EditorState.create({ doc, selection: EditorSelection.cursor(cursor), extensions: trackChanges(note, labels) })
  const state = editor(proposed)
  const changes = trackedChangesIn(state)
  const sentence = changes.find((change) => state.sliceDoc(change.from, change.to).trim() === added.trim())
  const day = changes.find((change) => change.removed === 'Tuesday')
  check('the editor marks the same changes the cards show', changes.length === 2 && sentence !== undefined && sentence.removed === '' && day !== undefined && state.sliceDoc(day.from, day.to) === 'Thursday', JSON.stringify(changes))

  const tooltips = (s) => s.facet(showTooltip).filter(Boolean).length
  check('accept and reject are offered where the cursor is in a change', tooltips(editor(proposed, day.from + 2)) === 1 && tooltips(editor(proposed, 0)) === 0, '')
  check('a change found from the cursor is that change', changeAt(state, day.from + 1)?.removed === 'Tuesday', '')

  const rejected = state.update(rejectSpec(state, day)).state
  check('rejecting a change puts back the text it removed', rejected.doc.toString() === note.replace(paragraph, paragraph + added) && trackedChangesIn(rejected).length === 1, rejected.doc.toString())

  const accepted = state.update(acceptSpec(state, day)).state
  check('accepting a change keeps the text and stops marking it', accepted.doc.toString() === proposed && trackedChangesIn(accepted).length === 1 && changeAt(accepted, day.from + 1) === null, JSON.stringify(trackedChangesIn(accepted)))

  const typed = accepted.update({ changes: { from: 0, insert: 'New opening. ' } }).state
  check('text typed while amending is marked as it is typed', trackedChangesIn(typed).length === 2 && typed.sliceDoc(trackedChangesIn(typed)[0].from, trackedChangesIn(typed)[0].to).includes('New opening'), JSON.stringify(trackedChangesIn(typed)))

  const appended = EditorState.create({ doc: 'Hello world. More information.', extensions: trackChanges('Hello world.', labels) })
  const [tail] = trackedChangesIn(appended)
  const afterAccept = appended.update(acceptSpec(appended, tail)).state
  check('accepting an appended sentence leaves nothing marked', trackedChangesIn(afterAccept).length === 0, JSON.stringify(trackedChangesIn(afterAccept)))
  const afterReject = appended.update(rejectSpec(appended, tail)).state
  check('rejecting an appended sentence restores the paragraph exactly', afterReject.doc.toString() === 'Hello world.' && trackedChangesIn(afterReject).length === 0, afterReject.doc.toString())

  const windows = EditorState.create({ doc: 'Hello everyone.\r\nSecond line.\r\n', extensions: trackChanges('Hello world.\r\nSecond line.\r\n', labels) })
  const [word] = trackedChangesIn(windows)
  check('a note saved with Windows line breaks marks only the changed word', trackedChangesIn(windows).length === 1 && word?.removed === 'world', JSON.stringify(trackedChangesIn(windows)))
  const windowsRejected = windows.update(rejectSpec(windows, word)).state
  check('rejecting in such a note restores it with nothing left marked', windowsRejected.doc.toString() === 'Hello world.\nSecond line.\n' && trackedChangesIn(windowsRejected).length === 0, JSON.stringify(trackedChangesIn(windowsRejected)))

  const readOnly = EditorState.create({ doc: proposed, selection: EditorSelection.cursor(day.from + 2), extensions: [trackChanges(note, labels), EditorState.readOnly.of(true)] })
  check('a locked editor offers no accept or reject', tooltips(readOnly) === 0, '')
}

{
  // A card of several authors' changes labels each changed line with whose it is.
  const { changedRanges } = await import('../src/lib/diffs.ts')
  const designer = note.replace('first Tuesday', 'first Thursday')
  const orchestrator = note.replace('- Where the spring sale is held.', '- Where the spring sale is held: the library.')
  const both = designer.replace('- Where the spring sale is held.', '- Where the spring sale is held: the library.')
  const authors = [
    ...changedRanges(note, designer).map((range) => ({ ...range, name: 'Designer' })),
    ...changedRanges(note, orchestrator).map((range) => ({ ...range, name: 'Orchestrator' })),
  ]
  const html = trackedChangesHtml(note, both, authors)
  check('each changed line is labelled with its author', html.includes('<span class="mm-diff-by">Designer</span>'), html)
  check('the other author labels the other line', html.includes('<span class="mm-diff-by">Orchestrator</span>') && (html.match(/mm-diff-by/g) ?? []).length === 2, html)
  check('a single author is not labelled', !trackedChangesHtml(note, designer).includes('mm-diff-by'), '')
}

const src = fileURLToPath(new URL('../src', import.meta.url))
const files = (dir) => readdirSync(dir).flatMap((name) => {
  const path = join(dir, name)
  return statSync(path).isDirectory() ? files(path) : /\.(ts|vue)$/.test(name) ? [path] : []
})
for (const path of files(src)) {
  const file = relative(src, path)
  if (file === join('lib', 'diffs.ts')) continue
  if (/from ['"](diff|@codemirror\/merge)['"]/.test(readFileSync(path, 'utf8'))) {
    failures.push(`${file} draws its own diff; edits are shown through lib/diffs.ts as tracked changes`)
  }
}

if (failures.length > 0) {
  console.error(`Edits are not shown as tracked changes:\n  ${failures.join('\n  ')}`)
  process.exit(1)
}
console.log('Edits are shown as tracked changes: changed words marked, a rewrite shown whole, no other diff renderer')
