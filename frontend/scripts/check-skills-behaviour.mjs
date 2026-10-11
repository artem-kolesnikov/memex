#!/usr/bin/env node
/**
 * The skills page and a skill's own page, asserted against the built SPA in a
 * browser with the API stubbed.
 *
 * The skills are the notes list's rows; opening one opens the skill as its
 * note, with the skill's switch at its name and its alias, example and details
 * in the rail. Pausing and narrowing to a connection go through
 * `PATCH /api/skills/<id>` from the page, renaming from the editor, and a bad
 * alias is refused inline before it is ever sent. None of that is visible to
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
  console.error('Skills behaviour check failed:\n')
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
  blocked: [], usage: { total_30d: 0, last_at: null, last_token_id: null, by_token: [] }, lint: [], size_tokens: 12,
}

const CONNECTIONS = [
  { id: 1, label: 'Claude Code', display_name: null, role: 'agent', icon: null, icon_url: null, icon_url_dark: null },
  { id: 2, label: 'Codex', display_name: null, role: 'agent', icon: null, icon_url: null, icon_url_dark: null },
]

const PENDING_ROW = {
  kind: 'note', status: 'pending', slug: 'draft-skill', title: 'Draft skill',
  description: 'Still waiting for review.', short: null, body: 'Steps.',
  updated_at: '2026-09-01 09:00:00', note_id: 6, enabled: true, auto: true, command: true,
  blocked: [], usage: { total_30d: 0, last_at: null, last_token_id: null, by_token: [] }, lint: [], size_tokens: 8,
}

/** A skill of the owner's as its note: the same text, tagged `skill`. */
function skillNote(row) {
  return {
    id: row.note_id, version: 1, title: row.title, body_md: row.body, summary: row.description, summary_by: null,
    status: row.status === 'pending' ? 'pending' : 'verified', source: 'manual', source_url: null,
    last_actor: 'owner', edited_by: null, added_by: null, added_by_token_id: null, described_at: null, embedded: true,
    pending_proposals: 0, flagged: false, curation_flag: null, tags: [{ id: 9, name: 'skill' }], links: [], backlinks: [],
    created_at: '2026-09-01T09:00:00+00:00', updated_at: '2026-09-01T09:00:00+00:00',
  }
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
    const verb = /^POST \/api\/skills\/system\/([a-z-]+)\/(unlock|restore)$/.exec(key)
    if (verb) {
      state.systemVerbs = [...(state.systemVerbs ?? []), `${verb[2]} ${verb[1]}`]
      const row = (state.extraRows ?? []).find((r) => r.slug === verb[1])
      Object.assign(row, verb[2] === 'unlock' ? { unlocked: true, outdated: false, note_id: 77 } : { unlocked: false, outdated: false, note_id: null })
      return json({ skill: row }, verb[2] === 'unlock' ? 201 : 200)
    }
    if (key === 'PUT /api/notes/5') {
      state.noteSaves = (state.noteSaves ?? 0) + 1
      return json({ note: skillNote(state.row) })
    }
    if (key === 'PATCH /api/skills/5') {
      const patch = request.postDataJSON()
      state.patches.push(patch)
      if (state.failPatch) return json({ error: 'Could not save this change.' }, 503)
      state.row = { ...state.row, ...patch }
      if ('enabled' in patch) state.row.status = patch.enabled ? 'served' : 'paused'
      return json({ skill: state.row })
    }
    const one = /^GET \/api\/notes\/(\d+)(\/revisions|\/graph)?$/.exec(key)
    if (one && one[2] === '/revisions') return json({ note_id: Number(one[1]), keep_per_note: 10, revisions: [] })
    if (one && one[2] === '/graph') return json({ center: Number(one[1]), depth: 1, nodes: [], edges: [], truncated: false, total_notes: 1 })
    if (one) {
      const row = [state.row, PENDING_ROW, ...(state.extraRows ?? [])].find((r) => r.note_id === Number(one[1]) && r.kind !== 'catalogue')
      if (row) return json(skillNote(row))
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

const SWITCH = '.mm-skill-title-line .mm-skill-switch input'
const RAIL = '.mm-note-context'

const openSkill = async (page, title = 'House style', id = 5) => {
  await page.goto(SKILLS)
  await page.getByRole('button', { name: title, exact: true }).click()
  await page.waitForURL(new RegExp(`/skills/${id}$`))
  await page.waitForSelector(`${RAIL} .mm-skill-alias, ${RAIL} .mm-context-skill`)
}

// ── scenarios ────────────────────────────────────────────────────────────

await scenario('opening a skill explains chat usage without internal load forms', async (page, state, { expectText }) => {
  await openSkill(page)
  await expectText('Use my Memex skill house-style')
  assert(await page.locator(SWITCH).isVisible(), 'enable control is missing')
  const rail = await page.locator(RAIL).innerText()
  assert(!rail.includes('get_skill(') && !rail.includes('memex://') && !rail.includes('/mcp__'), 'internal load forms crowd the instructions')
})

await scenario('pausing sends {enabled:false} and the switch turns off', async (page, state) => {
  await openSkill(page)
  await page.locator(SWITCH).click()
  await page.waitForTimeout(200)
  assert(JSON.stringify(state.patches.at(-1)) === JSON.stringify({ enabled: false }), `patch: ${JSON.stringify(state.patches.at(-1))}`)
  assert(!(await page.locator(SWITCH).isChecked()), 'the switch stayed on after pausing')
})

await scenario('unticking Claude Code sends {blocked:[1]}', async (page, state) => {
  await openSkill(page)
  await page.locator(RAIL).getByRole('checkbox', { name: 'Claude Code', exact: true }).uncheck()
  await page.locator(RAIL).getByRole('button', { name: 'Save connections', exact: true }).click()
  await page.waitForTimeout(200)
  assert(JSON.stringify(state.patches.at(-1)) === JSON.stringify({ blocked: [1] }), `patch: ${JSON.stringify(state.patches.at(-1))}`)
  assert(!(await page.locator(RAIL).getByRole('checkbox', { name: 'Claude Code', exact: true }).isChecked()), 'the saved choice did not stay')
})

await scenario('the alias is read and copied on the page, and renamed in the editor', async (page, state, { expectText }) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.copied = text } } }))
  await openSkill(page)
  assert(await page.locator(`${RAIL} input:not([type=checkbox])`).count() === 0, 'the alias is still a field on the page')
  assert(await page.locator(`${RAIL} .mm-skill-alias code`).innerText() === 'house-style', 'the alias is not shown')
  await page.locator(RAIL).getByRole('button', { name: 'Copy alias', exact: true }).click()
  assert(await page.evaluate(() => window.copied) === 'house-style', 'Copy alias did not copy the alias')
  await page.goto(`${SKILLS}/5/edit`)
  const alias = page.locator('#editor_alias')
  await alias.waitFor()
  assert(await alias.inputValue() === 'house-style', 'the editor does not hold the alias')
  const save = page.locator('form button[type=submit]').first()
  await alias.fill('Bad Slug')
  await save.click()
  await expectText('lowercase letters, digits and single hyphens')
  await alias.fill('a'.repeat(65))
  await save.click()
  assert(state.patches.length === 0 && !state.noteSaves, `a bad alias reached the server: ${JSON.stringify(state.patches)}`)
  await alias.fill('style')
  await Promise.all([page.waitForURL(/\/skills$/), save.click()])
  assert(JSON.stringify(state.patches) === JSON.stringify([{ slug: 'style' }]), `patch: ${JSON.stringify(state.patches)}`)
  assert(state.noteSaves === 1, 'the note was not saved with the alias')
})

