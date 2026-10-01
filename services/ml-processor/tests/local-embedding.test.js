'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const Module = require('module')

const fakes = {
  'onnxruntime-node': path.join(__dirname, 'fakes', 'onnxruntime-node.js'),
  '@huggingface/tokenizers': path.join(__dirname, 'fakes', 'tokenizers.js'),
}
const resolve = Module._resolveFilename
Module._resolveFilename = function (request, ...rest) {
  return fakes[request] || resolve.call(this, request, ...rest)
}

const { test, before, after, beforeEach } = require('node:test')
const assert = require('node:assert')
const { reset, installFetchStub, useThrowawayConfig, startApp, callCount } = require('./helper')
const { encoded } = require('./fakes/tokenizers')
const localEmbedding = require('../src/localEmbedding')

const MODEL = 'nomic-embed-text-v1.5'

let restoreFetch
let app

before(async () => {
  restoreFetch = installFetchStub()
  useThrowawayConfig()
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-model-'))
  fs.mkdirSync(path.join(dir, 'onnx'))
  fs.writeFileSync(path.join(dir, 'tokenizer.json'), '{}')
  fs.writeFileSync(path.join(dir, 'tokenizer_config.json'), '{}')
  fs.writeFileSync(path.join(dir, 'onnx', 'model_int8.onnx'), '')
  process.env.LOCAL_EMBEDDING_MODEL_DIR = dir
  app = await startApp()
})

after(async () => {
  await app.stop()
  restoreFetch()
})

beforeEach(() => {
  reset()
  encoded.length = 0
})

const norm = vector => Math.sqrt(vector.reduce((sum, v) => sum + v * v, 0))

test('a local embedding never reaches OpenAI and is reported as costing nothing', async () => {
  const answer = await app.post('/api/v1/create-embeddings', { model: MODEL, contents: ['first', 'second'], purpose: 'document' })

  assert.strictEqual(answer.status, 201)
  assert.strictEqual(callCount('/v1/embeddings'), 0)
  assert.strictEqual(answer.json.usage, null)
  assert.strictEqual(answer.json.embeddings.length, 2)
  for (const vector of answer.json.embeddings) {
    assert.strictEqual(vector.length, localEmbedding.DIMENSIONS)
    assert.ok(Math.abs(norm(vector) - 1) < 1e-6)
  }
  assert.deepStrictEqual(encoded, ['search_document: first', 'search_document: second'])
})

test('a search query is embedded as a query, and answered as one vector', async () => {
  const answer = await app.post('/api/v1/create-embeddings', { model: MODEL, content: 'find the <b>thing</b>', purpose: 'query' })

  assert.strictEqual(answer.status, 201)
  assert.strictEqual(answer.json.embeddings.length, localEmbedding.DIMENSIONS)
  assert.deepStrictEqual(encoded, ['search_query: find the thing'])
})

test('a note of many chunks comes back one vector per chunk, in order', async () => {
  const texts = Array.from({ length: 11 }, (_, i) => `chunk ${i} ${'x'.repeat(i * 7)}`)
  const batch = await app.post('/api/v1/create-embeddings', { model: MODEL, contents: texts })
  const last = await app.post('/api/v1/create-embeddings', { model: MODEL, content: texts[10] })

  assert.strictEqual(batch.status, 201)
  assert.strictEqual(batch.json.embeddings.length, 11)
  assert.deepStrictEqual(batch.json.embeddings[10], last.json.embeddings)
  assert.notDeepStrictEqual(batch.json.embeddings[0], batch.json.embeddings[10])
})

test('a text past the trained length is cut there and keeps its end marker', () => {
  const ids = [101, ...Array(3000).fill(7), 102]
  const cut = localEmbedding.truncate(ids)

  assert.strictEqual(cut.length, localEmbedding.MAX_TOKENS)
  assert.strictEqual(cut[0], 101)
  assert.strictEqual(cut[cut.length - 1], 102)
})
