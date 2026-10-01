#!/usr/bin/env node
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { dirname, extname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const root = join(dirname(fileURLToPath(import.meta.url)), '../dist')
assert(existsSync(join(root, 'index.html')), 'Build the SPA first')
const server = createServer((req, res) => {
  let file = join(root, new URL(req.url, 'http://local').pathname)
  if (!existsSync(file) || !extname(file)) file = join(root, 'index.html')
  res.setHeader('content-type', ({ '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html', '.svg': 'image/svg+xml' })[extname(file)] ?? 'application/octet-stream')
  res.end(readFileSync(file))
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const base = `http://127.0.0.1:${server.address().port}`
const handle = 'k7m2pq9wd4rn'

// The server's shape, with each option's text standing for itself so a test
// can tell which one the page shows.
const CHOICES = { scope: ['one_subject', 'one_idea', 'whole_topic'], opening: ['summary', 'answer', 'context'], format: ['mixed', 'prose', 'bullets'], reasoning: ['reasons', 'bare', 'rationale'] }
const ADD_ONS = { scope: ['scope_short'], format: ['format_minimal'], reasoning: ['reasoning_confidence', 'reasoning_sources'] }
const CONFLICTS = { scope_short: ['whole_topic'] }
const defaults = { writing: true, scope: 'one_subject', opening: 'summary', format: 'mixed', reasoning: 'reasons', scope_short: false, format_minimal: false, reasoning_confidence: false, reasoning_sources: false }
const said = (axis, value) => `Text for ${axis} ${value}.`
let revisions = 0
function view(settings = defaults, loadedBy = ['Claude']) {
  const options = Object.entries(CHOICES).map(([axis, values]) => ({
    axis,
    choices: values.map(value => ({ value, text: said(axis, value) })),
    add_ons: (ADD_ONS[axis] ?? []).map(key => ({ key, text: said(key, 'on'), conflicts_with: CONFLICTS[key] ?? [] })),
  }))
  const text = ['memex-writing header.', ...Object.keys(CHOICES).map(axis => said(axis, settings[axis]))].join('\n')
  return { revision: `r${++revisions}`, settings, defaults, options, skill: { slug: 'memex-writing', text, loaded_by: settings.writing ? loadedBy : [] }, profile_paragraph: '**The owner has no profile note yet.** A profile is a note tagged `user-profile`.' }
}
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r }); return { promise, resolve } }
const started = promise => Promise.race([promise, new Promise((_, reject) => { const t = setTimeout(() => reject(new Error('request did not start')), 5000); t.unref() })])
const browser = await chromium.launch()
const failures = []
let passed = 0
async function scenario(name, body, viewport = { width: 1440, height: 1000 }) {
  if (process.env.PERSONALIZATION_ONLY && !name.includes(process.env.PERSONALIZATION_ONLY)) return
  const context = await browser.newContext({ viewport })
  const page = await context.newPage()
  page.setDefaultTimeout(3000)
  const state = { profiles: [], view: view(), on: {}, writes: [] }
  const errors = []
  page.on('pageerror', e => errors.push(e.message))
  await page.route('**/api/**', async route => {
    const req = route.request(), key = `${req.method()} ${new URL(req.url()).pathname}`
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) })
    if (state.on[key]) return state.on[key](json, req)
    if (key === 'GET /api/me') return json({ id: 1, name: 'Test User', email: 'user@example.test', team: { id: 1, name: 'Demo', handle }, welcome_completed: true, system_tags: [], appearance: {}, icon_key: null, icon: null })
    if (key === 'GET /api/me/welcome') return json({ completed: true, facts: { profiles: state.profiles, connected: true, guide_read: true } })
    if (key === 'GET /api/personalization') return json(state.view)
    if (key === 'PATCH /api/personalization') {
      const { expected_revision, ...patch } = req.postDataJSON()
      state.writes.push({ expected_revision, ...patch })
      state.view = view({ ...state.view.settings, ...patch })
      return json(state.view)
    }
    if (key === 'GET /api/tags') return json({ tags: [], retired: [] })
    if (key === 'GET /api/inbox/count') return json({ pending_notes: 0, edit_proposals: 0, total: 0 })
    return json({})
  })
  try { await body(page, state); assert.deepEqual(errors, []); passed++; console.log(`ok ${name}`) }
  catch (e) { failures.push(`${name}: ${e.message}`); console.error(`FAIL ${name}: ${e.message}`) }
  finally { await context.close() }
}
async function open(page) { await page.goto(`${base}/${handle}/settings/personalization`); await page.locator('#pers-scope-one_subject').waitFor() }
const says = (page, axis) => page.locator(`tr[data-axis="${axis}"] .mm-pers-says`).textContent()
const saveState = (page, row) => page.locator(`[data-save-feedback="${row}"]`).first().getAttribute('data-state')

