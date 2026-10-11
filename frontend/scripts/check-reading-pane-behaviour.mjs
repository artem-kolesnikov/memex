#!/usr/bin/env node
/**
 * The reading pane, asserted against the built SPA in a browser with the API
 * stubbed.
 *
 * With Framed chosen in Preferences › Layout, or Dynamic on a window wide
 * enough, a row of the notes list opens its note beside the list: the address
 * carries it, a reload keeps it, another row, a wiki-link or a backlink inside
 * it replaces it, Edit and New note open the editor in it, Escape closes it;
 * Settings and the Activity log are dialogs that leave it as it was, and the
 * other list pages open what they list beside it the same way, their lists drawn as the notes list's rows; a skill
 * opens on its sheet, built-in, template or the owner's own, and the skills
 * list is the owner's or memex's, by last use, each switched at its name. The frame
 * keeps its close button on the actions' first line, its Info switch in one
 * place for reading and editing, and its scrollbar on the window's edge. With
 * Windowed, or Dynamic on a window too narrow for both, a row opens the note
 * page exactly as before, and a curation pass opens under its row. None of that
 * is visible to `npm run
 * build` or to a regex over the source. Needs `dist/`, no backend and no
 * database.
 */
import { createServer } from 'node:http'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Reading pane behaviour check failed:\n')
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
  id, version: 1, title: `Note ${id}`, source: 'web', source_url: null, status: 'verified',
  last_actor: 'owner', edited_by: null, summary: 'What it is about.', summary_by: null, tags: [],
  created_at: '2026-09-01T09:00:00+00:00', updated_at: '2026-09-01T09:00:00+00:00',
}))

const MANY = Array.from({ length: 120 }, (_, i) => ({ ...LIST[0], id: 1000 + i, title: `Many ${i + 1}` }))

// Note 5 links to note 7, so the pane has a wiki-link to follow, and note 6
// is linked from note 5, so it has a backlink. Note 7 waits for review, so its
// actions are the most a note has.
function note(id) {
  return {
    id, version: 1, title: `Note ${id}`,
    body_md: id === 5 ? 'Read [[Note 7]] next.' : id >= 9 ? `${'A long paragraph.\n\n'.repeat(120)}Then [[Note 10]].` : `The body of note ${id}.`,
    summary: null, summary_by: null, status: id === 7 ? 'pending' : 'verified', source: 'web', source_url: null,
    last_actor: 'owner', edited_by: null, added_by: null, added_by_token_id: null,
    described_at: null, embedded: true, pending_proposals: 0, flagged: false,
    curation_flag: null, tags: [],
    links: id === 5 ? [{ target: 'Note 7', note_id: 7, title: 'Note 7' }] : id >= 9 ? [{ target: 'Note 10', note_id: 10, title: 'Note 10' }] : [],
    backlinks: id === 6 ? [{ note_id: 5, title: 'Note 5' }] : [], created_at: '2026-09-01T09:00:00+00:00', updated_at: '2026-09-01T09:00:00+00:00',
  }
}

// The row check-skills-behaviour.mjs stubs, so the shape is what
// App\Controller\SkillController answers with.
const SKILL = {
  kind: 'note', status: 'served', slug: 'house-style', title: 'House style',
  description: 'How notes are written.', short: null, body: 'Steps.',
  updated_at: '2026-09-01 09:00:00', note_id: 5, enabled: true, auto: true, command: true,
  grants: [], usage: { total_30d: 0, last_at: null, last_token_id: null, by_token: [] }, lint: [], size_tokens: 12,
}

// A skill built into memex, a template not yet taken, and a second skill of
// the owner's that was loaded last week, so every kind of row is in the list
// and the order is by last use; switching the template on turns it into note 6.
const USED = {
  ...SKILL, slug: 'brief-style', title: 'Brief style', note_id: 8, body: 'A long step.\n\n'.repeat(120),
  usage: { total_30d: 2, last_at: '2026-10-01 10:00:00', last_token_id: null, by_token: [] },
}
const SHIPPED = {
  ...SKILL, kind: 'shipped', status: 'built_in', slug: 'skill-curation', title: 'Curation',
  description: 'Tidy the notes.', body: 'Curate.', note_id: null, updated_at: null, size_tokens: 40,
}
const TEMPLATE = {
  ...SKILL, kind: 'catalogue', status: 'offered', slug: 'skill-handoff', title: 'Handoff',
  description: 'Save where work stands.', body: 'Hand off.\n\n'.repeat(120), note_id: null, updated_at: null, size_tokens: 20,
}
const skillsTaken = { handoff: false }
const skillWrites = []
const CONNECTION = { id: 3, name: 'claude', label: 'Claude', display_name: null, role: 'agent', icon_key: null, icon: null, icon_url: null, icon_url_dark: null }

// The list's later pages: `broken` fails them while `fail` is set, `drift`
// answers one note short of its total, as a note written mid-scroll leaves it,
// and `shrinks` loses note 6 once `gone` is set, as an approval does.
const more = { fail: false, broken: 0, drift: 0, gone: false }

const ENTRY = {
  id: 41, created_at: '2026-09-01T09:00:00+00:00', by: 'Claude', actor: 'agent', action: 'edit',
  description: 'Edited **Note 5**.', operator_comment: null, is_precedent: false, note: { id: 5, title: 'Note 5' },
  note_title: 'Note 5', affected: [{ id: 5, title: 'Note 5' }], affected_total: 1, curation_run: null, diff: null,
}

const RUN = {
  log_id: 102, at: '2026-09-01T09:00:00+00:00', by: 'Nightly tidy', writer: 'curator', window_from: null,
  window_bounded: true, description: 'Tidied.',
  counts: { examined: 2, created: 0, edited: 1, held: 0, proposed: 0, flags_resolved: 0, observations: 0, tooling_gaps: 0 },
  claims: null, mismatches: [], still_defective: [], still_defective_total: 0, waiting: [], waiting_total: 0,
  written: [{ note_id: 5, title: 'Note 5', kind: 'edited', rows: 1 }], written_total: 1,
  examined: [{ note_id: 5, title: 'Note 5' }, { note_id: 6, title: 'Note 6' }],
}

