#!/usr/bin/env node
/**
 * The Review Inbox's safety properties, asserted against the running app.
 *
 * `check-review-inbox-ledger.mjs` reads the SOURCE for the tokens that carry
 * these properties, and a Codex review on 2026-09-01 showed exactly what that
 * is worth: eleven refactors that reintroduced multi-open cards, a checkbox
 * that expands, hidden selection surviving a filter change, Inbox zero after a
 * failed load and an ungated precedent flag each left all thirty of its
 * contracts green. A regex cannot see behaviour, only spelling.
 *
 * So this one drives the built SPA in a browser with the API stubbed, and
 * asserts what a person would see. It needs `dist/`, no backend and no
 * database: every `/api/**` request is answered from the fixtures below, which
 * is also how the failure states get exercised at all — a 500 and a slow
 * detail are not things a healthy server will produce on request.
 */
import { createServer } from 'node:http'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(frontend, 'dist')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('Review Inbox behaviour check failed:\n')
  console.error('  dist/index.html is missing — run `npm run build-only` first.')
  process.exit(1)
}

const MIME = {
  '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml',
  '.json': 'application/json', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png',
  '.ico': 'image/x-icon', '.map': 'application/json',
}

/** The SPA, served from disk with a catch-all to index.html for its routes. */
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

// Every page of the app is inside the knowledge base it belongs to, so the
// Inbox has an address only under a handle. Without one the shell loads, the
// router matches nothing, and every selector below waits out its timeout
// against the not-found page.
const HANDLE = 'k7m2pq9wd4rn'

const ME = {
  id: 1, email: 'guard@example.test', name: 'Guard', team: { id: 1, name: 'Guard', handle: HANDLE },
  system_tags: [], appearance: {}, icon_key: null, icon: null,
}

const noteRef = (id, title) => ({ id, title, status: 'verified', version: 5 })

const LONG_BODY = Array.from({ length: 120 }, (_, i) => `line ${i} of a long proposed body`).join('\n')

const EDIT = {
  id: 501, revision: 0, type: 'edit', note: noteRef(11, 'A note that gets an edit'), merge_into: null,
  change_title: 'Correct the heading', proposed_title: null,
  proposed_body_md: 'new body\nsecond line', proposed_patch: null, proposed_tags: null,
  proposed_summary: null, kinds: ['content'],
  comment: '- The heading is now **Deploy**.\n- The stale port line goes (note 9 has the current one).',
  proposed_by: 'Claude', created_at: '2026-09-01T09:00:00+00:00',
}

/** A merge that proposes no keeper body: it moves tags and backlinks and
 *  destroys the absorbed note, and changes no wording anywhere. */
const BODYLESS_MERGE = {
  id: 504, revision: 0, type: 'merge', note: noteRef(15, 'A note folded into another'),
  merge_into: { id: 16, title: 'The note that is kept', version: 12 },
  change_title: 'Fold the duplicate in', proposed_title: null, proposed_body_md: null,
  proposed_patch: null, proposed_tags: null, proposed_summary: null, kinds: ['merge'],
  comment: 'The same thing written twice.', proposed_by: 'Claude',
  created_at: '2026-09-01T07:00:00+00:00',
}

/** A merge that rewrites the keeper and brings a tag it does not have. */
const BODIED_MERGE = {
  id: 505, revision: 0, type: 'merge', note: noteRef(17, 'A note folded in with its text'),
  merge_into: { id: 18, title: 'The note that absorbs it', version: 12 },
  change_title: 'Fold it in', proposed_title: null,
  proposed_body_md: 'keeper text\nthe line only the keeper has\nand the folded-in line', proposed_patch: null,
  proposed_tags: null, proposed_summary: null, kinds: ['merge'],
  comment: 'Same subject, one page.', proposed_by: 'Claude',
  created_at: '2026-09-01T06:00:00+00:00',
}

const DELETE_PROPOSAL = {
  id: 502, revision: 0, type: 'delete', note: noteRef(12, 'A note proposed for deletion'), merge_into: null,
  change_title: 'Retire it', proposed_title: null, proposed_body_md: null, proposed_patch: null,
  proposed_tags: null, proposed_summary: null, kinds: ['deletion'],
  comment: 'Superseded.', proposed_by: 'Curator', created_at: '2026-09-01T08:00:00+00:00',
}

/** An anchored edit whose anchor still fits but whose result is blank. The
 *  server refuses this (`NotePatch::apply`), so the card has to say so before
 *  the click rather than after the refusal. */
const EMPTYING_PATCH = {
  id: 503, revision: 0, type: 'edit', note: noteRef(14, 'A note an anchored edit would empty'), merge_into: null,
  change_title: 'Drop the last line', proposed_title: null, proposed_body_md: null,
  proposed_patch: [{ find: 'only line', replace: '' }], proposed_tags: null, proposed_summary: null,
  kinds: ['content'], comment: 'It is redundant now.', proposed_by: 'Claude',
  created_at: '2026-09-01T05:00:00+00:00',
}

const PENDING_NOTE = {
  id: 13, version: 5, title: 'A note an agent wrote', source: 'agent', source_url: null, status: 'pending',
  last_actor: 'agent', edited_by: null, summary: 'A short summary.', summary_by: null, tags: [],
  created_at: '2026-09-01T06:00:00+00:00', updated_at: '2026-09-01T06:00:00+00:00',
}

const proposalDetail = (p) => ({
  ...p,
  note: { ...p.note, body_md: 'old body\nsecond line', summary: null, tags: [], backlinks: [{ id: 20, title: 'Something that links here' }] },
  merge_into: null,
})

/** An override for /api/proposals that answers the LIST and the DETAIL routes.
 *
 *  Overrides match by `includes`, so a key of '/api/proposals' captures
 *  '/api/proposals/502' as well. A handler that only knows how to build a list
 *  then answers every detail request with one, and the card renders nothing —
 *  which looks exactly like a broken card. `patch` is applied to the proposal
 *  whose id it names, in both payloads, so the two cannot disagree. */
function proposalsOverride(id, patch) {
  const all = [EDIT, DELETE_PROPOSAL, EMPTYING_PATCH, BODYLESS_MERGE, BODIED_MERGE]
    .map((p) => (p.id === id ? { ...p, ...patch } : p))

  return (route, json) => {
    const match = route.request().url().match(/\/api\/proposals\/(\d+)/)
    if (match === null) return json({ proposals: all })
    const one = all.find((p) => p.id === Number(match[1]))
    if (one === undefined) return json({ error: `no fixture for proposal ${match[1]}` }, 404)

    return json(detailFor(one))
  }
}

/** The detail payload the real stub would have built for this proposal. */
function detailFor(p) {
  if (p.type === 'merge') {
    const keeper = p.id === BODIED_MERGE.id
      ? { ...p.merge_into, body_md: 'keeper text', tags: ['kept'], summary: 'What the keeper is about.' }
      : { ...p.merge_into, body_md: 'what the keeper says', tags: [], summary: null }
    const absorbed = p.id === BODIED_MERGE.id
      ? { ...p.note, body_md: 'the folded-in line', summary: null, tags: ['brought-along'], backlinks: [] }
      : { ...p.note, body_md: 'what the duplicate says', summary: null, tags: [], backlinks: [] }

    return { ...p, note: absorbed, merge_into: keeper }
  }
  if (p.id === EMPTYING_PATCH.id) {
    return { ...p, note: { ...p.note, body_md: 'only line', summary: null, tags: [], backlinks: [] }, merge_into: null }
  }
  if (p.id === DELETE_PROPOSAL.id) {
    return {
      ...p,
      note: {
        ...p.note,
        body_md: 'the doomed note\nand its second line',
        summary: 'What the doomed note was for.',
        tags: ['doomed', 'log'],
        backlinks: [{ id: 20, title: 'Something that links here' }],
      },
      merge_into: null,
    }
  }

  return proposalDetail(p)
}

// ── the stub ─────────────────────────────────────────────────────────────

