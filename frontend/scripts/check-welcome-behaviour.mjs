#!/usr/bin/env node
/**
 * The profile screens and Settings, asserted against the built SPA in a
 * browser with the API stubbed.
 *
 * The failure paths a healthy server never produces on request — a 500 on a
 * read, a save that is refused, a token that lands after another account has
 * signed in — are exactly the ones a regex over the source cannot see. So
 * each scenario below stubs `/api/**`, drives the page and asserts what a
 * person would see. Needs `dist/`, no backend and no database.
 */
import { createServer } from 'node:http'
import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Welcome behaviour check failed:\n')
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
  ...patch,
})

const facts = (patch = {}) => ({ profiles: [], ...patch })

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
    if (key === 'GET /api/me/welcome') return json({ facts: facts(state.facts) })
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
  const state = { me: {}, facts: {}, ...(options.state ?? {}) }
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
const openWizard = async (page) => {
  await page.goto(WELCOME)
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.waitForTimeout(300)
}
const shot = async (page, name) => {
  if (SHOTS) await page.screenshot({ path: join(SHOTS, `${name}.png`) })
}
const profileTitle = (page) => page.locator('#welcome-title')

const TOKENS = { 'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }) }

// ── scenarios ────────────────────────────────────────────────────────────

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

await scenario('a token minted from Settings by one account is not shown to the next one', async (page, state) => {
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
  await page.fill('#connect-agent-name', 'My script')
  await page.locator('.mm-connect-token button').click()
  await page.locator('.btn-close').first().click()
  await page.waitForTimeout(200)
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.waitForSelector('.mm-login', { timeout: 5000 })
  assert(who === 0, 'sign-out did not reach the server')
  state.me = { name: 'Blake', team: { name: 'Second', handle: HANDLE_B } }
  await signInAgain(page)
  await page.waitForFunction(() => location.pathname.endsWith('/notes'), null, { timeout: 5000 })
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu a[role="menuitem"][href$="/settings"]').click()
  await page.locator('.app-settings-nav-item[href$="/connections"]').click()
  await page.waitForSelector('.mm-connect-guide')
  await page.waitForTimeout(1800)
  assert(await page.locator('#new-token-title').count() === 0, 'B was shown A\'s token in Settings')
}, { on: { 'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }) } })


// ── Settings › Assistants ─────────────────────────────────────────────────

const steps = (page) => page.locator('.mm-connect-steps > li')

await scenario('each assistant\'s steps are one list, with the address, the prompt last, and no screenshots', async (page) => {
  await page.goto(`${BASE}/${HANDLE}/settings/connections`)
  await page.waitForSelector('.mm-connect-steps')
  assert(await steps(page).count() === 4, `ChatGPT: ${await steps(page).count()} steps`)
  assert((await page.locator('.mm-connect-steps .mm-wiz-address code').textContent()) === `${BASE}/mcp`, 'the address is missing')
  assert((await steps(page).last().textContent()).includes('Remember this for all our future chats.'), 'the prompt is not the last step')
  assert(await page.locator('.mm-connect-guide img:not(.mm-wiz-provider img)').count() === 0, 'a screenshot is still shown')
  assert(await page.locator('.mm-connect-guide').getByRole('button', { name: /is on|added|there/ }).count() === 0, 'a step still asks to be confirmed')
  assert((await page.locator('.mm-connect-optional').textContent()).includes('Allow all actions'), 'the optional permissions line is missing')
  await shot(page, 'connect-chatgpt')
  await page.locator('.mm-wiz-provider').nth(1).click()
  assert(await steps(page).count() === 3, `Claude: ${await steps(page).count()} steps`)
  assert(await steps(page).last().getByRole('link', { name: 'Claude’s profile preferences' }).count() === 1, 'Claude\'s way to keep the prompt is missing')
  await page.locator('.mm-wiz-provider').nth(2).click()
  assert(await steps(page).count() === 5, `Gemini: ${await steps(page).count()} steps`)
  assert(await page.locator('.mm-connect-optional').count() === 0, 'Gemini shows a permissions line it has no setting for')
  await page.locator('.mm-wiz-provider').nth(2).click()
  await page.locator('.mm-connect-steps .mm-wiz-address button').click()
  assert((await page.evaluate(() => navigator.clipboard.readText())) === `${BASE}/mcp`, 'the copy button did not copy the address')
}, { on: TOKENS })

await scenario('a curator connection gets the review sentence that is true of it', async (page) => {
  await page.goto(`${BASE}/${HANDLE}/settings/connections#connect`)
  await page.waitForSelector('.mm-connect-steps')
  await page.waitForFunction(() => document.querySelector('.mm-connect-guide')?.textContent.includes('curator connection'), null, { timeout: 3000 })
}, { on: {
  'GET /api/tokens': (route, json) => json({ tokens: [{ id: 9, name: 'Claude', display_name: null, label: 'Claude', role: 'curator', revoked: false, created_at: '2026-09-01', last_used_at: null }], icons: [], logos: [] }),
  'GET /api/curation/wiring': (route, json) => json({ connections: [], other_count: 0 }),
  'GET /api/curation/instructions': (route, json) => json({ presets: [], field_options: {} }),
} })

await scenario('a token minted while the person switches assistant is still shown once', async (page) => {
  await page.route('**/api/tokens', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    await new Promise((r) => setTimeout(r, 700))
    return route.fallback()
  })
  await page.goto(`${BASE}/${HANDLE}/settings/connections`)
  await page.waitForSelector('.mm-connect-guide')
  await page.locator('.mm-wiz-provider').nth(3).click()
  await page.fill('#connect-agent-name', 'My script')
  await page.locator('.mm-connect-token button').click()
  await page.waitForTimeout(100)
  await page.locator('.mm-wiz-provider').nth(1).click()
  await page.waitForSelector('.modal.show .mm-address code', { timeout: 3000 })
  assert((await page.locator('.modal.show .mm-address code').last().textContent()) === 'mxt_test', 'the token was lost with the tab')
}, { on: TOKENS })

// ── the profile screens ───────────────────────────────────────────────────

const SHORT = 'Lead with the answer and keep it short.'
const KEEP_OUT = 'Keep personal details out of notes unless I ask for them to be kept.'
const option = (page, line) => page.locator('.mm-wiz-profile-option').filter({ has: page.locator(`input[value=${JSON.stringify(line)}]`) })

await scenario('continuing without profile preferences writes nothing and the message remains optional', async (page, state) => {
  const writes = []
  await page.route('**/api/notes', (route) => {
    if (route.request().method() === 'POST') writes.push(route.request().postDataJSON())
    return route.fallback()
  })
  await openWizard(page)
  await page.getByRole('button', { name: 'Continue', exact: true }).click()
  await page.waitForTimeout(150)
  assert((await profileTitle(page).textContent()).includes('Let your assistant add more'), 'Continue did not advance')
  assert(writes.length === 0, 'Continue created an empty profile')
  await page.getByRole('button', { name: 'Back', exact: true }).click()
  await page.waitForTimeout(150)
  assert(await page.locator('.mm-wiz-profile-option input:checked').count() === 0, 'Back invented preferences')
  await page.getByRole('button', { name: 'Continue', exact: true }).click()
  await primary(page).click()
  await page.waitForFunction(() => location.pathname.endsWith('/settings/personalization'), null, { timeout: 3000 })
  assert(writes.length === 0, 'finishing created an empty profile')
}, { on: { 'GET /api/me/sessions': (route, json) => json({ sessions: [] }) } })

await scenario('saving preferences writes exactly the chosen lines, tagged, and the assistant screen follows', async (page, state) => {
    const writes = []
  await page.route('**/api/notes', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    const body = route.request().postDataJSON()
    writes.push(body)
    state.facts = { profiles: [{ id: 9, title: body.title, status: 'verified' }] }
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ note: { id: 9, title: body.title, status: 'verified', body_md: body.body_md, tags: [{ id: 1, name: 'user-profile' }], version: 1 }, suggested_tags: { existing: [], new: [] } }) })
  })
  await page.route('**/api/notes/9', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 9, title: writes[0]?.title ?? '', status: 'verified', body_md: writes[0]?.body_md ?? '', tags: [{ id: 1, name: 'user-profile' }], version: 1, links: [], backlinks: [] }) }))
  await openWizard(page)
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
  state.facts = { profiles: [{ id: 7, title: 'About me', status: 'verified' }] }
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
  state.facts = { profiles: [{ id: 7, title: 'About me', status: 'verified' }] }
  const puts = []
  await page.route('**/api/notes/7', (route) => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 7, title: 'About me', status: 'verified', body_md: `# How I want to be answered\n${SHORT}\n`, tags: [{ id: 1, name: 'user-profile' }], version: 3, links: [], backlinks: [] }) })
    }
    puts.push(route.request().postDataJSON())
    return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ conflict: 'version', error: 'This note changed while you were editing it.', expected_version: 3, current_version: 4 }) })
  })
  await openWizard(page)
  await option(page, KEEP_OUT).click()
  await primary(page).click()
  await page.waitForTimeout(400)
  assert(puts.length === 1 && puts[0].expected_version === 3, 'the version the person saw was not the one sent back')
  assert((await page.locator('.mm-wiz-status.is-error').textContent()).includes('changed while you were editing'), 'the conflict is not shown')
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'moved on over a conflict')
})

