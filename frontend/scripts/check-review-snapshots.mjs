import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import vm from 'node:vm'
import ts from 'typescript'
import { parse, compileScript } from '@vue/compiler-sfc'
import * as Vue from 'vue'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const require = createRequire(import.meta.url)
const clone = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value))
const loadable = { detailLoaded: false, detailError: null, detailRequest: 0 }
const note = {
  id: 43, title: 'Listed title', summary: 'Listed summary', tags: [{ id: 2, name: 'listed' }],
  version: 5, status: 'pending', body_md: 'Listed body', created_at: '2026-09-17',
}
const proposal = {
  id: 91, type: 'edit', revision: 0, note: { id: 43, title: 'Listed title', status: 'verified', version: 5 },
  merge_into: null, proposed_title: 'Listed proposal', proposed_body_md: 'Listed proposed body',
  proposed_summary: null, proposed_tags: null, proposed_patch: null, kinds: ['content'],
  created_at: '2026-09-17',
}

function harness(viewName = 'InboxView', responses = {}) {
  const calls = []
  const cache = new Map()
  const stores = { refresh: async () => {}, user: { team: { handle: 'synthetic' } } }
  const router = { currentRoute: Vue.ref({ name: 'inbox' }), push() {} }
  const context = vm.createContext({
    console, URL, URLSearchParams, FormData, Blob, Error,
    document: { getElementById: () => null }, window: { confirm: () => true },
    fetch: async (url, options = {}) => {
      const call = { url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : undefined }
      calls.push(call)
      const reply = responses[url] ?? { status: 200, data: {} }
      const resolved = typeof reply === 'function' ? await reply(call) : reply
      return { status: resolved.status ?? 200, ok: (resolved.status ?? 200) < 400, json: async () => clone(resolved.data) }
    },
  })
  function moduleFor(filename, source) {
    if (cache.has(filename)) return cache.get(filename)
    const module = { exports: {} }
    const localRequire = specifier => {
      if (specifier === 'vue') return { ...Vue, onMounted() {}, onBeforeUnmount() {}, watch() {} }
      if (specifier === 'vue-i18n') return { useI18n: () => ({ t: key => key, locale: Vue.ref('en') }) }
      if (specifier === '@/i18n') return { i18n: { global: { t: key => key } } }
      if (specifier === 'vue-router') return { useRoute: () => ({ params: { id: 43 } }), useRouter: () => router }
      if (specifier === '@/router') return { default: router, __esModule: true }
      if (specifier.endsWith('.vue')) return {}
      if (specifier.includes('/stores/')) return new Proxy({}, { get: () => () => stores })
      if (specifier === '@/components/toastService') return { toastSuccess() {}, toastError() {} }
      if (specifier === '@/lib/systemTags') return { useSystemTags: () => ({ isSystemTag: () => false, systemReason: () => '' }) }
      if (specifier === '@/lib/markdown') return { renderNote: value => value, renderReason: value => value }
      if (specifier === '@/lib/datetime') return { formatDate: value => value, formatDateTime: value => value }
      if (specifier.startsWith('@/')) return moduleFor(join(root, 'src', `${specifier.slice(2)}.ts`))
      return require(specifier)
    }
    const js = ts.transpileModule(source ?? readFileSync(filename, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
    }).outputText
    vm.runInContext(`(function(require,module,exports){${js}\n})`, context, { filename })(localRequire, module, module.exports)
    cache.set(filename, module.exports)
    return module.exports
  }
  const filename = join(root, 'src/views', `${viewName}.vue`)
  const { descriptor } = parse(readFileSync(filename, 'utf8'))
  const compiled = compileScript(descriptor, { id: viewName })
  const component = moduleFor(filename, compiled.content).default
  const state = component.setup({}, { expose() {} })
  return { state, calls, responses }
}

function pending(h) {
  const row = { ...clone(note), ...loadable, body: note.body_md, detailVersion: note.version, detailLoaded: true }
  h.state.items.value = [row]
  return { key: 'note:43', kind: 'note', at: note.created_at, note: h.state.items.value[0] }
}

function proposed(h, patch = {}) {
  h.state.proposals.value = [{ ...clone(proposal), ...loadable, detailLoaded: true, ...patch }]
  return { key: 'proposal:91', kind: 'proposal', at: proposal.created_at, proposal: h.state.proposals.value[0] }
}

test('plain inbox approval sends the displayed note version through the real API serializer', async () => {
  const h = harness()
  await h.state.decide(pending(h), true)
  assert.deepEqual(clone(h.calls[0]), { url: '/api/notes/43/approve', method: 'POST', body: { expected_version: 5 } })
})

test('summary-only amendment includes an explicit empty summary and the displayed version', async () => {
  const h = harness()
  const entry = pending(h)
  await h.state.openForEditing(h.state.noteSeed(entry.note))
  h.state.amendedSummary.value = ''
  await h.state.decide(entry, true)
  const payload = h.calls.find(call => call.url.endsWith('/approve')).body
  assert.equal(payload.summary, '')
  assert.equal(payload.expected_version, 5)
  assert.equal(payload.body_md, 'Listed body')
})