/** Per-scenario overrides: a map of URL fragment → handler. */
function apiStub(overrides = {}) {
  return async (route) => {
    const url = route.request().url()
    const json = (body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })

    for (const [fragment, handler] of Object.entries(overrides)) {
      if (url.includes(fragment)) return handler(route, json)
    }

    if (url.includes('/api/me')) return json(ME)
    if (url.includes('/api/inbox/count')) return json({ pending_notes: 1, edit_proposals: 2, total: 3 })
    if (url.includes('/api/inbox/batch')) return json({ action: 'approve', done: 1, failed: [] })
    if (url.includes('/api/proposals/')) {
      if (route.request().method() === 'POST') {
        // The exact endpoint, not merely something under /api/proposals/. A
        // stub that says yes to any POST cannot tell approve from reject, and
        // a verdict sent to the wrong path would pass every scenario below.
        if (!/\/api\/proposals\/\d+\/(approve|reject)$/.test(url)) {
          return json({ error: `unexpected verdict endpoint: ${url}` }, 400)
        }
        return json({ note: null, suggested_tags: {} })
      }
      const id = Number(url.match(/proposals\/(\d+)/)?.[1])
      if (id === EMPTYING_PATCH.id) {
        return json({ ...EMPTYING_PATCH, note: { ...EMPTYING_PATCH.note, body_md: 'only line', summary: null, tags: [], backlinks: [] }, merge_into: null })
      }
      if (id === BODIED_MERGE.id) {
        return json({
          ...BODIED_MERGE,
          note: { ...BODIED_MERGE.note, body_md: 'the folded-in line', summary: 'What the absorbed note was about.', tags: ['brought-along'], backlinks: [] },
          merge_into: { ...BODIED_MERGE.merge_into, body_md: 'keeper text\nthe line only the keeper has', tags: ['kept'], summary: 'What the keeper is about.' },
        })
      }
      if (id === BODYLESS_MERGE.id) {
        return json({
          ...BODYLESS_MERGE,
          note: { ...BODYLESS_MERGE.note, body_md: 'what the duplicate says', summary: null, tags: [], backlinks: [] },
          merge_into: { ...BODYLESS_MERGE.merge_into, body_md: 'what the keeper says', tags: [], summary: null },
        })
      }

      if (id === DELETE_PROPOSAL.id) {
        return json({
          ...DELETE_PROPOSAL,
          // A note with tags and a description: a deletion loses the whole
          // document, and the card has to show all of it going.
          note: {
            ...DELETE_PROPOSAL.note,
            body_md: 'the doomed note\nand its second line',
            summary: 'What the doomed note was for.',
            tags: ['doomed', 'log'],
            backlinks: [{ id: 20, title: 'Something that links here' }],
          },
          merge_into: null,
        })
      }

      return json(proposalDetail(EDIT))
    }
    if (url.includes('/api/proposals')) return json({ proposals: [EDIT, DELETE_PROPOSAL, EMPTYING_PATCH, BODYLESS_MERGE, BODIED_MERGE] })
    if (url.includes('/api/tags')) return json({ tags: [{ id: 1, name: 'house' }, { id: 2, name: 'decision' }] })
    if (url.includes('/api/notes/13')) return json({ ...PENDING_NOTE, body_md: 'The whole note.', added_by: null, added_by_token_id: null, described_at: null, embedded: false, pending_proposals: 0, links: [], backlinks: [], curation_flag: null })
    if (url.includes('/api/notes?')) return json({ items: [PENDING_NOTE], total: 1, semantic_unavailable: false })
    return json({})
  }
}

// ── the checks ───────────────────────────────────────────────────────────

const browser = await chromium.launch()
const failures = []
const passed = []

async function scenario(name, overrides, body) {
  if (process.env.REVIEW_SCENARIO && !name.includes(process.env.REVIEW_SCENARIO)) return
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const page = await context.newPage()
  await page.route('**/api/**', apiStub(overrides))
  try {
    await page.goto(`${BASE}/${HANDLE}/inbox`)
    await page.waitForSelector('.mm-inbox', { timeout: 15000 })
    await body(page)
    passed.push(name)
  } catch (error) {
    failures.push(`${name}: ${error.message}`)
  } finally {
    await context.close()
  }
}

const assert = (ok, message) => { if (!ok) throw new Error(message) }

await scenario('only one card is open at a time', {}, async (page) => {
  await page.waitForSelector('#review-proposal-501')
  // Select every card first. Expansion and selection are separate states, and
  // the regression worth catching is one leaking into the other — a panel that
  // opens for a selected card leaves the whole queue expanded at once.
  await page.click('.mm-review-head .btn')
  await page.waitForSelector('.mm-batch')
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-review-card.is-open')
  // A pending note and a proposal load by different paths, so both are opened:
  // open-state that lives on one loader and not the other is the leak.
  await page.click('#review-note-13')
  await page.waitForFunction(() => document.querySelector('#review-note-13')?.getAttribute('aria-expanded') === 'true')
  // Count the PANELS, not the styling class. A panel that renders without
  // `is-open` is still an open card to the person reading the page, and
  // counting the class alone missed exactly that.
  const state = await page.evaluate(() => ({
    panels: document.querySelectorAll('.mm-review-panel').length,
    marked: [...document.querySelectorAll('.mm-review-card.is-open .mm-review-expand')].map((b) => b.id),
    expanded: [...document.querySelectorAll('.mm-review-card [aria-expanded="true"]')].map((b) => b.id),
  }))
  assert(state.panels === 1, `expected one open panel, got ${state.panels}`)
  assert(state.marked.length === 1 && state.marked[0] === 'review-note-13', `the wrong card is marked open: ${JSON.stringify(state.marked)}`)
  assert(state.expanded.length === 1 && state.expanded[0] === 'review-note-13', `aria-expanded disagrees: ${JSON.stringify(state.expanded)}`)
})

await scenario('a checkbox selects without expanding', {}, async (page) => {
  await page.waitForSelector('.mm-review-check')
  await page.click('.mm-review-card:nth-child(1) .mm-review-check')
  await page.waitForSelector('.mm-batch')
  const clicked = await page.evaluate(() => ({
    panels: document.querySelectorAll('.mm-review-panel').length,
    checked: document.querySelectorAll('.mm-review-check:checked').length,
  }))
  assert(clicked.panels === 0, 'the checkbox expanded a card')
  assert(clicked.checked === 1, `expected one selected item, got ${clicked.checked}`)

  // Space on a focused checkbox, which is how a keyboard reader selects. It
  // raises a click that bubbles exactly like a mouse one, and a handler on the
  // summary listening for keys rather than clicks would not be stopped by it.
  await page.focus('.mm-review-card:nth-child(2) .mm-review-check')
  await page.keyboard.press('Space')
  await page.waitForTimeout(120)
  const typed = await page.evaluate(() => ({
    panels: document.querySelectorAll('.mm-review-panel').length,
    checked: document.querySelectorAll('.mm-review-check:checked').length,
  }))
  assert(typed.panels === 0, 'Space on the checkbox expanded a card')
  assert(typed.checked === 2, `Space did not select: ${typed.checked} checked`)
})

await scenario('changing the filter drops the selection', {}, async (page) => {
  await page.waitForSelector('#kind-filter option:nth-child(2)', { state: 'attached' })
  // Every option the page offers, because clearing on one transition and not
  // the others is a refactor that a single-transition check cannot see.
  const options = await page.$$eval('#kind-filter option', (o) => o.map((x) => x.value).filter(Boolean))
  assert(options.length >= 2, `expected several kinds in the fixture, got ${JSON.stringify(options)}`)
  for (const option of [...options, '']) {
    await page.click('.mm-review-head .btn')
    await page.waitForSelector('.mm-batch')
    await page.selectOption('#kind-filter', option)
    await page.waitForTimeout(120)
    const left = await page.evaluate(() => ({
      panel: document.querySelector('.mm-batch') !== null,
      checked: document.querySelectorAll('.mm-review-check:checked').length,
    }))
    assert(!left.panel && left.checked === 0, `selection survived the change to ${option || 'Everything'}`)
  }
})

// The precedent flag and the reasoning box it was gated on were withdrawn
// from the card on 2026-09-07; the scenario that drove them went with them
// rather than being kept alive against markup nobody can reach.

// Each of the two sources in turn: swallowing one of them as an empty result
// is a refactor that a single-endpoint check cannot see.
for (const endpoint of ['/api/proposals', '/api/notes?']) {
  await scenario(`a failed ${endpoint} is not Inbox zero`, {
    [endpoint]: (route, json) => json({ error: 'boom' }, 500),
  }, async (page) => {
    await page.waitForSelector('.app-state-error')
    const text = await page.textContent('.mm-inbox')
    assert(!text.includes('Inbox zero'), 'a failed load rendered as Inbox zero')
    assert(!text.includes('Newest first'), 'a failed load still rendered a queue')
    assert(await page.isVisible('.app-state-error button'), 'no Retry was offered')
  })
}

