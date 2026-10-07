#!/usr/bin/env node
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { existsSync, readFileSync } from 'node:fs'
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
const handles = { 1: 'k7m2pq9wd4rn', 2: 'b3n8xq2vt6kc' }
let handle = handles[1]
const me = id => ({ name: `Account ${id}`, email: `account${id}@example.test`, team: { name: `Space ${id}`, handle: handles[id] }, welcome_completed: true, system_tags: [], appearance: {}, icon_key: null, icon: null })
const note = (id, patch = {}) => ({ id, title: `Note ${id}`, body_md: `Body ${id}`, summary: '', version: id + 2, tags: [], status: 'verified', source: 'manual', source_url: null, created_at: '2026-09-01', updated_at: '2026-09-01', links: [], backlinks: [], pending_proposals: 0, curation_flag: null, ...patch })
const revision = id => ({ id, body_md: `History ${id}`, title: `Old ${id}`, version: 1, content_updated_at: '2026-09-01', replaced_at: '2026-09-02', replaced_by: 'operator', replaced_by_actor: null, operation: 'edit' })
const waitForStart = promise => Promise.race([promise, new Promise((_, reject) => { const timer = setTimeout(() => reject(new Error('expected request did not start')), 5000); timer.unref() })])
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r }); return { promise, resolve } }
const browser = await chromium.launch()
const failures = []
let passed = 0
async function navigate(page, path) {
  await page.evaluate(path => {
    const state = history.state ?? {}
    history.pushState({ ...state, back: state.current, current: path, forward: null, position: (state.position ?? 0) + 1 }, '', path)
    dispatchEvent(new PopStateEvent('popstate', { state: history.state }))
  }, `/${handle}/${path}`)
}
async function scenario(name, body) {
  if (process.env.LIFETIME_ONLY && !name.includes(process.env.LIFETIME_ONLY)) return
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const page = await context.newPage()
  page.setDefaultTimeout(5000)
  handle = handles[1]
  let user = me(1)
  const state = { get user() { return user }, set user(next) { user = next; handle = next.team.handle }, notes: [note(1), note(2)], total: 2, on: {}, calls: [] }
  await page.route('**/api/**', async route => {
    const req = route.request(), url = new URL(req.url()), key = `${req.method()} ${url.pathname}`
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) })
    state.calls.push({ key, query: url.search, body: req.postData() })
    if (state.on[key]) return state.on[key](json, req, url)
    if (key === 'GET /api/me') return json(state.user)
    if (key === 'GET /api/me/identities') return json({ identities: [], available: [] })
    if (key === 'GET /api/me/sessions') return json({ sessions: [] })
    if (key === 'GET /api/tags/vocabulary') return json({ tags: [], retired: [] })
    if (key === 'POST /api/logout') return json({})
    if (key === 'GET /api/auth/providers') return json({ providers: [] })
    if (key === 'GET /api/me/setup') return json({ complete: true, dismissed: true, items: {} })
    if (key === 'GET /api/inbox/count') return json({ pending_notes: state.total, edit_proposals: 0, total: state.total })
    if (key === 'GET /api/tags') return json({ tags: [], retired: [] })
    if (key === 'GET /api/proposals') return json({ proposals: [] })
    if (key === 'GET /api/notes') return json({ items: state.notes, total: state.total, semantic_unavailable: false })
    const match = /^GET \/api\/notes\/(\d+)(\/revisions)?$/.exec(key)
    if (match) return json(match[2] ? { revisions: [], note_id: Number(match[1]) } : note(Number(match[1])))
    if (key === 'GET /api/deleted') return json({ notes: [], total: 0, page: 1, pages: 1, per_page: 20, limbo_days: 30 })
    if (key === 'GET /api/me/welcome') return json({ completed: state.user.welcome_completed, facts: { connected: true, connection: 'Synthetic', guide_read: true } })
    return json({})
  })
  page.on('dialog', dialog => dialog.accept())
  const pageErrors = []
  page.on('pageerror', error => pageErrors.push(error.message))
  try { await body(page, state, context); assert.deepEqual(pageErrors, [], 'unexpected browser errors'); passed++; console.log(`ok ${name}`) }
  catch (e) { failures.push(`${name}: ${e.message}`); console.error(`FAIL ${name}: ${e.message}`) }
  finally { await context.close() }
}
async function opened(page, path) { await page.goto(`${base}/${handle}/${path}`) }
async function editorTitle(page) {
  const field = page.locator('.mm-title-input')
  await field.waitFor()
  return field
}