await scenario('a pending skill disables its switch and Save connections', async (page) => {
  await openSkill(page, 'Draft skill', 6)
  assert(await page.locator(SWITCH).isDisabled(), 'the switch was not disabled on a pending skill')
  assert(await page.locator(RAIL).getByRole('button', { name: 'Save connections', exact: true }).isDisabled(), 'Save connections was not disabled on a pending skill')
  assert(await page.getByRole('button', { name: 'Approve', exact: true }).count() === 1, 'a pending skill lost its note\'s Approve')
})

await scenario('New skill opens the editor with the tag set and the scaffold in the body', async (page, state, { expectText }) => {
  await page.goto(SKILLS)
  await page.click('text=New skill')
  await page.waitForURL(/\/skills\/new$/)
  await page.waitForSelector('.cm-content')
  await expectText('skill')
  const body = await page.evaluate(() => document.querySelector('.cm-content')?.innerText ?? '')
  assert(body.includes('When to use this') && body.includes('Steps'), `body: ${body}`)
})

await scenario('one enable switch opens all supported skill access paths', async (page, state) => {
  state.row.enabled = false
  state.row.status = 'paused'
  state.row.auto = false
  state.row.command = false
  await openSkill(page)
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), page.locator(SWITCH).click()])
  assert(state.row.enabled && state.row.auto && state.row.command, 'enabling left a skill access path disabled')
  assert(await page.locator('#skill-auto, #skill-command').count() === 0, 'technical access switches remain in the UI')
})