await scenario('nothing can be created while what the account holds is unknown', async (page, state) => {
  let fail = true
  await page.route('**/api/me/welcome', (route) => {
    if (route.request().method() !== 'GET' || !fail) return route.fallback()
    return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
  })
  await page.goto(`${WELCOME}`)
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
    await page.route('**/api/notes/7', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 7, title: 'Filed by Claude', status: 'pending', body_md: 'Draft.\n', tags: [{ id: 1, name: 'user-profile' }], version: 1, links: [], backlinks: [] }) }))
  await openWizard(page)
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.waitForTimeout(200)
  state.facts = { profiles: [{ id: 7, title: 'Filed by Claude', status: 'pending' }] }
  await page.getByRole('button', { name: 'Back' }).click()
  await page.waitForTimeout(500)
  assert((await page.locator('.mm-wiz-profile-existing').textContent()).includes('Filed by Claude'), 'the profile the assistant filed was not picked up')
  assert((await primary(page).textContent()).trim() === 'Continue', 'an unchanged profile should offer Continue')
})

await scenario('the assistant screen offers a separate optional message without promising review', async (page, state) => {
  state.facts = {}
  await openWizard(page)
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.waitForTimeout(200)
  assert((await page.locator('.mm-wiz-profile-later').textContent()).includes('Settings → Personalization → About you'), 'the way back later is missing')
  assert(!(await page.locator('.mm-wiz-profile-ask').textContent()).includes('review'), 'the optional prompt implies a special review gate')
})

