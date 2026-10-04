#!/usr/bin/env node
/**
 * Where a URL takes you, asserted against the running app.
 *
 * Every page of memex is inside the knowledge base it belongs to, because note
 * numbers restart at 1 per team: `/notes/5` named a different note for every
 * reader, and an address that means something different depending on who opens
 * it is not an address. That rule is spread across three places that cannot see
 * each other — the vhost decides what reaches the SPA at all, the router
 * decides what matches, and one navigation guard decides what a handle which is
 * not yours means. A regex over any one of them proves nothing about the
 * journey, and the failures are all silent: the wrong note rendered as if it
 * were the right one, or a blank page where a route did not match.
 *
 * So this drives the built SPA in a browser with the API stubbed, and asserts
 * the address somebody ends up at. It needs `dist/`, no backend and no
 * database.
 *
 * THE SERVER BELOW IS NOT A CONVENIENCE. It applies the two location regexes
 * read out of deploy/nginx-spa-routes.conf, so "this 404s" is checked against
 * the allowlist that actually deploys rather than against a second copy of it
 * written here — which would agree with itself forever while production drifted.
 */
import { createServer } from 'node:http'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')
const VHOST = join(frontend, '..', 'deploy', 'nginx-spa-routes.conf')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Space URL check failed:\n')
  console.error('  dist/index.html is missing — run `npm run build-only` first.')
  process.exit(1)
}

// ── the vhost's own rules ────────────────────────────────────────────────

const conf = readFileSync(VHOST, 'utf8')

const locationRegex = (marker, pattern) => {
  const found = pattern.exec(conf)
  if (found === null) {
    console.error(`Space URL check failed:\n\n  ${VHOST} has no readable "${marker}" location.`)
    process.exit(1)
  }
  return new RegExp(found[1])
}

