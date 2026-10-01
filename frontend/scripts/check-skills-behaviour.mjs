#!/usr/bin/env node
/**
 * A skill's panel, asserted against the built SPA in a browser with the API
 * stubbed.
 *
 * Opening a card shows its usage example; pausing, narrowing to a
 * connection and renaming all go through `PATCH /api/skills/<id>`, and a bad
 * slug is refused inline before it is ever sent. None of that is visible to
 * `npm run build` or to a regex over the source, so this drives the panel the
 * way a person would and reads what is on screen. Needs `dist/`, no backend
 * and no database.
 */
import { createServer } from 'node:http'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Skills panel behaviour check failed:\n')
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
const SKILLS = `${BASE}/${HANDLE}/skills`

const ME = {
  id: 1, email: 'user@example.test', name: 'Test User',
  team: { id: 1, name: 'Demo', handle: HANDLE },
  system_tags: [], appearance: {}, icon_key: null, icon: null,
  welcome_completed: true,
}

// The row Task 10's own check-space-urls.mjs already stubs, so the shape is
// exactly what App\Controller\SkillController answers with.
const ROW = {
  kind: 'note', status: 'served', slug: 'house-style', title: 'House style',
  description: 'How notes are written.', short: null, body: 'Steps.',
  updated_at: '2026-09-01 09:00:00', note_id: 5, enabled: true, auto: true, command: true,
  grants: [], usage: { total_30d: 0, last_at: null, last_token_id: null, by_token: [] }, lint: [], size_tokens: 12,
}

const CONNECTIONS = [
  { id: 1, label: 'Claude Code', display_name: null, role: 'agent', icon: null, icon_url: null, icon_url_dark: null },
  { id: 2, label: 'Codex', display_name: null, role: 'agent', icon: null, icon_url: null, icon_url_dark: null },
]

const PENDING_ROW = {
  kind: 'note', status: 'pending', slug: 'draft-skill', title: 'Draft skill',
  description: 'Still waiting for review.', short: null, body: 'Steps.',
  updated_at: '2026-09-01 09:00:00', note_id: 6, enabled: true, auto: true, command: true,
  grants: [], usage: { total_30d: 0, last_at: null, last_token_id: null, by_token: [] }, lint: [], size_tokens: 8,
}

/**
 * The server the wizard checks share: mutable `state` so a scenario can watch
 * what changed between two presses, and every `PATCH /api/skills/5` recorded
 * and answered with the row merged with what was sent — a real server's own
 * behaviour, not a canned reply.
 */
function apiStub(state) {
  return async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const key = `${request.method()} ${url.pathname}`
    const json = (body, status = 200) =>
      route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })

    if (key === 'GET /api/me') return json(ME)
    if (key === 'GET /api/inbox/count') return json({ pending_notes: 0, edit_proposals: 0, total: 0 })
    if (key === 'GET /api/me/setup') return json({ items: {}, complete: true, dismissed: true })
    if (key === 'GET /api/me/onboarding') return json({ show: false, dismissed: true })
    if (key === 'GET /api/presets') return json({ presets: [], icons: [] })
    if (key === 'GET /api/tags') return json({ tags: [], retired: [] })
    if (key === 'GET /api/me/sessions') return json({ sessions: [] })
    if (key === 'GET /api/skills') return json({ skills: [state.row, PENDING_ROW, ...(state.extraRows ?? [])], connections: state.connections ?? CONNECTIONS })
    if (key === 'PATCH /api/skills/5') {
      const patch = request.postDataJSON()
      state.patches.push(patch)
      if (state.failPatch) return json({ error: 'Could not save this change.' }, 503)
      state.row = { ...state.row, ...patch }
      if ('enabled' in patch) state.row.status = patch.enabled ? 'served' : 'paused'
      return json({ skill: state.row })
    }
    if (key.startsWith('GET /api/notes')) return json({ items: [], notes: [], total: 0, tags: [], tag_counts: [], presets: [] })
    if (key.startsWith('GET /api/stats')) return json({ total: 0 })
    return json({}, 200)
  }
}