await scenario('a deletion names its consequence on the row, before anything is opened', {
  '/api/proposals/502': async (route, json) => {
    await new Promise((r) => setTimeout(r, 1200))
    return json(proposalDetail(DELETE_PROPOSAL))
  },
}, async (page) => {
  // The consequence used to be a notice block inside the panel, which meant it
  // arrived with the detail request and said nothing until the card was open.
  // It is a line on the collapsed row now (operator, 2026-09-07), so a slow
  // detail cannot hide it — and the verdicts still wait for the evidence.
  await page.waitForSelector('#review-proposal-502')
  const row = await page.evaluate(() => {
    const card = document.querySelector('#review-proposal-502').closest('.mm-review-card')
    return card.querySelector('.mm-review-relation')?.textContent.trim() ?? ''
  })
  assert(/restorable/i.test(row), `a collapsed deletion did not say what it costs: ${JSON.stringify(row)}`)

  await page.click('#review-proposal-502')
  await page.waitForSelector('.mm-decision-footer')
  const early = await page.evaluate(() => ({
    evidence: document.querySelector('.mm-evidence') !== null,
    live: [...document.querySelectorAll('.mm-decision-actions .btn')].filter((b) => !b.disabled).map((b) => b.textContent.trim()),
  }))
  assert(!early.evidence, 'the fixture did not exercise the slow path')
  // BOTH verdicts. Checking only the destructive one leaves the other free to
  // be decided against evidence nobody has seen.
  assert(early.live.length === 0, `these were live before the evidence arrived: ${JSON.stringify(early.live)}`)
  await page.waitForFunction(() => document.querySelector('.mm-evidence') !== null, null, { timeout: 8000 })
  await page.waitForFunction(() => [...document.querySelectorAll('.mm-decision-actions .btn')].every((b) => !b.disabled))
})

await scenario('a verdict hands focus to something that still exists', {}, async (page) => {
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-decision-actions .btn-primary:not([disabled])')
  await page.click('.mm-decision-actions .btn-primary')
  await page.waitForFunction(() => document.getElementById('review-proposal-501') === null, null, { timeout: 8000 })
  // Asserted positively. "Not <body>" passed while focus sat on <html>, which
  // is the same lost place by another name.
  // The NEIGHBOUR, not merely "something that still exists". Asking only for a
  // surviving control passed while focus walked to the top of the queue on
  // every verdict, which is the behaviour this exists to prevent.
  const where = await page.evaluate(() => {
    const el = document.activeElement
    return { tag: el?.tagName ?? 'NONE', id: el?.id ?? '' }
  })
  assert(
    where.id === 'review-proposal-502',
    `focus went to ${where.tag}${where.id ? '#' + where.id : ''} rather than the card after the one decided`,
  )
})

await scenario('deciding the last card leaves focus on the one before it', {}, async (page) => {
  // Newest first, so #503 at 05:00 is the bottom of the queue and its
  // neighbour is the one above it.
  const order = await page.$$eval('.mm-review-expand', (b) => b.map((x) => x.id))
  assert(order[order.length - 1] === 'review-proposal-503', `fixture order changed: ${JSON.stringify(order)}`)
  await page.click('#review-proposal-503')
  // Rejected rather than approved: this card is the patch that cannot land, so
  // its approve is disabled on purpose. Rejecting a proposal that will be
  // refused is exactly the verdict for it, and removes the row either way.
  await page.waitForSelector('.mm-decision-actions .btn-secondary:not([disabled])')
  await page.click('.mm-decision-actions .btn-secondary')
  await page.waitForFunction(() => document.getElementById('review-proposal-503') === null, null, { timeout: 8000 })
  const id = await page.evaluate(() => document.activeElement?.id ?? '')
  assert(id === order[order.length - 2], `focus went to ${id || 'nowhere'} rather than ${order[order.length - 2]}`)
})

await scenario('an anchored edit that would empty the note says so', {}, async (page) => {
  await page.click('#review-proposal-503')
  await page.waitForSelector('.mm-evidence')
  const state = await page.evaluate(() => ({
    notice: document.querySelector('.mm-doc-refusal')?.textContent ?? '',
    removed: document.querySelector('.mm-evidence .mm-diff-line.del')?.textContent ?? '',
    approve: document.querySelector('.mm-decision-actions .btn-primary')?.disabled,
    reject: document.querySelector('.mm-decision-actions .btn-secondary')?.disabled,
  }))
  assert(/empty the note/i.test(state.notice), `the card did not name the refusal: ${JSON.stringify(state.notice.slice(0, 80))}`)
  // The evidence is still shown. Withholding it left the reader told an edit
  // could not be applied and never told what the edit was.
  assert(/only line/.test(state.removed), `the content the patch removes was not shown: ${JSON.stringify(state.removed)}`)
  assert(state.approve === true, 'approve was live for an edit the server will refuse')
  assert(state.reject === false, 'reject was withheld for a proposal that cannot land')
})

await scenario('focus follows the VISIBLE neighbour under a filter', {}, async (page) => {
  // The neighbour is remembered before the verdict and the queue can change
  // underneath it. Under a filter, the card next in the unfiltered list is not
  // the one on screen — computing from the raw arrays lands focus on nothing.
  await page.selectOption('#kind-filter', 'content')
  await page.waitForTimeout(150)
  const visible = await page.$$eval('.mm-review-expand', (b) => b.map((x) => x.id))
  assert(visible.length >= 2, `expected several cards under the filter, got ${JSON.stringify(visible)}`)
  await page.click(`#${visible[0]}`)
  await page.waitForSelector('.mm-decision-actions .btn-secondary:not([disabled])')
  await page.click('.mm-decision-actions .btn-secondary')
  await page.waitForFunction((id) => document.getElementById(id) === null, visible[0], { timeout: 8000 })
  const id = await page.evaluate(() => document.activeElement?.id ?? '')
  assert(id === visible[1], `focus went to ${id || 'nowhere'} rather than the visible neighbour ${visible[1]}`)
})

await scenario('deciding the only card leaves focus on the heading', {
  '/api/proposals': (route, json) => json({ proposals: [] }),
}, async (page) => {
  const only = await page.$$eval('.mm-review-expand', (b) => b.map((x) => x.id))
  assert(only.length === 1 && only[0] === 'review-note-13', `expected a one-card queue, got ${JSON.stringify(only)}`)
  await page.click('#review-note-13')
  await page.waitForSelector('.mm-decision-actions .btn-primary:not([disabled])')
  await page.click('.mm-decision-actions .btn-primary')
  await page.waitForSelector('.app-state')
  const id = await page.evaluate(() => document.activeElement?.id ?? '')
  assert(id === 'inbox-heading', `focus went to ${id || 'nowhere'} rather than the heading of an emptied queue`)
})

await scenario('a successful batch does not drop focus', {}, async (page) => {
  await page.click('.mm-review-head .btn')
  await page.waitForSelector('.mm-batch')
  await page.click('.mm-batch .btn-primary')
  await page.waitForSelector('.mm-batch-dialog.show')
  await page.click('.mm-batch-dialog .modal-footer .btn:last-child')
  await page.waitForFunction(() => document.querySelector('.mm-batch') === null, null, { timeout: 8000 })
  await page.waitForTimeout(250)
  // The heading by name. A successful batch clears the selection, which takes
  // the button that opened the dialog with it — so "somewhere that is not the
  // body" passed while focus was handed to an element already on its way out.
  const where = await page.evaluate(() => ({ id: document.activeElement?.id ?? '', tag: document.activeElement?.tagName ?? '' }))
  assert(where.id === 'inbox-heading', `focus went to ${where.tag}${where.id ? '#' + where.id : ''} rather than the heading`)
})

// ── a pending note is edited in place, and only a real change is sent ─────
//
// The card opens the note for editing rather than for reading, which puts an
// editor pre-filled with the agent's text in front of every approval. That
// makes one property load-bearing: an approval nobody typed into must still be
// an approval AS FILED. Send the fields back unchanged and every verdict
// becomes an operator edit — a revision, the operator's name on the note, and
// a journal row claiming they rewrote text they only read.

await scenario('a pending note opens as a document, and unlocks like everything else', {}, async (page) => {
  // It used to open straight into an editor while every other card opened into
  // a read view — one structure for all four now (operator, 2026-09-07): read
  // what is proposed, then choose to change it.
  await page.click('#review-note-13')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const locked = await page.evaluate(() => ({
    editor: document.querySelector('.mm-note-form') !== null,
    amend: document.querySelector('.mm-amend-toggle') !== null,
    body: document.querySelector('.mm-evidence').innerText,
    approveEnabled: !document.querySelector('.mm-decision-actions .btn-primary').disabled,
  }))
  assert(!locked.editor, 'a pending note opened straight into an editor')
  assert(locked.amend, 'a pending note offers no way to take it over')
  assert(/The whole note\./.test(locked.body), `the document did not show the note: ${JSON.stringify(locked.body.slice(0, 80))}`)
  assert(locked.approveEnabled, 'a note read but not amended cannot be approved')

  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-cm')
  const unlocked = await page.evaluate(() => ({
    title: document.querySelector('.mm-note-form .mm-amend-title')?.value ?? null,
    body: document.querySelector('.mm-note-form .cm-content')?.innerText ?? '',
  }))
  assert(unlocked.title === 'A note an agent wrote', `the title field did not hold the note: ${JSON.stringify(unlocked.title)}`)
  assert(/The whole note\./.test(unlocked.body), `the editor did not hold the body: ${JSON.stringify(unlocked.body.slice(0, 60))}`)
})

