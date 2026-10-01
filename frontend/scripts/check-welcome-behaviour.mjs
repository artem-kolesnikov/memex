#!/usr/bin/env node
/**
 * The first-run wizard's state properties, asserted against the built SPA in
 * a browser with the API stubbed.
 *
 * Every fact the wizard shows comes from `/api/me/welcome`, and the failure
 * paths a healthy server never produces on request — a 500 on a check, an
 * older answer landing after a newer one, a save that is refused, a finish
 * that does not land — are exactly the ones a regex over the source cannot
 * see. So each scenario below stubs `/api/**`, drives the wizard and asserts
 * what a person would see. Needs `dist/`, no backend and no database.
 */
import { createServer } from 'node:http'
import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Welcome wizard behaviour check failed:\n')
  console.error('  dist/index.html is missing — run `npm run build-only` first.')
  process.exit(1)
}

const SHOTS = process.env.WELCOME_SHOTS ?? ''
if (SHOTS) mkdirSync(SHOTS, { recursive: true })
/** Run only the scenarios whose name contains this, while chasing one. */
const ONLY = process.env.WELCOME_ONLY ?? ''

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
const HANDLE_B = 'b3n8xq2vt6kc'
const WELCOME = `${BASE}/${HANDLE}/welcome`

const me = (patch = {}) => ({
  email: 'user@example.test', name: 'Test User',
  team: { name: 'Demo', handle: HANDLE },
  system_tags: [], appearance: {}, icon_key: null, icon: null,
  welcome_completed: false,
  ...patch,
})

const facts = (patch = {}) => ({
  connected: false, connection: null, last_seen: null, guide_read: false, curator: false, skills: 0, waiting: 0, kept: false,
  profiles: [],
  ...patch,
})

/**
 * The stub. `state` is mutable so a scenario can change what the server
 * says between two presses of the same button; `on` maps a method+path
 * prefix to a handler for the calls a scenario wants to shape.
 */
function apiStub(state, on = {}) {
  return async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const key = `${request.method()} ${url.pathname}`
    const json = (body, status = 200) =>
      route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })

    for (const [prefix, handler] of Object.entries(on)) {
      if (key.startsWith(prefix)) return handler(route, json, request)
    }
    if (key === 'GET /api/me') return json(me(state.me))
    if (key === 'GET /api/me/welcome') return json({ completed: state.completed, facts: facts(state.facts) })
    if (key === 'POST /api/me/welcome/done') {
      state.completed = true
      return json({ completed: true })
    }
    if (key === 'PATCH /api/me') {
      const body = request.postDataJSON()
      state.me = { ...state.me, name: body.name }
      state.saves.push(['name', body.name])
      return json(me(state.me))
    }
    if (key === 'PATCH /api/me/memex') {
      const body = request.postDataJSON()
      state.me = { ...state.me, team: { name: body.name, handle: HANDLE } }
      state.saves.push(['memex', body.name])
      return json(me(state.me))
    }
    if (key === 'POST /api/tokens') return json({ id: 9, name: request.postDataJSON().name, token: 'mxt_test' }, 201)
    if (key === 'GET /api/inbox/count') return json({ pending_notes: 0, edit_proposals: 0, total: 0 })
    if (key.startsWith('GET /api/notes')) return json({ notes: [], total: 0, tags: [], tag_counts: [], presets: [] })
    if (key.startsWith('GET /api/stats')) return json({ total: 0 })
    return json({}, 200)
  }
}

// ── harness ──────────────────────────────────────────────────────────────

const failures = []
const passed = []
const browser = await chromium.launch()

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

async function scenario(name, body, options = {}) {
  if (ONLY && !name.includes(ONLY)) return
  const context = await browser.newContext({
    viewport: options.viewport ?? { width: 1280, height: 720 },
    colorScheme: options.colorScheme ?? 'dark',
    permissions: options.clipboard === false ? [] : ['clipboard-read', 'clipboard-write'],
  })
  const state = { me: {}, facts: {}, completed: false, saves: [], ...(options.state ?? {}) }
  const page = await context.newPage()
  await page.route('**/api/**', apiStub(state, options.on ?? {}))
  const errors = []
  page.on('pageerror', (e) => errors.push(String(e)))
  try {
    await body(page, state, context)
    const relevant = errors.filter((e) => !e.includes('Missing required param "handle"'))
    assert(relevant.length === 0, `page errors: ${relevant.join(' | ')}`)
    passed.push(name)
    console.log(`  ok   ${name}`)
  } catch (e) {
    failures.push(`${name}: ${e.message}`)
    console.log(`  FAIL ${name}: ${e.message}`)
  } finally {
    await context.close()
  }
}

const primary = (page) => page.locator('.mm-wiz-footer .mm-wiz-button').last()
const status = (page) => page.locator('.mm-wiz-status strong')
const current = (page) => page.locator('.mm-wiz-progress [aria-current="step"]')
const openWizard = async (page) => {
  await page.goto(WELCOME)
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.waitForTimeout(300)
}
const shot = async (page, name) => {
  if (SHOTS) await page.screenshot({ path: join(SHOTS, `${name}.png`) })
}
const profileTitle = (page) => page.locator('#welcome-title')
/** Finish setup now leads to the two optional profile screens; this walks past them without saving. */
const throughProfile = async (page) => {
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), `expected the profile screen: ${await profileTitle(page).textContent()}`)
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.waitForTimeout(150)
  assert((await profileTitle(page).textContent()).includes('Let your assistant add more'), `expected the assistant screen: ${await profileTitle(page).textContent()}`)
  await primary(page).click()
  await page.waitForTimeout(150)
}

// ── scenarios ────────────────────────────────────────────────────────────

await scenario('a failed name save keeps the draft, says so, and a retry lands', async (page, state) => {
  let fail = true
  await page.route('**/api/me', (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback()
    if (fail) return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
    return route.fallback()
  })
  await openWizard(page)
  await page.fill('#wiz-name', 'Alexandra')
  await page.locator('#wiz-name').blur()
  await page.waitForTimeout(300)
  assert((await page.inputValue('#wiz-name')) === 'Alexandra', 'the draft was discarded after the failed save')
  assert(await page.locator('#wiz-name[aria-invalid="true"]').count() === 1, 'the field does not say it failed')
  assert(state.saves.length === 0, 'the stub recorded a save it refused')
  await primary(page).click()
  await page.waitForTimeout(300)
  assert((await current(page).textContent()).includes('Your space'), 'Next moved on over an unsaved name')
  fail = false
  await page.locator('#wiz-name').focus()
  await page.locator('#wiz-name').blur()
  await page.waitForTimeout(300)
  assert(JSON.stringify(state.saves) === '[["name","Alexandra"]]', `retry did not save: ${JSON.stringify(state.saves)}`)
  assert(await page.locator('#wiz-name[aria-invalid="true"]').count() === 0, 'the error did not clear')
})

await scenario('name then memex name then Next: both saves land, in order, before the step changes', async (page, state) => {
  await page.route('**/api/me', async (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback()
    await new Promise((r) => setTimeout(r, 400))
    return route.fallback()
  })
  await openWizard(page)
  await page.fill('#wiz-name', 'Alexandra')
  await page.fill('#wiz-memex', 'Garden notes')
  await primary(page).click()
  assert((await current(page).textContent()).includes('Your space'), 'the step changed before the saves landed')
  await page.waitForFunction(() => document.querySelector('.mm-wiz-progress [aria-current="step"]')?.textContent.includes('Connect'), null, { timeout: 3000 })
  assert(JSON.stringify(state.saves) === '[["name","Alexandra"],["memex","Garden notes"]]', `saves: ${JSON.stringify(state.saves)}`)
})

await scenario('the theme radios repaint the app and the header toggle flips it back', async (page) => {
  await openWizard(page)
  assert((await page.getAttribute('html', 'data-bs-theme')) === 'dark', 'did not open in the browser theme')
  await page.check('input[name="wiz-theme"][value="light"]')
  await page.waitForTimeout(100)
  assert((await page.getAttribute('html', 'data-bs-theme')) === 'light', 'the radio did not repaint the app')
  await shot(page, 'your-space-sunrise')
  await page.locator('.mm-wiz-header-meta .mm-wiz-icon-button').first().click()
  await page.waitForTimeout(100)
  assert((await page.getAttribute('html', 'data-bs-theme')) === 'dark', 'the header toggle did not flip the theme')
  assert(await page.isChecked('input[name="wiz-theme"][value="dark"]'), 'the radio did not follow the toggle')
})

await scenario('a failed check is an error with a retry, not "not yet"', async (page, state) => {
  state.facts = { connected: false }
  let fail = true
  await page.route('**/api/me/welcome', (route) => {
    if (fail && route.request().method() === 'GET' && state.opened) {
      return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"down"}' })
    }
    return route.fallback()
  })
  await openWizard(page)
  state.opened = true
  await page.locator('.mm-wiz-progress button').nth(2).click()
  await page.waitForTimeout(200)
  await shot(page, 'test-it-ready')
  await primary(page).click()
  await page.waitForTimeout(300)
  assert((await status(page).textContent()).includes('Couldn’t check'), `after a 500: ${await status(page).textContent()}`)
  assert((await primary(page).textContent()).includes('Check again'), 'no retry offered after a failed check')
  await shot(page, 'test-it-error')
  fail = false
  await primary(page).click()
  await page.waitForTimeout(300)
  assert((await status(page).textContent()).includes('No reply'), `after a clean "not yet": ${await status(page).textContent()}`)
  await shot(page, 'test-it-waiting')
})