// ── harness ──────────────────────────────────────────────────────────────

const failures = []
const browser = await chromium.launch()

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

async function scenario(name, body) {
  if (process.env.SKILLS_CHECK && !name.includes(process.env.SKILLS_CHECK)) return
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 } })
  const state = { row: { ...ROW }, patches: [] }
  const page = await context.newPage()
  page.setDefaultTimeout(3000)
  await page.route('**/api/**', apiStub(state))
  const errors = []
  page.on('pageerror', (e) => errors.push(String(e)))
  const expectText = async (text) => {
    const body = await page.evaluate(() => document.body.innerText)
    assert(body.includes(text), `expected to read "${text}", got:\n${body.slice(0, 2000)}`)
  }
  try {
    await body(page, state, { expectText })
    assert(errors.length === 0, `page errors: ${errors.join(' | ')}`)
    console.log(`  ok   ${name}`)
  } catch (e) {
    failures.push(`${name}: ${e.message}`)
    console.log(`  FAIL ${name}: ${e.message}`)
  } finally {
    await context.close()
  }
}

const openPanel = async (page) => {
  await page.goto(SKILLS)
  await Promise.all([
    page.evaluate(() => new Promise((resolve) => document.addEventListener('shown.bs.modal', resolve, { once: true }))),
    page.getByRole('button', { name: 'House style', exact: true }).click(),
  ])
  await page.waitForSelector('.modal.show #skill-panel-title')
}

// ── scenarios ────────────────────────────────────────────────────────────

await scenario('opening a skill explains chat usage without internal load forms', async (page, state, { expectText }) => {
  await openPanel(page)
  await expectText('Use my Memex skill house-style')
  assert(await page.getByRole('checkbox', { name: 'Enable this skill', exact: true }).isVisible(), 'enable control is missing')
  const body = await page.locator('.modal-body').innerText()
  assert(!body.includes('get_skill(') && !body.includes('memex://') && !body.includes('/mcp__'), 'internal load forms crowd the instructions')
})

await scenario('pausing sends {enabled:false} and the row shows "paused"', async (page, state, { expectText }) => {
  await openPanel(page)
  await page.locator('#skill-enabled').click()
  await page.waitForTimeout(200)
  assert(JSON.stringify(state.patches.at(-1)) === JSON.stringify({ enabled: false }), `patch: ${JSON.stringify(state.patches.at(-1))}`)
  await expectText('Off')
})

await scenario("narrowing to Codex sends {grants:[2]} and the row's Connections cell reads \"Codex\"", async (page, state, { expectText }) => {
  await openPanel(page)
  await page.locator('.modal-body').getByRole('checkbox', { name: 'Claude Code', exact: true }).uncheck()
  await page.locator('.modal-body').getByRole('button', { name: 'Save connections', exact: true }).click()
  await page.waitForTimeout(200)
  assert(JSON.stringify(state.patches.at(-1)) === JSON.stringify({ grants: [2] }), `patch: ${JSON.stringify(state.patches.at(-1))}`)
  await expectText('Codex')
})

await scenario('a bad slug is refused inline, a good one is sent', async (page, state, { expectText }) => {
  await openPanel(page)
  await page.fill('#skill-slug', 'Bad Slug')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expectText('lowercase letters, digits and single hyphens')
  assert(state.patches.length === 0, `a bad slug reached the server: ${JSON.stringify(state.patches)}`)
  await page.fill('#skill-slug', 'a'.repeat(65))
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  assert(state.patches.length === 0, 'an overlong slug reached the server')
  await page.fill('#skill-slug', 'style')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await page.waitForTimeout(200)
  assert(JSON.stringify(state.patches.at(-1)) === JSON.stringify({ slug: 'style' }), `patch: ${JSON.stringify(state.patches.at(-1))}`)
})