for (const failed of [false, true]) await scenario(`note response reversal ${failed ? 'error' : 'success'}`, async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['GET /api/notes/1'] = async json => { started.resolve(); await gate.promise; return json(failed ? { error: 'Obsolete failure' } : note(1), failed ? 500 : 200) }
  await opened(page, 'notes/1'); await waitForStart(started.promise)
  await navigate(page, 'notes/2')
  await page.locator('.mm-note-title').filter({ hasText: 'Note 2' }).waitFor()
  const response = page.waitForResponse(res => res.url().endsWith('/api/notes/1'))
  gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert.equal(await page.locator('.mm-note-title').textContent(), 'Note 2')
  assert(!(await page.textContent('body')).includes('Obsolete failure'))
})

await scenario('history response reversal', async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['GET /api/notes/1/revisions'] = async json => { started.resolve(); await gate.promise; return json({ revisions: [revision(11)] }) }
  state.on['GET /api/notes/2/revisions'] = json => json({ revisions: [revision(22), revision(23)] })
  await opened(page, 'notes/1'); await waitForStart(started.promise)
  await navigate(page, 'notes/2')
  await page.locator('.mm-note-title').filter({ hasText: 'Note 2' }).waitFor()
  await page.waitForFunction(() => document.querySelector('.mm-history-ledger')?.textContent.includes('2'))
  const response = page.waitForResponse(res => res.url().endsWith('/api/notes/1/revisions')); gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert.equal(await page.locator('.mm-history-ledger .mm-revision-entry').count(), 2)
})

await scenario('history failure is visible and retry restores actual zero', async (page, state) => {
  state.on['GET /api/notes/1/revisions'] = json => json({ error: 'History unavailable' }, 503)
  await opened(page, 'notes/1')
  await page.locator('.mm-note-title').waitFor()
  await page.locator('.mm-history-error').waitFor()
  assert((await page.locator('.mm-history-error').textContent()).includes('History unavailable'))
  state.on['GET /api/notes/1/revisions'] = json => json({ revisions: [] })
  await page.locator('.mm-history-error button').click()
  await page.locator('.mm-history-error').waitFor({ state: 'hidden' })
  assert(await page.locator('.mm-history-ledger').getByText('Forget history', { exact: false }).count() > 0)
})

await scenario('failed logout retains a visible retryable session failure', async (page, state) => {
  state.on['POST /api/logout'] = json => json({ error: 'Logout unavailable' }, 500)
  await opened(page, 'notes/1')
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.locator('.app-logout-error').waitFor()
  assert(!page.url().endsWith('/login'))
  assert((await page.locator('.app-logout-error').textContent()).includes('Logout unavailable'))
  state.on['POST /api/logout'] = json => json({})
  await page.locator('.app-logout-error button').click()
  await page.waitForURL('**/login')
})


async function logoutLogin(page, state, user) {
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.waitForURL('**/login')
  state.user = me(user)
  await authAction(page, 'check')
  await navigate(page, 'notes')
  await page.waitForURL(`**/${handle}/notes`)
}

for (const user of [1, 2]) await scenario(`old 401 cannot redirect session ${user === 1 ? 'same account' : 'other account'}`, async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['GET /api/notes/1/revisions'] = async json => { started.resolve(); await gate.promise; return json({ error: 'Old unauthorized' }, 401) }
  await opened(page, 'notes/1'); await waitForStart(started.promise)
  await logoutLogin(page, state, user)
  const response = page.waitForResponse(res => res.url().endsWith('/api/notes/1/revisions'))
  gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert(page.url().endsWith(`/${handle}/notes`), 'old unauthorized request redirected the new session')
})

await scenario('account rename response cannot revive the previous user', async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['PATCH /api/me'] = async json => { started.resolve(); await gate.promise; return json(me(1)) }
  await opened(page, 'settings/account')
  await page.fill('#display_name', 'Old delayed rename')
  await page.locator('#display_name').locator('..').locator('button').click()
  await waitForStart(started.promise)
  await navigate(page, 'notes')
  await page.locator('.modal').waitFor({ state: 'hidden' })
  await logoutLogin(page, state, 2)
  const response = page.waitForResponse(res => res.url().endsWith('/api/me') && res.request().method() === 'PATCH')
  gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert((await page.locator('.app-account-summary').textContent()).includes('Account 2'))
})