await scenario('each setting shows its choices and the exact text the current one adds', async (page, state) => {
  await open(page)
  assert.equal(await page.locator('tr[data-axis]').count(), 4)
  for (const axis of Object.keys(CHOICES)) {
    assert.equal((await says(page, axis)).trim(), said(axis, defaults[axis]))
    assert(await page.locator(`#pers-${axis}-${CHOICES[axis][0]}`).isChecked(), `${axis} does not start on its default`)
  }
  assert.equal(await page.locator('#pers-writing').isChecked(), false, 'the switch does not start on memex-writing')
  assert.match(await page.locator('#pers-writing-memex').getAttribute('class'), /is-active/)
  assert.match(await page.locator('[data-writing-reach]').textContent(), /Current text loaded by Claude\./)
  await page.locator('[data-writing-skill] > summary').click()
  assert.equal(await page.locator('[data-writing-skill] pre').textContent(), state.view.skill.text)
  assert.equal(state.writes.length, 0)
})

await scenario('a choice saves on its own and the text follows it', async (page, state) => {
  await open(page)
  let seen = state.view.revision
  await page.locator('label[for="pers-opening-answer"]').click()
  await page.waitForFunction(() => document.querySelector('[data-save-feedback="opening"]')?.dataset.state === 'saved')
  assert.deepEqual(state.writes, [{ expected_revision: seen, opening: 'answer' }])
  seen = state.view.revision
  assert.equal((await says(page, 'opening')).trim(), said('opening', 'answer'))
  await page.locator('#pers-reasoning_sources').check()
  await page.waitForFunction(() => document.querySelector('[data-save-feedback="reasoning"]')?.dataset.state === 'saved')
  assert.deepEqual(state.writes[1], { expected_revision: seen, reasoning_sources: true })
  assert.match(await says(page, 'reasoning'), /Text for reasoning_sources on\./)
})

await scenario('an add-on that contradicts the choice is disabled and says why', async (page, state) => {
  state.view = view({ ...defaults, scope_short: true })
  await open(page)
  assert.match(await says(page, 'scope'), /Text for scope_short on\./)
  await page.locator('label[for="pers-scope-whole_topic"]').click()
  await page.waitForFunction(() => document.querySelector('[data-save-feedback="scope"]')?.dataset.state === 'saved')
  assert.deepEqual(state.writes[0], { expected_revision: state.writes[0].expected_revision, scope: 'whole_topic', scope_short: false }, 'the contradicting add-on is not sent alongside')
  assert(await page.locator('#pers-scope_short').isDisabled())
  assert.equal(await page.locator('#pers-scope_short').isChecked(), false)
  assert.match(await page.locator('[data-add-on="scope_short"]').textContent(), /Not with Whole topic/)
  assert.doesNotMatch(await says(page, 'scope'), /scope_short/)
})

await scenario('choosing my own writing skills switches memex-writing off and says what that needs', async (page, state) => {
  await open(page)
  const help = page.locator('[data-writing-source] .mm-pers-source-help')
  assert.match(await help.textContent(), /nothing tells them how to write notes/)
  assert((await help.getByRole('link', { name: 'Skills page' }).getAttribute('href')).endsWith('/skills'))
  const seen = state.view.revision
  await page.locator('#pers-writing-own').click()
  await page.waitForFunction(() => document.querySelector('[data-save-feedback="writing"]')?.dataset.state === 'saved')
  assert.deepEqual(state.writes, [{ expected_revision: seen, writing: false }])
  assert(await page.locator('#pers-writing').isChecked(), 'the switch did not move to my own skills')
  assert.match(await page.locator('#pers-writing-own').getAttribute('class'), /is-active/)
  assert(await page.locator('#pers-scope-one_idea').isDisabled())
  assert.equal(await page.locator('[data-writing-reach]').count(), 0, 'the pane still says assistants are pointed at memex-writing')
  await page.locator('#pers-writing-own').click()
  assert.equal(state.writes.length, 1, 'choosing the side already chosen wrote again')
  await page.locator('#pers-writing').click()
  await page.waitForFunction(() => !document.querySelector('#pers-scope-one_idea').disabled)
  assert.deepEqual(state.writes[1], { expected_revision: state.writes[1].expected_revision, writing: true })
})

await scenario('a change from another tab is refused, reloaded and said', async (page, state) => {
  await open(page)
  state.on['PATCH /api/personalization'] = json => {
    state.view = view({ ...defaults, format: 'prose' })
    return json({ error: 'These settings changed in another tab. The current ones are shown; make your change again.', conflict: 'revision', current: state.view }, 409)
  }
  await page.locator('label[for="pers-format-bullets"]').click()
  await page.getByText('These settings changed in another tab', { exact: true }).waitFor()
  assert(await page.locator('#pers-format-prose').isChecked(), 'the current settings were not shown')
  assert.equal((await says(page, 'format')).trim(), said('format', 'prose'))
  assert.equal(await saveState(page, 'format'), 'failed')
})