await scenario('a pending row disables every switch, Rename and Save connections', async (page, state, { expectText }) => {
  await page.goto(SKILLS)
  await page.click('text=Draft skill')
  await page.waitForSelector('.modal.show #skill-panel-title')
  await expectText('Needs review')
  for (const id of ['#skill-enabled']) {
    assert(await page.isDisabled(id), `${id} was not disabled on a pending row`)
  }
  assert(await page.getByRole('button', { name: 'Save', exact: true }).isDisabled(), 'Rename was not disabled on a pending row')
  assert(await page.locator('.modal-body').getByRole('button', { name: 'Save connections', exact: true }).isDisabled(), 'Save connections was not disabled on a pending row')
})

await scenario('New skill opens the editor with the tag set and the scaffold in the body', async (page, state, { expectText }) => {
  await page.goto(SKILLS)
  await page.click('text=New skill')
  await page.waitForURL(/\/notes\/new\?skill=1$/)
  await page.waitForSelector('.cm-content')
  await expectText('skill')
  const body = await page.evaluate(() => document.querySelector('.cm-content')?.innerText ?? '')
  assert(body.includes('When to use this') && body.includes('Steps'), `body: ${body}`)
})

await scenario('every uploaded file rejected shows the structured report', async (page, state, { expectText }) => {
  await page.route('**/api/skills/import', (route) => route.fulfill({
    status: 400,
    contentType: 'application/json',
    body: JSON.stringify({ created: [], errors: [{ file: 'bad.md', error: 'A skill is already served as house-style' }], ignored: [] }),
  }))
  await page.goto(SKILLS)
  await page.setInputFiles('input[type=file]', { name: 'bad.md', mimeType: 'text/markdown', buffer: Buffer.from('# Bad\n') })
  await page.waitForTimeout(200)
  await expectText('bad.md')
  await expectText('A skill is already served as house-style')
})


await scenario('one enable switch opens all supported skill access paths', async (page, state) => {
  state.row.enabled = false
  state.row.status = 'paused'
  state.row.auto = false
  state.row.command = false
  await openPanel(page)
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), page.locator('#skill-enabled').check()])
  assert(state.row.enabled && state.row.auto && state.row.command, 'enabling left a skill access path disabled')
  assert(await page.locator('#skill-auto, #skill-command').count() === 0, 'technical access switches remain in the UI')
})

await scenario('paused and pending skills offer no working load forms', async (page, state) => {
  state.row.enabled = false
  state.row.status = 'paused'
  await openPanel(page)
  let panel = await page.locator('.modal-body').innerText()
  assert(!panel.includes('get_skill(') && !panel.includes('/mcp__'), 'paused skill advertised a load form')
  await page.getByRole('button', { name: 'Cancel', exact: true }).click()
  await page.waitForSelector('.modal.show', { state: 'hidden' })
  await page.click('text=Draft skill')
  await page.waitForSelector('.modal.show')
  panel = await page.locator('.modal-body').innerText()
  assert(!panel.includes('get_skill(') && !panel.includes('/mcp__'), 'pending skill advertised a load form')
})

await scenario('all connections can be restored without keyboard modifiers', async (page, state) => {
  state.row.grants = [2]
  await openPanel(page)
  await page.locator('.modal-body').getByRole('checkbox', { name: 'All connected assistants', exact: true }).check()
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), page.locator('.modal-body').getByRole('button', { name: 'Save connections', exact: true }).click()])
  assert(JSON.stringify(state.patches.at(-1)) === JSON.stringify({ grants: [] }), 'restoring all connections did not clear grants')
})

await scenario('skill descriptions are readable before opening a panel', async (page) => {
  await page.goto(SKILLS)
  const row = page.locator('article').filter({ has: page.getByRole('button', { name: 'House style', exact: true }) })
  await row.waitFor({ timeout: 1500 })
  assert((await row.innerText()).includes(ROW.description), 'skill purpose is hidden until the panel opens')
})