// `location ~ "^/[a-z2-9]{12}(/|$)"` and `location ~ ^/(login|…)(/|$)`, taken
// as written. nginx and JavaScript agree on this much regex syntax.
const HANDLE_LOCATION = locationRegex(
  'spa-handle-routes',
  /# BEGIN spa-handle-routes\n\s*location ~ "([^"]+)"/
)
const PUBLIC_LOCATION = locationRegex('spa-routes', /# BEGIN spa-routes\n\s*location ~ (\S+) \{/)

const MIME = {
  '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml',
  '.json': 'application/json', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png',
  '.ico': 'image/x-icon', '.map': 'application/json',
}

/** The box's routing, as far as a URL's fate is concerned. memex-local has no
 *  landing page: its nginx sends `/` to the app, which is the one difference
 *  `rootIsApp` makes. */
const serve = (rootIsApp) => createServer((req, res) => {
  const path = normalize(decodeURIComponent(new URL(req.url, 'http://x').pathname)).replace(/^(\.\.[/\\])+/, '')

  // A real file (the build's hashed assets) wins everywhere, as `try_files` has it.
  const file = join(dist, path)
  if (path !== '/' && existsSync(file) && extname(file) !== '') {
    res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' })
    return res.end(readFileSync(file))
  }

  if (path === '/' && !rootIsApp) {
    // `location = /` serves the landing page, which is not the SPA.
    res.writeHead(200, { 'content-type': 'text/html' })
    return res.end('<!doctype html><title>landing</title><h1 id="landing">landing</h1>')
  }

  if (path === '/' || HANDLE_LOCATION.test(path) || PUBLIC_LOCATION.test(path)) {
    res.writeHead(200, { 'content-type': 'text/html' })
    return res.end(readFileSync(join(dist, 'index.html')))
  }

  res.writeHead(404, { 'content-type': 'text/html' })
  res.end('<!doctype html><title>Not found</title><h1 id="server-404">Page not found</h1>')
})
const listen = async (server) => {
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  return `http://127.0.0.1:${server.address().port}`
}
const server = serve(false)
const standalone = serve(true)
const BASE = await listen(server)
const STANDALONE = await listen(standalone)

// ── fixtures ─────────────────────────────────────────────────────────────

const MINE = 'k7m2pq9wd4rn'
const THEIRS = 'zzzzzzzzzzzz'

const ME = {
  id: 1, email: 'guard@example.test', name: 'Guard',
  team: { id: 1, name: 'Guard', handle: MINE },
  system_tags: [], appearance: {}, icon_key: null, icon: null,
}

// Every field App\Controller\NoteController answers with. A fixture missing one
// threw inside the view and rendered nothing but the shell — which passed the
// address assertion, since the address was right.
const NOTE = {
  id: 5, title: 'A note the guard opens', body_md: 'a line of the note body',
  summary: null, summary_by: null, status: 'verified', source: 'web', source_url: null,
  last_actor: 'owner', edited_by: null, added_by: null, added_by_token_id: null,
  described_at: null, embedded: true, pending_proposals: 0, flagged: false,
  curation_flag: null, tags: [], links: [], backlinks: [],
  created_at: '2026-09-01T09:00:00+00:00', updated_at: '2026-09-01T09:00:00+00:00',
}

/** The list has to have rows in it. An empty knowledge base renders no note
 *  links at all, and the href rule at the bottom of this file then holds
 *  vacuously — it passed with the handle patch disabled for exactly that
 *  reason. */
const LIST = [5, 6, 7].map((id) => ({
  id, title: `Note ${id}`, source: 'web', source_url: null, status: 'verified',
  last_actor: 'owner', edited_by: null, summary: 'What it is about.', summary_by: null, tags: [],
  created_at: '2026-09-01T09:00:00+00:00', updated_at: '2026-09-01T09:00:00+00:00',
}))

/** Answers enough for the app to boot. Views that want more render empty, which
 *  changes no address — and the address is the whole subject here. */
/** Endpoints no scenario answers deliberately; reported once at the end. */
const unstubbed = new Set()

function apiStub(signedIn) {
  // A box, because signing in changes it mid-page: the provider round trip
  // ends and every later request is answered as the session it created.
  const state = { in: signedIn }
  return async (route) => {
    const url = new URL(route.request().url())
    const json = (body, status = 200) =>
      route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })

    // The whole OAuth round trip in one answer: the callback's redirect to
    // SocialAuthController::home(), which is Team::path() — the notes list
    // under the reader's handle.
    if (url.pathname === '/api/auth/google/start') {
      state.in = true
      return route.fulfill({ status: 302, headers: { location: `/${MINE}/notes` } })
    }
    if (url.pathname === '/api/auth/providers') {
      return json({ providers: [{ id: 'google', label: 'Google', icon: 'fa-brands fa-google' }] })
    }
    if (!state.in) return json({ error: 'unauthenticated' }, 401)
    if (url.pathname === '/api/me') return json(ME)
    if (url.pathname === '/api/me/setup') return json({ items: {}, complete: true, dismissed: true })
    if (url.pathname === '/api/inbox/count') return json({ pending_notes: 0, edit_proposals: 0, total: 0 })
    // The note page draws a neighbourhood map, which reads `nodes` and `edges`
    // off this without a guard — an empty object threw inside the component and
    // left the page as bare chrome.
    if (/^\/api\/notes\/\d+\/graph$/.test(url.pathname) || url.pathname === '/api/graph') {
      return json({ center: 5, depth: 1, nodes: [], edges: [], truncated: false, total_notes: LIST.length })
    }
    if (/^\/api\/notes\/\d+\/revisions$/.test(url.pathname)) {
      return json({ note_id: 5, keep_per_note: 10, revisions: [] })
    }
    if (url.pathname === '/api/me/onboarding') return json({ show: false, dismissed: true })
    if (url.pathname === '/api/presets') return json({ presets: [], icons: [] })
    if (url.pathname === '/api/tags') return json({ tags: [], retired: [] })
    if (url.pathname === '/api/me/identities') return json({ identities: [], available: [] })
    if (url.pathname === '/api/me/welcome') return json({ completed: true, facts: { connected: false, profiles: [] } })
    if (url.pathname === '/api/tokens') return json({ tokens: [], icons: [], logos: [] })
    if (url.pathname === '/api/curation/wiring') return json({ connections: [], other_count: 0 })
    if (url.pathname === '/api/curation/instructions') return json({ presets: [] })
    if (url.pathname === '/api/settings/ai') return json({ keys: [], providers: [], limits: null, enabled: false })
    if (url.pathname === '/api/me/sessions') return json({ sessions: [] })
    if (/^\/api\/notes\/\d+$/.test(url.pathname)) return json(NOTE)
    if (url.pathname === '/api/notes') return json({ items: LIST, total: LIST.length, semantic_unavailable: false })
    if (url.pathname === '/api/skills') {
      return json({
        skills: [{ kind: 'note', status: 'served', slug: 'house-style', title: 'House style', description: 'How notes are written.', short: null, body: 'Steps.', updated_at: '2026-09-01 09:00:00', note_id: 5, enabled: true, auto: true, command: true, grants: [], usage: { total_30d: 0, last_at: null, last_token_id: null, by_token: [] }, lint: [], size_tokens: 12 }],
        connections: [],
      })
    }
    // A generic object is worse than nothing where a component reads a key off
    // it: the note page asked for its revisions, got a shape with no
    // `revisions`, and threw — leaving bare chrome at exactly the right
    // address, which the assertion read as a pass. Anything still landing here
    // is named once at the end, so the next one is cheap to find.
    unstubbed.add(url.pathname)
    return json({ notes: [], items: [], tags: [], results: [], total: 0, count: 0 })
  }
}

