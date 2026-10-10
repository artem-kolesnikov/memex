#!/usr/bin/env node
/**
 * The two public-page scripts a person can lose something to, in a browser.
 *
 * /support: the server drops a message posted less than three seconds after
 * the page opened, as a bot's, and still answers "sent". A person who
 * autofills the address and pastes a prepared message can press Send sooner
 * than that, so the page has to wait out the rest before it posts; otherwise
 * the message is lost and the page says it went.
 *
 * The docs search: the index is fetched once and kept. A fetch that failed
 * (a dropped connection, a 502 during a deploy) used to be kept too, as an
 * empty index, and every search after it said "No page matches" until a
 * reload. A failure has to say so and the next keystroke has to fetch again.
 *
 * Builds landing/ itself and serves it with the API stubbed. Needs no backend.
 */
import { spawnSync } from 'node:child_process'
import { createServer } from 'node:http'
import { readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const landing = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'landing')
const built = spawnSync(process.execPath, [join(landing, 'build.mjs')], { encoding: 'utf8' })
if (built.status !== 0) {
  console.error('Public pages behaviour check failed:\n')
  console.error(built.stderr || built.stdout)
  process.exit(1)
}

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png' }
const posts = []
let indexRequests = 0

const server = createServer((req, res) => {
  const path = req.url.split('?')[0]
  if (path === '/api/support') {
    if (req.method === 'GET') return void res.writeHead(200, { 'Content-Type': 'application/json' }).end('{"open":true}')
    let body = ''
    req.on('data', (chunk) => { body += chunk })
    req.on('end', () => {
      posts.push(JSON.parse(body))
      res.writeHead(200, { 'Content-Type': 'application/json' }).end('{"sent":true}')
    })
    return
  }
  let file
  if (path === '/legal/assets/docs-index.json') {
    indexRequests++
    if (indexRequests === 1) return void res.writeHead(502).end()
    file = join(landing, 'dist', 'assets', 'docs-index.json')
  } else if (path.startsWith('/legal/assets/')) {
    file = join(landing, 'assets', normalize(path.slice('/legal/assets/'.length)).replace(/^(\.\.[/\\])+/, ''))
  } else if (path === '/support') {
    file = join(landing, 'dist', 'support.html')
  } else if (path.startsWith('/docs/')) {
    file = join(landing, 'dist', 'docs', `${normalize(path.slice('/docs/'.length)).replace(/^(\.\.[/\\])+/, '')}.html`)
  }
  try {
    const content = readFileSync(file)
    res.writeHead(200, { 'Content-Type': MIME[extname(file)] ?? 'application/octet-stream' }).end(content)
  } catch {
    res.writeHead(404).end()
  }
})
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
const origin = `http://127.0.0.1:${server.address().port}`

const failures = []
const browser = await chromium.launch()
try {
  const page = await browser.newPage()

  await page.goto(`${origin}/support`)
  await page.locator('#support-form').waitFor({ state: 'visible' })
  await page.fill('#support-email', 'visitor@example.test')
  await page.fill('#support-message', 'A message written before the page opened.')
  await page.click('#support-form button[type="submit"]')
  await page.locator('#support-done').waitFor({ state: 'visible', timeout: 10000 })
  if (posts.length !== 1) failures.push(`/support: ${posts.length} posts for one press of Send`)
  else if (!(posts[0].elapsed >= 3000)) failures.push(`/support: a quick Send posted after ${posts[0].elapsed} ms, which the server drops as a bot's`)

  await page.goto(`${origin}/docs/connect-claude`)
  await page.fill('#docs-q', 'claude')
  const first = page.locator('.docs-sidebar .docs-results')
  await first.waitFor({ state: 'visible' })
  const said = await first.innerText()
  if (/No page matches/.test(said)) failures.push('docs search: a failed index load reads as "No page matches"')
  await page.fill('#docs-q', 'claude connector')
  await page.locator('.docs-sidebar .docs-results a').first().waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
  const results = await page.locator('.docs-sidebar .docs-results a').count()
  if (indexRequests < 2) failures.push('docs search: the next search after a failed load did not fetch the index again')
  if (results === 0) failures.push('docs search: no results once the index loaded on the retry')
} finally {
  await browser.close()
  server.close()
}

if (failures.length) {
  console.error('Public pages behaviour check failed:\n')
  for (const failure of failures) console.error(`  ${failure}`)
  process.exit(1)
}
console.log('Public pages behaviour: a quick Send waits out the bot check; a failed docs index retries on the next search.')