await scenario('long skill content and panel controls fit a phone', async (page, state) => {
  await page.setViewportSize({ width: 390, height: 844 })
  state.row.title = 'A'.repeat(500)
  state.row.description = 'B'.repeat(1024)
  state.row.slug = 'c'.repeat(64)
  await page.goto(SKILLS)
  await page.getByRole('button', { name: state.row.title, exact: true }).waitFor()
  const fits = () => document.documentElement.scrollWidth <= window.innerWidth
  assert(await page.evaluate(fits), 'the skill table overflows a phone')
  const segment = page.locator(`.size-segment[data-alias="${state.row.slug}"]`)
  await segment.focus()
  await page.locator('#skill-size-detail').waitFor()
  assert(await page.locator('.skills-dashboard').evaluate(el => el.scrollWidth <= el.clientWidth + 1), 'the inspected skill title overflows the summary')
  assert(await page.evaluate(fits), 'the inspected skill title overflows a phone')
  await segment.press('Escape')
  await Promise.all([
    page.evaluate(() => new Promise((resolve) => document.addEventListener('shown.bs.modal', resolve, { once: true }))),
    page.getByRole('button', { name: state.row.title, exact: true }).click(),
  ])
  assert(await page.evaluate(fits), 'the skill panel overflows a phone')
  const dialogFits = await page.locator('.modal-content').evaluate((el) => el.scrollWidth <= el.clientWidth + 1)
  assert(dialogFits, 'panel controls exceed the dialog width')
  assert(await page.locator('.modal-body').evaluate(el => el.clientHeight) > 100, 'long content leaves no room for panel controls')
  await page.locator('#skill-slug').fill('fits-on-phone')
})


await scenario('a card can be paused and resumed without opening its panel', async (page, state) => {
  await page.goto(SKILLS)
  const card = page.locator('article').filter({ has: page.getByRole('button', { name: 'House style', exact: true }) })
  const toggle = card.getByRole('checkbox', { name: 'Enable House style', exact: true })
  await toggle.uncheck({ timeout: 1500 })
  await page.waitForFunction(() => document.querySelector('article[data-note-id="5"]')?.getAttribute('data-enabled') === 'false')
  assert(state.row.enabled === false, 'pause did not save')
  assert(await page.locator('[data-skill-group=available] article[data-note-id="5"]').count() === 1, 'disabled skill did not move to Available')
  assert(await page.locator('.modal.show').count() === 0, 'card switch opened the panel')
  await toggle.check()
  await page.waitForFunction(() => document.querySelector('article[data-note-id="5"]')?.getAttribute('data-enabled') === 'true')
  assert(state.row.enabled === true, 'resume did not save')
  assert(await page.locator('[data-skill-group=active] article[data-note-id="5"]').count() === 1, 'enabled skill did not move to Active')
  assert(state.row.auto && state.row.command, 'resuming did not open all supported access paths')
})