await scenario('a failed profile save keeps the choices and says so', async (page, state) => {
    await page.route('**/api/notes', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"no"}' })
  })
  await openWizard(page)
  await option(page, SHORT).click()
  await primary(page).click()
  await page.waitForTimeout(400)
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'moved on although the save failed')
  assert(await option(page, SHORT).locator('input').isChecked(), 'the choice was lost')
  assert(await page.locator('.mm-wiz-status.is-error').count() === 1, 'the failure is not shown')
  assert(!(await primary(page).isDisabled()), 'no way to try again')
})

await scenario('several profiles offer a choice of target and nothing is written until one is named', async (page, state) => {
  state.facts = { profiles: [{ id: 7, title: 'About me', status: 'verified' }, { id: 8, title: 'Work profile', status: 'pending' }] }
  await page.route('**/api/notes/8', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 8, title: 'Work profile', status: 'pending', body_md: 'Draft.\n', tags: [{ id: 1, name: 'user-profile' }], version: 1, links: [], backlinks: [] }) }))
  await openWizard(page)
  assert(await page.locator('#profile-target').count() === 1, 'no choice of target')
  assert(await page.locator('.mm-wiz-profile-existing').count() === 0, 'one profile was named as the profile')
  assert(await option(page, SHORT).locator('input').isDisabled(), 'a line can be chosen before a target is')
  await page.selectOption('#profile-target', '8')
  await page.waitForTimeout(300)
  assert(!(await option(page, SHORT).locator('input').isDisabled()), 'still disabled after naming a target')
  assert((await page.locator('#profile-target option:checked').textContent()).includes('awaiting your review'), 'a pending profile is not marked')
})