await scenario('inbox distinguishes loaded notes from global waiting and reaches next page', async (page, state) => {
  state.notes = Array.from({ length: 100 }, (_, i) => note(i + 1, { status: 'pending' })); state.total = 101
  state.on['GET /api/notes'] = (json, _, url) => json({ items: Number(url.searchParams.get('page') ?? '1') === 1 ? state.notes : [note(101, { status: 'pending' })], total: 101 })
  await opened(page, 'inbox')
  await page.locator('#review-note-100').waitFor()
  assert((await page.locator('.mm-inbox-head').textContent()).includes('101 waiting'))
  await page.locator('.mm-review-head .btn').click()
  assert((await page.locator('.mm-batch').textContent()).includes('100'))
  await page.locator('.mm-inbox-more').click()
  await page.locator('#review-note-101').waitFor()
  assert.equal(await page.locator('.mm-review-card').count(), 101)
})


await scenario('inbox continuation survives inserted head rows and reaches both ends', async (page, state) => {
  state.notes = Array.from({ length: 200 }, (_, i) => note(i + 1, { status: 'pending' }))
  const pages = []
  state.on['GET /api/notes'] = (json, _, url) => {
    const page = Number(url.searchParams.get('page') ?? '1')
    pages.push(page)
    return json({ items: state.notes.slice((page - 1) * 100, page * 100), total: state.notes.length })
  }
  await opened(page, 'inbox')
  await page.locator('#review-note-100').waitFor()
  state.notes.unshift(note(201, { status: 'pending' }))
  for (let i = 0; i < 3; i++) {
    const response = page.waitForResponse(res => new URL(res.url()).pathname === '/api/notes')
    await page.locator('.mm-inbox-more').click(); await response
    await page.evaluate(() => new Promise(requestAnimationFrame))
  }
  assert.deepEqual(pages, [1, 2, 3, 1], 'continuation must advance despite overlapping rows, then revisit the head')
  await page.locator('#review-note-200').waitFor()
  await page.locator('#review-note-201').waitFor()
  assert.equal(await page.locator('.mm-review-card').count(), 201)
  assert((await page.locator('.mm-inbox-head').textContent()).includes('201 waiting'))
  assert.equal(await page.locator('.mm-inbox-more').count(), 0)
})

for (const batch of [false, true]) await scenario(`inbox continuation restarts after ${batch ? 'partial batch' : 'single'} removal`, async (page, state) => {
  state.notes = Array.from({ length: 201 }, (_, i) => note(i + 1, { status: 'pending' }))
  const pages = []
  state.on['GET /api/notes'] = (json, _, url) => {
    const page = Number(url.searchParams.get('page') ?? '1'); pages.push(page)
    return json({ items: state.notes.slice((page - 1) * 100, page * 100), total: state.notes.length })
  }
  state.on['GET /api/notes/1'] = json => json(note(1, { status: 'pending' }))
  state.on['POST /api/notes/1/approve'] = json => { state.notes.shift(); return json({ note: note(1) }) }
  state.on['POST /api/inbox/batch'] = json => { state.notes.shift(); return json({ done: 1, failed: [{ kind: 'note', id: 2, error: 'Changed review' }] }) }
  await opened(page, 'inbox')
  await page.locator('#review-note-100').waitFor()
  await page.locator('.mm-inbox-more').click()
  await page.locator('#review-note-200').waitFor()
  if (batch) {
    await page.locator('.mm-review-card').filter({ has: page.locator('#review-note-1') }).locator('input[type="checkbox"]').check()
    await page.locator('.mm-review-card').filter({ has: page.locator('#review-note-2') }).locator('input[type="checkbox"]').check()
    await page.locator('.mm-batch .btn-primary').click()
    await page.locator('.mm-batch-dialog.show').waitFor()
    await page.locator('.mm-batch-dialog .modal-footer .btn').last().click()
    await page.locator('.mm-batch-dialog').waitFor({ state: 'hidden' })
  } else {
    await page.locator('#review-note-1').click()
    await page.locator('.mm-decision-actions .btn-primary').click()
  }
  await page.locator('#review-note-1').waitFor({ state: 'hidden' })
  for (let i = 0; i < 2; i++) {
    const response = page.waitForResponse(res => new URL(res.url()).pathname === '/api/notes')
    await page.locator('.mm-inbox-more').click(); await response
    await page.evaluate(() => new Promise(requestAnimationFrame))
  }
  assert.deepEqual(pages, [1, 2, 1, 2], 'local removals shift the page boundary and require a new scan')
  await page.locator('#review-note-201').waitFor()
  assert.equal(await page.locator('.mm-review-card').count(), 200)
  assert((await page.locator('.mm-inbox-head').textContent()).includes('200 waiting'))
})

