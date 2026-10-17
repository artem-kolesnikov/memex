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
 * The waitlist: the server keeps nothing while sign-up is open and still
 * answers "received". A visitor whose page loaded while sign-up was full, and
 * who asks after it opened, has to be offered sign-up rather than told they
 * are on a waitlist that holds nothing of theirs.
 *
 * Ask your AI: Copy prompt has to put the prompt on the clipboard before it
 * says Copied, and the menu has to close on Escape, or a visitor pastes
 * nothing into the assistant they meant to ask.
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
const asks = []
let indexRequests = 0
let registrationChecks = 0

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
  if (path === '/api/registration') {
    registrationChecks++
    return void res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ open: registrationChecks > 1 }))
  }
  if (path === '/api/invite-request') {
    let body = ''
    req.on('data', (chunk) => { body += chunk })
    req.on('end', () => {
      asks.push(JSON.parse(body))
      res.writeHead(200, { 'Content-Type': 'application/json' }).end('{"received":true}')
    })
    return
  }
  let file
  if (path === '/') {
    file = join(landing, 'dist', 'index.html')
  } else if (path === '/legal/assets/docs-index.json') {
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

  await page.goto(`${origin}/`)
  await page.locator('#invite-form').waitFor({ state: 'visible' })
  await page.fill('#invite-email', 'late@example.test')
  await page.click('#invite-form button[type="submit"]')
  await page.locator('#invite-open').waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
  if (asks.length !== 1) failures.push(`waitlist: ${asks.length} posts for one press`)
  else if (asks[0].website !== '') failures.push(`waitlist: a person's post filled the decoy field with ${JSON.stringify(asks[0].website)}`)
  if (!(await page.locator('#invite-open').isVisible())) failures.push('waitlist: sign-up opened while the page was open, and the visitor was not offered it')
  if (await page.locator('#invite-done').isVisible()) failures.push('waitlist: told the visitor they are on a waitlist that kept nothing')
  if (await page.evaluate(() => localStorage.getItem('memex-invite-asked')) !== null) failures.push('waitlist: remembered an ask the server did not keep')

  const prompt = JSON.parse(readFileSync(join(landing, 'locales', 'en.json'), 'utf8')).index.hero.ask.prompt
  const asking = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] })
  const askPage = await asking.newPage()
  await askPage.goto(`${origin}/`)
  await askPage.click('.ask-ai summary')
  await askPage.click('[data-ask-copy]')
  await askPage.locator('[data-ask-copy] span', { hasText: 'Copied' }).waitFor({ timeout: 3000 }).catch(() => {})
  const pasted = await askPage.evaluate(() => navigator.clipboard.readText())
  if (pasted !== prompt) failures.push(`ask your AI: Copy prompt put ${JSON.stringify(pasted)} on the clipboard`)
  if ((await askPage.locator('[data-ask-copy] span').innerText()) !== 'Copied') failures.push('ask your AI: Copy prompt did not say Copied')
  await askPage.keyboard.press('Escape')
  if (await askPage.locator('.ask-ai').evaluate((menu) => menu.open)) failures.push('ask your AI: Escape left the menu open')
  await asking.close()

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
console.log('Public pages behaviour: a quick Send waits out the bot check; a late waitlist ask is offered sign-up; Copy prompt copies the prompt and Escape closes the menu; a failed docs index retries on the next search.')
