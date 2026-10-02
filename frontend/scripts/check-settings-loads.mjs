import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import ts from 'typescript'

// A component's script runs here with its imports removed. The names the
// assertions depend on are defined below; any other name it reads, such as a
// store or helper imported later, is a stand-in that accepts every call and
// property, so adding an import to a component cannot break this check.
const standIn = new Proxy(function () {}, {
  get: (_, key) => (key === Symbol.toPrimitive ? () => '' : typeof key === 'symbol' || key === 'then' ? undefined : standIn),
  apply: () => standIn,
  construct: () => standIn,
})

function sandbox(defined) {
  return vm.createContext(new Proxy(defined, {
    has: () => true,
    get: (target, key) => (key in target ? target[key] : key in globalThis ? globalThis[key] : standIn),
  }))
}

function mountScript(file, probe) {
  const source = readFileSync(new URL(`../src/components/settings/${file}`, import.meta.url), 'utf8').match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1]
  const ast = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)
  const imports = ast.statements.filter(ts.isImportDeclaration)
  let body = source
  for (const node of imports.reverse()) body = body.slice(0, node.pos) + body.slice(node.end)
  const pending = { tokens: [], wiring: [] }
  const deferred = key => new Promise((resolve, reject) => pending[key].push({ resolve, reject }))
  const notices = []
  const context = sandbox({
    exports: {},
    ref: value => ({ value }), computed: get => ({ get value() { return get() } }),
    watch() {}, onMounted() {}, defineProps: () => ({}), defineEmits: () => () => {},
    useI18n: () => ({ t: key => key }),
    api: { tokens: () => deferred('tokens'), curationWiring: () => deferred('wiring') },
    toastError: (...args) => notices.push(args), toastSuccess() {},
  })
  vm.runInContext(ts.transpileModule(body, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText + `\nthis.probe = { ${probe} };`, context)
  return { ...context.probe, pending, notices }
}

for (const staleFails of [false, true]) {
  const pane = mountScript('CurationAutomationPanel.vue', 'loadWiring, loadCandidates, connections, candidates, wiringFailed')
  const older = [pane.loadWiring(), pane.loadCandidates()]
  const newer = [pane.loadWiring(), pane.loadCandidates()]
  const currentCurator = { id: 9, name: 'Current curator' }
  const currentCandidate = { id: 10, role: 'agent', revoked: false }
  pane.pending.wiring[1].resolve({ connections: [currentCurator], other_count: 1 })
  pane.pending.tokens[1].resolve({ tokens: [currentCandidate] })
  await Promise.all(newer)
  assert.equal(pane.connections.value[0].id, 9)
  assert.equal(pane.candidates.value[0].id, 10)
  if (staleFails) {
    pane.pending.wiring[0].reject(new Error('Old request failed'))
    pane.pending.tokens[0].reject(new Error('Old request failed'))
  } else {
    pane.pending.wiring[0].resolve({ connections: [{ id: 7, name: 'Revoked curator' }], other_count: 1 })
    pane.pending.tokens[0].resolve({ tokens: [{ id: 8, role: 'agent', revoked: false }] })
  }
  await Promise.all(older)
  assert.equal(pane.connections.value[0]?.id, 9, 'Old wiring response replaced current curator list')
  assert.equal(pane.candidates.value[0]?.id, 10, 'Old token response replaced current candidate list')
  assert.equal(pane.wiringFailed.value, false, 'Old failure hid the current curator list')

  const parent = mountScript('ConnectionsPane.vue', 'load, tokens, loadFailed')
  const first = parent.load()
  const last = parent.load()
  parent.pending.tokens[1].resolve({ tokens: [{ id: 9, role: 'curator' }], icons: [], logos: [] })
  await last
  if (staleFails) parent.pending.tokens[0].reject(new Error('Old request failed'))
  else parent.pending.tokens[0].resolve({ tokens: [{ id: 7, role: 'agent' }], icons: [], logos: [] })
  await first
  assert.equal(parent.tokens.value[0].id, 9, 'Old parent response restored obsolete connection state')
  assert.equal(parent.loadFailed.value, false, 'Old parent failure hid the current list')
  assert.equal(parent.notices.length, 0, 'Old parent failure showed an obsolete error')
}
console.log('Settings loads: newer connection state survives old successes and failures in all three loaders.')
