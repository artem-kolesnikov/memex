#!/usr/bin/env node
/**
 * A tab opened before a deploy, asserted against the built SPA in a browser
 * with the API stubbed.
 *
 * The deploy mirrors `dist/` with `rsync --delete`, so the page files the open
 * tab's build names are gone from the box. Before the router caught that, Edit,
 * Activity and Skills simply did not open until the reader reloaded
 * (2026-09-28). Here the Activity page's files answer 404 the way the box does
 * after a deploy, and the check reads where the reader ends up and how many
 * times the page was loaded. Needs `dist/`, no backend and no database.
 */
import { createServer } from 'node:http'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Stale build check failed:\n')
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

const ME = {
  id: 1, email: 'user@example.test', name: 'Test User',
  team: { id: 1, name: 'Demo', handle: HANDLE },
  system_tags: [], appearance: {}, icon_key: null, icon: null,
  welcome_completed: true,
}

async function api(route) {
  const url = new URL(route.request().url())
  const json = (body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
  if (url.pathname === '/api/me') return json(ME)
  if (url.pathname === '/api/inbox/count') return json({ pending_notes: 0, edit_proposals: 0, total: 0 })
  if (url.pathname === '/api/me/setup') return json({ items: {}, complete: true, dismissed: true })
  if (url.pathname === '/api/me/onboarding') return json({ show: false, dismissed: true })
  if (url.pathname === '/api/presets') return json({ presets: [], icons: [] })
  if (url.pathname === '/api/tags') return json({ tags: [], retired: [] })
  if (url.pathname === '/api/curation/digest') return json({ runs: [], runs_total: 0 })
  return json({ items: [], notes: [], rows: [], entries: [], runs: [], total: 0, tags: [], tag_counts: [], presets: [] })
}

// ── harness ──────────────────────────────────────────────────────────────

const failures = []
const browser = await chromium.launch()

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

/**
 * `gone(loads)` decides, per page load, whether the Activity page's files are
 * missing: the first load is the tab opened before the deploy.
 */
async function scenario(name, gone, body) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 } })
  const page = await context.newPage()
  page.setDefaultTimeout(5000)
  const seen = { loads: 0 }
  page.on('request', (request) => {
    if (request.resourceType() === 'document' && request.frame() === page.mainFrame()) seen.loads++
  })
  await page.route('**/api/**', api)
  await page.route(/\/assets\/ActivityView-[^/]+\.(js|css)$/, (route) =>
    gone(seen.loads) ? route.fulfill({ status: 404, contentType: 'text/html', body: 'Not found' }) : route.fallback())
  const errors = []
  page.on('pageerror', (e) => errors.push(String(e)))
  try {
    await page.goto(`${BASE}/${HANDLE}/notes`)
    await page.getByRole('link', { name: 'Activity log' }).click()
    await body(page, seen, errors)
    console.log(`  ok   ${name}`)
  } catch (e) {
    failures.push(`${name}: ${e.message}`)
    console.log(`  FAIL ${name}: ${e.message}`)
  } finally {
    await context.close()
  }
}

// ── scenarios ────────────────────────────────────────────────────────────

await scenario('a page a deploy deleted opens after one reload', (loads) => loads === 1, async (page, seen, errors) => {
  await page.waitForURL(`${BASE}/${HANDLE}/activity`)
  await page.getByRole('heading', { name: 'Activity log', level: 1 }).waitFor()
  assert(seen.loads === 2, `page loads: ${seen.loads}, expected the first and one reload`)
  assert(errors.length === 0, `page errors: ${errors.join(' | ')}`)
})

await scenario('a page missing from the new build too reloads once, not forever', () => true, async (page, seen) => {
  await page.waitForURL(`${BASE}/${HANDLE}/activity`)
  await page.waitForTimeout(1500)
  assert(seen.loads === 2, `page loads: ${seen.loads}, expected the first and one reload`)
})

await browser.close()
server.close()

if (failures.length) {
  console.error(`\nStale build check failed (${failures.length}):\n`)
  for (const f of failures) console.error(`  - ${f}`)
  process.exit(1)
}
console.log('\nStale build check passed.')