await scenario('an older answer landing second does not overwrite the newer one, nor move the step', async (page, state) => {
  // The opening read is slow and says "not connected"; the person walks to
  // the test and presses Check, which answers fast and says "connected".
  // The slow answer then lands and must change nothing.
  let calls = 0
  await page.route('**/api/me/welcome', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback()
    calls++
    if (calls === 1) {
      await new Promise((r) => setTimeout(r, 1200))
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ completed: false, facts: facts({ connected: false }) }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ completed: false, facts: facts({ connected: true, connection: 'Claude', last_seen: '2026-09-17T10:00:00+00:00' }) }) })
  })
  await page.goto(WELCOME)
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.locator('.mm-wiz-progress button').nth(2).click()
  await page.waitForTimeout(100)
  await primary(page).click()
  await page.waitForTimeout(300)
  assert((await status(page).textContent()).includes('Claude reached memex'), `the fast check did not show: ${await status(page).textContent()}`)
  await page.waitForTimeout(1500)
  assert((await status(page).textContent()).includes('Claude reached memex'), `the slow "not yet" overwrote the connection: ${await status(page).textContent()}`)
  assert((await current(page).textContent()).includes('Test it'), `the slow open moved the step: ${await current(page).textContent()}`)
})

await scenario('a connected account names the server\'s connection and unlocks First chat', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', last_seen: '2026-09-17T10:00:00+00:00' }
  await openWizard(page)
  assert((await current(page).textContent()).includes('Test it'), `did not resume on the test: ${await current(page).textContent()}`)
  assert((await status(page).textContent()).includes('Claude reached memex'), `did not name the connection: ${await status(page).textContent()}`)
  assert((await page.getAttribute('.mm-wiz-progress li:nth-child(4) button', 'aria-disabled')) === null, 'First chat stayed locked with a connection')
  await shot(page, 'test-it-success')
  await primary(page).click()
  await page.waitForTimeout(200)
  assert((await current(page).textContent()).includes('First chat'), 'Give it instructions did not move on')
  await shot(page, 'first-chat')
})

await scenario('without a connection First chat is locked and Finish is never offered', async (page, state) => {
  state.facts = { connected: false, guide_read: false }
  await openWizard(page)
  assert((await page.getAttribute('.mm-wiz-progress li:nth-child(4) button', 'aria-disabled')) === 'true', 'First chat is reachable without a connection')
  await page.evaluate(() => document.querySelector('.mm-wiz-progress li:nth-child(4) button').click())
  await page.waitForTimeout(200)
  assert((await current(page).textContent()).includes('Your space'), 'a locked rail step still navigated')
})

await scenario('the guide read by a different connection is still a read, and the copy stays neutral', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', guide_read: true, last_seen: '2026-09-17T10:00:00+00:00' }
  await openWizard(page)
  assert((await current(page).textContent()).includes('First chat'), `did not resume on First chat: ${await current(page).textContent()}`)
  const said = await status(page).textContent()
  assert(said.includes('The guide has been read') && !said.includes('Claude'), `attributed the read to the latest caller: ${said}`)
  assert((await primary(page).textContent()).includes('Finish setup'), 'Finish not offered with a recorded read')
  await shot(page, 'first-chat-read')
  await primary(page).click()
  await page.waitForTimeout(200)
  assert(await page.locator('.mm-wiz-completion-checks').count() === 0, 'finish screen shown before the profile screens')
  await shot(page, 'profile')
  await throughProfile(page)
  assert(await page.locator('.mm-wiz-completion-checks').count() === 1, 'no finish screen')
  await shot(page, 'finished')
})

await scenario('a refused clipboard still leads to the check, and copying never counts as reading', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', guide_read: false }
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await page.evaluate(() => {
    navigator.clipboard.writeText = () => Promise.reject(new Error('denied'))
  })
  assert((await primary(page).textContent()).includes('Copy instructions'), 'did not start on Copy')
  await primary(page).click()
  await page.waitForTimeout(300)
  assert((await primary(page).textContent()).includes('Check guide read'), `no route to the check after a refused copy: ${await primary(page).textContent()}`)
  assert(await page.locator('.mm-wiz-prompt-text').count() === 1, 'the prompt is not on screen to copy by hand')
  await primary(page).click()
  await page.waitForTimeout(300)
  assert((await status(page).textContent()).includes('hasn’t been read'), `copying counted as reading: ${await status(page).textContent()}`)
  await shot(page, 'first-chat-not-yet')
}, { clipboard: false })

await scenario('the copy icon in the prompt box counts as copying: the footer turns into the check', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude' }
  await openWizard(page)
  await page.locator('.mm-wiz-progress button').nth(3).click()
  await page.waitForTimeout(200)
  assert((await primary(page).textContent()).includes('Copy instructions'), 'precondition')
  await page.locator('.mm-wiz-prompt-heading .mm-wiz-copy-button').click()
  await page.waitForTimeout(200)
  assert((await primary(page).textContent()).includes('Check guide read'), `the icon did not count as a copy: ${await primary(page).textContent()}`)
  assert((await page.evaluate(() => navigator.clipboard.readText())).includes('memex guide'), 'the prompt was not on the clipboard')
})

const providersStub = (route) =>
  route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ providers: [] }) })

// A provider sign-in is a full page load. The session check is the one way a
// second account reaches a tab that still holds the first account's store.
/** Signs in again in the same tab and goes to `path` without a reload, so every store keeps what it held. */
async function signInAgain(page, path = `/${HANDLE}/notes`) {
  await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').check())
  await page.evaluate((path) => {
    const s = history.state ?? {}
    history.pushState({ ...s, back: s.current ?? null, current: path, forward: null, position: (s.position ?? 0) + 1, replaced: false, scroll: null }, '', path)
    window.dispatchEvent(new PopStateEvent('popstate', { state: history.state }))
  }, path)
}