/** Approve the open note, recording exactly what the verdict posted. */
async function approveAndCapture(page, before) {
  const sent = []
  await page.route('**/api/notes/13/approve', async (route) => {
    sent.push(JSON.parse(route.request().postData() || '{}'))
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ note: PENDING_NOTE }) })
  })
  await page.click('#review-note-13')
  await page.waitForSelector('.mm-evidence .mm-doc')
  if (before) {
    await page.click('.mm-amend-toggle')
    await page.waitForSelector('.mm-note-form .mm-cm')
    await before()
  }
  await page.click('.mm-decision-actions .btn-primary')
  await page.waitForFunction(() => document.querySelector('#review-note-13') === null)
  assert(sent.length === 1, `expected one verdict, got ${sent.length}`)

  return sent[0]
}

await scenario('approving an untouched note sends no edits', {}, async (page) => {
  const body = await approveAndCapture(page, null)
  const fields = Object.keys(body)
  assert(body.expected_version === 5, 'plain approval omitted the displayed version')
  assert(
    !fields.includes('title') && !fields.includes('body_md') && !fields.includes('tags'),
    `approving as filed sent an edit: ${JSON.stringify(fields)}`,
  )
})

await scenario('what is typed into the card is what the approval carries', {}, async (page) => {
  const body = await approveAndCapture(page, () => page.fill('.mm-note-form .mm-amend-title', 'The title the operator wants'))
  assert(body.title === 'The title the operator wants', `the typed title did not reach the server: ${JSON.stringify(body.title)}`)
  assert(body.body_md === 'The whole note.', `the body did not travel with it: ${JSON.stringify(body.body_md)}`)
  assert(!Object.hasOwn(body, 'summary'), 'a title-only amendment re-attributed the unchanged summary')
})

await scenario('a note emptied in the card cannot be approved', {}, async (page) => {
  await page.click('#review-note-13')
  await page.waitForSelector('.mm-evidence .mm-doc')
  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-cm')
  await page.fill('.mm-note-form .mm-amend-title', '   ')
  await page.waitForFunction(() => document.querySelector('.mm-decision-actions .btn-primary')?.disabled === true)
  const rejectable = await page.evaluate(
    () => !document.querySelector('.mm-decision-actions .btn-secondary, .mm-decision-actions .btn-danger')?.disabled,
  )
  assert(rejectable, 'a note that cannot be approved must still be rejectable')
})

// ── unlocking a proposal edits it in place, and in every field ───────────
//
// Two panes — a diff to read and a box to type in — was the shape this
// replaced, and it could only reach the body. What is asserted here is that
// unlocking REPLACES the evidence rather than appearing beside it, that all
// four fields a proposal carries are reachable, and that an untouched unlock
// still approves the proposal exactly as filed.

await scenario('unlocking a proposal replaces the diff rather than sitting beside it', {}, async (page) => {
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-evidence .mm-diff-body')
  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-cm')
  const state = await page.evaluate(() => ({
    panes: document.querySelectorAll('.mm-note-form').length,
    diffsLeft: document.querySelectorAll('.mm-evidence .mm-diff-body').length,
    fields: [...document.querySelectorAll('.mm-note-form .form-label')].map((l) => l.textContent.trim()),
    body: document.querySelector('.mm-note-form .cm-content')?.innerText ?? '',
  }))
  assert(state.panes === 1, `expected one editable pane, got ${state.panes}`)
  assert(state.diffsLeft === 0, 'the read-only diff is still on screen beside the editor')
  assert(state.fields.length === 4, `expected title, body, tags and description, got ${JSON.stringify(state.fields)}`)
  assert(/new body/.test(state.body), `the editor did not open on the proposed text: ${JSON.stringify(state.body.slice(0, 40))}`)
})

await scenario('the proposed change is shown as a change, not as a fresh body', {}, async (page) => {
  // The point of unlocking into a merge view rather than a plain editor: what
  // the agent removed has to stay visible while it is being edited.
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-evidence .mm-diff-body')
  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-cm')
  await page.waitForFunction(() => document.querySelector('.mm-note-form .cm-deletedChunk') !== null)
  const marks = await page.evaluate(() => {
    const deleted = document.querySelector('.mm-note-form .cm-deletedChunk')
    const added = document.querySelector('.mm-note-form .cm-merge-b .cm-changedLine')

    return {
      removedText: deleted?.innerText ?? '',
      controls: [...document.querySelectorAll('.mm-note-form .cm-deletedChunk button')].map((b) => b.textContent),
      // The colours are the point, and WHOSE colours matters: CodeMirror
      // injects its own merge theme at a specificity that has already beaten
      // ours once in this file's history. Its green is rgb(100 160 128) and
      // ours is the rgb(26 138 79) the read-only diffs use, so asserting the
      // exact triple is what tells a working style from a losing one —
      // "something is coloured" passes either way.
      addedBg: added ? getComputedStyle(added).backgroundColor : null,
      removedBg: deleted ? getComputedStyle(deleted).backgroundColor : null,
    }
  })
  assert(/old body/.test(marks.removedText), `the removed text is not shown: ${JSON.stringify(marks.removedText.slice(0, 60))}`)
  assert(marks.controls.length === 2, `expected accept and reject on the chunk, got ${JSON.stringify(marks.controls)}`)
  assert(/^rgba?\(26, 138, 79[,)]/.test(marks.addedBg ?? ''), `added lines are not wearing our green: ${marks.addedBg}`)
  assert(/^rgba?\(192, 57, 43[,)]/.test(marks.removedBg ?? ''), `removed lines are not wearing our red: ${marks.removedBg}`)
})

/** Approve the open proposal, recording exactly what the verdict posted. */
async function approveProposalCapturing(page, before) {
  const sent = []
  await page.route('**/api/proposals/501/approve', async (route) => {
    sent.push(JSON.parse(route.request().postData() || '{}'))
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ note: null, suggested_tags: {} }) })
  })
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-evidence .mm-diff-body')
  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-cm')
  if (before) await before()
  await page.click('.mm-decision-actions .btn-primary')
  await page.waitForFunction(() => document.querySelector('#review-proposal-501') === null)
  assert(sent.length === 1, `expected one verdict, got ${sent.length}`)

  return sent[0]
}

await scenario('unlocking and changing nothing approves the proposal as filed', {}, async (page) => {
  const body = await approveProposalCapturing(page, null)
  const fields = Object.keys(body).filter((f) => ['title', 'body_md', 'tags', 'summary'].includes(f))
  assert(fields.length === 0, `an untouched unlock sent an amendment: ${JSON.stringify(fields)}`)
  assert(body.expected_revision === 0 && body.expected_version === 5, 'proposal approval omitted its snapshot')
})

await scenario('a title typed into the unlocked pane travels with the approval', {}, async (page) => {
  const body = await approveProposalCapturing(page, () => page.fill('.mm-note-form .mm-amend-title', 'The title the operator wants'))
  assert(body.title === 'The title the operator wants', `the typed title did not reach the server: ${JSON.stringify(body.title)}`)
  assert(body.body_md === undefined, 'a title-only amendment must not resend the body the operator never touched')
})

await scenario('the amend control is a button, not a link buried in the footer', {}, async (page) => {
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const state = await page.evaluate(() => {
    const btn = document.querySelector('.mm-amend-toggle')
    const footer = document.querySelector('.mm-decision-footer')

    return {
      exists: !!btn,
      link: btn?.classList.contains('btn-link') ?? null,
      // Left of the verdicts, not among them: amending is what you do BEFORE
      // deciding, and a control that sits inside the decide group reads as a
      // third verdict.
      beforeActions: btn && footer ? btn.compareDocumentPosition(footer.querySelector('.mm-decision-actions')) === Node.DOCUMENT_POSITION_FOLLOWING : null,
      footerText: footer.innerText,
    }
  })
  assert(state.exists, 'the amend control is gone')
  assert(state.link === false, 'the amend control is still a link rather than a button')
  assert(state.beforeActions, 'the amend button is not to the left of the verdicts')
  assert(!/reason/i.test(state.footerText), `the withdrawn reasoning box is back: ${JSON.stringify(state.footerText)}`)
})