await scenario('paused and pending skills offer no working load forms', async (page, state) => {
  state.row.enabled = false
  state.row.status = 'paused'
  await openSkill(page)
  let rail = await page.locator(RAIL).innerText()
  assert(!rail.includes('get_skill(') && !rail.includes('/mcp__'), 'paused skill advertised a load form')
  await openSkill(page, 'Draft skill', 6)
  rail = await page.locator(RAIL).innerText()
  assert(!rail.includes('get_skill(') && !rail.includes('/mcp__'), 'pending skill advertised a load form')
})

await scenario('ticking a connection back gives it the skill again', async (page, state) => {
  state.row.blocked = [1]
  await openSkill(page)
  assert(!(await page.locator(RAIL).getByRole('checkbox', { name: 'Claude Code', exact: true }).isChecked()), 'a kept-out connection shows ticked')
  await page.locator(RAIL).getByRole('checkbox', { name: 'Claude Code', exact: true }).check()
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), page.locator(RAIL).getByRole('button', { name: 'Save connections', exact: true }).click()])
  assert(JSON.stringify(state.patches.at(-1)) === JSON.stringify({ blocked: [] }), 'ticking a connection back did not clear its block')
})

await scenario('skill descriptions are readable before opening a panel', async (page) => {
  await page.goto(SKILLS)
  const row = page.locator('article').filter({ has: page.getByRole('button', { name: 'House style', exact: true }) })
  await row.waitFor({ timeout: 1500 })
  assert((await row.innerText()).includes(ROW.description), 'skill purpose is hidden until the panel opens')
})


await scenario('long skill content and its page fit a phone', async (page, state) => {
  await page.setViewportSize({ width: 390, height: 844 })
  state.row.title = 'A'.repeat(500)
  state.row.description = 'B'.repeat(1024)
  state.row.slug = 'c'.repeat(64)
  await page.goto(SKILLS)
  await page.getByRole('button', { name: state.row.title, exact: true }).waitFor()
  const fits = () => document.documentElement.scrollWidth <= window.innerWidth
  assert(await page.evaluate(fits), 'the skills list overflows a phone')
  await page.getByRole('button', { name: state.row.title, exact: true }).click()
  await page.waitForURL(/\/skills\/5$/)
  await page.locator(`${RAIL} .mm-skill-alias`).waitFor()
  assert(await page.evaluate(fits), 'the skill page overflows a phone')
})