await scenario('a token minted from Settings by one account is not shown to the next one, with the wizard never opened', async (page, state) => {
  state.me = { welcome_completed: true }
  let who = 1
  await page.route('**/api/tokens', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    await new Promise((r) => setTimeout(r, 1500))
    return route.fallback()
  })
  await page.route('**/api/auth/providers', providersStub)
  await page.route('**/api/logout', (route) => {
    who = 0
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' })
  })
  await page.goto(`${BASE}/${HANDLE}/settings/connections`)
  await page.waitForSelector('.mm-connect-guide')
  await page.locator('.mm-wiz-provider').nth(3).click()
  await page.fill('#wiz-agent-name', 'My script')
  await page.locator('.mm-wiz-custom .mm-wiz-button').click()
  await page.locator('.btn-close').first().click()
  await page.waitForTimeout(200)
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.waitForSelector('.mm-login', { timeout: 5000 })
  assert(who === 0, 'sign-out did not reach the server')
  state.me = { name: 'Blake', team: { name: 'Second', handle: HANDLE_B }, welcome_completed: true }
  await signInAgain(page)
  await page.waitForFunction(() => location.pathname.endsWith('/notes'), null, { timeout: 5000 })
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu a[role="menuitem"][href$="/settings"]').click()
  await page.locator('.app-settings-nav-item[href$="/connections"]').click()
  await page.waitForSelector('.mm-connect-guide')
  await page.waitForTimeout(1800)
  assert(await page.locator('#new-token-title').count() === 0, 'B was shown A\'s token in Settings')
}, { on: { 'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }) } })

await scenario('a failed finish stays on the finish screen with a retry, and the retry leaves', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', guide_read: true }
  let fail = true
  await page.route('**/api/me/welcome/done', (route) => {
    if (fail) return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
    return route.fallback()
  })
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await throughProfile(page)
  await primary(page).click()
  await page.waitForTimeout(400)
  assert(page.url().endsWith('/welcome'), 'navigated away although the finish did not land')
  assert((await page.locator('.mm-wiz-footer-note').textContent()).includes('could not save'), 'the failed finish is not visible')
  assert((await primary(page).textContent()).includes('Retry'), 'no retry after a failed finish')
  fail = false
  await primary(page).click()
  await page.waitForFunction(() => location.pathname.endsWith('/notes'), null, { timeout: 3000 })
  assert(state.completed, 'the retry did not write the stamp')
})

await scenario('a cross-tab completion is picked up and leaving does not bounce back', async (page, state) => {
  state.facts = { connected: false }
  await openWizard(page)
  state.completed = true
  await page.locator('.mm-wiz-progress button').nth(2).click()
  await primary(page).click()
  await page.waitForTimeout(300)
  await page.locator('.mm-wiz-header-meta .mm-wiz-icon-button').nth(1).click()
  await page.waitForTimeout(200)
  await primary(page).click()
  await page.waitForFunction(() => location.pathname.endsWith('/notes'), null, { timeout: 3000 })
  await page.waitForTimeout(300)
  assert(page.url().endsWith('/notes'), `bounced: ${page.url()}`)
})

await scenario('the cursor survives a provider switch and a reload', async (page, state) => {
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await page.locator('.mm-wiz-guide-dot').nth(2).click()
  await page.locator('.mm-wiz-provider').nth(1).click()
  await page.locator('.mm-wiz-guide-dot').nth(1).click()
  await page.locator('.mm-wiz-provider').nth(0).click()
  await page.waitForTimeout(100)
  assert((await page.locator('.mm-wiz-guide-dot[aria-current="step"]').textContent()) === '3', 'ChatGPT lost its instruction on a switch')
  await page.reload()
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.waitForTimeout(300)
  assert((await current(page).textContent()).includes('Connect'), `reload lost the step: ${await current(page).textContent()}`)
  assert((await page.locator('.mm-wiz-provider[aria-pressed="true"]').textContent()).includes('ChatGPT'), 'reload lost the provider')
  assert((await page.locator('.mm-wiz-guide-dot[aria-current="step"]').textContent()) === '3', 'reload lost the instruction')
  await page.locator('.mm-wiz-provider').nth(1).click()
  assert((await page.locator('.mm-wiz-guide-dot[aria-current="step"]').textContent()) === '2', 'reload lost the other provider\'s instruction')
  await shot(page, 'connect-claude-2')
})

await scenario('Back walks the instructions, then the steps; Back from the test returns to the last instruction', async (page) => {
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await primary(page).click()
  await primary(page).click()
  assert((await page.locator('.mm-wiz-guide-dot[aria-current="step"]').textContent()) === '3', 'Next did not advance the instruction')
  await page.locator('.mm-wiz-text-button.is-back').click()
  assert((await page.locator('.mm-wiz-guide-dot[aria-current="step"]').textContent()) === '2', 'Back did not step back an instruction')
  await page.locator('.mm-wiz-guide-dot').nth(3).click()
  await page.waitForTimeout(100)
  assert(await page.locator('.mm-wiz-optional-actions').count() === 1, 'the optional instruction has no second exit')
  await shot(page, 'connect-chatgpt-optional')
  await page.locator('.mm-wiz-optional-actions .mm-wiz-text-button').click()
  await page.waitForTimeout(200)
  assert((await current(page).textContent()).includes('Test it'), 'Skip this step did not reach the test')
  await page.locator('.mm-wiz-text-button.is-back').click()
  await page.waitForTimeout(200)
  assert((await current(page).textContent()).includes('Connect'), 'Back from the test did not return to Connect')
  assert((await page.locator('.mm-wiz-guide-dot[aria-current="step"]').textContent()) === '4', 'Back from the test lost the instruction')
  await page.locator('.mm-wiz-text-button.is-back').click()
  await page.locator('.mm-wiz-text-button.is-back').click()
  await page.locator('.mm-wiz-text-button.is-back').click()
  await page.locator('.mm-wiz-text-button.is-back').click()
  await page.waitForTimeout(200)
  assert((await current(page).textContent()).includes('Your space'), 'Back from the first instruction did not return to Your space')
})

await scenario('a screenshot enlarges, closes on Escape, hands focus back, and leaves the wizard open', async (page) => {
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await page.locator('.mm-wiz-thumbnail').first().click()
  await page.waitForSelector('dialog.mm-wiz-image-dialog[open]')
  assert((await page.locator('dialog.mm-wiz-image-dialog img').getAttribute('alt')).length > 10, 'the enlarged image has no alt text')
  await shot(page, 'connect-screenshot-open')
  await page.keyboard.press('Escape')
  await page.waitForTimeout(200)
  assert(await page.locator('dialog.mm-wiz-image-dialog').count() === 0, 'Escape did not close the picture')
  assert(await page.locator('.modal.show .mm-wiz').count() === 1, 'Escape closed the wizard')
  assert(await page.evaluate(() => document.activeElement?.classList.contains('mm-wiz-thumbnail')), 'focus did not return to the thumbnail')
  await page.locator('.mm-wiz-thumbnail').first().click()
  await page.waitForSelector('dialog.mm-wiz-image-dialog[open]')
  await page.mouse.click(5, 5)
  await page.waitForTimeout(200)
  assert(await page.locator('dialog.mm-wiz-image-dialog').count() === 0, 'an outside click did not close the picture')
  assert(await page.locator('.modal.show .mm-wiz').count() === 1, 'the outside click closed the wizard')
})

await scenario('the token flow shows the secret once and the address, and counts nothing as a connection', async (page, state) => {
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await page.locator('.mm-wiz-provider').nth(3).click()
  await page.fill('#wiz-agent-name', 'My script')
  await page.locator('.mm-wiz-custom .mm-wiz-button').click()
  await page.waitForSelector('.modal.show .mm-address code')
  assert((await page.locator('.modal.show .mm-address code').last().textContent()) === 'mxt_test', 'the token is not shown')
  await shot(page, 'connect-other-token')
  await page.locator('.modal.show .modal-footer .btn').last().click()
  await page.waitForTimeout(400)
  await primary(page).click()
  await page.waitForTimeout(200)
  assert((await current(page).textContent()).includes('Test it'), 'Test connection did not reach the test')
  await primary(page).click()
  await page.waitForFunction(() => !document.querySelector('.mm-wiz-status strong')?.textContent.includes('Ready when you are'), null, { timeout: 5000 })
  assert((await status(page).textContent()).includes('No reply'), `a minted token counted as a connection: ${await status(page).textContent()}`)
})

await scenario('typing the old name back over an unanswered rename sends it again, and Next waits', async (page, state) => {
  await page.route('**/api/me', async (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback()
    await new Promise((r) => setTimeout(r, 500))
    return route.fallback()
  })
  await openWizard(page)
  await page.fill('#wiz-name', 'Alexandra')
  await page.locator('#wiz-name').blur()
  await page.fill('#wiz-name', 'Test User')
  await primary(page).click()
  await page.waitForFunction(() => document.querySelector('.mm-wiz-progress [aria-current="step"]')?.textContent.includes('Connect'), null, { timeout: 4000 })
  assert(JSON.stringify(state.saves) === '[["name","Alexandra"],["name","Test User"]]', `saves: ${JSON.stringify(state.saves)}`)
  assert(state.me.name === 'Test User', `the account ended up as ${state.me.name}`)
})

await scenario('the rail cannot leave Your space over a refused save, and the draft survives', async (page, state) => {
  let fail = true
  await page.route('**/api/me', (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback()
    if (fail) return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
    return route.fallback()
  })
  await openWizard(page)
  await page.fill('#wiz-name', 'Alexandra')
  await page.locator('.mm-wiz-progress button').nth(1).click()
  await page.waitForTimeout(400)
  assert((await current(page).textContent()).includes('Your space'), 'the rail left over a refused save')
  assert((await page.inputValue('#wiz-name')) === 'Alexandra', 'the draft was lost')
  fail = false
  await page.locator('.mm-wiz-progress button').nth(1).click()
  await page.waitForTimeout(400)
  assert((await current(page).textContent()).includes('Connect'), 'the rail did not move once the save landed')
  assert(JSON.stringify(state.saves) === '[["name","Alexandra"]]', `saves: ${JSON.stringify(state.saves)}`)
})

await scenario('a remembered First chat cursor is pulled back to Test it when the connection is gone, with no tick', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', guide_read: false }
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  assert((await current(page).textContent()).includes('First chat'), 'precondition: not on First chat')
  state.facts = { connected: false }
  await page.reload()
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.waitForTimeout(400)
  assert((await current(page).textContent()).includes('Test it'), `resumed on ${await current(page).textContent()} without a connection`)
  assert(await page.locator('.mm-wiz-progress li:nth-child(3) button.is-past').count() === 0, 'Test it is ticked without a connection')
  assert((await page.getAttribute('.mm-wiz-progress li:nth-child(4) button', 'aria-disabled')) === 'true', 'First chat stayed reachable')
})

await scenario('a success on screen goes back to waiting when a later read says the connection is gone', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', guide_read: false }
  await openWizard(page)
  assert((await status(page).textContent()).includes('Claude reached memex'), 'precondition: not connected')
  await primary(page).click()
  await page.waitForTimeout(200)
  await primary(page).click()
  await page.waitForTimeout(200)
  assert((await primary(page).textContent()).includes('Check guide read'), 'precondition: not at the check')
  state.facts = { connected: false }
  await primary(page).click()
  await page.waitForTimeout(400)
  assert((await current(page).textContent()).includes('Test it'), `a lost connection left the person on ${await current(page).textContent()}`)
  assert((await status(page).textContent()).includes('No reply'), `the stale success survived: ${await status(page).textContent()}`)
})

await scenario('a token minted while the person switches provider is still shown once', async (page) => {
  await page.route('**/api/tokens', async (route) => {
    await new Promise((r) => setTimeout(r, 700))
    return route.fallback()
  })
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await page.locator('.mm-wiz-provider').nth(3).click()
  await page.fill('#wiz-agent-name', 'My script')
  await page.locator('.mm-wiz-custom .mm-wiz-button').click()
  await page.waitForTimeout(100)
  await page.locator('.mm-wiz-provider').nth(1).click()
  await primary(page).click()
  await page.waitForSelector('.modal.show .mm-address code', { timeout: 3000 })
  assert((await page.locator('.modal.show .mm-address code').last().textContent()) === 'mxt_test', 'the token was lost with the tab')
})

await scenario('a new account opens on the page it asked for, never on the wizard', async (page) => {
  await page.goto(`${BASE}/${HANDLE}/inbox`)
  await page.waitForTimeout(600)
  assert(page.url().endsWith(`/${HANDLE}/inbox`), `a new account was sent elsewhere: ${page.url()}`)
  assert(await page.locator('.mm-wiz').count() === 0, 'the wizard opened on its own')
})

await scenario('a failed opening read is shown with a retry, and the retry lands', async (page, state) => {
  let fail = true
  await page.route('**/api/me/welcome', (route) => {
    if (fail && route.request().method() === 'GET') return route.fulfill({ status: 503, contentType: 'application/json', body: '{"error":"down"}' })
    return route.fallback()
  })
  await page.goto(WELCOME)
  await page.waitForSelector('.mm-wiz-open-failed', { timeout: 3000 })
  fail = false
  state.facts = { connected: true, connection: 'Claude' }
  await page.locator('.mm-wiz-open-failed .mm-wiz-text-button').click()
  await page.waitForTimeout(400)
  assert(await page.locator('.mm-wiz-open-failed').count() === 0, 'the error stayed after a successful retry')
  assert((await current(page).textContent()).includes('Test it'), `the retry did not place the person: ${await current(page).textContent()}`)
})