test('title-only and body-only amendments omit an unchanged assistant summary', async () => {
  for (const field of ['amendedTitle', 'amendedBody']) {
    const h = harness()
    const entry = pending(h)
    entry.note.summary_by = 'Claude'
    await h.state.openForEditing(h.state.noteSeed(entry.note))
    h.state[field].value = 'Operator changed only this field'
    await h.state.decide(entry, true)
    const payload = h.calls.find(call => call.url.endsWith('/approve')).body
    assert.equal(Object.hasOwn(payload, 'summary'), false, 'unchanged summary must not claim new operator attribution')
    assert.equal(payload.expected_version, 5)
  }
})

test('proposal and merge approvals bind the displayed proposal revision and both base versions', async () => {
  for (const merge of [false, true]) {
    const h = harness()
    const entry = proposed(h, merge ? { type: 'merge', merge_into: { id: 71, title: 'Keeper', version: 12 } } : {})
    await h.state.decide(entry, true)
    assert.deepEqual(clone(h.calls[0].body), {
      expected_revision: 0, expected_version: 5, ...(merge ? { expected_merge_version: 12 } : {}),
    })
  }
})

test('missing note or merge versions prevent approval instead of fetching unseen versions', async () => {
  for (const kind of ['note', 'merge']) {
    const h = harness()
    const entry = kind === 'note' ? pending(h) : proposed(h, { type: 'merge', merge_into: { id: 71, title: 'Keeper' } })
    if (kind === 'note') { delete entry.note.version; delete entry.note.detailVersion }
    await h.state.decide(entry, true)
    assert.deepEqual(h.calls, [])
    assert.ok(h.state.decisionError.value)
  }
})

test('note detail refresh replaces all displayed metadata and body alongside its version', async () => {
  const latest = { ...note, title: 'Detail title', summary: 'Detail summary', tags: [{ id: 9, name: 'detail' }], body_md: 'Detail body', version: 8 }
  const h = harness('InboxView', { '/api/notes/43': { data: latest } })
  const row = { ...clone(note), ...loadable }
  await h.state.loadNote(row)
  assert.deepEqual({ title: row.title, summary: row.summary, tags: clone(row.tags), body: row.body, version: row.version },
    { title: latest.title, summary: latest.summary, tags: latest.tags, body: latest.body_md, version: 8 })
})

test('proposal detail replaces proposed fields and note identity metadata as a coherent snapshot', async () => {
  const latest = { ...proposal, revision: 3, proposed_title: 'Detail proposal', proposed_summary: '', proposed_tags: ['detail'], proposed_patch: [{ find: 'old', replace: 'new' }], proposed_body_md: null,
    note: { ...proposal.note, title: 'Detail base', version: 8, body_md: 'old', summary: 'Base summary', tags: ['base'] } }
  const h = harness('InboxView', { '/api/proposals/91': { data: latest } })
  const row = { ...clone(proposal), ...loadable }
  await h.state.loadProposal(row)
  for (const field of ['revision', 'proposed_title', 'proposed_summary', 'proposed_tags', 'proposed_patch', 'proposed_body_md', 'note']) {
    assert.deepEqual(clone(row[field]), latest[field], field)
  }
  assert.equal(row.currentBody, 'old')
  assert.equal(row.currentSummary, 'Base summary')
})

test('batch approval sends each displayed snapshot without fetching any detail first', async () => {
  const h = harness('InboxView', { '/api/inbox/batch': { data: { done: 0, failed: [{ kind: 'note', id: 43, error: 'conflict' }, { kind: 'proposal', id: 91, error: 'conflict' }] } } })
  pending(h)
  proposed(h, { type: 'merge', merge_into: { id: 71, title: 'Keeper', version: 12 } })
  h.state.selected.value = new Set(['note:43', 'proposal:91'])
  h.state.batchAction.value = 'approve'
  await h.state.runBatch()
  assert.deepEqual(clone(h.calls[0].body.items), [
    { kind: 'note', id: 43, expected_version: 5 },
    { kind: 'proposal', id: 91, expected_version: 5, expected_revision: 0, expected_merge_version: 12 },
  ])
  assert.equal(h.calls.length, 1, 'failed reviews must not be silently replaced with newer rows')
})

test('batch refuses a missing version locally, while rejection needs no snapshot', async () => {
  for (const action of ['approve', 'reject']) {
    const h = harness('InboxView', { '/api/inbox/batch': { data: { done: 0, failed: [{ kind: 'note', id: 43, error: 'synthetic refusal' }] } } })
    const entry = pending(h)
    delete entry.note.version
    h.state.selected.value = new Set(['note:43'])
    h.state.batchAction.value = action
    await h.state.runBatch()
    if (action === 'approve') {
      assert.deepEqual(h.calls, [])
      assert.equal(h.state.batchReport.value.failed.length, 1)
    } else {
      assert.deepEqual(clone(h.calls[0].body), { action: 'reject', items: [{ kind: 'note', id: 43 }] })
    }
  }
})