async function apiStub(route) {
  const url = new URL(route.request().url())
  const method = route.request().method()
  const json = (body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
  if (url.pathname === '/api/me') return json(ME)
  if (url.pathname === '/api/me/setup') return json({ items: {}, complete: true, dismissed: true })
  if (url.pathname === '/api/me/onboarding') return json({ show: false, dismissed: true })
  if (url.pathname === '/api/inbox/count') return json({ pending_notes: 0, edit_proposals: 0, total: 0 })
  if (url.pathname === '/api/presets') return json({ presets: [], icons: [] })
  if (url.pathname === '/api/tags') return json({ tags: [], retired: [] })
  if (url.pathname === '/api/locales') return json({ locales: [] })
  if (url.pathname === '/api/skills') {
    const template = skillsTaken.handoff ? { ...TEMPLATE, kind: 'note', status: 'served', note_id: 6 } : TEMPLATE
    return json({ skills: [SKILL, template, USED, SHIPPED], connections: [CONNECTION] })
  }
  if (/^\/api\/skills\/\d+$/.test(url.pathname) && method === 'PATCH') {
    skillWrites.push(route.request().postDataJSON())
    return json({ skill: { ...SKILL, enabled: false, status: 'paused' } })
  }
  if (url.pathname === '/api/skills/skill-handoff/add' && method === 'POST') {
    skillsTaken.handoff = true
    return json({ note: note(6) })
  }
  const q = url.searchParams.get('q'), at = Number(url.searchParams.get('page'))
  if (url.pathname === '/api/notes' && q === 'broken') {
    if (at > 1) more.broken++
    if (at > 1 && more.fail) return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'down' }) })
    return json({ items: MANY.slice((at - 1) * 50, at * 50), total: MANY.length, semantic_unavailable: false })
  }
  if (url.pathname === '/api/notes' && q === 'drift') {
    if (at > 2) more.drift++
    return json({ items: at === 1 ? MANY.slice(0, 50) : at === 2 ? MANY.slice(49, 60) : [], total: 61, semantic_unavailable: false })
  }
  if (url.pathname === '/api/notes' && q === 'shrinks') {
    const items = more.gone ? LIST.filter((n) => n.id !== 6) : LIST
    return json({ items, total: items.length, semantic_unavailable: false })
  }
  if (url.pathname === '/api/notes' && url.searchParams.get('q') === 'many') {
    // 120 notes, read a page at a time as the list is scrolled.
    const per = Number(url.searchParams.get('per_page')), at = (Number(url.searchParams.get('page')) - 1) * per
    const items = MANY.slice(at, at + per)
    return json({ items, total: MANY.length, semantic_unavailable: false })
  }
  if (url.pathname === '/api/notes') return json({ items: LIST, total: LIST.length, semantic_unavailable: false })
  if (url.pathname === '/api/proposals') return json({ proposals: [] })
  if (url.pathname === '/api/curator-log') {
    return json({ entries: [ENTRY], filters: { actions: [], writers: [] }, run: null, total: 1, page: 1, pages: 1, per_page: 50 })
  }
  if (url.pathname === '/api/curation/digest') return json({ runs: [RUN], runs_total: 1 })
  const graph = /^\/api\/notes\/(\d+)\/graph$/.exec(url.pathname)
  if (graph) return json({ center: Number(graph[1]), depth: 1, nodes: [], edges: [], truncated: false, total_notes: 3 })
  const revisions = /^\/api\/notes\/(\d+)\/revisions$/.exec(url.pathname)
  if (revisions) return json({ note_id: Number(revisions[1]), keep_per_note: 10, revisions: [] })
  const one = /^\/api\/notes\/(\d+)$/.exec(url.pathname)
  if (one && method === 'PUT') return json({ note: note(Number(one[1])), suggested_tags: { tag_ids: [], new_tags: [] } })
  if (one) return json(note(Number(one[1])))
  return json({ items: [], notes: [], tags: [], total: 0 })
}

// ── the harness ──────────────────────────────────────────────────────────

const browser = await chromium.launch()
const problems = []
let checked = 0

function expect(condition, message) {
  checked++
  if (!condition) problems.push(message)
}

async function open({ layout, width }) {
  const context = await browser.newContext({ viewport: { width, height: 1000 } })
  await context.route('**/api/**', apiStub)
  if (layout) await context.addInitScript((mode) => localStorage.setItem('mm-layout', mode), layout)
  const page = await context.newPage()
  await page.goto(NOTES)
  await page.locator('.app-note-row').first().waitFor()
  return { context, page }
}

const row = (page, n) => page.locator('.app-note-row .app-note-main').nth(n)
const paneTitle = (page, text) => page.locator('.app-frame-reading .mm-note-title', text ? { hasText: text } : {})
const path = (page) => { const u = new URL(page.url()); return u.pathname + u.search }
// A wait that gives up quietly: the expectation after it says what went wrong.
const settle = (promise) => promise.then(() => true, () => false)