await scenario('memex-writing switched off in Personalization says so and is not counted', async (page, state) => {
  state.extraRows = [
    { ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-guide', title: 'Memex guide', size_tokens: 100 },
    { ...ROW, kind: 'shipped', status: 'switched_off', note_id: null, slug: 'memex-writing', title: 'memex — writing', size_tokens: 1200 },
  ]
  await page.goto(SKILLS)
  const card = page.locator('[data-system-skill="memex-writing"]')
  await card.waitFor()
  assert((await card.textContent()).includes('Off in Settings › Personalization'), 'the switched-off card does not say so')
  assert((await card.getByRole('link').getAttribute('href')).endsWith('/settings/personalization'), 'the card does not lead to Personalization')
  assert(!(await page.locator('[data-system-skill="memex-guide"]').textContent()).includes('Off in'), 'an always-on skill claims to be off')
  assert(await page.getByTestId('skills-active-count').innerText() === '2', 'a switched-off skill counted as enabled')
})

await scenario('the size summary groups built-ins first and measures shares of enabled instructions', async (page, state) => {
  state.row.size_tokens = 12500
  state.row.grants = [2]
  state.extraRows = [
    { ...ROW, note_id: 9, slug: 'disabled-skill', status: 'paused', enabled: false, size_tokens: 99999 },
    { ...ROW, kind: 'shipped', note_id: null, slug: 'memex-guide', title: 'Memex guide', size_tokens: 37500 },
  ]
  await page.goto(SKILLS)
  const dashboard = page.getByRole('region', { name: 'skills enabled', exact: true })
  await dashboard.waitFor()
  assert(await page.getByTestId('skills-active-count').innerText() === '2', 'inactive or pending skills counted as enabled')
  assert(await page.getByTestId('skills-context-size').innerText() === '~50K', 'unavailable text counted in total')
  assert(await dashboard.getByRole('combobox').count() === 0, 'connection switcher remains')
  assert(await dashboard.locator('.size-segment').count() === 2, 'incorrect segment count')
  assert(await dashboard.locator('.size-segment').first().getAttribute('data-kind') === 'shipped', 'built-ins do not lead the bar')
  const segment = dashboard.locator('[data-alias="house-style"]')
  const bar = await dashboard.getByTestId('skills-size-bar').boundingBox()
  const bounds = await segment.boundingBox()
  assert(Math.abs(bounds.width / bar.width - .25) < .001, 'segment does not show its share of the full instruction size')
  await segment.hover()
  assert((await dashboard.getByRole('status').innerText()).includes('House style'), 'hover does not show the skill name')
  assert((await dashboard.getByRole('status').innerText()).includes('25% of total'), 'share missing from details')
  await dashboard.getByRole('heading').hover()
  await segment.focus()
  assert(await dashboard.getByRole('status').isVisible(), 'keyboard focus has no details')
  await segment.press('Escape')
  assert(await dashboard.getByRole('status').count() === 0, 'Escape did not dismiss details')
  await page.locator('article[data-note-id="5"]').getByRole('checkbox').uncheck()
  await page.waitForFunction(() => document.querySelector('[data-testid="skills-active-count"]')?.textContent === '1')
  assert(await page.getByTestId('skills-context-size').innerText() === '~37.5K', 'disabled instructions remain in total')
  assert(await dashboard.locator('.size-segment').count() === 1, 'disabled skill retains a segment')
})

await scenario('large libraries fill the composition bar without suggesting a capacity limit', async (page, state) => {
  state.row.size_tokens = 300000
  state.extraRows = [{ ...ROW, note_id: 9, slug: 'another-skill', size_tokens: 50000 }]
  await page.goto(SKILLS)
  const dashboard = page.getByRole('region', { name: 'skills enabled', exact: true })
  await dashboard.waitFor()
  assert(!(await dashboard.innerText()).includes('budget'), 'summary still suggests a capacity budget')
  assert(await page.getByTestId('skills-context-size').innerText() === '~350K', 'total size is incorrect')
  assert(await dashboard.locator('.size-segment').count() === 2, 'large skill disappeared')
  const widths = await dashboard.locator('.size-segment').evaluateAll(els => els.reduce((sum, el) => sum + el.getBoundingClientRect().width, 0))
  const bar = await dashboard.getByTestId('skills-size-bar').boundingBox()
  assert(Math.abs(widths - bar.width) < 1, 'segments do not fill the bar')
  assert(await dashboard.evaluate(el => el.scrollWidth <= el.clientWidth), 'bar overflows dashboard')
})

await scenario('a rejected card switch stays enabled and shows the error', async (page, state) => {
  state.failPatch = true
  await page.goto(SKILLS)
  const card = page.locator('article[data-note-id="5"]')
  const toggle = card.getByRole('checkbox', { name: 'Enable House style', exact: true })
  await toggle.click()
  await card.getByRole('alert').waitFor()
  assert(await toggle.isChecked(), 'failed pause left the switch unchecked')
  assert(await card.getAttribute('data-enabled') === 'true', 'failed pause dimmed the card')
})


await scenario('a rejected panel switch returns to the saved state', async (page, state) => {
  state.failPatch = true
  await openPanel(page)
  await page.locator('#skill-enabled').click()
  await page.locator('.modal-body .alert').waitFor()
  assert(await page.locator('#skill-enabled').isChecked(), 'failed panel pause left the switch unchecked')
})

await scenario('panel connection checkboxes cannot accidentally grant access to everyone', async (page, state) => {
  await openPanel(page)
  const card = page.locator('.modal-body')
  await card.getByRole('checkbox', { name: 'Claude Code', exact: true }).uncheck()
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), card.getByRole('button', { name: 'Save connections', exact: true }).click()])
  assert(JSON.stringify(state.row.grants) === '[2]', 'connection restriction was not saved')
  await card.getByRole('checkbox', { name: 'Codex', exact: true }).uncheck()
  assert(await card.getByRole('button', { name: 'Save connections', exact: true }).isDisabled(), 'empty selection would grant every connection')
  assert(state.patches.length === 1, 'empty connection selection was sent')
})