test('409 preserves the amendment and blocks resubmission until explicit refresh', async () => {
  const h = harness('InboxView', { '/api/notes/43/approve': { status: 409, data: { error: 'Changed' } } })
  const entry = pending(h)
  await h.state.openForEditing(h.state.noteSeed(entry.note))
  h.state.amendedSummary.value = 'Keep my amendment'
  await h.state.decide(entry, true)
  const count = h.calls.length
  assert.equal(h.state.amendedSummary.value, 'Keep my amendment')
  assert.equal(h.state.items.value.length, 1)
  await h.state.decide(entry, true)
  assert.equal(h.calls.length, count, 'a conflicted review needs explicit refresh before another approval')
})

test('NoteView approval sends the displayed version and does not reload on conflict', async () => {
  const h = harness('NoteView', { '/api/notes/43/approve': { status: 409, data: { error: 'Changed' } } })
  h.state.note.value = clone(note)
  await h.state.approve()
  assert.deepEqual(clone(h.calls), [{ url: '/api/notes/43/approve', method: 'POST', body: { expected_version: 5 } }])
  await h.state.approve()
  assert.equal(h.calls.length, 1)
})

test('collapse and switch confirm draft discard while conflict survives returning to the row', async () => {
  const h = harness('InboxView', { '/api/notes/43/approve': { status: 409, data: { error: 'Changed' } } })
  const entry = pending(h)
  h.state.openKey.value = entry.key
  await h.state.openForEditing(h.state.noteSeed(entry.note))
  h.state.amendedTitle.value = 'Keep this draft'
  await h.state.decide(entry, true)
  const collapse = h.state.toggleCard(entry)
  assert.equal(h.state.discardDialogOpen?.value, true)
  h.state.finishDiscard(false)
  await collapse
  assert.equal(h.state.openKey.value, entry.key)
  assert.equal(h.state.amendedTitle.value, 'Keep this draft')
  const other = proposed(h)
  const switchCard = h.state.toggleCard(other)
  assert.equal(h.state.discardDialogOpen.value, true)
  h.state.finishDiscard(true)
  await switchCard
  await h.state.toggleCard(entry)
  assert.equal(h.state.approvable(entry), false, 'discarding a draft must not clear its review conflict')
  const calls = h.calls.length
  await h.state.decide(entry, true)
  assert.equal(h.calls.length, calls)
})

test('review refresh uses cancellable shared confirmation before clearing a draft', async () => {
  const h = harness()
  const entry = pending(h)
  await h.state.openForEditing(h.state.noteSeed(entry.note))
  h.state.amendedSummary.value = 'Keep this summary'
  const calls = h.calls.length
  const refreshing = h.state.refreshReview(entry)
  assert.equal(h.state.discardDialogOpen?.value, true)
  h.state.finishDiscard(false)
  await refreshing
  assert.equal(h.state.amendedSummary.value, 'Keep this summary')
  assert.equal(h.calls.length, calls)
})


test('batch opening requires draft discard consent before setting the batch action', async () => {
  const h = harness()
  const entry = pending(h)
  await h.state.openForEditing(h.state.noteSeed(entry.note))
  h.state.amendedTitle.value = 'Keep the unrelated draft'
  const calls = h.calls.length
  const cancelled = h.state.askBatch('approve', { currentTarget: null })
  assert.equal(h.state.discardDialogOpen.value, true)
  assert.equal(h.state.batchAction.value, null)
  h.state.finishDiscard(false)
  await cancelled
  assert.equal(h.state.batchAction.value, null)
  assert.equal(h.state.amendedTitle.value, 'Keep the unrelated draft')
  const confirmed = h.state.askBatch('approve', { currentTarget: null })
  h.state.finishDiscard(true)
  await confirmed
  assert.equal(h.state.discardDialogOpen.value, false)
  assert.equal(h.state.batchAction.value, 'approve')
  assert.equal(h.state.amendOpen.value, false)
  assert.equal(h.calls.length, calls)
})

test('failed batch approvals require explicit refresh while rejection failures do not hold approval', async () => {
  for (const action of ['approve', 'reject']) {
    const h = harness('InboxView', { '/api/inbox/batch': { data: { done: 0, failed: [{ kind: 'note', id: 43, error: 'Changed' }] } } })
    const entry = pending(h)
    h.state.selected.value = new Set([entry.key])
    h.state.batchAction.value = action
    await h.state.runBatch()
    assert.equal(h.state.approvable(entry), action === 'reject')
    if (action === 'approve') {
      const count = h.calls.length
      await h.state.decide(entry, true)
      assert.equal(h.calls.length, count, 'failed batch approval was resubmitted without review')
    }
  }
})