// ── the harness ──────────────────────────────────────────────────────────

const browser = await chromium.launch()
const problems = []
let checked = 0

/** Open `path` and report where the browser came to rest. */
async function land(path, { signedIn = true, base = BASE } = {}) {
  const context = await browser.newContext()
  const asked = []
  await context.route('**/api/**', apiStub(signedIn))
  const context_ = context
  context_.on('request', (req) => {
    const m = /\/api\/notes\/(\d+)$/.exec(new URL(req.url()).pathname)
    if (m !== null) asked.push(Number(m[1]))
  })
  const page = await context.newPage()
  const response = await page.goto(base + path, { waitUntil: 'networkidle' })
  const status = response?.status() ?? 0
  const where = new URL(page.url()).pathname
  const body = await page.evaluate(() => document.body.innerText)
  const serverPage = await page.evaluate(() => document.getElementById('server-404') !== null)
  const pane = await page.locator('.app-settings-pane-title').count() ? await page.locator('.app-settings-pane-title').textContent() : null
  const sections = await page.locator('.app-settings-pane h3').allTextContents()
  await context.close()
  return { status, where, body, serverPage, asked, pane, sections }
}

const KNOWN = ['status', 'where', 'body', 'serverPage', 'asked', 'pane', 'sections']

const expect = async (what, path, options, want) => {
  checked++
  const got = await land(path, options)
  // A misspelled key compared undefined with undefined and passed, which is an
  // assertion that asserts nothing and reads exactly like one that does.
  for (const key of Object.keys(want)) {
    if (!KNOWN.includes(key)) {
      console.error(`Space URL check failed:\n\n  '${key}' is not something land() reports. Known: ${KNOWN.join(', ')}.`)
      process.exit(1)
    }
  }
  const ok = Object.entries(want).every(([key, value]) =>
    typeof value === 'function' ? value(got[key]) : got[key] === value
  )
  if (!ok) {
    problems.push(
      `${what}\n    opened ${path}\n    wanted ${JSON.stringify(want, (_, v) => (typeof v === 'function' ? '<predicate>' : v))}` +
        `\n    got    status ${got.status}, at ${got.where}${got.serverPage ? ' (server 404 page)' : ''}`
    )
  }
}

// --- 1. a knowledge base's own pages stay exactly where they are -------------

await expect('The notes list keeps its address.', `/${MINE}/notes`, {}, { where: `/${MINE}/notes`, status: 200 })
await expect('The inbox keeps its address.', `/${MINE}/inbox`, {}, { where: `/${MINE}/inbox` })
await expect('The skills page lists what is served.', `/${MINE}/skills`, {}, { where: `/${MINE}/skills`, body: (t) => t.includes('House style') && t.includes('How notes are written.') && t.includes('Full instruction size') })
// The note's own title, not just the address: swapping the component for the
// not-found page left "keeps its address" satisfied, which is the whole failure
// a foreign handle would produce.
await expect(
  'A note keeps its address and renders that note.',
  `/${MINE}/notes/5`,
  {},
  { where: `/${MINE}/notes/5`, body: (text) => text.includes(NOTE.title) }
)
await expect(
  'The edit route keeps its address and opens the note.',
  `/${MINE}/notes/5/edit`,
  {},
  { where: `/${MINE}/notes/5/edit`, asked: (calls) => calls.includes(5) }
)
await expect('Settings keeps its address.', `/${MINE}/settings/account`, {}, { where: `/${MINE}/settings/account` })
for (const path of ['curation', 'automation#curation', 'advanced#curation', 'developer#curation', 'connections#curation']) {
  await expect('Old maintenance links open the assistant controls.', `/${MINE}/settings/${path}`, {}, {
    pane: 'Assistants', sections: (titles) => titles.includes('Note maintenance'),
  })
}
await expect('Preferences contains appearance, but not the space name.', `/${MINE}/settings/memex`, {}, {
  pane: 'Preferences', sections: (titles) => titles.includes('Appearance') && !titles.includes('Space name'),
})
await expect('Notes keeps import and export together.', `/${MINE}/settings/content`, {}, {
  pane: 'Notes', sections: (titles) => !titles.includes('Space name') && titles.includes('Import') && titles.includes('Export'),
})
await expect('Old space settings still reach Notes.', `/${MINE}/settings/knowledge-base`, {}, {
  pane: 'Notes', sections: (titles) => titles.includes('Import'),
})
await expect('Account links to export beside permanent deletion.', `/${MINE}/settings/account`, {}, {
  pane: 'Account', sections: (titles) => !titles.includes('Export') && titles.includes('Delete my account'), body: (text) => text.includes('Download your notes before deleting your account'),
})
await expect('An old sign-in callback stays in Account.', `/${MINE}/settings/general`, {}, { pane: 'Account' })
await expect('Server settings are not in the app.', `/${MINE}/settings/server`, {}, { pane: 'Account' })