await scenario('file imports ignore reversed reads and stale route completion', async (page, state, context) => {
  await context.addInitScript(() => {
    const original = File.prototype.text
    window.fileReads = {}
    File.prototype.text = async function () {
      if (this.name.startsWith('held')) await new Promise(resolve => { window.fileReads[this.name] = resolve })
      return original.call(this)
    }
  })
  await opened(page, 'notes/new')
  const input = page.locator('.mm-intake-dock input[type="file"]')
  await input.setInputFiles({ name: 'held-a.md', mimeType: 'text/markdown', buffer: Buffer.from('---\ntitle: Old file\n---\nOld file body') })
  await page.waitForFunction(() => !!window.fileReads['held-a.md'])
  await input.setInputFiles({ name: 'new-b.md', mimeType: 'text/markdown', buffer: Buffer.from('---\ntitle: New file\n---\nNew file body') })
  await page.waitForFunction(() => document.querySelector('.mm-title-input')?.value === 'New file')
  await page.evaluate(() => window.fileReads['held-a.md']())
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert.equal(await page.inputValue('.mm-title-input'), 'New file')
  await input.setInputFiles({ name: 'held-c.md', mimeType: 'text/markdown', buffer: Buffer.from('---\ntitle: Wrong route file\n---\nWrong body') })
  await page.waitForFunction(() => !!window.fileReads['held-c.md'])
  await navigate(page, 'notes/2/edit')
  await page.waitForFunction(() => document.querySelector('.mm-title-input')?.value === 'Note 2')
  await page.evaluate(() => window.fileReads['held-c.md']())
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert.equal(await page.inputValue('.mm-title-input'), 'Note 2')
})

await scenario('save completion after unmount cannot navigate or toast over another note', async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['PUT /api/notes/1'] = async json => { started.resolve(); await gate.promise; return json({ note: note(1, { title: 'Old saved draft' }), suggested_tags: {} }) }
  await opened(page, 'notes/1/edit')
  await page.fill('.mm-title-input', 'Old saved draft')
  await page.locator('button[type="submit"]').click(); await waitForStart(started.promise)
  await navigate(page, 'notes/2')
  await page.locator('.mm-note-title').filter({ hasText: 'Note 2' }).waitFor()
  const response = page.waitForResponse(res => res.url().endsWith('/api/notes/1') && res.request().method() === 'PUT'); gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert(page.url().endsWith('/notes/2'))
  assert(!(await page.locator('.p-toast').textContent()).includes('Old saved draft'))
})

await scenario('approval failure for previous note cannot block current note', async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['GET /api/notes/1'] = json => json(note(1, { status: 'pending' }))
  state.on['GET /api/notes/2'] = json => json(note(2, { status: 'pending' }))
  state.on['POST /api/notes/1/approve'] = async json => { started.resolve(); await gate.promise; return json({ error: 'Old conflict' }, 409) }
  await opened(page, 'notes/1')
  await page.locator('.mm-note-actions .btn-success').click(); await waitForStart(started.promise)
  await navigate(page, 'notes/2')
  await page.locator('.mm-note-title').filter({ hasText: 'Note 2' }).waitFor()
  const response = page.waitForResponse(res => res.url().endsWith('/api/notes/1/approve')); gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert(!await page.locator('.mm-note-actions .btn-success').isDisabled())
  assert(!(await page.textContent('body')).includes('Old conflict'))
})

await scenario('last loaded inbox approval does not claim the global queue is empty', async (page, state) => {
  state.notes = [note(1, { status: 'pending' })]; state.total = 2
  state.on['GET /api/notes/1'] = json => json(note(1, { status: 'pending' }))
  state.on['POST /api/notes/1/approve'] = json => { state.notes = [note(2, { status: 'pending' })]; state.total = 1; return json({ note: note(1) }) }
  await opened(page, 'inbox')
  await page.locator('#review-note-1').click()
  await page.locator('.mm-decision-actions .btn-primary').click()
  await page.locator('#review-note-1').waitFor({ state: 'hidden' })
  assert(!(await page.locator('.mm-inbox').textContent()).includes('Nothing to review'))
  await page.locator('.mm-inbox-more').click()
  await page.locator('#review-note-2').waitFor()
})