await scenario('a merge that rewrites nothing is still amendable', {}, async (page) => {
  // Approving it produces a new version of the surviving note — merged tags,
  // retargeted links, one fewer note beside it — and the operator may take that
  // over whether or not the agent proposed wording (operator, 2026-09-07).
  // What unlocks is the KEEPER, which is also what the card is titled by.
  await page.click('#review-proposal-504')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const locked = await page.evaluate(() => ({
    unlock: !!document.querySelector('.mm-amend-toggle'),
    title: document.querySelector('.mm-review-card.is-open .mm-review-title').textContent.trim(),
    evidence: document.querySelector('.mm-evidence').innerText,
  }))
  assert(locked.unlock, 'a merge proposing no keeper body offered no way to amend it')
  assert(locked.title === 'The note that is kept', `the card was titled by the wrong note: ${JSON.stringify(locked.title)}`)
  assert(/what the keeper says/.test(locked.evidence), 'the surviving note is not the document being shown')
  assert(/what the duplicate says/.test(locked.evidence), 'the absorbed content is not shown at all')

  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-cm')
  const unlocked = await page.evaluate(() => ({
    body: document.querySelector('.mm-note-form .cm-content')?.innerText ?? '',
    title: document.querySelector('.mm-note-form .mm-amend-title') !== null,
    folded: document.querySelector('.mm-note-form .mm-doc-destroyed') !== null,
  }))
  assert(/what the keeper says/.test(unlocked.body), `unlocking opened the wrong note: ${JSON.stringify(unlocked.body.slice(0, 60))}`)
  assert(!unlocked.title, 'a merge offered to retitle the keeper, which it never proposed to change')
  assert(unlocked.folded, 'the text being folded in disappeared at the moment it was being used')
})

await scenario('a delete offers nothing to unlock', {}, async (page) => {
  // A delete proposes no content, so there is nothing to edit — and offering
  // the link would promise a change the server refuses.
  await page.click('#review-proposal-502')
  await page.waitForSelector('.mm-evidence')
  const footer = await page.evaluate(() => document.querySelector('.mm-decision-footer').innerText)
  assert(!/Edit this change/i.test(footer), `a delete offered an edit link: ${JSON.stringify(footer)}`)
})

// ── one structure, whatever the agent filed ──────────────────────────────
//
// Every card asks the same question — is this what the note should say — so
// every card answers it the same way: a heading, then body, tags and
// description, in that order. Before 2026-09-07 a delete showed backlinks and
// red text, an edit showed field-by-field diffs, a merge showed a keeper diff
// under a panel of the absorbed note, and a pending note showed an editor.
// Four shapes for one question, and the operator had to learn all four.

const FIELD_ORDER = ['BODY', 'TAGS', 'DESCRIPTION']

for (const [what, opener, expect] of [
  ['an edit', '#review-proposal-501', /new body/],
  ['a deletion', '#review-proposal-502', /the doomed note/],
  ['a merge', '#review-proposal-505', /keeper text/],
  ['a new note', '#review-note-13', /The whole note\./],
]) {
  await scenario(`${what} is shown as body, tags and description, in that order`, {}, async (page) => {
    await page.click(opener)
    await page.waitForSelector('.mm-evidence .mm-doc')
    const state = await page.evaluate(() => ({
      labels: [...document.querySelectorAll('.mm-evidence .mm-doc > .mm-doc-field > .mm-doc-label')]
        .map((el) => el.textContent.trim().toUpperCase()),
      text: document.querySelector('.mm-evidence').innerText,
      heading: document.querySelector('.mm-doc-heading')?.textContent.trim() ?? '',
    }))
    assert(
      JSON.stringify(state.labels) === JSON.stringify(FIELD_ORDER),
      `${what} drew its fields as ${JSON.stringify(state.labels)}`,
    )
    assert(expect.test(state.text), `${what} did not show the document itself: ${JSON.stringify(state.text.slice(0, 90))}`)
    assert(state.heading.length > 0, `${what} did not say what the document below it is`)
  })
}

// ── the reason is a report, not an essay ─────────────────────────────────
//
// An agent files one short sentence per change and a bullet per change where
// there is more than one (canon.md, "Filing a decision"). The card used to
// interpolate that as text, so the bullets and emphasis were served as the
// characters `-` and `**` — which is what an operator saw on 2026-09-16.

await scenario('a reason with a change per line renders as a list', {}, async (page) => {
  await page.waitForSelector('#review-proposal-501')
  const state = await page.evaluate(() => {
    const card = document.querySelector('#review-proposal-501').closest('.mm-review-card')
    const reason = card.querySelector('.mm-review-reason')
    return {
      // Scoped to the block wrapper, not to the reason: a list renders on its
      // own lines whatever element holds it, so an unscoped query passes while
      // `block` is wrong in either direction.
      items: [...reason.querySelectorAll('.mm-reason-md li')].map((el) => el.textContent.trim()),
      strong: reason.querySelectorAll('strong').length,
      text: reason.innerText,
    }
  })
  assert(state.items.length === 2, `the two changes did not render as two list items: ${JSON.stringify(state.items)}`)
  assert(state.strong === 1, `emphasis in the reason was not rendered: ${state.strong} strong elements`)
  assert(!/(\*\*|^\s*-\s)/m.test(state.text), `markdown was served as characters: ${JSON.stringify(state.text)}`)
})

await scenario('a one-sentence reason stays beside its label', {}, async (page) => {
  // The common case is a single change, and giving it a block of its own put
  // the label on a line by itself for no reason.
  await page.waitForSelector('#review-proposal-502')
  const state = await page.evaluate(() => {
    const card = document.querySelector('#review-proposal-502').closest('.mm-review-card')
    const reason = card.querySelector('.mm-review-reason')
    return { block: reason.querySelector('.mm-reason-md') !== null, lines: reason.innerText.trim().split('\n').length }
  })
  assert(!state.block, 'a single sentence was rendered as a block')
  assert(state.lines === 1, `the label and a one-sentence reason were split over ${state.lines} lines`)
})

await scenario('a link in a reason does not close the card or drop an amendment', {
  '/api/proposals': proposalsOverride(EDIT.id, { comment: 'See [the runbook](https://example.test/runbook).' }),
}, async (page) => {
  // Markdown means the reason has interactive descendants now. The row's own
  // click handler read one as a click on the row, so following a link
  // collapsed the card and `resetDecision` cleared the amendment with it.
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-evidence .mm-diff-body')
  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-cm')
  await page.fill('.mm-note-form .mm-amend-title', 'The title the operator wants')
  await page.click('.mm-review-reason a')
  const state = await page.evaluate(() => ({
    panels: document.querySelectorAll('.mm-review-panel').length,
    title: document.querySelector('.mm-note-form .mm-amend-title')?.value ?? null,
  }))
  assert(state.panels === 1, 'following a link in the reason closed the card')
  assert(
    state.title === 'The title the operator wants',
    `following a link in the reason discarded the amendment: ${JSON.stringify(state.title)}`,
  )
})

await scenario('a merge shows the tags the surviving note gains, as additions', {}, async (page) => {
  // The union is something the merge DOES. It used to be asserted in a
  // sentence beside the tags; now the tag row shows it in the same green the
  // body uses for a line that is being added.
  await page.click('#review-proposal-505')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const tags = await page.evaluate(() => [...document.querySelectorAll('.mm-doc-tag')]
    .map((el) => `${el.textContent.trim()}:${el.classList.contains('is-added') ? 'added' : el.classList.contains('is-removed') ? 'removed' : 'same'}`))
  assert(tags.includes('kept:same'), `the keeper's own tags are not shown as kept: ${JSON.stringify(tags)}`)
  assert(tags.includes('brought-along:added'), `the tag the merge moves is not shown as an addition: ${JSON.stringify(tags)}`)
})

await scenario('a merge whose keeper did not arrive is refused, not seeded from the corpse', {
  // Version skew: an older server, or one that stops sending the keeper's
  // fields. The card then seeded its unlock from the ABSORBED note, and
  // approving would have replaced the survivor's tags with the dead note's
  // (Codex, 2026-09-07).
  '/api/proposals/505': (route, json) => json({
    ...BODIED_MERGE,
    note: { ...BODIED_MERGE.note, body_md: 'the folded-in line', summary: null, tags: ['brought-along'], backlinks: [] },
    merge_into: { ...BODIED_MERGE.merge_into },
  }),
}, async (page) => {
  await page.click('#review-proposal-505')
  await page.waitForSelector('.mm-review-detail-error')
  const state = await page.evaluate(() => ({
    text: document.querySelector('.mm-inbox').innerText,
    canDecide: document.querySelector('.mm-decision-actions') !== null,
    canUnlock: document.querySelector('.mm-amend-toggle') !== null,
  }))
  assert(!state.canDecide, 'a merge with no surviving note to show could still be approved')
  assert(!state.canUnlock, 'a merge with no surviving note to show could still be unlocked')
  assert(!/brought-along/.test(state.text), 'the absorbed note\'s tags were rendered as the merge result')
})