await scenario('a slow opening read does not undo where a completed account has already navigated', async (page, state) => {
  state.completed = true
  state.me = { welcome_completed: true }
  await page.route('**/api/me/welcome', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback()
    await new Promise((r) => setTimeout(r, 900))
    return route.fallback()
  })
  await page.goto(WELCOME)
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.locator('.mm-wiz-progress button').nth(1).click()
  await page.waitForTimeout(100)
  await page.locator('.mm-wiz-provider').nth(1).click()
  await page.waitForTimeout(1200)
  assert((await current(page).textContent()).includes('Connect'), `the slow read reset the step: ${await current(page).textContent()}`)
  assert((await page.locator('.mm-wiz-provider[aria-pressed="true"]').textContent()).includes('Claude'), 'the slow read reset the provider')
})

await scenario('coming back to the window re-reads the facts without moving the step', async (page, state) => {
  state.facts = { connected: false }
  await openWizard(page)
  await page.locator('.mm-wiz-progress button').nth(2).click()
  await page.waitForTimeout(100)
  await primary(page).click()
  await page.waitForFunction(() => document.querySelector('.mm-wiz-status strong')?.textContent.includes('No reply'), null, { timeout: 3000 })
    .catch(() => assert(false, 'precondition'))
  state.facts = { connected: true, connection: 'Claude' }
  await page.evaluate(() => window.dispatchEvent(new Event('focus')))
  await page.waitForFunction(() => document.querySelector('.mm-wiz-status strong')?.textContent.includes('Claude reached memex'), null, { timeout: 3000 })
    .catch(async () => assert(false, `focus did not refresh: ${await status(page).textContent()}`))
  assert((await current(page).textContent()).includes('Test it'), 'the refresh moved the step')
})

await scenario('a curator connection gets the sentence that is true of it', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', curator: true }
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  assert((await page.locator('.mm-wiz-review-reminder').textContent()).includes('curator'), 'the agent-role sentence was shown for a curator')
})

await scenario('another account signing in on the same tab does not inherit the first account\'s facts', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', guide_read: true }
  let who = 1
  // B's own read is slow, so the first thing B sees is whatever the store kept.
  await page.route('**/api/me/welcome', async (route) => {
    if (route.request().method() !== 'GET' || who !== 2) return route.fallback()
    await new Promise((r) => setTimeout(r, 900))
    return route.fallback()
  })
  await page.route('**/api/auth/providers', providersStub)
  await page.route('**/api/logout', (route) => {
    who = 0
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' })
  })
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await throughProfile(page)
  await primary(page).click()
  await page.waitForTimeout(400)
  assert(state.completed, 'precondition: A did not finish')
  await page.waitForFunction(() => location.pathname.endsWith('/notes'), null, { timeout: 3000 })
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.waitForSelector('.mm-login', { timeout: 5000 })
  assert(who === 0, 'sign-out did not reach the server')
  who = 2
  state.me = { name: 'Blake', team: { name: 'Second', handle: HANDLE_B }, welcome_completed: false }
  state.facts = { connected: false, guide_read: false }
  state.completed = false
  await signInAgain(page, `/${HANDLE_B}/welcome`)
  await page.waitForSelector('.modal.show .mm-wiz', { timeout: 5000 })
  await page.waitForTimeout(100)
  assert((await current(page).textContent()).includes('Your space'), `B opened on ${await current(page).textContent()}`)
  assert(await page.locator('.mm-wiz-completion-checks').count() === 0, 'B saw A\'s finish screen')
  assert(await page.locator('.mm-wiz-progress button.is-past').count() === 0, 'B saw A\'s ticks')
  await page.locator('.mm-wiz-progress button').nth(2).click()
  await page.waitForTimeout(100)
  assert(!(await status(page).textContent()).includes('Claude'), `B saw A's connection before B's read: ${await status(page).textContent()}`)
  await page.waitForTimeout(1200)
  assert(!(await status(page).textContent()).includes('Claude'), `B saw A's connection after B's read: ${await status(page).textContent()}`)
})

await scenario('a token minted by the first account is not shown to the next one, even when the first account\'s reads failed', async (page, state) => {
  let who = 1
  await page.route('**/api/me/welcome', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback()
    if (who === 1) return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
    await new Promise((r) => setTimeout(r, 900))
    return route.fallback()
  })
  await page.route('**/api/auth/providers', providersStub)
  await page.route('**/api/logout', (route) => {
    who = 0
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' })
  })
  await openWizard(page)
  assert(await page.locator('.mm-wiz-open-failed').count() === 1, 'precondition: the opening read did not fail')
  await page.locator('.mm-wiz-progress button').nth(1).click()
  await page.waitForTimeout(200)
  await page.locator('.mm-wiz-provider').nth(3).click()
  await page.fill('#wiz-agent-name', 'My script')
  await page.locator('.mm-wiz-custom .mm-wiz-button').click()
  await page.waitForSelector('#new-token-title')
  await page.waitForTimeout(400)
  // Leaving with the token dialog still up: the wizard's own buttons are
  // under it, so the clicks are dispatched rather than aimed.
  await page.locator('.mm-wiz-header .mm-wiz-icon-button').nth(1).dispatchEvent('click')
  await page.waitForTimeout(100)
  await primary(page).dispatchEvent('click')
  await page.waitForFunction(() => location.pathname.endsWith('/notes'), null, { timeout: 3000 })
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.waitForSelector('.mm-login', { timeout: 5000 })
  who = 2
  state.me = { name: 'Blake', team: { name: 'Second', handle: HANDLE_B }, welcome_completed: false }
  state.facts = {}
  state.completed = false
  await signInAgain(page, `/${HANDLE_B}/welcome`)
  await page.waitForSelector('.modal.show .mm-wiz', { timeout: 5000 })
  await page.waitForTimeout(100)
  assert(await page.locator('#new-token-title').count() === 0, 'B was shown A\'s token before B\'s read')
  await page.waitForTimeout(1200)
  assert(await page.locator('#new-token-title').count() === 0, 'B was shown A\'s token after B\'s read')
})

await scenario('a finish that fails after another account signs in does not mark the next account as unfinished', async (page, state) => {
  state.facts = { connected: true, connection: 'Claude', guide_read: true }
  await page.route('**/api/me/welcome/done', async (route) => {
    await new Promise((r) => setTimeout(r, 1500))
    return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
  })
  await page.route('**/api/auth/providers', providersStub)
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await throughProfile(page)
  await primary(page).click()
  await page.waitForTimeout(100)
  // Public sign-in remains reachable while completion is pending. Private
  // routes must keep the wizard until the server confirms completion.
  await page.evaluate((path) => {
    const s = history.state ?? {}
    history.pushState({ ...s, back: s.current ?? null, current: path, forward: null, position: (s.position ?? 0) + 1, replaced: false, scroll: null }, '', path)
    window.dispatchEvent(new PopStateEvent('popstate', { state: history.state }))
  }, '/login')
  await page.waitForFunction(() => document.querySelector('.modal.show .mm-wiz') === null, null, { timeout: 3000 })
  await page.waitForSelector('.mm-login', { timeout: 5000 })
  state.me = { name: 'Blake', team: { name: 'Second', handle: HANDLE_B }, welcome_completed: false }
  state.facts = {}
  state.completed = false
  await signInAgain(page, `/${HANDLE_B}/welcome`)
  await page.waitForSelector('.modal.show .mm-wiz', { timeout: 5000 })
  await page.waitForTimeout(1800)
  assert(!(await page.locator('.mm-wiz-footer-note').textContent()).includes('could not save'), 'A\'s failed finish was shown to B')
  assert((await current(page).textContent()).includes('Your space'), `B was moved: ${await current(page).textContent()}`)
})

await scenario('a failed check that lands after a newer successful read does not overwrite it', async (page, state) => {
  let calls = 0
  await page.route('**/api/me/welcome', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback()
    calls++
    if (calls !== 2) return route.fallback()
    await new Promise((r) => setTimeout(r, 800))
    return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
  })
  await openWizard(page)
  await page.locator('.mm-wiz-progress button').nth(2).click()
  await page.waitForTimeout(100)
  await primary(page).click()
  await page.waitForTimeout(100)
  state.facts = { connected: true, connection: 'Claude' }
  await page.evaluate(() => window.dispatchEvent(new Event('focus')))
  await page.waitForTimeout(300)
  assert((await status(page).textContent()).includes('Claude reached memex'), `the newer read did not land: ${await status(page).textContent()}`)
  await page.waitForTimeout(800)
  assert((await status(page).textContent()).includes('Claude reached memex'), `the older failure overwrote it: ${await status(page).textContent()}`)
})

await scenario('typing on while Next waits for a save is saved too, before the step changes', async (page, state) => {
  await page.route('**/api/me', async (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback()
    await new Promise((r) => setTimeout(r, 600))
    return route.fallback()
  })
  await openWizard(page)
  await page.fill('#wiz-name', 'Alexandra')
  await primary(page).click()
  await page.waitForTimeout(100)
  await page.fill('#wiz-name', 'Alexandra Test')
  await page.waitForFunction(() => document.querySelector('.mm-wiz-progress [aria-current="step"]')?.textContent.includes('Connect'), null, { timeout: 4000 })
  assert(JSON.stringify(state.saves) === '[["name","Alexandra"],["name","Alexandra Test"]]', `saves: ${JSON.stringify(state.saves)}`)
})