await scenario('a save that fails puts the control back', async (page, state) => {
  await open(page)
  state.on['PATCH /api/personalization'] = json => json({ error: 'Server unavailable' }, 503)
  await page.locator('label[for="pers-reasoning-bare"]').click()
  await page.waitForFunction(() => document.querySelector('[data-save-feedback="reasoning"]')?.dataset.state === 'failed')
  assert(await page.locator('#pers-reasoning-reasons').isChecked(), 'the failed choice stayed selected')
  assert.equal((await says(page, 'reasoning')).trim(), said('reasoning', 'reasons'))
})

await scenario('nothing else can change while a save is running', async (page, state) => {
  const gate = deferred(), began = deferred()
  await open(page)
  state.on['PATCH /api/personalization'] = async (json, req) => {
    began.resolve(); await gate.promise
    state.view = view({ ...state.view.settings, ...req.postDataJSON(), expected_revision: undefined })
    return json(state.view)
  }
  await page.locator('label[for="pers-scope-one_idea"]').click(); await started(began.promise)
  try {
    assert(await page.locator('#pers-format-prose').isDisabled(), 'a second change could start against the old revision')
    assert(await page.locator('#pers-writing').isDisabled())
  } finally { gate.resolve() }
  await page.waitForFunction(() => !document.querySelector('#pers-format-prose').disabled)
})

await scenario('an answer for a pane that was left is not applied to the one reopened', async (page, state) => {
  const gate = deferred(), began = deferred()
  let first = true
  state.on['GET /api/personalization'] = async json => {
    if (first) { first = false; began.resolve(); await gate.promise; return json(view({ ...defaults, format: 'prose' })) }
    return json(view({ ...defaults, format: 'bullets' }))
  }
  await page.goto(`${base}/${handle}/settings/personalization`); await started(began.promise)
  await page.locator('.app-settings-nav a, .app-settings-nav button').filter({ hasText: 'Preferences' }).first().click()
  await page.locator('.app-settings-nav a, .app-settings-nav button').filter({ hasText: 'Personalization' }).first().click()
  await page.locator('#pers-format-bullets').waitFor()
  const response = page.waitForResponse(r => r.url().endsWith('/api/personalization'))
  gate.resolve(); await response
  await page.waitForTimeout(100)
  assert(await page.locator('#pers-format-bullets').isChecked(), 'a stale read overwrote the current one')
})

await scenario('a failed profile lookup offers a retry, never a new profile, and a pending one is not in force', async (page, state) => {
  state.on['GET /api/me/welcome'] = json => json({ error: 'Discovery unavailable' }, 503)
  await open(page)
  const block = page.locator('[data-profile-note]')
  await block.getByRole('button', { name: 'Try again', exact: true }).waitFor()
  assert.equal(await block.getByRole('button', { name: 'Add profile' }).count(), 0, 'a failed lookup was presented as no profile')
  delete state.on['GET /api/me/welcome']
  state.profiles = [{ id: 7, title: 'About me', status: 'pending' }]
  await block.getByRole('button', { name: 'Try again', exact: true }).click()
  await block.getByRole('link', { name: 'Open profile', exact: true }).waitFor()
  assert.match(await block.textContent(), /waiting for your review, so it is not in force/)
})

await scenario('what assistants are told about the profile is shown word for word', async (page, state) => {
  await open(page)
  const block = page.locator('[data-profile-note]')
  await block.getByRole('button', { name: 'Add profile' }).waitFor()
  await page.locator('[data-profile-told] > summary').click()
  assert.equal(await page.locator('[data-profile-told] pre').textContent(), state.view.profile_paragraph)
})

for (const theme of ['light', 'dark']) await scenario(`390px ${theme} stacks each setting one line per cell`, async (page, state) => {
  await open(page)
  await page.evaluate(theme => window.setPreferredTheme(theme), theme)
  await page.waitForFunction(theme => document.documentElement.dataset.bsTheme === theme, theme)
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'horizontal overflow')
  const cells = await page.locator('tr[data-axis="scope"] td[data-label]').evaluateAll(tds => tds.map(td => getComputedStyle(td).display))
  assert.deepEqual(cells, ['flex', 'flex'], 'a stacked cell is not one line: label beside value')
  const radio = await page.locator('label[for="pers-scope-one_idea"]').boundingBox()
  assert(radio && radio.x >= 0 && radio.x + radio.width <= 391, 'a choice is outside the viewport')
  await page.locator('label[for="pers-scope-one_idea"]').click()
  await page.waitForFunction(() => document.querySelector('[data-save-feedback="scope"]')?.dataset.state === 'saved')
  if (process.env.PERSONALIZATION_SHOTS) {
    mkdirSync(process.env.PERSONALIZATION_SHOTS, { recursive: true })
    await page.screenshot({ path: join(process.env.PERSONALIZATION_SHOTS, `${theme}-390.png`), fullPage: true })
  }
}, { width: 390, height: 844 })

await browser.close(); await new Promise(resolve => server.close(resolve))
console.log(`${passed} personalization scenarios passed; ${failures.length} failed`)
if (failures.length) process.exitCode = 1