// Each keeper field on its own. Dropping all three together never isolates
// any of them, which is how the missing-summary case survived the first
// version of the refusal scenario (Codex, 2026-09-07).
for (const missing of ['body_md', 'tags', 'summary']) {
  await scenario(`a merge whose keeper arrives without its ${missing} is refused`, {
    '/api/proposals/505': (route, json) => {
      const keeper = {
        ...BODIED_MERGE.merge_into,
        body_md: 'keeper text\nthe line only the keeper has',
        tags: ['kept'],
        summary: 'What the keeper is about.',
      }
      delete keeper[missing]

      return json({
        ...BODIED_MERGE,
        note: { ...BODIED_MERGE.note, body_md: 'the folded-in line', summary: 'What the absorbed note was about.', tags: ['brought-along'], backlinks: [] },
        merge_into: keeper,
      })
    },
  }, async (page) => {
    await page.click('#review-proposal-505')
    await page.waitForSelector('.mm-review-detail-error')
    const state = await page.evaluate(() => ({
      text: document.querySelector('.mm-inbox').innerText,
      canDecide: document.querySelector('.mm-decision-actions') !== null,
    }))
    assert(!state.canDecide, `a merge missing the keeper's ${missing} could still be approved`)
    // The absorbed note's own description must not stand in for the keeper's.
    assert(!/What the absorbed note was about/.test(state.text), `the absorbed note's description was shown as the keeper's`)
  })
}

await scenario('re-ordering the tags sends no amendment at all', {
  // Two tags, so removing one and adding it back really does reorder the list.
  '/api/proposals/501': (route, json) => json({
    ...EDIT,
    note: { ...EDIT.note, body_md: 'old body\nsecond line', summary: null, tags: ['alpha', 'beta'], backlinks: [] },
    merge_into: null,
  }),
}, async (page) => {
  // Removing a tag and adding it back leaves the same set in a different
  // order. Compared by position that was a rewrite, and every such verdict put
  // the operator's name on the proposer's text (Codex, 2026-09-07).
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-evidence .mm-doc')
  await page.click('.mm-amend-toggle')
  await page.waitForSelector('.mm-note-form .mm-taginput')

  const before = await page.$$eval('.mm-note-form .mm-tag-pill', (p) => p.map((el) => el.textContent.replace('×', '').trim()))
  assert(before.join() === 'alpha,beta', `the pane did not start from the note's tags: ${JSON.stringify(before)}`)

  await page.click('.mm-note-form .mm-tag-pill:first-child .mm-tag-pill-x')
  await page.fill('.mm-note-form .mm-taginput-input', 'alpha')
  await page.keyboard.press('Enter')
  const after = await page.$$eval('.mm-note-form .mm-tag-pill', (p) => p.map((el) => el.textContent.replace('×', '').trim()))
  assert(after.join() === 'beta,alpha', `the tags were not actually re-ordered: ${JSON.stringify(after)}`)

  const sent = []
  await page.route('**/api/proposals/*/approve', async (route) => {
    sent.push(JSON.parse(route.request().postData() ?? '{}'))
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ note: null, suggested_tags: {} }) })
  })
  await page.click('.mm-decision-actions .btn-primary')
  await page.waitForFunction(() => document.getElementById('review-proposal-501') === null, null, { timeout: 8000 })
  assert(sent.length === 1, `expected one verdict, got ${sent.length}`)
  assert(!('tags' in sent[0]), `a re-ordered tag row was sent as an amendment: ${JSON.stringify(sent[0])}`)
})

await scenario('a deletion renders the whole document as removal', {}, async (page) => {
  await page.click('#review-proposal-502')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const state = await page.evaluate(() => ({
    kept: document.querySelectorAll('.mm-evidence .mm-diff-line.ins').length,
    removed: document.querySelectorAll('.mm-evidence .mm-diff-line.del').length,
    tags: [...document.querySelectorAll('.mm-doc-tag')].map((el) => el.classList.contains('is-removed')),
  }))
  assert(state.removed > 0, 'a deletion showed no removed lines')
  assert(state.kept === 0, 'a deletion showed lines being added')
  assert(state.tags.length > 0 && state.tags.every(Boolean), 'a deletion showed tags that survive it')
})

await scenario('the absorbed note is named once, and its text sits inside the body', {}, async (page) => {
  // It was said three times on one card — the relation line, a panel heading,
  // and the panel's own copy (operator, 2026-09-07). It belongs to the body it
  // is being folded into, as the removal it is.
  await page.click('#review-proposal-504')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const state = await page.evaluate(() => {
    const card = document.querySelector('.mm-review-card.is-open')
    const title = 'A note folded into another'
    return {
      mentions: card.innerText.split(title).length - 1,
      insideBody: document.querySelector('.mm-doc-field .mm-doc-destroyed') !== null,
      row: card.querySelector('.mm-review-relation')?.textContent ?? '',
    }
  })
  assert(state.mentions === 1, `the absorbed note is named ${state.mentions} times on one card`)
  assert(state.insideBody, 'the destroyed text is not inside the body field it is folded into')
  assert(/A note folded into another/.test(state.row), 'the row does not name what the merge consumes')
})

await scenario('a review item and a note are numbered differently', {}, async (page) => {
  // `#5` is a proposal and `note 7` is a note. Both wore a bare `#` until the
  // operator asked what the numbers meant.
  await page.waitForSelector('#review-proposal-504')
  const state = await page.evaluate(() => {
    const merge = document.querySelector('#review-proposal-504').closest('.mm-review-card')
    const note = document.querySelector('#review-note-13').closest('.mm-review-card')
    return {
      proposal: merge.querySelector('.mm-review-id').textContent.trim(),
      keeper: merge.querySelector('.mm-review-title-line .mm-review-noteref').textContent.trim(),
      pending: note.querySelector('.mm-review-id').textContent.trim(),
    }
  })
  assert(state.proposal === '#504', `the review item is not numbered as one: ${JSON.stringify(state.proposal)}`)
  assert(/^\|?\s*note 16$/.test(state.keeper), `the surviving note is not referenced as a note: ${JSON.stringify(state.keeper)}`)
  // A pending note IS the review item, so it is numbered as a note rather than
  // borrowing a proposal number that would collide with a real one.
  assert(state.pending === 'note 13', `a pending note borrowed a proposal number: ${JSON.stringify(state.pending)}`)
})

await scenario('a merge is documented by the note that survives it', {}, async (page) => {
  // Body, tags and description are three separate reads of `merge_into`, and
  // Codex repointed all three at the ABSORBED note on 2026-09-07 with every
  // ledger contract green. The description is the one nothing else asserts.
  await page.click('#review-proposal-505')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const state = await page.evaluate(() => ({
    title: document.querySelector('.mm-review-card.is-open .mm-review-title').textContent.trim(),
    // The document's own description field, not the pane's text: the absorbed
    // note's body is legitimately inside the pane as the removal it is, so a
    // whole-pane match would pass on either note's description.
    summary: document.querySelector('.mm-evidence .mm-doc-summary')?.textContent.trim() ?? '',
    // PROVENANCE, not presence. The keeper's own line is also inside the
    // PROPOSED body, so "is it on screen" passes with keeperBody pointed at
    // the absorbed note (Codex, 2026-09-07). What the before/after pair can
    // only get right when keeperBody really is the keeper is which text it is
    // REPLACING — so this reads the removed side of the body diff, excluding
    // the absorbed note's own block, which is a removal for a different reason.
    replacing: [...document.querySelectorAll('.mm-doc-field .mm-diff-body:not(.mm-doc-destroyed) .mm-diff-line.del')]
      .map((el) => el.textContent.replace(/^−\s*/, '').trim()),
  }))
  assert(state.title === 'The note that absorbs it', `the card is not titled by the survivor: ${JSON.stringify(state.title)}`)
  assert(state.summary === 'What the keeper is about.', `the document describes the wrong note: ${JSON.stringify(state.summary)}`)
  assert(
    state.replacing.some((l) => l.includes('the line only the keeper has')),
    `the body is not shown as replacing the keeper's own text: ${JSON.stringify(state.replacing)}`,
  )
  assert(
    !state.replacing.some((l) => l.includes('folded-in')),
    `the body is shown as replacing the ABSORBED note's text: ${JSON.stringify(state.replacing)}`,
  )
})

await scenario('a proposed title is shown against the one it replaces', {
  '/api/proposals': proposalsOverride(EDIT.id, { proposed_title: 'The name the agent wants' }),
}, async (page) => {
  // The card header names the note as it STANDS, so a rename is the one thing
  // it cannot carry. Between the restructure and this scenario the new name
  // appeared nowhere at all until the pane was unlocked.
  await page.click('#review-proposal-501')
  await page.waitForSelector('.mm-evidence .mm-doc')
  const state = await page.evaluate(() => ({
    title: document.querySelector('.mm-doc-heading .mm-doc-title')?.textContent.trim() ?? '',
    was: document.querySelector('.mm-doc-heading .mm-doc-was del')?.textContent.trim() ?? '',
  }))
  assert(state.title === 'The name the agent wants', `the proposed title is not on the card: ${JSON.stringify(state.title)}`)
  assert(state.was === 'A note that gets an edit', `the name it replaces is not struck through beside it: ${JSON.stringify(state.was)}`)
})