await scenario('a save still out across a pause keeps its place in the queue, and the draft comes back', async (page, state) => {
  await page.route('**/api/me/memex', async (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback()
    await new Promise((r) => setTimeout(r, route.request().postDataJSON().name === 'Older' ? 1200 : 100))
    return route.fallback()
  })
  await openWizard(page)
  await page.fill('#wiz-memex', 'Older')
  await page.locator('.mm-wiz-header .mm-wiz-icon-button').nth(1).click()
  await page.waitForTimeout(100)
  await page.locator('.mm-wiz-completion .mm-wiz-button').click()
  await page.waitForTimeout(100)
  assert((await page.inputValue('#wiz-memex')) === 'Older', `resume forgot the draft still being saved: ${await page.inputValue('#wiz-memex')}`)
  await page.fill('#wiz-memex', 'Latest')
  await page.locator('#wiz-memex').blur()
  await page.waitForTimeout(1800)
  assert(JSON.stringify(state.saves) === '[["memex","Older"],["memex","Latest"]]', `saves: ${JSON.stringify(state.saves)}`)
  assert(state.me.team.name === 'Latest', `the older save won: ${state.me.team.name}`)
})

// ── the profile screens ───────────────────────────────────────────────────

const READY = { connected: true, connection: 'Claude', guide_read: true }
const SHORT = 'Lead with the answer and keep it short.'
const KEEP_OUT = 'Keep personal details out of notes unless I ask for them to be kept.'
const option = (page, line) => page.locator('.mm-wiz-profile-option').filter({ has: page.locator(`input[value=${JSON.stringify(line)}]`) })

await scenario('continuing without profile preferences writes nothing and the message remains optional', async (page, state) => {
  state.facts = READY
  const writes = []
  await page.route('**/api/notes', (route) => {
    if (route.request().method() === 'POST') writes.push(route.request().postDataJSON())
    return route.fallback()
  })
  await openWizard(page)
  await primary(page).click()
  await page.getByRole('button', { name: 'Continue', exact: true }).click()
  await page.waitForTimeout(150)
  assert((await profileTitle(page).textContent()).includes('Let your assistant add more'), 'Continue did not advance')
  assert(writes.length === 0, 'Continue created an empty profile')
  await page.getByRole('button', { name: 'Back', exact: true }).click()
  await page.waitForTimeout(150)
  assert(await page.locator('.mm-wiz-profile-option input:checked').count() === 0, 'Back invented preferences')
  await page.getByRole('button', { name: 'Continue', exact: true }).click()
  await primary(page).click()
  assert(await page.locator('.mm-wiz-completion-checks').count() === 1, 'finish required the prompt to be sent')
  assert(writes.length === 0, 'finishing created an empty profile')
})

await scenario('skipping the profile creates no note and reaches the finish screen', async (page, state) => {
  state.facts = READY
  const writes = []
  await page.route('**/api/notes', (route) => {
    if (route.request().method() === 'POST') writes.push(route.request().postDataJSON())
    return route.fallback()
  })
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'Finish setup did not lead to the profile screen')
  assert(await page.locator('.mm-wiz-profile-option input:checked').count() === 0, 'a preference was preselected')
  assert(await primary(page).isEnabled() && (await primary(page).textContent()).includes('Continue'), 'an empty selection must offer Continue without saving')
  assert(await page.locator('.mm-wiz-progress button.is-past').count() === 4, 'the rail does not show the four steps done')
  await page.getByRole('button', { name: 'Skip profile setup' }).click()
  await page.waitForTimeout(200)
  assert(await page.locator('.mm-wiz-completion-checks').count() === 1, 'skip did not reach the finish screen')
  assert(writes.length === 0, 'skipping wrote a note')
  await primary(page).click()
  await page.waitForFunction(() => location.pathname.endsWith('/notes'), null, { timeout: 3000 })
  assert(state.completed, 'leaving after the skip did not write the stamp')
})

await scenario('Your profile is the fifth rail item, reachable with no connection, and ticked only by finishing', async (page, state) => {
  state.facts = { connected: false }
  await openWizard(page)
  assert(await page.locator('.mm-wiz-progress button').count() === 5, 'the rail does not show five items')
  await page.locator('.mm-wiz-progress button').nth(4).click()
  await page.waitForTimeout(200)
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'the fifth item did not open the profile screen')
  assert((await current(page).textContent()).includes('Your profile'), 'the rail does not mark the profile as current')
  const ticked = await page.locator('.mm-wiz-progress button.is-past').allTextContents()
  assert(ticked.length === 1 && ticked[0].includes('Your space'), `only the step left behind may be ticked: ${JSON.stringify(ticked)}`)
  await page.getByRole('button', { name: 'Skip profile setup' }).click()
  await page.waitForTimeout(200)
  assert(await page.locator('.mm-wiz-completion-checks').count() === 1, 'skip did not reach the finish screen')
})

await scenario('saving preferences writes exactly the chosen lines, tagged, and the assistant screen follows', async (page, state) => {
  state.facts = READY
  const writes = []
  await page.route('**/api/notes', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    const body = route.request().postDataJSON()
    writes.push(body)
    state.facts = { ...READY, profiles: [{ id: 9, title: body.title, status: 'verified' }] }
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ note: { id: 9, title: body.title, status: 'verified', body_md: body.body_md, tags: [{ id: 1, name: 'user-profile' }], version: 1 }, suggested_tags: { existing: [], new: [] } }) })
  })
  await page.route('**/api/notes/9', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 9, title: writes[0]?.title ?? '', status: 'verified', body_md: writes[0]?.body_md ?? '', tags: [{ id: 1, name: 'user-profile' }], version: 1, links: [], backlinks: [] }) }))
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await option(page, SHORT).click()
  await option(page, KEEP_OUT).click()
  await page.waitForTimeout(100)
  assert(!(await primary(page).isDisabled()), 'Save stays disabled after a choice')
  await page.locator('.mm-wiz-profile-change summary').click()
  assert((await page.locator('.mm-wiz-profile-change').innerText()).includes(SHORT), 'the exact selected line is not available before saving')
  assert((await page.locator('.mm-wiz-profile-change').innerText()).includes(KEEP_OUT), 'the exact boundary is not available before saving')
  await shot(page, 'profile-chosen')
  await primary(page).click()
  await page.waitForTimeout(400)
  assert(writes.length === 1, `expected one note, got ${writes.length}`)
  const [note] = writes
  assert(note.tags.length === 1 && note.tags[0] === 'user-profile', `wrong tags: ${JSON.stringify(note.tags)}`)
  assert(note.body_md === `# How I want to be answered\n${SHORT}\n\n# Boundaries\n${KEEP_OUT}\n`, `the body is not the chosen lines alone:\n${note.body_md}`)
  assert(typeof note.summary === 'string' && note.summary.length > 0, 'the note was created without a description')
  assert((await profileTitle(page).textContent()).includes('Let your assistant add more'), 'the save did not lead to the assistant screen')
  assert((await page.locator('.mm-wiz-prompt-text').textContent()).includes('profile in memex'), 'the example prompt is missing')
  await shot(page, 'profile-ask')
  await page.getByRole('button', { name: 'Back' }).click()
  await page.waitForTimeout(300)
  assert((await page.locator('.mm-wiz-profile-existing').textContent()).includes(note.title), 'the saved profile is not shown as existing on return')
  assert(await option(page, SHORT).locator('input').isChecked(), 'the saved line is not preselected on return')
})

await scenario('an existing profile is preselected from the note as it stands, and a change keeps everything else', async (page, state) => {
  state.facts = { ...READY, profiles: [{ id: 7, title: 'About me', status: 'verified' }] }
  const custom = '# Who I am\nI keep bees in Kent, and I am not a beekeeper by trade.\n'
  let body = `${custom}\n# How I want to be answered\n${SHORT}\n`
  const puts = []
  await page.route('**/api/notes/7', (route) => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 7, title: 'About me', status: 'verified', body_md: body, tags: [{ id: 1, name: 'user-profile' }], version: 3, links: [], backlinks: [] }) })
    }
    const sent = route.request().postDataJSON()
    puts.push(sent)
    body = sent.body_md
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ note: { id: 7, title: 'About me', status: 'verified', body_md: body, tags: [{ id: 1, name: 'user-profile' }], version: 4 }, suggested_tags: { existing: [], new: [] } }) })
  })
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(400)
  assert((await page.locator('.mm-wiz-profile-existing').textContent()).includes('About me'), 'the existing profile is not named')
  assert(await option(page, SHORT).locator('input').isChecked(), 'the line the note carries is not preselected')
  assert(await primary(page).isEnabled(), 'an unchanged profile must let the person continue')
  assert((await primary(page).textContent()).trim() === 'Continue', `wrong label: ${await primary(page).textContent()}`)
  await option(page, KEEP_OUT).click()
  await page.waitForTimeout(100)
  assert((await page.locator('.mm-wiz-profile-change').textContent()).includes('Adds 1 line'), 'the change is not spelled out before saving')
  await primary(page).click()
  await page.waitForTimeout(400)
  assert(puts.length === 1, 'the existing profile was not patched once')
  assert(puts[0].expected_version === 3, 'the version read was not sent back')
  assert(puts[0].title === undefined && puts[0].tags === undefined, 'a preference save touched the title or the tags')
  assert(puts[0].body_md.startsWith(custom), `the owner's own text did not survive:\n${puts[0].body_md}`)
  assert(puts[0].body_md.includes(`\n# Boundaries\n${KEEP_OUT}\n`), `the new line is not under its heading:\n${puts[0].body_md}`)
  assert(puts[0].body_md.includes(SHORT), 'the line already there was dropped')
})