try {
  // Windowed: a row is the note page, as it always was.
  {
    const { context, page } = await open({ layout: 'windowed', width: 1920 })
    await row(page, 0).click()
    await settle(page.waitForURL(/\/notes\/5$/, { timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes/5`, `windowed: a row should open the note page, landed on ${path(page)}`)
    await context.close()
  }

  // Dynamic, wide window: two frames, before any note is open.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    const frames = await page.evaluate(() => ({
      list: document.querySelector('.app-frame-list')?.getBoundingClientRect().width ?? null,
      reading: document.querySelector('.app-frame-reading')?.getBoundingClientRect().width ?? null,
    }))
    expect(frames.list !== null && frames.reading !== null, 'frames: the list and the reading frame should both be there before a note is open')
    expect(frames.list === 420, `frames: the list frame should start 420px wide, was ${frames.list}px`)

    const divider = page.locator('.app-frame-divider')
    await divider.focus().catch(() => {})
    await page.keyboard.press('ArrowRight')
    const widened = await page.evaluate(() => ({
      drawn: document.querySelector('.app-frame-list')?.getBoundingClientRect().width ?? null,
      kept: localStorage.getItem('mm-list-width'),
    }))
    expect(widened.drawn === 436 && widened.kept === '436', `frames: an arrow key on the divider should widen the list and remember it, was ${JSON.stringify(widened)}`)

    const before = await page.evaluate(() => history.length)
    await row(page, 0).click()
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?open=5`, `pane: the address should name the open note, was ${path(page)}`)
    expect(await paneTitle(page, 'Note 5').count() === 1, 'pane: a row should open its note in the reading frame')
    expect(await page.locator('.app-note-row.is-open').count() === 1, 'pane: the open row should be marked')

    if (await paneTitle(page, 'Note 5').count() === 1) {
      await row(page, 1).click()
      expect(await settle(paneTitle(page, 'Note 6').waitFor({ timeout: 5000 })), 'pane: another row should replace the note')
      expect(await page.evaluate(() => history.length) === before, 'pane: opening notes should not add history entries')
    }
    await context.close()
  }

  // The address alone reopens it, and a wiki-link inside it stays in it.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await page.goto(`${NOTES}?open=5`)
    expect(await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 })), 'pane: ?open= on arrival should open the note')
    await page.locator('.app-frame-reading a.wiki-link').click({ timeout: 5000 }).catch(() => {})
    await settle(paneTitle(page, 'Note 7').waitFor({ timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?open=7`, `pane: a wiki-link should open in the pane, landed on ${path(page)}`)

    await page.keyboard.press('Escape')
    await settle(page.waitForURL((u) => !u.search.includes('open='), { timeout: 5000 }))
    expect(await paneTitle(page).count() === 0 && !path(page).includes('open='), 'pane: Escape should close it')
    await context.close()
  }

  // Everything a note offers stays in the frames: its editor, a new note,
  // a backlink. Saving or cancelling comes back to the note.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await page.goto(`${NOTES}?open=5`)
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    await page.locator('.app-frame-reading .mm-note-actions a', { hasText: 'Edit' }).click({ timeout: 5000 }).catch(() => {})
    await settle(page.locator('.app-frame-reading #title').waitFor({ timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?edit=5`, `edit: Edit should open the editor in the frame, landed on ${path(page)}`)
    await page.locator('.app-frame-reading .mm-note-actions button', { hasText: 'Cancel' }).click({ timeout: 5000 }).catch(() => {})
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?open=5`, `edit: Cancel should come back to the note, landed on ${path(page)}`)

    await page.locator('.app-frame-reading .mm-note-actions a', { hasText: 'Edit' }).click({ timeout: 5000 }).catch(() => {})
    await settle(page.locator('.app-frame-reading #title').waitFor({ timeout: 5000 }))
    await page.locator('.app-frame-reading .mm-note-actions button[type="submit"]').click({ timeout: 5000 }).catch(() => {})
    await settle(page.waitForURL(/open=5/, { timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?open=5`, `edit: Save should come back to the note, landed on ${path(page)}`)

    await page.locator('.app-page-head a', { hasText: 'New note' }).click({ timeout: 5000 }).catch(() => {})
    await settle(page.locator('.app-frame-reading #title').waitFor({ timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?edit=new`, `new: New note should open the editor in the frame, landed on ${path(page)}`)

    await page.goto(`${NOTES}?open=6`)
    await settle(paneTitle(page, 'Note 6').waitFor({ timeout: 5000 }))
    await page.locator('.app-frame-reading .mm-backlink-list a').first().click({ timeout: 5000 }).catch(() => {})
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?open=5`, `backlink: a backlink should open in the frame, landed on ${path(page)}`)
    await context.close()
  }

  // Settings is a dialog over the frames, which stay as they were under it.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await page.goto(`${NOTES}?open=5`)
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    await page.locator('.app-stage-head a[href$="/settings"]').click({ timeout: 5000 }).catch(() => {})
    await settle(page.waitForURL(/\/settings/, { timeout: 5000 }))
    await page.waitForTimeout(500)
    expect(await paneTitle(page, 'Note 5').count() === 1, 'settings: the open note should stay in its frame under Settings')
    await context.close()
  }

  // The skills in frames are rows like the notes list's: the owner's or
  // memex's, never both, the last used first, each marked enabled or disabled
  // at its name. A skill opens beside them as a note does: its switch and
  // name, its instructions on a sheet, Edit above. A template is a disabled
  // skill, and switching it on takes it into the knowledge base.
  {
    skillsTaken.handoff = false
    skillWrites.length = 0
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await page.goto(`${BASE}/${HANDLE}/skills`)
    const rows = page.locator('.app-frame-list .mm-skill-row')
    await settle(rows.first().waitFor({ timeout: 5000 }))
    const listed = await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-skill')))
    expect(JSON.stringify(listed) === JSON.stringify(['brief-style', 'skill-handoff', 'house-style']),
      `skills: the owner's skills should be one list, the last used first and then by name, was ${JSON.stringify(listed)}`)
    expect(await page.locator('.skill-card, .system-skill-card, .mm-skills-group, [data-testid="skills-size-bar"]').count() === 0, 'skills: no cards, group heads or size bar in frames')
    expect(await page.locator('[data-testid="skills-active-count"]').textContent().catch(() => '') === '2', 'skills: the count should be the owner\'s skills that are on')
    const marks = await rows.evaluateAll((els) => els.map((el) => [el.querySelector('.mm-skill-state')?.textContent?.trim(), el.querySelectorAll('input').length]))
    expect(JSON.stringify(marks) === JSON.stringify([['Enabled', 0], ['Disabled', 0], ['Enabled', 0]]),
      `skills: each row should be marked enabled or disabled, with no switch of its own, was ${JSON.stringify(marks)}`)
    await page.locator('.mm-skills-summary select').selectOption('system').catch(() => {})
    const system = await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-skill')))
    expect(JSON.stringify(system) === JSON.stringify(['skill-curation']), `skills: System skills should list memex's own alone, was ${JSON.stringify(system)}`)
    const counted = await page.locator('[data-testid="skills-active-count"]').textContent().catch(() => '')
    expect(counted === '1', `skills: the count should follow the selector, was ${JSON.stringify(counted)}`)
    await page.locator('.mm-skill-row[data-skill="skill-curation"] .app-note-main').click({ timeout: 5000 }).catch(() => {})
    const frame = page.locator('.app-frame-reading .mm-skill-frame')
    expect(await settle(frame.locator('.mm-note-title', { hasText: 'Curation' }).waitFor({ timeout: 5000 })), 'skills: a built-in skill should open beside the list')
    await page.locator('.mm-skills-summary select').selectOption('user').catch(() => {})

    const switchOf = frame.locator('.mm-skill-title-line .mm-skill-switch input')
    await page.locator('.mm-skill-row[data-skill="skill-handoff"] .app-note-main').click({ timeout: 5000 }).catch(() => {})
    await settle(frame.locator('.mm-note-title', { hasText: 'Handoff' }).waitFor({ timeout: 5000 }))
    await switchOf.check({ timeout: 5000 }).catch(() => {})
    await page.waitForTimeout(500)
    expect(skillsTaken.handoff && await page.locator('.mm-skill-row[data-skill="skill-handoff"] .mm-skill-state').textContent().catch(() => '') === 'Enabled',
      'skills: switching a template on should take it in, enabled')

    await page.locator('.mm-skill-row[data-skill="house-style"] .app-note-main').click({ timeout: 5000 }).catch(() => {})
    expect(await settle(switchOf.waitFor({ timeout: 5000 })), 'skills: the open skill should carry its switch at its name')
    expect(await page.locator('.mm-skill-row.is-open[data-skill="house-style"]').count() === 1, 'skills: the open row should be marked')
    await switchOf.uncheck({ timeout: 5000 }).catch(() => {})
    await page.waitForTimeout(300)
    expect(skillWrites.length === 1 && skillWrites[0].enabled === false, `skills: the switch should turn the skill off, sent ${JSON.stringify(skillWrites)}`)
    expect(await page.locator('.mm-skill-row[data-skill="house-style"] .mm-skill-state').textContent().catch(() => '') === 'Disabled',
      'skills: the row should read Disabled once the skill is off')
    expect((await frame.locator('.mm-note-paper .note-body').textContent().catch(() => ''))?.includes('Steps.'),
      'skills: the skill\'s instructions should be on the sheet')
    await frame.locator('a', { hasText: 'Edit' }).first().click({ timeout: 5000 }).catch(() => {})
    await settle(page.waitForURL(/edit=5/, { timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?edit=5`, `skills: Edit should open the editor in the notes frames, landed on ${path(page)}`)
    await context.close()
  }

  // Windowed, the skills are the cards and a skill is the dialog, as before.
  {
    const { context, page } = await open({ layout: 'windowed', width: 1920 })
    await page.goto(`${BASE}/${HANDLE}/skills`)
    await settle(page.locator('.skill-card').first().waitFor({ timeout: 5000 }))
    expect(await page.locator('.mm-skill-row').count() === 0, 'windowed: the skills should stay cards')
    await page.locator('.skill-card .skill-title').first().click({ timeout: 5000 }).catch(() => {})
    expect(await settle(page.locator('.modal.mm-skill-panel.show').waitFor({ timeout: 5000 })), 'windowed: a skill should open as the dialog')
    await context.close()
  }

  // The frame's own furniture: the close button keeps the corner however the
  // actions wrap, the Info switch is in one place for the note and its editor,
  // and the scroller runs to the window's edge however wide the frame is.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 1600 })
    await page.goto(`${NOTES}?open=7`)
    await settle(paneTitle(page, 'Note 7').waitFor({ timeout: 5000 }))
    const corner = await page.evaluate(() => {
      const top = (el) => el ? Math.round(el.getBoundingClientRect().top) : null
      const actions = document.querySelector('.app-frame-reading .mm-note-actions')
      return {
        close: top(document.querySelector('.app-frame-reading .mm-pane-close')),
        first: top(actions?.firstElementChild),
        last: top(actions?.lastElementChild),
      }
    })
    expect(corner.first !== null && corner.last > corner.first, `close: the pending note's actions should wrap in this frame, were ${JSON.stringify(corner)}`)
    expect(corner.close !== null && corner.close === corner.first, `close: the close button should stay on the actions' first line, was ${JSON.stringify(corner)}`)

    const info = () => page.evaluate(() => {
      const r = document.querySelector('.app-frame-reading .app-pane-toggle')?.getBoundingClientRect()
      return r ? `${Math.round(r.left)},${Math.round(r.top)}` : null
    })
    await page.goto(`${NOTES}?open=5`)
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    const reading = await info()
    await page.goto(`${NOTES}?edit=5`)
    await settle(page.locator('.app-frame-reading #title').waitFor({ timeout: 5000 }))
    const editing = await info()
    expect(reading !== null && reading === editing, `info: the Info switch should not move between the note (${reading}) and its editor (${editing})`)
    await context.close()
  }
  // The third frame scrolls as one, out to the window's edge, with the footer
  // at the end of it: held at the bottom under a short note, reached by
  // scrolling under a long one. What opens next starts at the top.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 2600 })
    await page.goto(`${NOTES}?open=5`)
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    const geometry = () => page.evaluate(() => {
      const column = document.querySelector('.app-frame-column')
      const footer = document.querySelector('.app-frame-column .footer')
      return {
        right: Math.round(column?.getBoundingClientRect().right ?? 0),
        window: document.documentElement.clientWidth,
        bottom: Math.round(column?.getBoundingClientRect().bottom ?? 0),
        footerTop: Math.round(footer?.getBoundingClientRect().top ?? 0),
        footerBottom: Math.round(footer?.getBoundingClientRect().bottom ?? 0),
        scrolls: column ? column.scrollHeight > column.clientHeight : false,
        top: column?.scrollTop ?? -1,
      }
    })
    const short = await geometry()
    expect(short.right > 0 && short.right === short.window, `scrollbar: the third frame's scroller should reach the window's edge, was ${JSON.stringify(short)}`)
    expect(short.footerBottom === short.bottom, `footer: under a short note the footer should sit at the frame's bottom, was ${JSON.stringify(short)}`)
    await page.goto(`${NOTES}?open=9`)
    await settle(paneTitle(page, 'Note 9').waitFor({ timeout: 5000 }))
    const long = await geometry()
    expect(long.scrolls && long.footerTop > long.bottom, `footer: under a long note the footer should wait at the end of the scroll, was ${JSON.stringify(long)}`)
    await page.evaluate(() => { const c = document.querySelector('.app-frame-column'); c?.scrollTo({ top: c.scrollHeight }) })
    const end = await geometry()
    expect(end.footerBottom === end.bottom, `footer: scrolled to the end, the footer should close the frame, was ${JSON.stringify(end)}`)
    await page.locator('.app-frame-reading .note-body a', { hasText: 'Note 10' }).click({ timeout: 5000 }).catch(() => {})
    await settle(paneTitle(page, 'Note 10').waitFor({ timeout: 5000 }))
    await page.waitForTimeout(100)
    const next = await geometry()
    expect(next.scrolls && next.top === 0, `scroll: a long note opened from the end of another should start at the top, was ${JSON.stringify(next)}`)
    await context.close()
  }

  // A skill opens at the top of the frame even when the one before it was
  // read to its end: the frame scrolls as one and is not drawn afresh.
  {
    skillsTaken.handoff = false
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await page.goto(`${BASE}/${HANDLE}/skills`)
    await page.locator('.mm-skill-row[data-skill="brief-style"] .app-note-main').click({ timeout: 5000 }).catch(() => {})
    await settle(page.locator('.app-frame-reading .mm-note-title', { hasText: 'Brief style' }).waitFor({ timeout: 5000 }))
    await page.evaluate(() => { const c = document.querySelector('.app-frame-column'); c?.scrollTo({ top: c.scrollHeight }) })
    await page.locator('.mm-skill-row[data-skill="skill-handoff"] .app-note-main').click({ timeout: 5000 }).catch(() => {})
    await settle(page.locator('.app-frame-reading .mm-note-title', { hasText: 'Handoff' }).waitFor({ timeout: 5000 }))
    await page.waitForTimeout(100)
    const top = await page.evaluate(() => document.querySelector('.app-frame-column')?.scrollTop ?? -1)
    expect(top === 0, `scroll: a skill opened after reading another to its end should start at the top, was at ${top}`)
    await context.close()
  }

  // Collapsing the sidebar moves nothing sideways or up and down: the mark,
  // the destinations and the account keep their centres, only the words go.
  {
    const { context, page } = await open({ layout: 'windowed', width: 1440 })
    const centres = () => page.evaluate(() => [
      document.querySelector('.app-brand-mark'),
      ...document.querySelectorAll('.app-nav .app-nav-icon i'),
      document.querySelector('.app-account-summary .app-account-avatar'),
    ].map((el) => { const r = el?.getBoundingClientRect(); return r ? `${Math.round(r.left + r.width / 2)},${Math.round(r.top + r.height / 2)}` : null }))
    const before = await centres()
    await page.locator('.app-sidebar-toggle').click({ timeout: 5000 }).catch(() => {})
    await page.waitForTimeout(400)
    const after = await centres()
    expect(before.every((c) => c !== null) && JSON.stringify(before) === JSON.stringify(after),
      `sidebar: collapsing should leave every icon where it was, was ${JSON.stringify(before)} then ${JSON.stringify(after)}`)
    await context.close()
  }

  // No pages: the list reads on as it is scrolled, 50 notes at a time, until
  // every match is there, in both layouts.
  for (const layout of ['windowed', 'framed']) {
    const { context, page } = await open({ layout, width: 1440 })
    await page.goto(`${NOTES}?q=many`)
    const rows = page.locator('.app-note-row')
    await settle(page.locator('.app-note-row', { hasText: 'Many 1' }).first().waitFor({ timeout: 5000 }))
    const first = await rows.count()
    const counts = [first]
    for (let i = 0; i < 4; i++) {
      await page.locator('.app-note-row').last().scrollIntoViewIfNeeded().catch(() => {})
      await page.waitForTimeout(400)
      counts.push(await rows.count())
    }
    expect(first === 50 && counts[counts.length - 1] === 120, `${layout}: scrolling should read on to every note, 50 at a time, counted ${JSON.stringify(counts)}`)
    expect(await page.locator('.app-pagination, .app-rows-control').count() === 0, `${layout}: the notes list should have no pager`)
    await context.close()
  }

  // The signed-in window never scrolls, so it keeps no gutter beside the
  // scrollbar that does: Windowed as well as framed.
  for (const layout of ['windowed', 'framed']) {
    const { context, page } = await open({ layout, width: 1440 })
    const gutter = await page.evaluate(() => getComputedStyle(document.documentElement).scrollbarGutter)
    expect(gutter === 'auto', `${layout}: the window should keep no scrollbar gutter, was ${gutter}`)
    await context.close()
  }

  // The other list pages are the notes list's rows, and a row opens beside
  // them what the page opens from it.
  for (const [where, opened, label] of [
    ['inbox', '.mm-review-panel', 'inbox'],
  ]) {
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await page.goto(`${BASE}/${HANDLE}/${where}`)
    const first = page.locator('.app-frame-list .app-note-row .app-note-main').first()
    expect(await settle(first.waitFor({ timeout: 5000 })), `${label}: the list should be the notes list's rows`)
    await first.click({ timeout: 5000 }).catch(() => {})
    expect(await settle(page.locator(`.app-frame-reading ${opened}`).waitFor({ timeout: 5000 })), `${label}: a row should open beside the list`)
    expect(await page.locator('.app-frame-list .app-note-row.is-open').count() === 1, `${label}: the open row should be marked`)
    await context.close()
  }

  // The Activity log is a dialog over the page in every layout, never a page
  // or a frame: the account menu opens it over the list, a pass opens under its row
  // inside it, and closing it returns to the page under it, however many views
  // were opened in between. Typed in, it opens over the notes list.
  for (const layout of ['windowed', 'framed']) {
    const { context, page } = await open({ layout, width: 1920 })
    await page.locator('.app-account-summary').click({ timeout: 5000 }).catch(() => {})
    await page.locator('.app-account-menu a[href$="/activity"]').click({ timeout: 5000 }).catch(() => {})
    const dialog = page.locator('.modal.mm-activity-dialog.show')
    expect(await settle(dialog.waitFor({ timeout: 5000 })), `${layout}: the Activity log should open as a dialog`)
    expect(await page.locator('.app-note-row').count() > 0, `${layout}: the notes list should stay under the Activity log`)
    expect(await page.locator('.app-frame-list .mm-journal, .app-frame-reading .mm-journal-frame').count() === 0, `${layout}: the Activity log should not be framed`)
    await dialog.locator('.app-segment', { hasText: 'Curation' }).click({ timeout: 5000 }).catch(() => {})
    await dialog.locator('.mm-run-tr').first().click({ timeout: 5000 }).catch(() => {})
    expect(await settle(dialog.locator('tr.mm-run-detail-row .mm-run-detail').waitFor({ timeout: 5000 })), `${layout}: a pass should open under its row in the dialog`)
    await dialog.locator('.btn-close').click({ timeout: 5000 }).catch(() => {})
    await settle(page.waitForURL((url) => url.pathname === `/${HANDLE}/notes`, { timeout: 5000 }))
    await page.waitForTimeout(400)
    expect(path(page) === `/${HANDLE}/notes`, `${layout}: closing the Activity log should return to the notes list, landed on ${path(page)}`)
    expect(await page.locator('.modal, .modal-backdrop').count() === 0, `${layout}: closing the Activity log should leave no dialog or backdrop`)
    await context.close()
  }
  {
    const { context, page } = await open({ layout: 'windowed', width: 1440 })
    await page.goto(`${BASE}/${HANDLE}/activity`)
    const dialog = page.locator('.modal.mm-activity-dialog.show')
    expect(await settle(dialog.waitFor({ timeout: 5000 })), 'typed in: the Activity log should open as a dialog')
    expect(await settle(page.locator('.app-note-row').first().waitFor({ timeout: 5000 })), 'typed in: the notes list should be under it')
    await dialog.locator('.btn-close').click({ timeout: 5000 }).catch(() => {})
    await settle(page.waitForURL(/\/notes$/, { timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes`, `typed in: closing should land on the notes list, landed on ${path(page)}`)
    await context.close()
  }

  // Dynamic, a window too narrow for both: the note page.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 1280 })
    await row(page, 0).click()
    await settle(page.waitForURL(/\/notes\/5$/, { timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes/5`, `narrow: a row should open the note page, landed on ${path(page)}`)
    await context.close()
  }

  // Framed stays framed on a window too narrow for Dynamic's frames.
  {
    const { context, page } = await open({ layout: 'framed', width: 1280 })
    await row(page, 0).click()
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    expect(path(page) === `/${HANDLE}/notes?open=5`, `framed: a row should open in the frame on a narrow window, landed on ${path(page)}`)
    await context.close()
  }

  // Keyboard entry is the same on every list, and Escape returns to its row.
  for (const where of ['notes', 'inbox', 'skills']) {
    const { context, page } = await open({ layout: 'framed', width: 1280 })
    await page.goto(`${BASE}/${HANDLE}/${where}`)
    const first = page.locator('.app-frame-list .app-note-main').first()
    await first.waitFor()
    await first.focus()
    await page.keyboard.press('Enter')
    await page.waitForTimeout(200)
    expect(await page.locator('#app-frame-detail').evaluate((el) => el.contains(document.activeElement)), `${where}: keyboard opening should enter the detail frame`)
    await page.keyboard.press('F6')
    expect(await first.evaluate((el) => el === document.activeElement), `${where}: F6 should return to the row`)
    await page.keyboard.press('F6')
    await page.keyboard.press('Escape')
    await page.waitForTimeout(200)
    expect(await page.locator('.app-frame-empty').count() === 1, `${where}: Escape should close the detail`)
    expect(await first.evaluate((el) => el === document.activeElement), `${where}: closing should restore row focus`)
    await context.close()
  }

  {
    const { context, page } = await open({ layout: 'framed', width: 1280 })
    await row(page, 0).click()
    await paneTitle(page, 'Note 5').waitFor()
    const order = await page.evaluate(() => ({
      body: document.querySelector('.app-frame-reading .mm-note-paper').getBoundingClientRect().top,
      details: document.querySelector('.app-frame-reading .mm-context-details').getBoundingClientRect().top,
    }))
    expect(order.body < order.details, `reading: narrow frames should show the note before metadata, was ${JSON.stringify(order)}`)
    const divider = page.locator('.app-frame-divider')
    await divider.focus()
    await page.keyboard.press('Home')
    expect(await page.evaluate(() => localStorage.getItem('mm-list-width')) === '420', 'divider: Home should restore the default width')
    expect(await divider.getAttribute('aria-valuemin') !== null && await divider.getAttribute('aria-valuemax') !== null, 'divider: assistive technology should receive its limits')
    await context.close()
  }

  // Escape belongs to an overlay before it belongs to the frame underneath.
  {
    const { context, page } = await open({ layout: 'framed', width: 1440 })
    await row(page, 0).click()
    await paneTitle(page, 'Note 5').waitFor()
    await page.locator('.app-stage-head a[href$="/settings"]').click()
    await page.locator('.app-settings-modal.show').waitFor()
    await page.keyboard.press('Escape')
    await page.waitForTimeout(400)
    expect(await paneTitle(page, 'Note 5').count() === 1, 'settings: Escape should leave the note open')
    await context.close()
  }

  // Pointer resizing uses the same saved width as keyboard resizing.
  {
    const { context, page } = await open({ layout: 'framed', width: 1920 })
    const divider = page.locator('.app-frame-divider')
    const box = await divider.boundingBox()
    await page.mouse.move(box.x, box.y + 100)
    await page.mouse.down()
    await page.mouse.move(box.x + 80, box.y + 100, { steps: 5 })
    await page.mouse.up()
    expect(await page.locator('.app-frame-list').evaluate((el) => el.clientWidth) === 500, 'divider: dragging should resize the list')
    await page.reload()
    await page.locator('.app-note-row').first().waitFor()
    expect(await page.locator('.app-frame-list').evaluate((el) => el.clientWidth) === 500, 'divider: a reload should preserve the dragged width')
    await context.close()
  }

  // A fresh note URL honors the device choice before any list is mounted.
  {
    const context = await browser.newContext({ viewport: { width: 1920, height: 1000 } })
    await context.route('**/api/**', apiStub)
    await context.addInitScript(() => localStorage.setItem('mm-layout', 'framed'))
    const page = await context.newPage()
    await page.goto(`${NOTES}/5`)
    await page.waitForTimeout(300)
    expect(await paneTitle(page, 'Note 5').count() === 1, 'arrival: a fresh note URL should honor Framed')
    await context.close()
  }

  // Resizing must never throw away the thing being read or an unsaved draft.
  {
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await row(page, 0).click()
    await paneTitle(page, 'Note 5').waitFor()
    await page.setViewportSize({ width: 1280, height: 1000 })
    await page.waitForTimeout(100)
    expect(await page.locator('.mm-note-title', { hasText: 'Note 5' }).isVisible().catch(() => false), 'dynamic: shrinking should keep the open note visible')
    await page.setViewportSize({ width: 1920, height: 1000 })
    await paneTitle(page, 'Note 5').waitFor()
    await page.locator('.app-frame-reading .mm-note-actions a', { hasText: 'Edit' }).click()
    await page.locator('#title').fill('An unsaved draft')
    await page.setViewportSize({ width: 1280, height: 1000 })
    await page.waitForTimeout(100)
    expect(await page.locator('#title').inputValue().catch(() => null) === 'An unsaved draft', 'dynamic: shrinking should preserve the unsaved editor')
    await page.setViewportSize({ width: 1920, height: 1000 })
    await page.waitForTimeout(100)
    expect(await page.locator('#title').inputValue().catch(() => null) === 'An unsaved draft', 'dynamic: expanding should preserve the unsaved editor')
    await context.close()
  }

  // A clicked close button is removed before Vue finishes updating focus.
  for (const where of ['notes', 'skills']) {
    const { context, page } = await open({ layout: 'framed', width: 1440 })
    await page.goto(`${BASE}/${HANDLE}/${where}`)
    const first = page.locator('.app-frame-list .app-note-main').first()
    await first.click()
    await page.locator('.app-frame-reading .mm-pane-close').click()
    await page.waitForTimeout(100)
    expect(await first.evaluate((el) => el === document.activeElement), `${where}: clicking Close should restore row focus`)
    await context.close()
  }

  // Both teleport destinations must survive while the inbox is windowed.
  for (const initialWidth of [1280, 1920]) {
    const { context, page } = await open({ layout: 'dynamic', width: initialWidth })
    await page.goto(`${BASE}/${HANDLE}/inbox`)
    await page.locator('.mm-review-tray [aria-controls^="evidence-"]').first().click()
    for (const width of [1280, 1920]) {
      await page.setViewportSize({ width, height: 1000 })
      await page.waitForTimeout(100)
      expect(await page.locator('.mm-decision-footer').isVisible().catch(() => false), `inbox: actions should survive resize from ${initialWidth} to ${width}`)
    }
    await context.close()
  }

  {
    const { context, page } = await open({ layout: 'framed', width: 1440 })
    await row(page, 0).click()
    await paneTitle(page, 'Note 5').waitFor()
    await page.locator('.app-page-head button[aria-label="Map"]').click()
    await page.locator('.mm-map-dialog .mm-notes-map').waitFor()
    await page.keyboard.press('Escape')
    await page.waitForTimeout(400)
    expect(await page.locator('.mm-map-dialog.show').count() === 0 && await paneTitle(page, 'Note 5').count() === 1, 'map: Escape should close only the map dialog')
    await context.close()
  }

  // Escape closes one thing: the account menu, and not the note under it.
  {
    const { context, page } = await open({ layout: 'framed', width: 1440 })
    await row(page, 0).click()
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    await page.locator('.app-account-summary').click({ timeout: 5000 }).catch(() => {})
    await settle(page.locator('.app-account-menu').waitFor({ timeout: 5000 }))
    await page.keyboard.press('Escape')
    await page.waitForTimeout(400)
    expect(await page.locator('.app-account-menu').count() === 0, 'escape: Escape should close the account menu')
    expect(await paneTitle(page, 'Note 5').count() === 1, 'escape: closing the account menu should leave the note open')
    await context.close()
  }

  // A page of the list that fails is asked for once, and Retry asks again; a
  // page that brings nothing new ends the list instead of asking on and on.
  {
    more.fail = true
    more.broken = 0
    const { context, page } = await open({ layout: 'framed', width: 1440 })
    await page.goto(`${NOTES}?q=broken`)
    await settle(page.locator('.app-note-row', { hasText: 'Many 1' }).first().waitFor({ timeout: 5000 }))
    await page.locator('.app-note-row').last().scrollIntoViewIfNeeded().catch(() => {})
    await page.waitForTimeout(1500)
    expect(more.broken === 1, `more: a page that failed should be asked for once, was asked ${more.broken} times`)
    more.fail = false
    await page.locator('.app-note-more button').click({ timeout: 5000 }).catch(() => {})
    await page.waitForTimeout(500)
    const read = await page.locator('.app-note-row').count()
    expect(read === 100, `more: Retry should read the next 50, counted ${read}`)

    await page.goto(`${NOTES}?q=drift`)
    await settle(page.locator('.app-note-row', { hasText: 'Many 1' }).first().waitFor({ timeout: 5000 }))
    for (let i = 0; i < 3; i++) {
      await page.locator('.app-note-row').last().scrollIntoViewIfNeeded().catch(() => {})
      await page.waitForTimeout(500)
    }
    expect(more.drift === 1, `more: a page that brought nothing new should end the list, ${more.drift} more were asked for`)
    await context.close()
  }

  // In frames the list keeps its select control, and All shown selects what
  // is shown.
  {
    const { context, page } = await open({ layout: 'framed', width: 1440 })
    const toggle = page.locator('.app-frame-list .mm-select-toggle')
    expect(await toggle.isVisible().catch(() => false), 'select: the framed list should offer its select control')
    await toggle.click({ timeout: 5000 }).catch(() => {})
    await page.locator('.app-frame-list .app-note-list-head .dropdown-item').first().click({ timeout: 5000 }).catch(() => {})
    const chosen = await page.locator('.app-selection-bar strong').textContent({ timeout: 5000 }).catch(() => '')
    expect(chosen === '3', `select: All shown should select the three notes shown, selected ${JSON.stringify(chosen)}`)
    await context.close()
  }

  // What is selected is what is shown: a note an approval or a save takes out
  // of the list leaves the selection with it.
  {
    more.gone = false
    const { context, page } = await open({ layout: 'framed', width: 1440 })
    await page.goto(`${NOTES}?q=shrinks`)
    await settle(page.locator('.app-note-row', { hasText: 'Note 6' }).waitFor({ timeout: 5000 }))
    for (const title of ['Note 5', 'Note 6']) {
      await page.locator('.app-note-row', { hasText: title }).locator('.app-note-check input').check({ timeout: 5000 }).catch(() => {})
    }
    more.gone = true
    await row(page, 0).click()
    await settle(paneTitle(page, 'Note 5').waitFor({ timeout: 5000 }))
    await page.locator('.app-frame-reading .mm-note-actions a', { hasText: 'Edit' }).click({ timeout: 5000 }).catch(() => {})
    await settle(page.locator('.app-frame-reading #title').waitFor({ timeout: 5000 }))
    await page.locator('.app-frame-reading .mm-note-actions button[type="submit"]').click({ timeout: 5000 }).catch(() => {})
    await settle(page.waitForURL(/open=5/, { timeout: 5000 }))
    await page.waitForTimeout(500)
    const left = { rows: await page.locator('.app-note-row').count(), selected: await page.locator('.app-selection-bar strong').textContent().catch(() => '') }
    expect(left.rows === 2 && left.selected === '1', `select: a note gone from the list should leave the selection, was ${JSON.stringify(left)}`)
    await context.close()
  }

  // Windowed, the Activity log and Settings open over the note or the editor
  // that was showing, which stays as it was: the place read to, the draft.
  {
    const { context, page } = await open({ layout: 'windowed', width: 1440 })
    await page.goto(`${NOTES}/9`)
    await settle(page.locator('.mm-note-title', { hasText: 'Note 9' }).waitFor({ timeout: 5000 }))
    const before = await page.evaluate(() => {
      document.querySelector('.mm-note-title').dataset.kept = '1'
      const stage = document.querySelector('.app-stage')
      stage.scrollTo({ top: 800 })
      return stage.scrollTop
    })
    await page.locator('.app-account-summary').click({ timeout: 5000 }).catch(() => {})
    await page.locator('.app-account-menu a[href$="/activity"]').click({ timeout: 5000 }).catch(() => {})
    const dialog = page.locator('.modal.mm-activity-dialog.show')
    await settle(dialog.waitFor({ timeout: 5000 }))
    const under = { note: await page.locator('.mm-note-title[data-kept]').count(), list: await page.locator('.app-note-row').count() }
    expect(under.note === 1 && under.list === 0, `activity: the note should stay under the Activity log, was ${JSON.stringify(under)}`)
    await dialog.locator('.btn-close').click({ timeout: 5000 }).catch(() => {})
    await settle(page.waitForURL(/\/notes\/9$/, { timeout: 5000 }))
    await page.waitForTimeout(400)
    const after = await page.evaluate(() => ({
      kept: document.querySelector('.mm-note-title')?.dataset.kept ?? null,
      top: document.querySelector('.app-stage')?.scrollTop ?? -1,
    }))
    expect(before > 0 && after.kept === '1' && after.top === before, `activity: closing should return to the note where it was read (${before}), was ${JSON.stringify(after)}`)

    await page.goto(`${NOTES}/5/edit`)
    await settle(page.locator('#title').waitFor({ timeout: 5000 }))
    await page.locator('#title').fill('An unsaved draft')
    const asked = []
    page.on('dialog', (d) => { asked.push(d.message()); void d.dismiss() })
    await page.locator('.app-stage-head a[href$="/settings"]').click({ timeout: 5000 }).catch(() => {})
    const settings = await settle(page.locator('.app-settings-modal.show').waitFor({ timeout: 5000 }))
    expect(settings && asked.length === 0, `settings: Settings should open over the editor without asking to discard it, asked ${JSON.stringify(asked)}`)
    await page.keyboard.press('Escape')
    await settle(page.waitForURL(/\/notes\/5\/edit$/, { timeout: 5000 }))
    await page.waitForTimeout(400)
    expect(await page.locator('#title').inputValue().catch(() => null) === 'An unsaved draft', 'settings: closing Settings should return to the draft as it was')
    await context.close()
  }

  // The Activity log opened by its address keeps the view the address names.
  {
    const { context, page } = await open({ layout: 'windowed', width: 1440 })
    await page.goto(`${BASE}/${HANDLE}/activity?view=curation`)
    const dialog = page.locator('.modal.mm-activity-dialog.show')
    await settle(dialog.waitFor({ timeout: 5000 }))
    await page.waitForTimeout(500)
    const shown = await dialog.locator('.app-segment.is-active').textContent().catch(() => '')
    expect(path(page) === `/${HANDLE}/activity?view=curation` && shown?.trim() === 'Curation',
      `activity: a reload on Curation should stay on Curation, was ${path(page)} showing ${JSON.stringify(shown)}`)
    await context.close()
  }

  // Dynamic swaps the skill's frame for its dialog as the window narrows, and
  // an unsaved alias or choice of connections goes along.
  {
    skillsTaken.handoff = false
    const { context, page } = await open({ layout: 'dynamic', width: 1920 })
    await page.goto(`${BASE}/${HANDLE}/skills`)
    await page.locator('.mm-skill-row[data-skill="house-style"] .app-note-main').click({ timeout: 5000 }).catch(() => {})
    await page.locator('#skill-frame-slug').fill('house-draft', { timeout: 5000 }).catch(() => {})
    await page.locator('.app-frame-reading .skill-connections .connection-all input').uncheck({ timeout: 5000 }).catch(() => {})
    const draft = (scope) => page.evaluate((s) => {
      const root = document.querySelector(s)
      return [root?.querySelector('input.font-monospace')?.value ?? null, root?.querySelector('.connection-all input')?.checked ?? null]
    }, scope)
    await page.setViewportSize({ width: 1280, height: 1000 })
    await settle(page.locator('.modal.mm-skill-panel.show #skill-slug').waitFor({ timeout: 5000 }))
    const narrow = await draft('.modal.mm-skill-panel')
    expect(JSON.stringify(narrow) === JSON.stringify(['house-draft', false]), `skills: narrowing should keep the unsaved alias and connections, was ${JSON.stringify(narrow)}`)
    await page.setViewportSize({ width: 1920, height: 1000 })
    await settle(page.locator('#skill-frame-slug').waitFor({ timeout: 5000 }))
    const wide = await draft('.app-frame-reading')
    expect(JSON.stringify(wide) === JSON.stringify(['house-draft', false]), `skills: widening should keep the unsaved alias and connections, was ${JSON.stringify(wide)}`)
    await context.close()
  }

  // The choice itself: the first thing in Preferences, Windowed until another
  // is chosen.
  {
    const { context, page } = await open({ width: 1440 })
    await page.goto(`${BASE}/${HANDLE}/settings/memex`)
    const framed = page.locator('#pref-layout-framed')
    await framed.waitFor()
    const first = await page.locator('.mm-block-title').first().textContent()
    expect(first?.trim() === 'Layout', `settings: the layout should be the first choice in Preferences, was ${JSON.stringify(first)}`)
    expect(await page.locator('#pref-layout-windowed').isChecked(), 'settings: Windowed should be chosen until another is')
    for (const mode of ['framed', 'dynamic']) {
      await page.locator(`#pref-layout-${mode}`).check().catch(() => {})
      expect(await page.evaluate(() => localStorage.getItem('mm-layout')) === mode, `settings: ${mode} should be remembered on this device`)
    }
    await context.close()
  }

  // Every size the text slider offers is the size a note is drawn at, after a
  // reload as well as at once.
  {
    const { context, page } = await open({ layout: 'windowed', width: 1440 })
    await page.goto(`${BASE}/${HANDLE}/settings/memex`)
    const slider = page.locator('#pref-text')
    await slider.waitFor()
    const ends = await slider.evaluate((el) => [el.min, el.max])
    for (const size of ends) {
      await page.goto(`${BASE}/${HANDLE}/settings/memex`)
      await slider.fill(size)
      const now = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--mm-note-size').trim())
      expect(now === `${size}px`, `text size: moving the slider to ${size} should apply ${size}px, was ${JSON.stringify(now)}`)
      await page.goto(`${BASE}/${HANDLE}/notes/5`)
      await settle(page.locator('.note-body').waitFor({ timeout: 5000 }))
      const drawn = await page.locator('.note-body').evaluate((el) => getComputedStyle(el).fontSize).catch(() => null)
      expect(drawn === `${size}px`, `text size: a note should be drawn at ${size}px after choosing it, was ${JSON.stringify(drawn)}`)
    }
    await context.close()
  }
} finally {
  await browser.close()
  server.close()
}

if (problems.length) {
  console.error('Reading pane behaviour check failed:\n')
  for (const p of problems) console.error(`  ${p}`)
  process.exit(1)
}
console.log(`Reading pane behaves: ${checked} assertions.`)