await scenario('memex-docs and memex-writing are served but not listed among memex\'s skills', async (page, state) => {
  state.extraRows = [
    { ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-recall', title: 'memex — recall', size_tokens: 100, unlockable: true },
    { ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-docs', title: 'memex — docs', size_tokens: 100, unlockable: false },
    { ...ROW, kind: 'shipped', status: 'switched_off', note_id: null, slug: 'memex-writing', title: 'memex — writing', size_tokens: 1200, unlockable: false },
  ]
  await page.goto(SKILLS)
  await page.locator('.mm-skills-summary select').selectOption('system')
  const listed = await page.locator('.mm-skill-row').evaluateAll((els) => els.map((el) => el.getAttribute('data-skill')))
  assert(JSON.stringify(listed) === JSON.stringify(['memex-recall']), `System skills: ${JSON.stringify(listed)}`)
  assert(await page.getByTestId('skills-active-count').innerText() === '1', 'an unlisted skill is counted')
  await page.locator('.mm-skills-summary select').selectOption('user')
  assert(await page.locator('.mm-skill-row[data-skill^="memex-"]').count() === 0, 'memex\'s skills are listed as the owner\'s')
})

await scenario('the line above the list counts what is on and its size, for whose skills are shown', async (page, state) => {
  state.row.size_tokens = 12500
  state.extraRows = [
    { ...ROW, note_id: 9, slug: 'disabled-skill', title: 'Disabled skill', status: 'paused', enabled: false, size_tokens: 99999 },
    { ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-curation', title: 'memex — curation', size_tokens: 337500, unlockable: true },
  ]
  await page.goto(SKILLS)
  await page.getByTestId('skills-active-count').waitFor()
  const line = () => page.locator('.mm-skills-summary-line').innerText()
  assert(await page.getByTestId('skills-active-count').innerText() === '1', 'off or pending skills counted as enabled')
  assert((await line()).includes('~12.5K tk'), `the size counts what is off, or memex's: ${await line()}`)
  assert(await page.locator('.mm-skill-row[data-skill="memex-curation"]').count() === 0, 'memex\'s skills are mixed into the owner\'s')
  await page.locator('.mm-skills-summary select').selectOption('system')
  assert((await line()).includes('~337.5K tk'), `the size does not follow the selector: ${await line()}`)
  assert(await page.locator('.mm-skills-summary').evaluate((el) => el.getBoundingClientRect().height < 60), 'the selector and the count are not on one line')
  const notes = await page.locator('.mm-skills-summary select').evaluate((el) => el.getBoundingClientRect().height)
  assert(notes >= 36, `the selector is smaller than the notes list's: ${notes}px`)
})

await scenario('in a window the rows sit under a head, the last used first, and the whole row opens', async (page, state) => {
  state.extraRows = [{ ...ROW, note_id: 9, slug: 'used-skill', title: 'Used skill', usage: { total_30d: 3, last_at: '2026-10-01 10:00:00', last_token_id: 1, by_token: [] } }]
  await page.goto(SKILLS)
  await page.locator('.app-tray .app-note-list-head').waitFor()
  const listed = await page.locator('.mm-skill-row').evaluateAll((els) => els.map((el) => el.getAttribute('data-skill')))
  assert(JSON.stringify(listed) === JSON.stringify(['used-skill', 'draft-skill', 'house-style']), `order: ${JSON.stringify(listed)}`)
  assert(await page.locator('.mm-skill-row input').count() === 0, 'a row carries a switch')
  await page.locator('.mm-skill-row[data-skill="house-style"] .app-note-summary').click({ force: true })
  await page.waitForURL(/\/skills\/5$/)
  await page.goBack()
  await page.locator('.mm-skill-row').first().waitFor()
})

await scenario('one of memex\'s skills is unlocked behind a warning, and restored from its note', async (page, state, { expectText }) => {
  state.extraRows = [
    { ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-recall', title: 'memex — recall', unlockable: true, unlocked: false, outdated: false },
    { ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-docs', title: 'memex — docs', unlockable: false, unlocked: false, outdated: false },
  ]
  await page.goto(`${SKILLS}?open=memex-docs`)
  await page.locator('.mm-skill-frame').waitFor()
  assert(await page.getByRole('button', { name: 'Unlock', exact: true }).count() === 0, 'memex-docs offers Unlock')
  await page.goto(`${SKILLS}?open=memex-recall`)
  await page.getByRole('button', { name: 'Unlock', exact: true }).click()
  const dialog = page.locator('.modal.show')
  await dialog.waitFor()
  assert((await dialog.innerText()).includes('no longer updated by new memex releases'), 'the warning does not say what unlocking costs')
  assert(!(state.systemVerbs ?? []).length, 'Unlock acted before it was confirmed')
  await Promise.all([page.waitForURL(/\/skills\/77$/), dialog.getByRole('button', { name: 'Unlock and edit', exact: true }).click()])
  assert(JSON.stringify(state.systemVerbs) === JSON.stringify(['unlock memex-recall']), `verbs: ${JSON.stringify(state.systemVerbs)}`)
  await page.getByRole('link', { name: 'Edit this skill', exact: true }).waitFor()
  await expectText('You unlocked this system skill')
  assert(await page.locator(SWITCH).isDisabled(), 'an unlocked system skill can be switched off')
  assert(await page.locator(`${RAIL} .skill-connections`).count() === 0, 'an unlocked system skill can be kept from a connection')

  state.extraRows[0].outdated = true
  await page.reload()
  await page.getByText('memex has updated this skill since you unlocked it').waitFor()
  await page.goto(SKILLS)
  await page.locator('.mm-skills-summary select').selectOption('system')
  assert(await page.locator('.mm-skill-row[data-skill="memex-recall"] .mm-skill-unlocked').count() === 1, 'the list does not mark the unlocked skill')
  await page.goto(`${SKILLS}/77`)
  await page.getByRole('button', { name: "Restore memex's version", exact: true }).click()
  await Promise.all([page.waitForURL(/open=memex-recall/), page.locator('.modal.show').getByRole('button', { name: 'Restore', exact: true }).click()])
  assert(JSON.stringify(state.systemVerbs.at(-1)) === JSON.stringify('restore memex-recall'), `verbs: ${JSON.stringify(state.systemVerbs)}`)
  await page.getByRole('button', { name: 'Unlock', exact: true }).waitFor()
  assert(await page.getByRole('link', { name: 'Edit this skill', exact: true }).count() === 0, 'a restored skill still offers Edit')
})

await scenario('a rejected switch on the skill page returns to the saved state', async (page, state) => {
  state.failPatch = true
  await openSkill(page)
  await page.locator(SWITCH).click()
  await page.getByRole('alert').first().waitFor()
  assert(await page.locator(SWITCH).isChecked(), 'failed pause left the switch unchecked')
})

await scenario('every connection is ticked until unticked, and there is no All option', async (page, state) => {
  await openSkill(page)
  const card = page.locator(RAIL)
  assert(await card.getByText('All connected assistants').count() === 0, 'the All option is still there')
  for (const name of ['Claude Code', 'Codex']) {
    assert(await card.getByRole('checkbox', { name, exact: true }).isChecked(), `${name} is not ticked by default`)
  }
  await card.getByRole('checkbox', { name: 'Claude Code', exact: true }).uncheck()
  await card.getByRole('checkbox', { name: 'Codex', exact: true }).uncheck()
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), card.getByRole('button', { name: 'Save connections', exact: true }).click()])
  assert(JSON.stringify(state.row.blocked) === '[1,2]', `unticking both did not keep the skill from both: ${JSON.stringify(state.row.blocked)}`)
})

await scenario('the skill holds its example, settings and details in the rail', async (page, state) => {
  state.row.usage = { total_30d: 3, last_at: '2026-10-01 10:00:00', last_token_id: 1, by_token: [{ token_id: 1, count: 2, last_at: '2026-10-01 10:00:00' }, { token_id: 2, count: 1, last_at: '2026-09-30 10:00:00' }] }
  await openSkill(page)
  const rail = page.locator(RAIL)
  const box = rail.locator('.mm-skill-box')
  assert(await box.count() === 1, 'the skill\'s own parts are not in one box')
  for (const part of ['Who can use this skill', 'Skill alias', 'How to use this skill']) {
    assert((await box.innerText()).includes(part), `${part} is not in the skill's box`)
  }
  assert((await box.innerText()).includes('describe your task'), 'chat example lacks a place for the actual task')
  const details = await rail.locator('.mm-context-details').innerText()
  for (const fact of ['Loaded', '3 loads in 30 days', 'Last used', 'Used by', 'Claude Code · 2', 'Codex · 1', 'Created', 'Size']) {
    assert(details.includes(fact), `the details lack "${fact}": ${details}`)
  }
  assert(await rail.locator('.mm-context-backlinks').count() === 0, 'a skill shows backlinks')
  assert(await rail.locator('details').count() === 0, 'skill settings are hidden behind disclosures')
  assert((await page.locator('.mm-note-abstract').innerText()).includes(ROW.description), 'note summary is missing')
  assert((await page.locator('.note-body').innerText()).includes(ROW.body), 'the instructions are not on the sheet')
  assert(await page.getByText('About this card', { exact: true }).count() === 0, 'metadata explanation remains on the skill')
})

await scenario('memex\'s own skills are listed apart and open with their details, nothing to switch', async (page, state) => {
  state.extraRows = [{ ...ROW, kind: 'shipped', status: 'built_in', note_id: null, slug: 'memex-recall', title: 'memex — recall', size_tokens: 100, unlockable: true }]
  await page.goto(SKILLS)
  await page.locator('.mm-skills-summary select').selectOption('system')
  const listed = await page.locator('.mm-skill-row').evaluateAll((els) => els.map((el) => el.getAttribute('data-skill')))
  assert(JSON.stringify(listed) === JSON.stringify(['memex-recall']), `System skills: ${JSON.stringify(listed)}`)
  await page.locator('.mm-skill-row[data-skill="memex-recall"] .app-note-main').click()
  await page.waitForURL(/open=memex-recall/)
  const frame = page.locator('.mm-skill-frame')
  await frame.waitFor()
  assert(await frame.locator('.mm-skill-switch input').isDisabled(), 'a system skill can be switched off')
  const details = await frame.locator('.mm-context-details').innerText()
  assert(details.includes('Built into Memex') && details.includes('~100 tokens'), `the details lack source or size: ${details}`)
  await page.goBack()
  await page.locator('.mm-skill-row').first().waitFor()
  assert(!page.url().includes('open='), 'Back did not return to the list')
})

await scenario('a skill is its note: the switch at its name, its editor, and everything a note has', async (page, state) => {
  state.row.short = 'Short catalogue teaser'
  state.row.description = 'The exact saved note summary.'
  await openSkill(page)
  assert(await page.locator(`.mm-note-heading ${SWITCH}`).isVisible(), 'enable control is not at the name')
  assert(await page.locator('.mm-note-abstract').innerText() === state.row.description, 'the page replaced the saved summary')
  assert(await page.locator(RAIL).getByRole('heading', { name: 'How to use this skill', exact: true }).isVisible(), 'usage heading missing')
  const edit = page.getByRole('link', { name: 'Edit this skill', exact: true })
  assert((await edit.getAttribute('href')).endsWith('/skills/5/edit'), 'edit action does not target the skill\'s editor')
  assert(await page.getByRole('link', { name: 'Download SKILL.md', exact: true }).count() === 1, 'Download SKILL.md is missing')
  assert(await page.getByRole('link', { name: 'Download .md', exact: true }).count() === 0, 'a skill offers the note\'s Markdown download beside SKILL.md')
  assert(await page.locator('.mm-context-map').count() === 0, 'a skill shows the neighbourhood map')
  assert(await page.getByRole('button', { name: 'Flag for curation', exact: true }).count() === 1, 'the note\'s flag is missing')
  assert(await page.locator('.mm-history-ledger').count() === 1, 'the note\'s history is missing')
  assert((await page.locator('.mm-note-breadcrumbs').innerText()).startsWith('Skills'), 'the skill is not under Skills')
  assert(state.patches.length === 0, 'opening a skill changed it')
})

await scenario('twenty connections form two balanced columns and save the last choice', async (page, state) => {
  state.connections = Array.from({ length: 20 }, (_, i) => ({ ...CONNECTIONS[0], id: i + 1, display_name: `Assistant ${i + 1}` }))
  await openSkill(page)
  const options = page.locator('.connection-option')
  assert(await options.count() === 20, 'some connections are missing')
  const boxes = await options.evaluateAll(els => els.map(el => { const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width } }))
  assert(new Set(boxes.map(b => b.x)).size === 2, 'connections are not arranged into two columns')
  assert(boxes[0].y === boxes[1].y && Math.abs(boxes[0].width - boxes[1].width) < 1, 'columns are unbalanced')
  await page.locator(RAIL).getByRole('checkbox', { name: 'Assistant 20', exact: true }).uncheck()
  await Promise.all([page.waitForResponse(r => r.request().method() === 'PATCH'), page.getByRole('button', { name: 'Save connections', exact: true }).click()])
  assert(JSON.stringify(state.row.blocked) === '[20]', 'last connection choice was not saved')
})

await scenario('chat examples copy the current short name and report clipboard failures', async (page, state) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.copiedSkillExample = text } } }))
  await openSkill(page)
  const card = page.locator(RAIL)
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
  console.error(`\nSkills behaviour check: ${failures.length} failure(s)`)
  for (const f of failures) console.error(`  ${f}`)
  process.exit(1)
}
console.log('\nSkills behaviour check: all scenarios passed')