await scenario('a change to an existing profile is sent against the version the person saw, and a 409 is shown', async (page, state) => {
  state.facts = { ...READY, profiles: [{ id: 7, title: 'About me', status: 'verified' }] }
  const puts = []
  await page.route('**/api/notes/7', (route) => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 7, title: 'About me', status: 'verified', body_md: `# How I want to be answered\n${SHORT}\n`, tags: [{ id: 1, name: 'user-profile' }], version: 3, links: [], backlinks: [] }) })
    }
    puts.push(route.request().postDataJSON())
    return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ conflict: 'version', error: 'This note changed while you were editing it.', expected_version: 3, current_version: 4 }) })
  })
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(400)
  await option(page, KEEP_OUT).click()
  await primary(page).click()
  await page.waitForTimeout(400)
  assert(puts.length === 1 && puts[0].expected_version === 3, 'the version the person saw was not the one sent back')
  assert((await page.locator('.mm-wiz-status.is-error').textContent()).includes('changed while you were editing'), 'the conflict is not shown')
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'moved on over a conflict')
})

await scenario('nothing can be created while what the account holds is unknown', async (page, state) => {
  state.me = { welcome_completed: true }
  let fail = true
  await page.route('**/api/me/welcome', (route) => {
    if (route.request().method() !== 'GET' || !fail) return route.fallback()
    return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
  })
  await page.goto(`${WELCOME}?section=profile`)
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.waitForTimeout(400)
  assert(await page.locator('.mm-wiz-open-failed').count() === 1, 'a failed read is not shown')
  await option(page, SHORT).click()
  await page.waitForTimeout(100)
  assert(await primary(page).isDisabled(), 'Save is offered although the server never said what the account holds')
  fail = false
  state.facts = { profiles: [{ id: 7, title: 'About me', status: 'verified' }] }
  await page.route('**/api/notes/7', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 7, title: 'About me', status: 'verified', body_md: 'Mine.\n', tags: [{ id: 1, name: 'user-profile' }], version: 1, links: [], backlinks: [] }) }))
  await page.locator('.mm-wiz-open-failed button').click()
  await page.waitForTimeout(500)
  assert((await page.locator('.mm-wiz-profile-existing').textContent()).includes('About me'), 'the retry did not bring the profile the read would have shown')
})

await scenario('a profile filed by the assistant meanwhile is found when coming back to the choices', async (page, state) => {
  state.facts = READY
  await page.route('**/api/notes/7', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 7, title: 'Filed by Claude', status: 'pending', body_md: 'Draft.\n', tags: [{ id: 1, name: 'user-profile' }], version: 1, links: [], backlinks: [] }) }))
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.waitForTimeout(200)
  state.facts = { ...READY, profiles: [{ id: 7, title: 'Filed by Claude', status: 'pending' }] }
  await page.getByRole('button', { name: 'Back' }).click()
  await page.waitForTimeout(500)
  assert((await page.locator('.mm-wiz-profile-existing').textContent()).includes('Filed by Claude'), 'the profile the assistant filed was not picked up')
  assert((await primary(page).textContent()).trim() === 'Continue', 'an unchanged profile should offer Continue')
})

await scenario('the assistant screen offers a separate optional message without promising review', async (page, state) => {
  state.facts = { ...READY, curator: true }
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.waitForTimeout(200)
  assert((await page.locator('.mm-wiz-profile-later').textContent()).includes('Settings → Personalization → About you'), 'the way back later is missing')
  assert(!(await page.locator('.mm-wiz-profile-ask').textContent()).includes('review'), 'the optional prompt implies a special review gate')
})

await scenario('a failed profile save keeps the choices and says so', async (page, state) => {
  state.facts = READY
  await page.route('**/api/notes', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
  })
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(200)
  await option(page, SHORT).click()
  await primary(page).click()
  await page.waitForTimeout(400)
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'moved on although the save failed')
  assert(await option(page, SHORT).locator('input').isChecked(), 'the choice was lost')
  assert(await page.locator('.mm-wiz-status.is-error').count() === 1, 'the failure is not shown')
  assert(!(await primary(page).isDisabled()), 'no way to try again')
})

await scenario('several profiles offer a choice of target and nothing is written until one is named', async (page, state) => {
  state.facts = { ...READY, profiles: [{ id: 7, title: 'About me', status: 'verified' }, { id: 8, title: 'Work profile', status: 'pending' }] }
  await page.route('**/api/notes/8', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 8, title: 'Work profile', status: 'pending', body_md: 'Draft.\n', tags: [{ id: 1, name: 'user-profile' }], version: 1, links: [], backlinks: [] }) }))
  await openWizard(page)
  await primary(page).click()
  await page.waitForTimeout(300)
  assert(await page.locator('#profile-target').count() === 1, 'no choice of target')
  assert(await page.locator('.mm-wiz-profile-existing').count() === 0, 'one profile was named as the profile')
  assert(await option(page, SHORT).locator('input').isDisabled(), 'a line can be chosen before a target is')
  await page.selectOption('#profile-target', '8')
  await page.waitForTimeout(300)
  assert(!(await option(page, SHORT).locator('input').isDisabled()), 'still disabled after naming a target')
  assert((await page.locator('#profile-target option:checked').textContent()).includes('awaiting your review'), 'a pending profile is not marked')
})

await scenario('Settings opens the profile screens on their own, without the rail, and Cancel returns there', async (page, state) => {
  state.me = { welcome_completed: true }
  await page.goto(`${BASE}/${HANDLE}/settings/personalization`)
  await page.waitForSelector('[data-profile-note] button')
  assert(await page.locator('[data-profile-note]').getByRole('link', { name: 'Open profile' }).count() === 0, 'an empty account offers an existing profile')
  await page.getByRole('button', { name: 'Add profile' }).click()
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.waitForTimeout(300)
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'did not open on the profile screen')
  assert(await page.locator('.mm-wiz-progress').count() === 0, 'the setup rail is shown for the profile alone')
  await page.getByRole('button', { name: 'Cancel' }).click()
  await page.waitForFunction(() => location.pathname.endsWith('/settings/personalization'), null, { timeout: 3000 })
  assert(!state.completed, 'the standalone profile screens wrote the completion stamp')
}, { on: {
  'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }),
  'GET /api/me/sessions': (route, json) => json({ sessions: [] }),
  'GET /api/me/identities': (route, json) => json({ identities: [], available: [] }),
  'GET /api/stats': (route, json) => json({ total: 0 }),
} })

await scenario('Settings distinguishes a failed profile lookup from no profile and retries', async (page, state) => {
  state.me = { welcome_completed: true }
  let fail = true
  await page.route('**/api/me/welcome', async (route) => {
    if (!fail) return route.fallback()
    await new Promise((resolve) => setTimeout(resolve, 200))
    return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"unavailable"}' })
  })
  await page.goto(`${BASE}/${HANDLE}/settings/personalization`)
  const block = page.locator('[data-profile-note]')
  await block.getByRole('button', { name: 'Try again', exact: true }).waitFor()
  assert(await block.getByRole('button', { name: 'Add profile' }).count() === 0, 'failed lookup was presented as no profile')
  fail = false
  state.facts = { profiles: [{ id: 7, title: 'About me', status: 'pending' }] }
  await block.getByRole('button', { name: 'Try again', exact: true }).click()
  await block.getByRole('link', { name: 'Open profile', exact: true }).waitFor()
  assert((await block.textContent()).includes('waiting for your review, so it is not in force'), 'retry lost the pending status')
}, { on: {
  'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }),
  'GET /api/me/sessions': (route, json) => json({ sessions: [] }),
  'GET /api/me/identities': (route, json) => json({ identities: [], available: [] }),
  'GET /api/stats': (route, json) => json({ total: 0 }),
} })

await scenario('Settings profile cards and the optional message fit a phone', async (page, state) => {
  state.me = { welcome_completed: true }
  state.facts = { profiles: [{ id: 7, title: 'A'.repeat(500), status: 'pending' }] }
  await page.goto(`${BASE}/${HANDLE}/settings/personalization`)
  const block = page.locator('[data-profile-note]')
  await block.getByRole('link', { name: 'Open profile', exact: true }).waitFor()
  const ask = block.getByRole('button', { name: 'Ask an assistant to help write it', exact: true })
  assert(await ask.getAttribute('aria-expanded') === 'false', 'the optional prompt starts open')
  await ask.click()
  assert(await ask.getAttribute('aria-expanded') === 'true', 'expanded state is not announced')
  const dimensions = await block.evaluate((el) => ({ width: el.clientWidth, scroll: el.scrollWidth }))
  assert(dimensions.scroll <= dimensions.width + 1, 'the long title or prompt overflows the phone')
  assert((await block.locator('.mm-profile-prompt-text').textContent()).includes('profile in memex'), 'the example message is missing')
  await shot(page, 'settings-profile-phone')
  await ask.click()
  assert(await block.locator('.mm-profile-prompt-text').count() === 0, 'the message cannot be collapsed')
}, { viewport: { width: 390, height: 844 }, on: {
  'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }),
  'GET /api/me/sessions': (route, json) => json({ sessions: [] }),
  'GET /api/me/identities': (route, json) => json({ identities: [], available: [] }),
  'GET /api/stats': (route, json) => json({ total: 0 }),
} })