await scenario('no card scrolls inside itself', {
  // A document far taller than any plausible cap. With short fixtures this
  // scenario passed against a 30vh scroller, which is a guard measuring the
  // fixture rather than the page.
  // Keep the list and detail fixtures consistent; detail replaces the whole review snapshot.
  '/api/proposals': proposalsOverride(EDIT.id, { proposed_body_md: LONG_BODY }),
}, async (page) => {
  // A pane capped at 46vh with its own scroller put a second scrollbar inside a
  // page that already scrolls, and hid the end of any document a little over
  // the cap (operator, 2026-09-07).
  for (const opener of ['#review-proposal-501', '#review-proposal-502', '#review-proposal-504', '#review-note-13']) {
    await page.click(opener)
    await page.waitForSelector('.mm-evidence')
    const scrollers = await page.evaluate(() => {
      const out = []
      for (const el of document.querySelector('.mm-review-card.is-open').querySelectorAll('*')) {
        const overflow = getComputedStyle(el).overflowY
        if (el.scrollHeight > el.clientHeight + 2 && (overflow === 'auto' || overflow === 'scroll')) {
          out.push(el.className || el.tagName)
        }
      }
      return out
    })
    assert(scrollers.length === 0, `${opener} scrolls inside itself: ${JSON.stringify(scrollers)}`)
    const capped = await page.evaluate(
      () => getComputedStyle(document.querySelector('.mm-review-card.is-open .mm-evidence')).maxHeight,
    )
    assert(capped === 'none', `the evidence pane is capped at ${capped}, so a taller document would scroll inside it`)

    // Unlocked as well. The editor carries `max-height: 70vh` of its own, so
    // removing the pane's cap does not remove the card's inner scrollbar.
    const amendable = await page.evaluate(() => document.querySelector('.mm-amend-toggle') !== null)
    if (amendable) {
      await page.click('.mm-amend-toggle')
      await page.waitForSelector('.mm-note-form .mm-cm')
      await page.waitForTimeout(300)
      const unlocked = await page.evaluate(() => {
        const out = []
        for (const el of document.querySelector('.mm-review-card.is-open').querySelectorAll('*')) {
          const overflow = getComputedStyle(el).overflowY
          if (el.scrollHeight > el.clientHeight + 2 && (overflow === 'auto' || overflow === 'scroll')) {
            out.push(el.className || el.tagName)
          }
        }
        return out
      })
      assert(unlocked.length === 0, `${opener} scrolls inside itself once unlocked: ${JSON.stringify(unlocked)}`)
    }
    await page.click(opener)
  }
})

await scenario('clearing only the pending-note summary reaches approval as an explicit empty value', {}, async page => {
  const body = await approveAndCapture(page, () => page.fill('.mm-note-form textarea', ''))
  assert(body.summary === '', `summary clearing was lost: ${JSON.stringify(body)}`)
  assert(body.expected_version === 5, 'summary amendment omitted the reviewed version')
})

let releaseNoteDetail
const delayedNoteDetail = new Promise(resolve => { releaseNoteDetail = resolve })
await scenario('opening a note replaces stale list metadata together with its version', {
  '/api/notes/13': async (route, json) => {
    await delayedNoteDetail
    return json({ ...PENDING_NOTE, version: 8, title: 'Current detail title',
      summary: 'Current detail summary', tags: [{ id: 9, name: 'current-detail-tag' }], body_md: 'Current detail body' })
  },
}, async page => {
  await page.click('#review-note-13')
  await page.waitForSelector('.mm-decision-actions')
  assert(await page.isDisabled('.mm-decision-actions .btn-primary'), 'approval became available before the replacement detail arrived')
  releaseNoteDetail()
  await page.waitForSelector('.mm-evidence .mm-doc')
  const text = await page.textContent('.mm-review-card.is-open')
  for (const value of ['Current detail title', 'Current detail summary', 'current-detail-tag', 'Current detail body']) {
    assert(text.includes(value), `detail refresh left stale metadata: missing ${value}`)
  }
  const sent = []
  await page.route('**/api/notes/13/approve', async route => {
    sent.push(route.request().postDataJSON())
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ note: PENDING_NOTE }) })
  })
  await page.click('.mm-decision-actions .btn-primary')
  await page.waitForFunction(() => document.querySelector('#review-note-13') === null)
  assert(sent[0].expected_version === 8, 'approval did not bind the displayed detail version')
})
releaseNoteDetail()

{
  let reads = 0
  const sent = []
  await scenario('a changed proposal preserves amendments on conflict and refreshes only on request', {
    '/api/proposals/501': (route, json) => {
      if (route.request().method() === 'POST') {
        sent.push(route.request().postDataJSON())
        return sent.length === 1 ? json({ error: 'The proposal changed. Review it again.' }, 409)
          : json({ note: null, suggested_tags: {} })
      }
      reads++
      return json(reads === 1 ? proposalDetail(EDIT) : proposalDetail({
        ...EDIT, revision: 4, proposed_title: 'Refreshed proposal title', proposed_body_md: 'Refreshed proposed body',
        proposed_summary: 'Refreshed proposed summary', note: { ...EDIT.note, title: 'Refreshed base title', version: 8 },
      }))
    },
  }, async page => {
    await page.click('#review-proposal-501')
    await page.waitForSelector('.mm-evidence .mm-doc')
    await page.click('.mm-amend-toggle')
    await page.fill('.mm-note-form .mm-amend-title', 'Preserve my amendment')
    await page.click('.mm-decision-actions .btn-primary')
    await page.waitForSelector('.mm-decision-error')
    assert(reads === 1, 'a conflict silently fetched a replacement review')
    assert(await page.inputValue('.mm-note-form .mm-amend-title') === 'Preserve my amendment', 'the conflict discarded the amendment')
    assert(await page.isDisabled('.mm-decision-actions .btn-primary'), 'conflicted approval remained enabled')
    assert(sent[0].expected_revision === 0 && sent[0].expected_version === 5, 'initial approval used an unseen snapshot')
    await page.click('.mm-decision-error button')
    await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]')
    await page.click('.modal.show .modal-footer .btn-outline-secondary')
    await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]', { state: 'hidden' })
    assert(await page.inputValue('.mm-note-form .mm-amend-title') === 'Preserve my amendment', 'cancelling refresh discarded the amendment')
    assert(reads === 1, 'cancelling refresh fetched a replacement review')
    await page.click('.mm-decision-error button')
    await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]')
    await page.click('.modal.show .modal-footer .btn-danger')
    await page.waitForSelector('.mm-evidence .mm-doc')
    const text = await page.textContent('.mm-evidence')
    for (const value of ['Refreshed proposal title', 'Refreshed proposed body', 'Refreshed proposed summary', 'Refreshed base title']) {
      assert(text.includes(value), `refresh mixed old and new review fields: missing ${value}`)
    }
    await page.click('.mm-decision-actions .btn-primary')
    await page.waitForFunction(() => document.querySelector('#review-proposal-501') === null)
    assert(reads === 2, 'approval itself reloaded the review')
    assert(sent[1].expected_revision === 4 && sent[1].expected_version === 8, 'refreshed approval did not use the reviewed replacement snapshot')
    assert(!('title' in sent[1]), 'discarded amendment was silently reapplied to the replacement review')
  })
}

await scenario('conflicted amendments survive cancelled collapse and require discard before switching cards', {
  '/api/notes/13/approve': (route, json) => json({ error: 'The note changed. Review it again.' }, 409),
}, async page => {
  await page.click('#review-note-13')
  await page.waitForSelector('.mm-evidence .mm-doc')
  await page.click('.mm-amend-toggle')
  await page.fill('.mm-note-form .mm-amend-title', 'My protected amendment')
  await page.click('.mm-decision-actions .btn-primary')
  await page.waitForSelector('.mm-decision-error')
  await page.click('#review-note-13')
  await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]', { timeout: 2500 })
  await page.click('.modal.show .modal-footer .btn-outline-secondary')
  await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]', { state: 'hidden' })
  assert(await page.inputValue('.mm-note-form .mm-amend-title') === 'My protected amendment', 'cancelled collapse lost the amendment')
  assert(await page.isDisabled('.mm-decision-actions .btn-primary'), 'cancelled collapse cleared the conflict')
  await page.click('#review-proposal-501')
  await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]')
  await page.click('.modal.show .modal-footer .btn-danger')
  await page.waitForSelector('#review-proposal-501[aria-expanded="true"]')
  await page.click('#review-note-13')
  await page.waitForSelector('#review-note-13[aria-expanded="true"]')
  assert(await page.locator('.mm-note-form').count() === 0, 'explicit discard retained the amendment editor')
  assert(await page.isDisabled('.mm-decision-actions .btn-primary'), 'switching away and back cleared the stale review hold')
  assert(await page.isVisible('.mm-decision-error button'), 'returning to a conflicted row offered no refresh')
})