await scenario('stale inbox badge cannot replace the next account count', async (page, state) => {
  const gate = deferred(), started = deferred()
  let reads = 0
  state.on['GET /api/inbox/count'] = async json => {
    if (++reads === 1) { started.resolve(); await gate.promise; return json({ total: 91 }) }
    return json({ total: 3 })
  }
  await opened(page, 'notes/1'); await waitForStart(started.promise)
  await logoutLogin(page, state, 2)
  const response = page.waitForResponse(res => res.url().endsWith('/api/inbox/count') && res.status() === 200)
  gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  const label = await page.locator('a[href$="/inbox"]').first().textContent()
  assert(label.includes('3') && !label.includes('91'), label)
})


await scenario('failed logout preserves the open editor draft', async (page, state) => {
  state.on['POST /api/logout'] = json => json({ error: 'Logout unavailable' }, 500)
  await opened(page, 'notes/1/edit')
  await page.fill('.mm-title-input', 'Unsaved at logout')
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.locator('.app-logout-error').waitFor()
  assert.equal(await page.inputValue('.mm-title-input'), 'Unsaved at logout')
})


await scenario('delayed account language cannot repaint the replacement session', async (page, state) => {
  state.user.locale = 'zz'
  const gate = deferred(), started = deferred()
  state.on['GET /api/locales/zz'] = async json => { started.resolve(); await gate.promise; return json({}) }
  await opened(page, 'notes/1'); await waitForStart(started.promise)
  await logoutLogin(page, state, 2)
  const response = page.waitForResponse(res => res.url().endsWith('/api/locales/zz')); gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert.equal(await page.locator('html').getAttribute('lang'), 'en')
})

await scenario('account menu opened before the first page lands stays open', async (page, state) => {
  const gate = deferred(), started = deferred()
  await page.route(/\/assets\/NoteView-[^/]*\.js$/, async route => { started.resolve(); await gate.promise; await route.fallback() })
  await opened(page, 'notes/1'); await waitForStart(started.promise)
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu').waitFor()
  gate.resolve()
  await page.locator('.mm-note-title').waitFor()
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert.equal(await page.locator('.app-account-summary').getAttribute('aria-expanded'), 'true', 'the first page landing closed the account menu')
})

for (const path of ['notes/1', 'notes/1/edit', 'inbox']) await scenario(`failed logout leaves interrupted ${path} load retryable`, async (page, state) => {
  const gate = deferred(), started = deferred()
  const endpoint = path === 'inbox' ? 'GET /api/notes' : 'GET /api/notes/1'
  state.on[endpoint] = async json => { started.resolve(); await gate.promise; return json(path === 'inbox' ? { items: [], total: 0 } : note(1)) }
  state.on['POST /api/logout'] = json => json({ error: 'Logout unavailable' }, 500)
  await opened(page, path); await waitForStart(started.promise)
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.locator('.app-logout-error').waitFor()
  await page.locator('.container .app-state-error').waitFor()
  gate.resolve()
  state.on[endpoint] = json => json(path === 'inbox' ? { items: [], total: 0 } : note(1))
  await page.locator('.container .app-state-error button').click()
  if (path.endsWith('/edit')) await page.locator('.mm-title-input').waitFor()
  else if (path === 'inbox') await page.locator('.mm-inbox-state:not(.app-state-error)').getByText('Nothing to review', { exact: true }).waitFor()
  else await page.locator('.mm-note-title').waitFor()
})

await scenario('network failure during logout remains visible until a confirmed retry', async (page, state) => {
  let attempts = 0
  await page.route('**/api/logout', route => ++attempts === 1 ? route.abort('failed') : route.fallback())
  await opened(page, 'notes/1')
  await page.locator('.app-account-summary').click()
  await page.locator('.app-account-menu button[role="menuitem"]').click()
  await page.locator('.app-logout-error').waitFor()
  assert(!page.url().endsWith('/login'))
  assert((await page.locator('.app-account-summary').textContent()).includes('Account 1'))
  await page.locator('.app-logout-error button').click()
  await page.waitForURL('**/login')
})