await scenario('Settings names the one profile and lists several without choosing', async (page, state) => {
  state.facts = { profiles: [{ id: 7, title: 'About me', status: 'verified' }] }
  await page.goto(`${BASE}/${HANDLE}/settings/personalization`)
  await page.waitForSelector('[data-profile-note] a')
  const block = page.locator('[data-profile-note]')
  assert((await block.textContent()).includes('About me'), 'the profile is not named')
  assert(await block.getByRole('link', { name: 'Open profile' }).count() === 1, 'no Open profile')
  await block.getByRole('button', { name: 'Ask an assistant to help write it' }).click()
  assert((await block.locator('.mm-profile-prompt-text').textContent()).includes('profile in memex'), 'the prompt is not shown')
  state.facts = { profiles: [{ id: 7, title: 'About me', status: 'verified' }, { id: 8, title: 'Work profile', status: 'pending' }] }
  await page.reload()
  await page.waitForSelector('[data-profile-note] li')
  assert(await page.locator('[data-profile-note] li').count() === 2, 'several profiles are not all listed')
  assert(await page.locator('[data-profile-note]').getByRole('link', { name: 'Open profile' }).count() === 0, 'one of several was chosen')
}, { state: { me: { welcome_completed: true } }, on: {
  'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }),
  'GET /api/me/sessions': (route, json) => json({ sessions: [] }),
  'GET /api/me/identities': (route, json) => json({ identities: [], available: [] }),
  'GET /api/stats': (route, json) => json({ total: 0 }),
} })

for (const [name, viewport, colorScheme] of [
  ['1280x720 light', { width: 1280, height: 720 }, 'light'],
  ['640x720 dark', { width: 640, height: 720 }, 'dark'],
  ['640x720 light', { width: 640, height: 720 }, 'light'],
  ['390x844 light', { width: 390, height: 844 }, 'light'],
]) {
  await scenario(`nothing overflows sideways at ${name}, and the main screens need no scroll at 640 and above`, async (page, state) => {
    state.facts = { connected: true, connection: 'Claude', guide_read: true }
    await openWizard(page)
    const tag = name.replace(/[^a-z0-9]+/gi, '-')
    const check = async (label) => {
      const m = await page.evaluate(() => {
        const body = document.querySelector('.mm-wiz-body')
        return {
          pageW: document.documentElement.scrollWidth, innerW: innerWidth,
          bodyScroll: body.scrollHeight, bodyClient: body.clientHeight,
        }
      })
      assert(m.pageW <= m.innerW, `${label}: page scrolls sideways (${m.pageW} > ${m.innerW})`)
      if (viewport.width >= 640) assert(m.bodyScroll <= m.bodyClient + 1, `${label}: the body scrolls (${m.bodyScroll} > ${m.bodyClient})`)
      await shot(page, `${tag}-${label}`)
    }
    await page.locator('.mm-wiz-progress button').nth(0).click()
    await page.waitForTimeout(150)
    await check('your-space')
    await page.locator('.mm-wiz-progress button').nth(1).click()
    await page.waitForTimeout(150)
    for (let i = 0; i < 4; i++) {
      await page.locator('.mm-wiz-guide-dot').nth(i).click()
      await page.waitForTimeout(100)
      await check(`connect-chatgpt-${i + 1}`)
    }
    await page.locator('.mm-wiz-provider').nth(2).click()
    await page.waitForTimeout(100)
    await check('connect-gemini-1')
    await page.locator('.mm-wiz-provider').nth(3).click()
    await page.waitForTimeout(100)
    await check('connect-other')
    await page.locator('.mm-wiz-progress button').nth(2).click()
    await page.waitForTimeout(150)
    await check('test-it')
    await page.locator('.mm-wiz-progress button').nth(3).click()
    await page.waitForTimeout(150)
    await check('first-chat')
    await primary(page).click()
    await page.waitForTimeout(150)
    await check('profile')
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.waitForTimeout(150)
    await check('profile-ask')
  }, { viewport, colorScheme })
}

await scenario('Settings preferences and grouped navigation fit a phone', async (page, state) => {
  state.me = { welcome_completed: true }
  await page.goto(`${BASE}/${HANDLE}/settings/memex`)
  await page.locator('.app-settings-pane-title').waitFor()
  await page.locator('.mm-settings-themes input').first().waitFor({ state: 'attached' })
  const dimensions = await page.locator('.app-settings-pane').evaluate(el => ({ width: el.clientWidth, content: el.scrollWidth }))
  assert(dimensions.content <= dimensions.width + 1, `Preferences overflows: ${JSON.stringify(dimensions)}`)
  const choices = await page.locator('.app-settings-select option').allTextContents()
  assert(choices.join('|') === 'Account|Preferences|Personalization|Notes|Assistants|AI features', `Wrong settings order: ${choices}`)
  const groups = await page.locator('.app-settings-select optgroup').evaluateAll(items => items.map(item => item.label))
  assert(groups.join('|') === 'Personal|Your memex', `Wrong groups for ordinary account: ${groups}`)
}, { viewport: { width: 390, height: 844 }, on: { 'GET /api/locales': (route, json) => json({ locales: [] }) } })

await scenario('Settings offers no setup wizard, and export reaches its home', async (page, state) => {
  state.me = { welcome_completed: true }
  await page.goto(`${BASE}/${HANDLE}/settings/account`)
  await page.locator('.app-settings-pane-title').filter({ hasText: /^Account$/ }).waitFor()
  assert(await page.getByRole('button', { name: 'Setup guide' }).count() === 0, 'the setup wizard is still offered in Settings')
  assert(await page.getByRole('heading', { name: 'Export', exact: true }).count() === 0, 'export is still duplicated in Account')
  await page.locator('.app-settings-pane-title').filter({ hasText: /^Account$/ }).waitFor()
  await page.getByRole('link', { name: 'Download your notes before deleting your account' }).click()
  await page.waitForURL(`**/${HANDLE}/settings/content#export`)
  await page.locator('#export').getByRole('button', { name: 'Download notes' }).waitFor()
  assert(await page.getByRole('heading', { name: 'Space name' }).count() === 0, 'space name control remains')
}, { on: {
  'GET /api/tags': (route, json) => json({ tags: [], retired: [] }),
  'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }),
  'GET /api/me/sessions': (route, json) => json({ sessions: [] }),
  'GET /api/me/identities': (route, json) => json({ identities: [], available: [] }),
} })

await scenario('Settings curator assignment is explicit and removing the role keeps the connection', async (page, state) => {
  state.me = { welcome_completed: true }
  let role = 'agent'
  const changed = []
  await page.route('**/api/tokens', route => route.fulfill({ json: { tokens: [{ id: 9, name: 'Claude', display_name: 'Claude', label: 'Claude', role, revoked: false, created_at: '2026-09-01', last_used_at: null }], icons: [], logos: [] } }))
  await page.route('**/api/tokens/9/role', async route => {
    role = route.request().postDataJSON().role
    changed.push(role)
    await route.fulfill({ json: { id: 9, role } })
  })
  await page.route('**/api/curation/wiring', route => route.fulfill({ json: { connections: role === 'curator' ? [{ id: 9, name: 'Claude', label: 'Claude', last_run_at: null, charter_last_loaded_at: null }] : [], other_count: role === 'agent' ? 1 : 0 } }))
  await page.route('**/api/curation/instructions', route => route.fulfill({ json: { presets: [{ id: 1, name: 'Standard', is_standard: true, fields: {} }], field_options: {} } }))
  await page.route('**/api/curation/preview', route => route.fulfill({ json: { short: 'Load memex-curation and follow it.', full: '' } }))
  await page.goto(`${BASE}/${HANDLE}/settings/connections#curation`)
  const section = page.locator('#curation')
  const make = section.getByRole('button', { name: 'Make curator', exact: true })
  await make.waitFor()
  assert(await make.isDisabled(), 'a curator can be assigned without choosing an assistant')
  assert((await section.textContent()).includes('Deletes and merges still need your approval'), 'role consequences are missing')
  await section.getByRole('combobox', { name: 'Assistant to make a curator' }).selectOption('9')
  await make.click()
  await section.getByRole('button', { name: 'Remove role' }).waitFor()
  await section.getByRole('button', { name: 'Copy message' }).waitFor()
  assert(await section.getByRole('button', { name: 'Copy message' }).isEnabled(), 'maintenance message is unavailable')
  await section.getByRole('button', { name: 'Remove role' }).click()
  await make.waitFor()
  assert(changed.join('|') === 'curator|agent', `Wrong role changes: ${changed}`)
  assert(await section.getByRole('combobox', { name: 'Assistant to make a curator' }).locator('option[value="9"]').count() === 1, 'removing the role also lost the connection')
}, { viewport: { width: 390, height: 844 } })