await scenario('cards show chat examples directly and keep settings in the panel', async (page) => {
  await page.goto(SKILLS)
  const card = page.locator('article[data-note-id="5"]')
  await card.getByRole('button', { name: 'Copy prompt', exact: true }).waitFor()
  assert((await card.innerText()).includes('house-style'), 'chat example does not identify the skill')
  assert((await card.innerText()).includes('describe your task'), 'chat example lacks a place for the actual task')
  assert(await card.getByRole('group', { name: 'Who can use this skill' }).count() === 0, 'connection settings remain on the card')
  assert(await card.getByText('About this card', { exact: true }).count() === 0, 'metadata explanation remains on the card')
  await card.getByRole('button', { name: 'Open skill', exact: true }).click()
  const panel = page.locator('.modal-body')
  await panel.getByLabel('Skill alias', { exact: true }).waitFor()
  assert(await panel.locator('details').count() === 0, 'panel sections are hidden behind disclosures')
  assert((await panel.innerText()).includes(ROW.description), 'note summary is missing')
  assert(!(await panel.innerText()).includes(ROW.body), 'full instructions are duplicated in the panel')
  assert(await panel.getByText('About this card', { exact: true }).count() === 0, 'metadata explanation remains in the panel')
})

await scenario('system skills are separated, immutable, and included in the size breakdown', async (page, state) => {
  state.extraRows = [{ ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-guide', title: 'Memex guide', size_tokens: 100 }]
  await page.goto(SKILLS)
  await page.getByTestId('skills-active-count').waitFor()
  assert(await page.getByTestId('skills-active-count').innerText() === '2', 'always-on system skill excluded from active total')
  assert(await page.getByTestId('skills-context-size').innerText() === '~112', 'token breakdown total is incorrect')
  assert(await page.locator('.size-segment[data-alias="memex-guide"]').count() === 1, 'system skill has no size segment')
  assert(await page.getByRole('heading', { name: 'Built into Memex · 1', exact: true }).isVisible(), 'system skills section is hidden')
  const system = page.locator('.system-skill-card').filter({ has: page.getByRole('heading', { name: 'Memex guide', exact: true }) })
  assert(await system.getByRole('checkbox').count() === 0, 'system skills can be disabled')
  assert((await system.innerText()).includes('~100 tokens'), 'mini-card does not show token size')
  assert(await system.locator('button, a, input').count() === 0, 'system mini-card has interactive controls')
  assert(await page.locator('.skill-group').last().getAttribute('data-skill-group') === 'system', 'system section is not last')
  assert(await page.locator('[data-skill-group=available] article[data-note-id="6"]').count() === 1, 'pending skill did not stay in Available')
})

await scenario('opened card has balanced setup columns, editor action, and footer Cancel', async (page, state) => {
  state.row.short = 'Short catalogue teaser';
  state.row.description = 'The exact saved note summary.';
  await openPanel(page)
  assert(await page.locator('.modal-header #skill-enabled').isVisible(), 'enable control is not in the header')
  assert(await page.locator('.skill-summary').innerText() === state.row.description, 'panel replaced the saved summary')
  assert(await page.locator('.modal-body').getByRole('heading', { name: 'How to use this skill', exact: true }).isVisible(), 'usage heading missing')
  const edit = page.locator('.modal-footer').getByRole('link', { name: 'Edit this skill', exact: true })
  assert((await edit.getAttribute('href')).endsWith('/notes/5/edit'), 'edit action does not target the note editor')
  assert(await page.locator('.modal-header .btn-close').count() === 0, 'header still has a close icon')
  const left = await page.locator('.skill-alias-section').boundingBox()
  const right = await page.locator('.skill-howto-section').boundingBox()
  assert(Math.abs(left.width - right.width) < 1 && Math.abs(left.height - right.height) < 1, 'setup columns are not equal width and height')
  assert(left.y === right.y && right.x > left.x, 'setup sections are not side by side')
  const access = await page.locator('.skill-connections').boundingBox()
  assert(access.y + access.height <= left.y, 'assistant access is not above the setup columns')
  await page.getByLabel('Skill alias', { exact: true }).fill('unsaved-alias')
  await page.getByRole('checkbox', { name: 'Claude Code', exact: true }).uncheck()
  await page.getByRole('button', { name: 'Cancel', exact: true }).click()
  await page.waitForSelector('.modal.show', { state: 'hidden' })
  assert(state.patches.length === 0, 'Cancel submitted unfinished edits')
  await openPanel(page)
  assert(await page.getByLabel('Skill alias', { exact: true }).inputValue() === ROW.slug, 'unsaved alias survived Cancel')
  assert(await page.getByRole('checkbox', { name: 'All connected assistants', exact: true }).isChecked(), 'unsaved connection selection survived Cancel')
})

await scenario('twenty connections form two balanced columns and save the last choice', async (page, state) => {
  state.connections = Array.from({ length: 20 }, (_, i) => ({ ...CONNECTIONS[0], id: i + 1, display_name: `Assistant ${i + 1}` }))
  await openPanel(page)
  const options = page.locator('.connection-option')
  assert(await options.count() === 20, 'some connections are missing')
  const boxes = await options.evaluateAll(els => els.map(el => { const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width } }))
  assert(new Set(boxes.map(b => b.x)).size === 2, 'connections are not arranged into two columns')
  assert(boxes[0].y === boxes[1].y && Math.abs(boxes[0].width - boxes[1].width) < 1, 'columns are unbalanced')
  await page.locator('.modal-body').getByRole('checkbox', { name: 'Assistant 20', exact: true }).uncheck()
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), page.getByRole('button', { name: 'Save connections', exact: true }).click()])
  assert(state.row.grants.length === 19 && !state.row.grants.includes(20), 'last connection choice was not saved')
})

await scenario('chat examples copy the current short name and report clipboard failures', async (page, state) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.copiedSkillExample = text } } }))
  await page.goto(SKILLS)
  const card = page.locator('article[data-note-id="5"]')
  await card.getByRole('button', { name: 'Copy prompt', exact: true }).waitFor()
  await card.getByRole('button', { name: 'Copy prompt', exact: true }).click()
  await card.getByRole('button', { name: 'Copied', exact: true }).waitFor()
  assert((await page.evaluate(() => window.copiedSkillExample)).includes('skill house-style to'), 'copied prompt omitted the short name')
  await page.evaluate(() => { navigator.clipboard.writeText = async () => { throw new Error('Clipboard denied') } })
  await card.getByRole('button', { name: 'Copied', exact: true }).click()
  await card.getByRole('alert').waitFor()
  assert((await card.getByRole('alert').innerText()).includes('Could not copy'), 'clipboard failure was hidden')
})

await browser.close()
server.close()

if (failures.length) {
  console.error(`\nSkills panel behaviour check: ${failures.length} failure(s)`)
  for (const f of failures) console.error(`  ${f}`)
  process.exit(1)
}
console.log('\nSkills panel behaviour check: all scenarios passed')