await expect('The bare knowledge base opens its list.', `/${MINE}`, {}, { where: `/${MINE}/notes` })

// --- 2. somebody else's knowledge base sends you to your own -----------------
//
// Never to the same PAGE under your own handle: note 5 is a different note in
// every account, and answering with yours would be the silent wrong note this
// whole address shape exists to prevent.

await expect(
  "Another account's note sends you to your list, and is never asked for.",
  `/${THEIRS}/notes/5`,
  {},
  // `asked` is every /api/notes/<n> the page requested. Landing on the right
  // address while having fetched the reader's note 5 on the way is the silent
  // wrong note with a redirect painted over it.
  { where: `/${MINE}/notes`, asked: (calls) => calls.length === 0, body: (t) => !t.includes(NOTE.title) }
)
await expect("Another account's inbox sends you to your list.", `/${THEIRS}/inbox`, {}, { where: `/${MINE}/notes` })
await expect("Another account's address sends you to your list.", `/${THEIRS}`, {}, { where: `/${MINE}/notes` })

// --- 3. a page that does not exist says so, and guesses nothing --------------

await expect(
  'An unknown page inside your own knowledge base says so and stays put.',
  `/${MINE}/nonsense`,
  {},
  { where: `/${MINE}/nonsense`, body: (text) => /page not found/i.test(text), asked: (c) => c.length === 0 }
)


// --- 4. nothing without a knowledge base is an address ----------------------
//
// These are the links option 3 retired. Each one must reach the SERVER's 404 —
// not the SPA, which would mean the shell was served and the app decided, and
// therefore that `/notes/5` still resolves to somebody's note.

for (const path of ['/notes', '/notes/5', '/inbox', '/activity', '/map', '/skills', '/welcome', '/settings']) {
  await expect(`${path} is not an address.`, path, {}, { status: 404, serverPage: true })
}
await expect('A typo is not an address.', '/random-text/another-text', {}, { status: 404, serverPage: true })
await expect('A handle-shaped typo with no page is still the app.', `/${THEIRS}/notes`, {}, { status: 200 })

// --- 5. signed out, every address leads to the same place --------------------
//
// One answer for a handle that exists and one that does not: nothing here tells
// anybody which knowledge bases are real.

await expect('Signed out, your own page asks you to sign in.', `/${MINE}/notes`, { signedIn: false }, { where: '/login' })
await expect("Signed out, another account's page asks the same.", `/${THEIRS}/inbox`, { signedIn: false }, { where: '/login' })
await expect('Signed out, a note asks the same.', `/${MINE}/notes/5`, { signedIn: false }, { where: '/login' })

// --- 5b. where memex-local serves `/` from the app, it is the way in --------

await expect('Signed in, `/` opens your list.', '/', { base: STANDALONE }, { where: `/${MINE}/notes`, body: (t) => t.includes(LIST[0].title) })
await expect('Signed out, `/` asks you to sign in.', '/', { base: STANDALONE, signedIn: false }, { where: '/login', body: (t) => t.includes('Google') })

// --- 6. signing in lands you in your own knowledge base ----------------------
//
// A provider button is a full page load out of the SPA and back, so what lands
// is an address the vhost has to serve and the guard has to keep: the list
// under the reader's own handle, rendering their notes.

