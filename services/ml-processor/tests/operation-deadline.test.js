'use strict'
const { test, before, after } = require('node:test')
const assert = require('node:assert/strict')
const http = require('node:http')
const helper = require('./helper.js')
let app
const realFetch = globalThis.fetch
const originalCwd = process.cwd()
before(async () => { helper.useThrowawayConfig(); app = await helper.startApp() })
after(async () => {
  // A guard-removal run deliberately leaves old retry work alive. Never restore
  // native fetch while that work can run; this test file has its own process.
  globalThis.fetch = async () => new Response('{}', { status: 400 })
  await app.stop()
  process.chdir(originalCwd)
})
const reply = () => new Response(JSON.stringify({ data: [{ embedding: Array(1536).fill(0.5) }] }), { status: 200 })

test('expired work makes no provider call', async () => {
  let calls = 0
  globalThis.fetch = async () => { calls++; return reply() }
  const response = await realFetch(app.base+'/api/v1/create-embeddings', { method: 'POST', headers: { 'content-type': 'application/json', 'x-memex-deadline': String(Date.now()-1) }, body: '{"content":"synthetic"}' })
  await response.text()
  assert.equal(response.status, 408)
  assert.equal(calls, 0)
})

test('end-to-end deadline cancels an in-flight provider request', async () => {
  let aborted = false
  let calls = 0
  globalThis.fetch = (_url, { signal }) => new Promise((resolve, reject) => {
    calls++
    const timer = setTimeout(() => resolve(reply()), 500)
    signal.addEventListener('abort', () => { aborted = true; clearTimeout(timer); reject(signal.reason) }, { once: true })
  })
  const response = await realFetch(app.base+'/api/v1/create-embeddings', { method: 'POST', headers: { 'content-type': 'application/json', 'x-memex-deadline': String(Date.now()+80) }, body: '{"content":"synthetic"}' })
  await response.text()
  assert.equal(aborted, true)
  assert.equal(calls, 1)
  assert.notEqual(response.status, 201)
})

test('a disconnected caller cannot buy a retry during backoff', async () => {
  let calls = 0
  let reached
  const first = new Promise(resolve => { reached = resolve })
  globalThis.fetch = async () => { calls++; reached(); return new Response('{}', { status: 503 }) }
  const req = http.request(app.base+'/api/v1/create-embeddings', { method: 'POST', headers: { 'content-type': 'application/json' } })
  req.on('error', () => {})
  req.end('{"content":"synthetic"}')
  await first
  req.destroy()
  await new Promise(resolve => setTimeout(resolve, 1250))
  assert.equal(calls, 1)
})