await scenario('Settings opens the profile screens, and Cancel returns there', async (page, state) => {
  await page.goto(`${BASE}/${HANDLE}/settings/personalization`)
  await page.waitForSelector('[data-profile-note] button')
  assert(await page.locator('[data-profile-note]').getByRole('link', { name: 'Open profile' }).count() === 0, 'an empty account offers an existing profile')
  await page.getByRole('button', { name: 'Add profile' }).click()
  await page.waitForSelector('.modal.show .mm-wiz')
  await page.waitForTimeout(300)
  assert((await profileTitle(page).textContent()).includes('Choose a few preferences'), 'did not open on the profile screen')
  await page.getByRole('button', { name: 'Cancel' }).click()
  await page.waitForFunction(() => location.pathname.endsWith('/settings/personalization'), null, { timeout: 3000 })
}, { on: {
  'GET /api/tokens': (route, json) => json({ tokens: [], icons: [], logos: [] }),
  'GET /api/me/sessions': (route, json) => json({ sessions: [] }),
  'GET /api/me/identities': (route, json) => json({ identities: [], available: [] }),
  'GET /api/stats': (route, json) => json({ total: 0 }),
} })

await scenario('Settings distinguishes a failed profile lookup from no profile and retries', async (page, state) => {
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
}, { on: {
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
  const tag = name.replace(/[^a-z0-9]+/gi, '-')
  await scenario(`the profile screens do not overflow sideways at ${name}, nor scroll at 640 and above`, async (page) => {
    await openWizard(page)
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
    await check('profile')
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.waitForTimeout(150)
    await check('profile-ask')
  }, { viewport, colorScheme })

  await scenario(`no assistant's steps overflow sideways at ${name}`, async (page) => {
    await page.goto(`${BASE}/${HANDLE}/settings/connections`)
    await page.waitForSelector('.mm-connect-guide')
    for (let i = 0; i < 4; i++) {
      await page.locator('.mm-wiz-provider').nth(i).click()
      await page.waitForTimeout(100)
      const m = await page.locator('.mm-connect-guide').evaluate((el) => ({ width: el.clientWidth, content: el.scrollWidth, pageW: document.documentElement.scrollWidth, innerW: innerWidth }))
      assert(m.content <= m.width + 1 && m.pageW <= m.innerW, `tab ${i + 1} overflows: ${JSON.stringify(m)}`)
      await shot(page, `${tag}-connect-${i + 1}`)
    }
  }, { viewport, colorScheme, on: TOKENS })
}

await scenario('Settings preferences and grouped navigation fit a phone', async (page, state) => {
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

await scenario('Settings offers no setup guide, and export reaches its home', async (page, state) => {
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

await scenario('where ChatGPT, Claude and Gemini cannot reach this memex, Settings offers only the assistants beside it', async (page, state) => {
  state.me = { web_assistants: false }
  await page.goto(`${BASE}/${HANDLE}/settings/connections`)
  await page.waitForSelector('.mm-connect-guide')
  assert(await page.locator('.mm-wiz-provider').count() === 0, 'the web assistants are still offered')
  assert(await page.locator('.mm-connect-prompt').count() === 0 && !(await page.locator('#connect').textContent()).includes('ChatGPT'), 'a web assistant\'s steps are shown')
  assert(!(await page.locator('#connect').textContent()).includes('Choose your app'), 'it still asks which app')
  assert((await page.locator('.mm-wiz-address code').textContent()) === `${BASE}/mcp`, 'the local setups lost the address')
}, { on: TOKENS })

await scenario('where they can reach it, Settings offers them first, then the others', async (page, state) => {
  state.me = { web_assistants: true }
  await page.goto(`${BASE}/${HANDLE}/settings/connections`)
  await page.waitForSelector('.mm-connect-guide')
  const tabs = await page.locator('.mm-wiz-provider').allTextContents()
  assert(tabs.length === 4 && tabs[0].includes('ChatGPT'), `tabs: ${JSON.stringify(tabs)}`)
  assert((await page.locator('.mm-wiz-provider[aria-pressed="true"]').textContent()).includes('ChatGPT'), 'ChatGPT is not the first one open')
}, { on: TOKENS })

await browser.close()
server.close()

if (failures.length) {
  console.error('\nWelcome behaviour check failed:\n')
  for (const failure of failures) console.error(`  ${failure}`)
  process.exit(1)
}

console.log(`Welcome behaviour check passed: ${passed.length} properties hold in a browser.`)