await scenario('queue refresh uses the shared discard dialog and cancellation keeps the draft', {}, async page => {
  await page.click('#review-note-13')
  await page.waitForSelector('.mm-evidence .mm-doc')
  await page.click('.mm-amend-toggle')
  await page.fill('.mm-note-form .mm-amend-title', 'Unsaved queue-refresh amendment')
  await page.click('.mm-inbox-tools > button')
  await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]')
  await page.click('.modal.show .modal-footer .btn-outline-secondary')
  await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]', { state: 'hidden' })
  assert(await page.inputValue('.mm-note-form .mm-amend-title') === 'Unsaved queue-refresh amendment', 'cancelled queue refresh lost the draft')
  await page.click('.mm-inbox-tools > button')
  await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]')
  await page.click('.modal.show .modal-footer .btn-danger')
  await page.waitForFunction(() => document.querySelector('.mm-review-panel') === null)
  assert(await page.locator('.mm-note-form').count() === 0, 'confirmed queue refresh did not discard the draft')
})

{
  let batches = 0
  await scenario('batch opening asks to discard an unrelated conflicted amendment before its own dialog', {
    '/api/notes/13/approve': (route, json) => json({ error: 'Changed' }, 409),
    '/api/inbox/batch': (route, json) => { batches++; return json({ action: 'approve', done: 1, failed: [] }) },
  }, async page => {
    await page.click('#review-note-13')
    await page.waitForSelector('.mm-evidence .mm-doc')
    await page.click('.mm-amend-toggle')
    await page.fill('.mm-note-form .mm-amend-title', 'Unrelated protected draft')
    await page.click('.mm-decision-actions .btn-primary')
    await page.waitForSelector('.mm-decision-error')
    await page.locator('.mm-review-card').filter({ has: page.locator('#review-proposal-501') }).locator('.mm-review-check').check()
    await page.click('.mm-batch .btn-primary')
    await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]', { timeout: 2500 })
    assert(await page.locator('.mm-batch-dialog').count() === 0, 'batch dialog stacked on discard confirmation')
    await page.click('.modal.show .modal-footer .btn-outline-secondary')
    await page.waitForSelector('.modal.show', { state: 'hidden' })
    assert(await page.inputValue('.mm-note-form .mm-amend-title') === 'Unrelated protected draft', 'batch cancellation discarded the amendment')
    assert(await page.isDisabled('.mm-decision-actions .btn-primary'), 'batch cancellation cleared the conflict')
    assert(batches === 0, 'cancelled discard submitted a batch')
    await page.click('.mm-batch .btn-primary')
    await page.waitForSelector('.modal.show[aria-labelledby="confirm-dialog-title"]')
    await page.click('.modal.show .modal-footer .btn-danger')
    await page.waitForSelector('.mm-batch-dialog.show')
    assert(await page.locator('.modal[aria-labelledby="confirm-dialog-title"]').count() === 0, 'discard dialog remained under batch dialog')
    assert(await page.locator('.mm-note-form').count() === 0, 'confirmed discard retained the amendment')
    await page.click('.mm-batch-dialog .modal-footer .btn:last-child')
    await page.waitForFunction(() => document.querySelector('.mm-batch') === null, null, { timeout: 5000 })
    assert(batches === 1, 'confirmed batch did not submit exactly once')
  })
}

{
  let reads = 0
  const batches = []
  await scenario('failed batch approval holds the open card until explicit review refresh', {
    '/api/proposals/501': (route, json) => {
      reads++
      return json(proposalDetail(reads === 1 ? EDIT : { ...EDIT, revision: 4, proposed_title: 'Latest batch review', note: { ...EDIT.note, version: 8 } }))
    },
    '/api/inbox/batch': (route, json) => {
      batches.push(route.request().postDataJSON())
      return json(batches.length === 1
        ? { action: 'approve', done: 0, failed: [{ kind: 'proposal', id: 501, error: 'Changed' }] }
        : { action: 'approve', done: 1, failed: [] })
    },
  }, async page => {
    await page.click('#review-proposal-501')
    await page.waitForSelector('.mm-evidence .mm-doc')
    await page.locator('.mm-review-card.is-open .mm-review-check').check()
    await page.click('.mm-batch .btn-primary')
    await page.waitForSelector('.mm-batch-dialog.show')
    await page.click('.mm-batch-dialog .modal-footer .btn:last-child')
    await page.waitForSelector('.mm-batch-dialog', { state: 'hidden' })
    assert(await page.isDisabled('.mm-decision-actions .btn-primary'), 'failed batch approval remained enabled')
    assert(await page.isVisible('.mm-decision-error button'), 'failed batch offered no explicit review refresh')
    assert(reads === 1, 'failed batch silently fetched an unseen replacement')
    await page.click('.mm-decision-error button')
    await page.waitForFunction(() => document.querySelector('.mm-evidence')?.textContent.includes('Latest batch review'), null, { timeout: 5000 })
    assert(!await page.isDisabled('.mm-decision-actions .btn-primary'), 'refreshed review remained blocked')
    await page.click('.mm-batch .btn-primary')
    await page.waitForSelector('.mm-batch-dialog.show')
    await page.click('.mm-batch-dialog .modal-footer .btn:last-child')
    await page.waitForFunction(() => document.querySelector('.mm-batch') === null, null, { timeout: 5000 })
    assert(batches.length === 2, 'refreshed batch did not submit')
    assert(batches[1].items[0].expected_revision === 4 && batches[1].items[0].expected_version === 8, 'batch ignored refreshed review snapshot')
  })
}

for (const status of [200, 409]) {
  let historyReads = 0
  const deletions = []
  await scenario(`zero-revision history erasure reports HTTP ${status} accurately`, {
    '/api/notes/13/revisions': (route, json) => {
      if (route.request().method() === 'DELETE') {
        deletions.push(route.request().url())
        return status === 200 ? json({ forgotten: 0 })
          : json({ error: 'Decide the pending proposal before erasing history.' }, status)
      }
      historyReads++
      return json({ revisions: [] })
    },
  }, async page => {
    await page.goto(`${BASE}/${HANDLE}/notes/13`)
    await page.waitForSelector('.mm-note-actions')
    await page.waitForSelector('.mm-forget-history', { timeout: 2000 })
    assert((await page.textContent('.mm-history-count')).includes('0'), 'empty history did not show its zero count')
    let confirmation = ''
    page.once('dialog', async dialog => {
      confirmation = dialog.message()
      await dialog.dismiss()
    })
    await page.click('.mm-forget-history')
    assert(confirmation.includes('0 previous versions'), 'confirmation did not describe the empty revision history')
    assert(confirmation.includes('retained proposal content'), 'confirmation omitted retained proposal content')
    assert(deletions.length === 0, 'dismissing erasure confirmation sent a DELETE')
    const historyAfterErasure = status === 200
      ? page.waitForResponse(response => response.url().endsWith('/api/notes/13/revisions') && response.request().method() === 'GET')
      : null
    page.once('dialog', dialog => dialog.accept())
    await page.click('.mm-forget-history')
    if (status === 200) {
      await page.waitForSelector('.p-toast-message-success')
      assert((await page.textContent('.p-toast-message-success')).includes('History destroyed'), 'successful erasure was not reported')
      await historyAfterErasure
      assert(historyReads === 2, 'successful erasure did not refresh the history')
    } else {
      await page.waitForSelector('.p-toast-message-error')
      assert((await page.textContent('.p-toast-message-error')).includes('Decide the pending proposal'), 'erasure failure did not show the server error')
      assert(await page.locator('.p-toast-message-success').count() === 0, 'a refused erasure was announced as successful')
      assert(historyReads === 1, 'a refused erasure ran the success refresh')
    }
    assert(deletions.length === 1 && deletions[0].endsWith('/api/notes/13/revisions'), 'confirmation did not send exactly one erasure DELETE')
    assert(await page.isVisible('.mm-forget-history'), 'zero revisions hid the erasure control again')
  })
}

await browser.close()
server.close()

if (failures.length) {
  console.error('Review Inbox behaviour check failed:\n')
  for (const failure of failures) console.error(`  ${failure}`)
  process.exit(1)
}

console.log(`Review Inbox behaviour check passed: ${passed.length} properties hold in a browser.`)