checked++
{
  const context = await browser.newContext()
  await context.route('**/api/**', apiStub(false))
  const page = await context.newPage()
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' })
  await page.click('button.mm-login-provider')
  await page
    .waitForURL((url) => new URL(url).pathname !== '/login', { timeout: 5000 })
    .catch(() => {})
  await page.waitForLoadState('networkidle')
  const where = new URL(page.url()).pathname
  const body = await page.evaluate(() => document.body.innerText)
  await context.close()
  if (where !== `/${MINE}/notes` || !body.includes(LIST[0].title)) {
    problems.push(
      `Signing in did not land in the reader's own knowledge base.\n    wanted /${MINE}/notes with its notes\n    got    ${where}`
    )
  }
}

// --- 6. a rendered link carries the knowledge base ---------------------------
//
// The guard rewrites an address somebody typed; this is the other half — what
// the app itself puts in an href, which is what gets copied out and pasted.

checked++
{
  const context = await browser.newContext()
  await context.route('**/api/**', apiStub(true))
  const page = await context.newPage()
  await page.goto(`${BASE}/${MINE}/notes`, { waitUntil: 'networkidle' })
  // Every same-origin href on the page, minus the ones that are not app pages.
  const hrefs = await page.evaluate(() =>
    [...document.querySelectorAll('a[href]')]
      .map((a) => a.getAttribute('href'))
      .filter((h) => h !== null && h.startsWith('/') && !h.startsWith('//'))
  )

  // The fixture's own notes, by address. A count passed when every note row's
  // href became '/', because '/' is exempt below and the shell's own links made
  // the total.
  // Paired with the title beside it, so rotating which row links to which note
  // is a failure rather than a reshuffle of the same three strings.
  // Each row's own title beside its own href, so a rotation is visible. The
  // row link carries the description too, so the title is read on its own.
  const rows = await page.evaluate(() =>
    [...document.querySelectorAll('a[href]')]
      .map((a) => ({ href: a.getAttribute('href'), text: (a.querySelector('.app-note-title')?.textContent ?? '').trim() }))
      .filter((r) => /^Note \d+$/.test(r.text))
      .map((r) => ({ href: r.href, id: Number(r.text.replace('Note ', '')) }))
  )
  const wrong = rows.filter((r) => r.href !== `/${MINE}/notes/${r.id}`)
  if (rows.length !== LIST.length || wrong.length > 0) {
    problems.push(
      `The notes list did not link each row to its own note.\n` +
        `    rows: ${JSON.stringify(rows)}\n    expected ids ${LIST.map((n) => n.id).join(', ')} in order`
    )
  }
  await context.close()

  const wanted = LIST.map((n) => `/${MINE}/notes/${n.id}`)
  const missing = wanted.filter((w) => !hrefs.includes(w))
  if (missing.length > 0) {
    problems.push(
      `The notes list did not link to its own notes.\n    missing: ${missing.join(' ')}\n` +
        `    every href on the page: ${hrefs.join(' ') || '(none)'}`
    )
  }
  const inSpace = hrefs.filter((h) => h.startsWith(`/${MINE}/`))
  // The landing site's own pages, which the vhost serves from the static root
  // and which belong to nobody's knowledge base — the app's footer links to
  // them like any visitor would.
  const LANDING = ['/', '/about', '/privacy', '/terms']
  const strays = hrefs.filter(
    (h) => !h.startsWith(`/${MINE}/`) && !PUBLIC_LOCATION.test(h) && !LANDING.includes(h)
  )
  // A count, because the rule above is about links that EXIST: with the handle
  // patch switched off the list rendered no in-app hrefs at all and every
  // filter came back empty, which read as a pass.
  if (inSpace.length < 3) {
    problems.push(
      `The notes list rendered ${inSpace.length} link(s) into the knowledge base, expected at least 3.\n` +
        `    Every href on the page: ${hrefs.join(' ') || '(none)'}\n` +
        '    Too few to tell a correct link from a missing one — the fixture or the page changed.'
    )
  }
  if (strays.length > 0) {
    problems.push(
      `A rendered link left out the knowledge base.\n    ${strays.join('\n    ')}\n` +
        '    Every in-app link resolves through the router, which fills the handle in. A bare one is a link that 404s when pasted.'
    )
  }
}

// ── report ───────────────────────────────────────────────────────────────

await browser.close()
server.close()
standalone.close()

if (problems.length > 0) {
  console.error(`Space URL check failed — ${problems.length} of ${checked}:\n`)
  for (const problem of problems) console.error(`  ${problem}\n`)
  process.exit(1)
}

console.log(`Space URLs passed: ${checked} addresses behave as the contract says.`)
if (unstubbed.size > 0) {
  console.log(`  (${unstubbed.size} endpoint(s) answered generically: ${[...unstubbed].sort().join(', ')})`)
}