for (const leave of [false, true]) await scenario(`confirmation cancel during opening ${leave ? 'cannot outlive an unmounted view' : 'preserves the draft'}`, async (page, state) => {
  state.notes = [note(1, { status: 'pending' })]
  state.on['GET /api/notes/1'] = json => json(note(1, { status: 'pending' }))
  await opened(page, 'inbox')
  await page.addStyleTag({ content: '.modal.fade .modal-dialog { transform: none !important; transition-duration: 1s !important; }' })
  await page.evaluate(() => {
    window.modalEvents = []
    document.addEventListener('show.bs.modal', event => {
      window.modalEvents.push('show')
      for (const kind of ['shown', 'hide', 'hidden']) event.target.addEventListener(`${kind}.bs.modal`, () => window.modalEvents.push(kind), true)
    }, true)
  })
  await page.locator('#review-note-1').click()
  await page.locator('.mm-amend-toggle').click()
  await page.fill('.mm-note-form .mm-amend-title', 'Preserved early cancellation')
  await page.locator('.mm-inbox-refresh').click()
  await page.locator('.modal.show[aria-labelledby="confirm-dialog-title"]').waitFor()
  assert(!(await page.evaluate(() => window.modalEvents)).includes('shown'), 'fixture must click during opening')
  await page.locator('.modal.show .modal-footer .btn-outline-secondary').click()
  if (leave) {
    await navigate(page, 'notes/2')
    await page.locator('.mm-note-title').filter({ hasText: 'Note 2' }).waitFor()
    await page.waitForFunction(() => window.modalEvents.includes('shown'))
    await page.evaluate(() => new Promise(requestAnimationFrame))
    assert.equal(await page.locator('.modal, .modal-backdrop').count(), 0)
    assert.deepEqual(await page.evaluate(() => window.modalEvents), ['show', 'shown'], 'unmounted instance must not start a queued hide transition')
    assert(page.url().endsWith('/notes/2'))
    return
  }
  await page.locator('.modal.show[aria-labelledby="confirm-dialog-title"]').waitFor({ state: 'hidden' })
  assert.equal(await page.inputValue('.mm-note-form .mm-amend-title'), 'Preserved early cancellation')
  await page.waitForFunction(() => window.modalEvents.includes('hidden'))
  assert.deepEqual(await page.evaluate(() => window.modalEvents), ['show', 'shown', 'hide', 'hidden'])
  assert.equal(await page.locator('.modal-backdrop').count(), 0)
})

for (const control of ['.modal-footer .btn-secondary', '.modal-header .btn-close']) await scenario(`batch cancellation during opening uses the helper for ${control}`, async (page, state) => {
  state.notes = [note(1, { status: 'pending' })]
  await opened(page, 'inbox')
  await page.addStyleTag({ content: '.modal.fade .modal-dialog { transform: none !important; transition-duration: 1s !important; }' })
  await page.evaluate(() => {
    window.modalEvents = []
    document.addEventListener('show.bs.modal', event => {
      window.modalEvents.push('show')
      for (const kind of ['shown', 'hide', 'hidden']) event.target.addEventListener(`${kind}.bs.modal`, () => window.modalEvents.push(kind), true)
    }, true)
  })
  await page.locator('.mm-review-card .mm-review-check').check()
  await page.locator('.mm-batch .btn-primary').click()
  await page.locator('.mm-batch-dialog.show').waitFor()
  assert(!(await page.evaluate(() => window.modalEvents)).includes('shown'), 'fixture must cancel during opening')
  await page.locator(`.mm-batch-dialog ${control}`).click()
  await page.locator('.mm-batch-dialog').waitFor({ state: 'hidden' })
  await page.waitForFunction(() => window.modalEvents.includes('hidden'))
  assert.deepEqual(await page.evaluate(() => window.modalEvents), ['show', 'shown', 'hide', 'hidden'])
  assert.equal(await page.locator('.modal-backdrop').count(), 0)
  assert(await page.locator('.mm-review-card .mm-review-check').isChecked(), 'Cancel must retain the selection')
  assert.equal(state.calls.filter(call => call.key === 'POST /api/inbox/batch').length, 0)
})


