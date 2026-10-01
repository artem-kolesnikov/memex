#!/usr/bin/env node
// The search URL contract, checked statically because this repository has no
// frontend test runner and the two things worth protecting here
// are both visible in the source.
//
// Same shape as check-responsive.mjs and check-csp-compat.mjs: a property that
// a build cannot notice, asserted about the code rather than about a rendered
// page.
//
// TWO PROPERTIES.
//
// 1. **`replace`, never `push`.** Every applied search would otherwise become a
//    history entry, and Back would walk backwards through them one at a time
//    instead of leaving the page. That is not a subtle degradation — it makes
//    the browser's most-used control useless on the app's busiest screen, and
//    it is one word to reintroduce.
//
// 2. **The keys WRITTEN are the keys READ.** `syncUrl()` puts state into the
//    address bar and `onMounted` takes it back out. They are forty lines apart
//    and nothing connects them, so a fourth key added to one and not the other
//    produces a link that looks right, carries the state, and silently drops it
//    on open. Nobody would see that in review; the URL is correct and only the
//    page is wrong.
import { readFileSync } from 'node:fs'

const FILE = 'frontend/src/views/SearchView.vue'
const raw = readFileSync(FILE, 'utf8')
// EVERYTHING BELOW READS `source`, NEVER `raw`.
//
// The first version of this guard scanned the file as text, and a review broke
// it in one line: a key named only in a TODO comment counted as a reader, so
// `next.sort = [...]` plus `// still need to read route.query.sort` passed with
// nothing reading it. That is the third guard on this project to be defeated by
// a comment mentioning the thing it was checking for — the same mistake as the
// `OwnerInitiated` occurrence count and the embedQuery adjacency scan. A guard
// that reads prose is a guard that can be argued with.
const source = stripComments(raw)

const problems = []

// --- 1. replace, never push -------------------------------------------------

if (!/router\.replace\(/.test(source)) {
  problems.push(`${FILE}: no router.replace() — the search no longer syncs to the URL at all`)
}
const pushes = source.match(/router\.push\(/g)
if (pushes) {
  problems.push(
    `${FILE}: ${pushes.length} router.push() call(s). Search state must use replace(), ` +
      'or every applied search becomes a history entry and Back stops leaving the page.',
  )
}

// --- 2. written keys === read keys ------------------------------------------

// Written: `next.<key> = ` inside syncUrl().
const syncBody = section(source, 'function syncUrl()')
const written = new Set([...syncBody.matchAll(/\bnext\.([a-zA-Z_][\w]*)\s*=/g)].map((m) => m[1]))

// Declared: the literal list currentUrlCriteria() walks, which is what the
// no-op comparison is built from. If it disagrees with the writer, the
// comparison silently stops suppressing redundant navigations.
const declaredMatch = source.match(/for \(const key of \[([^\]]*)\] as const\)/)
if (!declaredMatch) {
  problems.push(`${FILE}: currentUrlCriteria()'s key list could not be found — this guard is checking nothing`)
}
const declared = new Set(
  declaredMatch ? [...declaredMatch[1].matchAll(/'([^']+)'/g)].map((m) => m[1]) : [],
)

// Read: every key the mount handler actually pulls out of the address bar.
//
// Both spellings count. `queryValues('tag')` is the named accessor the readers
// go through — one place that knows vue-router hands back a string, an array or
// null — and `route.query.tag` is the direct form, which still exists in the
// wild and must not become an unchecked back door. A key reached only through a
// non-literal (`queryValues(key)` inside a loop) is deliberately NOT counted:
// that is `currentUrlCriteria()` walking its own declared list, which is
// checked separately below, and counting it here would let that list satisfy
// this test on its own.
const read = new Set([
  ...[...source.matchAll(/queryValues\(\s*'([^']+)'\s*\)/g)].map((m) => m[1]),
  ...[...source.matchAll(/route\.query\.([a-zA-Z_][\w]*)/g)].map((m) => m[1]),
])

const sorted = (set) => [...set].sort().join(', ') || '(none)'

if (written.size === 0) problems.push(`${FILE}: syncUrl() writes no keys — parsing is broken or the function is gone`)
if (read.size === 0) problems.push(`${FILE}: nothing reads route.query — parsing is broken or the reader is gone`)

for (const key of written) {
  if (!read.has(key)) {
    problems.push(
      `${FILE}: '${key}' is written to the URL but never read back. ` +
        'A link carrying it would look correct and silently lose that state when opened.',
    )
  }
}
for (const key of read) {
  if (!written.has(key)) {
    problems.push(
      `${FILE}: '${key}' is read from the URL but never written. ` +
        'The page can be opened in a state it can never produce a link for.',
    )
  }
}
for (const key of written) {
  if (!declared.has(key)) {
    problems.push(
      `${FILE}: '${key}' is written but missing from currentUrlCriteria()'s key list, ` +
        'so the redundant-navigation check no longer sees it.',
    )
  }
}
// And the other direction, which the first version did not check: a key left
// in that list after being removed everywhere else is an always-empty lookup —
// inert today, and a lie about what this screen puts in the URL.
for (const key of declared) {
  if (!written.has(key)) {
    problems.push(
      `${FILE}: '${key}' is listed in currentUrlCriteria() but never written. ` +
        'The list is meant to name exactly the keys this screen owns.',
    )
  }
}

/**
 * Blank out COMMENTS, preserving offsets and line count so the brace matching
 * below still works on the result.
 *
 * String literals are KEPT, deliberately, and the distinction is the whole
 * point. `queryValues('tag')` names the key in a string — that string is code
 * and is exactly what this guard needs to see. A comment saying "we should
 * also read route.query.sort" is prose, and letting prose vote is how the
 * first version of this guard passed with nothing reading the key.
 *
 * Strings are still PARSED rather than ignored, because a `//` inside one —
 * `'https://memex.tools'` is the obvious case — would otherwise look like the
 * start of a comment and blank the rest of the line, silently removing real
 * code from the scan.
 */
function stripComments(text) {
  let out = ''
  let i = 0
  const blank = (s) => s.replace(/[^\n]/g, ' ')

  while (i < text.length) {
    const rest = text.slice(i)

    // <!-- --> in the template half of a .vue file
    let m = rest.match(/^<!--[\s\S]*?-->/)
    if (m) { out += blank(m[0]); i += m[0].length; continue }

    m = rest.match(/^\/\*[\s\S]*?\*\//)
    if (m) { out += blank(m[0]); i += m[0].length; continue }

    m = rest.match(/^\/\/[^\n]*/)
    if (m) { out += blank(m[0]); i += m[0].length; continue }

    // A string: copied through untouched, so its contents stay visible and a
    // `//` inside it cannot be mistaken for a comment.
    m = rest.match(/^'(?:\\.|[^'\\\n])*'|^"(?:\\.|[^"\\\n])*"|^`(?:\\.|[^`\\])*`/)
    if (m) { out += m[0]; i += m[0].length; continue }

    out += text[i]
    i += 1
  }
  return out
}

/** The body of a function, by brace matching from its declaration. */
function section(text, declaration) {
  const start = text.indexOf(declaration)
  if (start === -1) return ''
  let depth = 0
  for (let i = text.indexOf('{', start); i < text.length; i++) {
    if (text[i] === '{') depth++
    else if (text[i] === '}' && --depth === 0) return text.slice(start, i + 1)
  }
  return ''
}

if (problems.length) {
  console.error('Search URL contract failed:\n')
  for (const problem of problems) console.error('  - ' + problem)
  process.exit(1)
}

console.log(
  `Search URL contract passed: replace-only, and the same keys are written and read (${sorted(written)}).`,
)