for (const width of [1440, 390]) {
  await scenario(`Settings share content edges and table rows at ${width}px`, async (page, state) => {
    state.me = { welcome_completed: true }
    await page.goto(`${BASE}/${HANDLE}/settings/account`)
    await page.locator('.mm-settings-table tbody tr').first().waitFor()
    const widths = await page.locator('.mm-account-details, .mm-block > .table-responsive').evaluateAll(items => items.map(el => {
      const box = el.getBoundingClientRect()
      return { left: box.left, right: box.right }
    }))
    assert(widths.length === 3, `Expected name, sign-in and browser containers; got ${widths.length}`)
    assert(widths.every(box => Math.abs(box.left - widths[0].left) < 1 && Math.abs(box.right - widths[0].right) < 1), `Account edges differ: ${JSON.stringify(widths)}`)
    const controls = await page.locator('.mm-settings-table button').evaluateAll(items => items.map(el => el.getBoundingClientRect().right))
    assert(controls.every(right => right <= width), 'A table action is outside the viewport')
    const rowDisplay = await page.locator('.mm-settings-table tbody tr').first().evaluate(el => getComputedStyle(el).display)
    assert(rowDisplay === (width < 768 ? 'block' : 'table-row'), `Wrong table layout: ${rowDisplay}`)
    await page.goto(`${BASE}/${HANDLE}/settings/memex`)
    await page.locator('.mm-map-settings').waitFor()
    const prefs = await page.locator('.mm-settings-themes, .mm-settings-grid, .mm-map-settings').evaluateAll(items => items.map(el => {
      const box = el.getBoundingClientRect()
      return { left: box.left, right: box.right }
    }))
    assert(prefs.length === 4, `Expected four Preferences containers; got ${prefs.length}`)
    assert(prefs.every(box => Math.abs(box.left - prefs[0].left) < 1 && Math.abs(box.right - prefs[0].right) < 1), `Preferences edges differ: ${JSON.stringify(prefs)}`)
  }, { viewport: { width, height: 900 }, on: {
    'GET /api/me/identities': (route, json) => json({ identities: [{ id: 1, provider: 'google', label: 'Google', icon: 'fa-brands fa-google', email: 'user@example.test', linked_at: '2026-09-01', last_used_at: '2026-09-25' }], available: [] }),
    'GET /api/me/sessions': (route, json) => json({ sessions: [{ id: 1, browser: 'Chrome', current: true, ip: '127.0.0.1', created_at: '2026-09-01', last_seen_at: '2026-09-25' }] }),
    'GET /api/locales': (route, json) => json({ locales: [] }),
  } })
}

for (const [label, patch, expected] of [
  ['off', {}, 'Off'],
  ['included', { included: true }, 'On'],
  ['included with a model', { included: true, included_model: 'gpt-4.1-nano' }, 'On'],
  ['personal', { enabled: true, credential_id: 9, own: true }, 'On'],
  ['unreadable', { enabled: true, credential_id: 9, unreadable: true }, 'Off'],
]) {
  await scenario(`Settings AI features explain current limits and activation: ${label}`, async (page, state) => {
    state.me = { welcome_completed: true }
    await page.goto(`${BASE}/${HANDLE}/settings/automation`)
    await page.locator('#enrichment .badge').first().waitFor()
    assert((await page.locator('#enrichment .mm-block-head .badge').textContent()).trim() === expected, 'wrong effective feature state')
    const content = await page.locator('.app-settings-content').textContent()
    assert(!/sponsor|tier/i.test(content), 'administrative terminology exposed')
    assert(await page.locator('.app-settings-nav-item small').count() === 0, 'menu subtitles remain')
    if (!patch.own) {
      assert(content.includes('17 of 37 remaining today.'), 'note processing allowance is not the server value')
      assert(content.includes('8 of 19 remaining today.'), 'search allowance is not the server value')
    } else {
      assert(content.includes('No Memex daily limit.'), 'personal key still shows a cap')
    }
    if (label === 'included') {
      assert(content.includes('6 of 11 remaining today.'), 'included text allowance is missing')
      assert(content.includes('Add your own API key to remove the daily limit and choose a model.'), 'the personal key is not offered as a way past the limit')
      assert(!content.includes('Already on'), 'reads as if a personal key were already on')
      assert(await page.locator('#enrichment .mm-step-wait').count() === 0, 'contradictory no-key advice')
    }
    if (label === 'included with a model') {
      assert((await page.locator('#enrichment .mm-ai-usage').textContent()).includes('6 of 11 remaining today; model: gpt-4.1-nano.'), 'the model memex pays for is not named with the allowance')
    }
    if (label === 'off') assert(content.includes('to turn this on'), 'personal key purpose missing')
  }, { viewport: { width: 390, height: 844 }, on: {
    'GET /api/settings/ai': (route, json) => json({
      enabled: false, included: false, included_model: null, credential_id: null, provider: null, model: null, embed_credential_id: null,
      ...patch,
      keys: patch.credential_id ? [{ id: 9, name: 'Test key', provider: 'openai', provider_label: 'OpenAI', hint: 'test', verified_at: '2026-09-25', readable: !patch.unreadable, last_used_at: null }] : [],
      providers: [{ id: 'openai', label: 'OpenAI' }],
      limits: { embed_daily: 37, search_daily: 19, text_daily: 11, own_embed_key: !!patch.own, own_text_key: !!patch.own,
        left_today: { embed: patch.own ? null : 17, search: patch.own ? null : 8, text: patch.own ? null : 6 } },
    }),
    'GET /api/settings/ai/models/9': (route, json) => json({ models: [], default_model: '' }),
  } })
}

const searchKey = { id: 9, name: 'Test key', provider: 'openai', provider_label: 'OpenAI', hint: 'test', verified_at: '2026-09-25', readable: true, last_used_at: null }
const searchSettings = (embed) => ({
  enabled: true, included: false, included_model: null, credential_id: 9, provider: 'openai', model: null, embed_credential_id: embed,
  keys: [searchKey], providers: [{ id: 'openai', label: 'OpenAI' }],
  limits: { embed_daily: 37, search_daily: 19, text_daily: 11, own_embed_key: embed !== null, own_text_key: true,
    left_today: { embed: embed === null ? 17 : null, search: embed === null ? 8 : null, text: null } },
})
const searchPuts = []
await scenario('Settings Search offers a listed OpenAI key it does not use, and adopts it', async (page, state) => {
  state.me = { welcome_completed: true }
  await page.goto(`${BASE}/${HANDLE}/settings/automation`)
  const search = page.locator('#embeddings')
  const use = search.getByRole('button', { name: 'Use for search' })
  await use.waitFor()
  assert((await search.textContent()).includes('Search does not use this key yet.'), 'the unused key is described as missing')
  assert(!(await search.textContent()).includes('Add your OpenAI API key'), 'asks for a key that is already listed')
  assert(await page.locator('#enrichment').getByRole('button', { name: 'Use for search' }).count() === 0, 'Descriptions offers the search action')
  await use.click()
  await page.waitForFunction(() => document.querySelector('#embeddings')?.textContent.includes('Your personal key is in use.'), null, { timeout: 3000 })
    .catch(() => assert(false, 'adopting the key did not update the section'))
  assert(searchPuts.length === 1 && searchPuts[0].embed_credential_id === 9, `the adopted key was not sent: ${JSON.stringify(searchPuts)}`)
  assert(await use.count() === 0, 'the action stays after the key is in use')
  assert((await search.textContent()).includes('No Memex daily limit.'), 'the search limits remain after adopting')
}, { on: {
  'PUT /api/settings/ai/sections': (route, json, request) => {
    searchPuts.push(request.postDataJSON())
    return json(searchSettings(request.postDataJSON().embed_credential_id))
  },
  'GET /api/settings/ai/models/9': (route, json) => json({ models: [], default_model: '' }),
  'GET /api/settings/ai': (route, json) => json(searchSettings(null)),
} })

const TOKENS = { 'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }) }

await scenario('where ChatGPT, Claude and Gemini cannot reach this memex, Settings offers only the assistants beside it', async (page, state) => {
  state.me = { welcome_completed: true, web_assistants: false }
  await page.goto(`${BASE}/${HANDLE}/settings/connections`)
  await page.waitForSelector('.mm-connect-guide')
  assert(await page.locator('.mm-wiz-provider').count() === 0, 'the web assistants are still offered')
  assert(await page.locator('.mm-wiz-guide-dot').count() === 0, 'a web assistant\'s steps are shown')
  assert(!(await page.locator('#connect').textContent()).includes('Choose your app'), 'it still asks which app')
  assert((await page.locator('.mm-wiz-address code').textContent()) === `${BASE}/mcp`, 'the local setups lost the address')
}, { on: TOKENS })

await scenario('where they can reach it, Settings offers them first, then the others', async (page, state) => {
  state.me = { welcome_completed: true, web_assistants: true }
  await page.goto(`${BASE}/${HANDLE}/settings/connections`)
  await page.waitForSelector('.mm-connect-guide')
  const tabs = await page.locator('.mm-wiz-provider').allTextContents()
  assert(tabs.length === 4 && tabs[0].includes('ChatGPT'), `tabs: ${JSON.stringify(tabs)}`)
  assert((await page.locator('.mm-wiz-provider[aria-pressed="true"]').textContent()).includes('ChatGPT'), 'ChatGPT is not the first one open')
}, { on: TOKENS })

await scenario('the wizard on a memex they cannot reach connects an assistant beside it, with no tabs', async (page, state) => {
  state.me = { web_assistants: false }
  state.facts = { connected: false }
  await openWizard(page)
  await page.locator('.mm-wiz-progress button').nth(1).click()
  await page.waitForTimeout(150)
  assert(await page.locator('.mm-wiz-provider').count() === 0, 'the wizard still offers the web assistants')
  assert((await page.locator('.mm-wiz-address code').textContent()) === `${BASE}/mcp`, 'the wizard does not give the address')
}, { on: TOKENS })

await browser.close()
server.close()

if (failures.length) {
  console.error('\nWelcome wizard behaviour check failed:\n')
  for (const failure of failures) console.error(`  ${failure}`)
  process.exit(1)
}

console.log(`Welcome wizard behaviour check passed: ${passed.length} properties hold in a browser.`)
