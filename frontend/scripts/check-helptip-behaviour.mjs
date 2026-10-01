#!/usr/bin/env node
/**
 * A (?) hint, pressed the way a person presses it, stays open.
 *
 * A press reaches `HelpTip` as `mouseenter` or `focus` first, and both open
 * the panel; the `click` that follows used to toggle, found it open, and shut
 * it. On a phone, which has no hover, no hint could be read at all, and on a
 * desktop a click on a hint the pointer had already opened closed it. The
 * build was green throughout, because the order of those events exists only
 * in a browser — so this drives the notes list in one, with the API stubbed,
 * and reads what is on screen. Needs `dist/`, no backend and no database.
 *
 * Closing is asserted too: a click that only opens is safe only while moving
 * away, tabbing away and tapping elsewhere still close. The phone runs in
 * WebKit, as an iPhone does: Chromium focuses a tapped button and so closes on
 * blur, which let a hint with no tap-elsewhere handler pass here and stay open
 * on Safari.
 */
import { createServer } from 'node:http'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium, devices, webkit } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('HelpTip behaviour check failed:\n')
  console.error('  dist/index.html is missing — run `npm run build-only` first.')
  process.exit(1)
}

const MIME = {
  '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml',
  '.json': 'application/json', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png',
  '.jpg': 'image/jpeg', '.ico': 'image/x-icon', '.map': 'application/json',
}

const server = createServer((req, res) => {
  const path = normalize(decodeURIComponent(new URL(req.url, 'http://x').pathname)).replace(/^(\.\.[/\\])+/, '')
  let file = join(dist, path)
  if (!existsSync(file) || extname(file) === '') file = join(dist, 'index.html')
  res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' })
  res.end(readFileSync(file))
})
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
const BASE = `http://127.0.0.1:${server.address().port}`

// ── fixtures ─────────────────────────────────────────────────────────────

const HANDLE = 'k7m2pq9wd4rn'
const NOTES = `${BASE}/${HANDLE}/notes`

const ME = {
  id: 1, email: 'user@example.test', name: 'Test User',
  team: { id: 1, name: 'Demo', handle: HANDLE },
  system_tags: [], appearance: {}, icon_key: null, icon: null,
  welcome_completed: true,
}

const LIST = [5, 6, 7].map((id) => ({
  id, title: `Note ${id}`, source: 'web', source_url: null, status: 'verified',
  last_actor: 'owner', edited_by: null, summary: 'What it is about.', summary_by: null, tags: [],
  created_at: '2026-09-01T09:00:00+00:00', updated_at: '2026-09-01T09:00:00+00:00',
}))

async function stub(route) {
  const url = new URL(route.request().url())
  const json = (body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
  if (url.pathname === '/api/me') return json(ME)
  if (url.pathname === '/api/me/setup') return json({ items: {}, complete: true, dismissed: true })
  if (url.pathname === '/api/me/onboarding') return json({ show: false, dismissed: true })
  if (url.pathname === '/api/me/welcome') return json({ completed: true, facts: { connected: false, profiles: [] } })
  if (url.pathname === '/api/inbox/count') return json({ pending_notes: 0, edit_proposals: 0, total: 0 })
  if (url.pathname === '/api/presets') return json({ presets: [], icons: [] })
  if (url.pathname === '/api/tags') return json({ tags: [], retired: [] })
  if (url.pathname === '/api/notes') return json({ items: LIST, total: LIST.length, semantic_unavailable: false })
  return json({ notes: [], items: [], tags: [], results: [], total: 0, count: 0 })
}

// ── the harness ──────────────────────────────────────────────────────────

const browser = await chromium.launch()
const phone = await webkit.launch()
const problems = []

/** Longer than HelpTip's 180ms grace period, so a pending close has run. */
const SETTLE = 400

async function notesList(contextOptions, engine = browser) {
  const context = await engine.newContext(contextOptions)
  await context.route('**/api/**', stub)
  const page = await context.newPage()
  await page.goto(NOTES, { waitUntil: 'networkidle' })
  const icon = page.locator('.app-note-list-title .mm-helptip-icon')
  await icon.waitFor()
  return { context, page, icon, panel: page.locator('.mm-helptip-panel') }
}

async function expectOpen(what, panel, want) {
  await panel.page().waitForTimeout(SETTLE)
  const open = await panel.isVisible()
  if (open !== want) problems.push(`${what}: the panel is ${open ? 'open' : 'closed'}`)
}

// --- desktop: the pointer opens it, and the click that follows keeps it ------
{
  const { context, page, icon, panel } = await notesList({ viewport: { width: 1280, height: 800 } })
  await icon.click()
  await expectOpen('A desktop click on the (?) the pointer is over', panel, true)
  await page.mouse.move(640, 780)
  await expectOpen('Moving the pointer away from the open hint', panel, false)
  await context.close()
}

// --- keyboard: focus opens it, Enter keeps it, Tab away closes it -----------
{
  const { context, page, icon, panel } = await notesList({ viewport: { width: 1280, height: 800 } })
  await page.mouse.move(640, 780)
  await icon.focus()
  await page.keyboard.press('Enter')
  await expectOpen('Enter on the focused (?)', panel, true)
  await page.keyboard.press('Tab')
  await expectOpen('Tabbing away from the open hint', panel, false)
  await context.close()
}

// --- phone: a tap opens it, a tap elsewhere closes it -----------------------
{
  const { context, page, icon, panel } = await notesList({ ...devices['iPhone 13'] }, phone)
  await icon.tap()
  await expectOpen('A tap on the (?) on a phone', panel, true)
  // The column's own word, beside the (?) and outside it.
  const heading = await page.locator('.app-note-list-title').boundingBox()
  await page.touchscreen.tap(heading.x + 4, heading.y + heading.height / 2)
  await expectOpen('A tap elsewhere on the page', panel, false)
  await context.close()
}

await browser.close()
await phone.close()
server.close()

if (problems.length > 0) {
  console.error('HelpTip behaviour check failed:\n')
  for (const problem of problems) console.error(`  ${problem}.`)
  process.exit(1)
}
console.log('HelpTip behaviour check passed: 6 presses on the notes list.')