async function authAction(page, action) {
  return page.evaluate(action => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth')[action](), action)
}
async function failLogout(page, state) {
  state.on['POST /api/logout'] = json => json({ error: 'Logout unavailable' }, 500)
  await authAction(page, 'logout')
  await page.locator('.app-logout-error').waitFor()
}

for (const operation of ['create', 'analyze']) await scenario(`Claude M1 failed logout preserves ${operation} lock`, async (page, state) => {
  const gate = deferred(), started = deferred()
  const endpoint = operation === 'create' ? '/api/notes' : '/api/analyze'
  state.on['POST ' + endpoint] = async json => { started.resolve(); await gate.promise; return json({ note: note(3), title: 'Old result', body_md: 'Old body' }) }
  await opened(page, 'notes/new')
  await page.fill('.mm-title-input', 'Pending synthetic note')
  await page.locator('.cm-content').fill('Pending synthetic body')
  const button = operation === 'create' ? page.locator('button[type="submit"]') : page.getByRole('button', { name: /^(Analyze draft|Analyzing…)/ })
  await button.click(); await waitForStart(started.promise)
  await failLogout(page, state)
  assert(await button.isDisabled(), 'session invalidation re-enabled an outstanding operation')
  await page.locator('.mm-operation-unknown').waitFor()
  if (operation === 'create') await page.keyboard.press('ControlOrMeta+s')
  assert.equal(state.calls.filter(x => x.key === 'POST ' + endpoint).length, 1)
  const response = page.waitForResponse(r => new URL(r.url()).pathname === endpoint && r.request().method() === 'POST')
  gate.resolve(); await response; await page.evaluate(() => new Promise(requestAnimationFrame))
  assert(await button.isDisabled(), 'discarded result silently allowed an unknown-outcome retry')
  await navigate(page, 'notes/2/edit')
  await editorTitle(page)
  assert.equal(await page.locator('.mm-operation-unknown').count(), 0, 'new route inherited outcome lock')
  assert(!(await page.locator('button[type="submit"]').isDisabled()), 'new route cannot save')
})

const flag = () => ({ comment: 'Synthetic flag', flagged_by: 'operator', flagged_at: '2026-09-01T00:00:00+00:00', reworded_at: null })
async function startFlag(page) {
  await page.locator('.mm-note-actions button', { hasText: 'Flag for curation' }).click()
  await page.locator('#flag-comment').fill('Synthetic flag')
  await page.locator('.mm-flag-actions button.btn-warning').click()
}

await scenario('Claude M1 failed logout preserves flag lock', async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['POST /api/notes/1/flag'] = async json => { started.resolve(); await gate.promise; return json({ curation_flag: flag() }) }
  await opened(page, 'notes/1')
  await startFlag(page)
  const save = page.locator('.mm-flag-actions button.btn-warning')
  await waitForStart(started.promise); await failLogout(page, state)
  assert(await save.isDisabled(), 'pending flag became repeatable')
  await page.locator('.mm-operation-unknown').waitFor()
  const response = page.waitForResponse(r => r.url().endsWith('/flag')); gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert(await save.isDisabled())
  await navigate(page, 'notes/2'); await page.locator('.mm-note-title').filter({ hasText: 'Note 2' }).waitFor()
  assert.equal(await page.locator('.mm-operation-unknown').count(), 0, 'another note inherited outcome lock')
  await page.locator('.mm-note-actions button', { hasText: 'Flag for curation' }).click()
  assert(!(await page.locator('.mm-flag-actions button.btn-warning').isDisabled()), 'another note inherited flag lock')
})

