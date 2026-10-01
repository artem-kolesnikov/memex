'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')

const { test, before, after } = require('node:test')
const assert = require('node:assert')
const { installFetchStub, useThrowawayConfig, startApp, callCount } = require('./helper')

let restoreFetch
let app

before(async () => {
  restoreFetch = installFetchStub()
  useThrowawayConfig()
  process.env.LOCAL_EMBEDDING_MODEL_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'no-model-'))
  app = await startApp()
})

after(async () => {
  await app.stop()
  restoreFetch()
})

test('without the model files a local embedding answers 503 and says how to install them', async () => {
  const answer = await app.post('/api/v1/create-embeddings', { model: 'nomic-embed-text-v1.5', content: 'Something to embed.' })

  assert.strictEqual(answer.status, 503)
  assert.match(answer.json.error, /npm run fetch-model/)
  assert.strictEqual(answer.json.usage, null)
  assert.strictEqual(callCount('/v1/embeddings'), 0, 'a missing local model fell through to OpenAI')
})