for (const batch of [false, true]) await scenario(`Claude M1 failed logout preserves inbox ${batch ? 'batch' : 'decision'} lock`, async (page, state) => {
  const gate = deferred(), started = deferred()
  state.notes = [note(1, { status: 'pending' })]; state.total = 1
  state.on['GET /api/notes/1'] = json => json(note(1, { status: 'pending' }))
  const endpoint = batch ? '/api/inbox/batch' : '/api/notes/1/approve'
  state.on['POST ' + endpoint] = async json => { started.resolve(); await gate.promise; return json(batch ? { done: 1, failed: [] } : { note: note(1) }) }
  await opened(page, 'inbox'); await page.locator('#review-note-1').waitFor()
  if (batch) {
    await page.locator('.mm-review-card input[type="checkbox"]').check()
    await page.locator('.mm-batch .btn-primary').click()
    await page.locator('.mm-batch-dialog.show').waitFor()
    await page.locator('.mm-batch-dialog .modal-footer .btn').last().click()
  } else {
    await page.locator('#review-note-1').click()
    await page.locator('.mm-decision-actions .btn-primary').click()
  }
  await waitForStart(started.promise); await failLogout(page, state)
  const button = batch ? page.locator('.mm-batch .btn-primary') : page.locator('.mm-decision-actions .btn-primary')
  assert(await button.isDisabled(), 'inbox operation was re-enabled before its response')
  await page.locator('.mm-operation-unknown').waitFor()
  const response = page.waitForResponse(r => new URL(r.url()).pathname === endpoint); gate.resolve(); await response
  await page.evaluate(() => new Promise(requestAnimationFrame))
  assert(await button.isDisabled())
  assert.equal(state.calls.filter(x => x.key === 'POST ' + endpoint).length, 1)
  state.user = me(2); await authAction(page, 'check')
  const detail = page.waitForResponse(r => new URL(r.url()).pathname === '/api/notes/1')
  await page.locator('#review-note-1').click()
  await detail; await page.evaluate(() => new Promise(requestAnimationFrame))
  assert(!(await page.locator('.mm-decision-actions .btn-primary').isDisabled()), 'new account inherited the old write lock')
  assert.equal(await page.locator('.mm-operation-unknown').count(), 0)
})

await scenario('Claude L1 superseded logout releases its own busy flag', async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['POST /api/logout'] = async json => { started.resolve(); await gate.promise; return json({}) }
  await opened(page, 'notes/1'); await page.locator('.mm-note-title').waitFor()
  const logout = authAction(page, 'logout'); await waitForStart(started.promise)
  state.user = me(2); await authAction(page, 'check')
  gate.resolve(); await logout
  assert.equal(await page.evaluate(() => document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('auth').loggingOut), false)
  await page.locator('.app-account-summary').click()
  assert(!(await page.locator('.app-account-menu button[role="menuitem"]').isDisabled()))
})

await scenario('Claude L2 stale preset failure is localized and remains a failure', async (page, state) => {
  state.user.locale = 'zz'
  state.on['GET /api/locales/zz'] = json => json({ common: { session_changed: 'Synthetic translated session change' } })
  state.on['GET /api/presets'] = json => json({ presets: [], icons: [] })
  const gate = deferred(), started = deferred()
  state.on['POST /api/presets'] = async json => { started.resolve(); await gate.promise; return json({ preset: { id: 17, name: 'Stale preset', q: 'synthetic', tags: [] } }) }
  await opened(page, 'notes?q=synthetic')
  await page.waitForSelector('html[lang="zz"]')
  await page.getByRole('button', { name: 'Save as workspace', exact: true }).click()
  await page.fill('#preset-name', 'Stale preset')
  await page.locator('.modal.show button[type="submit"]').click(); await waitForStart(started.promise)
  state.user = { ...me(2), locale: 'zz' }; await authAction(page, 'check')
  const response = page.waitForResponse(r => r.url().endsWith('/api/presets') && r.request().method() === 'POST'); gate.resolve(); await response
  await page.getByText('Synthetic translated session change', { exact: true }).waitFor()
  assert(await page.locator('#preset-name').isVisible(), 'stale failure was treated as successful save')
  assert(!(await page.locator('.p-toast').textContent()).includes('earlier session'))
})

await scenario('Claude M1 explicit reload reconciles an unknown flag outcome', async (page, state) => {
  const gate = deferred(), started = deferred()
  state.on['POST /api/notes/1/flag'] = async json => { started.resolve(); await gate.promise; return json({ curation_flag: flag() }) }
  await opened(page, 'notes/1')
  await startFlag(page)
  await waitForStart(started.promise); await failLogout(page, state)
  state.on['GET /api/notes/1'] = json => json(note(1, { body_md: 'Flagged content from server', curation_flag: flag(), flagged: true }))
  const response = page.waitForResponse(r => r.url().endsWith('/flag')); gate.resolve(); await response
  await page.locator('.mm-operation-unknown button').click()
  await page.getByText('Flagged content from server', { exact: true }).waitFor()
  await page.locator('.mm-flag-notice').waitFor()
  assert.equal(await page.locator('.mm-operation-unknown').count(), 0)
})

await browser.close(); await new Promise(resolve => server.close(resolve))
console.log(`${passed} lifetime scenarios passed; ${failures.length} failed`)
if (failures.length || passed === 0) process.exitCode = 1
