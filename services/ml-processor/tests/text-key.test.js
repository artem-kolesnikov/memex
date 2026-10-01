'use strict'

const { test, before, after } = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const path = require('path')

const { BOX_KEY, reset, installFetchStub, useThrowawayConfig, startApp, lastCall } = require('./helper')

// TWO OpenAI keys, and the split is about the hard spend limit rather than
// about permissions. Embeddings run on the operator's account for every
// knowledge base and cannot be switched off without making notes unfindable;
// text runs on it only for a team he sponsors. One key means a sponsored
// account's summaries can exhaust the limit every team's search depends on, and
// that limit is set at OpenAI, per key.
//
// Its own file because the key is read once and cached, so a test that writes
// the file after the first text call would be measuring the cache.
const TEXT_KEY = 'sk-test-text-key-not-real'

let restoreFetch
let app

before(async () => {
  restoreFetch = installFetchStub()
  const dir = useThrowawayConfig()
  fs.writeFileSync(path.join(dir, 'config', 'openapi_apikey_text.txt'), TEXT_KEY)
  app = await startApp()
  reset()
})

after(async () => {
  await app.stop()
  restoreFetch()
})

const bearer = call => String(call.headers.authorization || '').replace(/^Bearer /, '')

test('text goes out on the text key and embeddings stay on the other one', async () => {
  await app.post('/api/v1/summarize', { content: 'Something to summarise.' })
  await app.post('/api/v1/create-embeddings', { content: 'Something to embed.' })

  assert.strictEqual(bearer(lastCall('/chat/completions')), TEXT_KEY)
  assert.strictEqual(bearer(lastCall('/v1/embeddings')), BOX_KEY, 'the two budgets were merged again')
})

// A caller that named a provider it sent no key for made a request this service
// refuses, not a request that broke it.
test('a provider named without its key is a 400, not a 500', async () => {
  const answer = await app.post('/api/v1/summarize', { content: 'A note.', provider: 'anthropic' })

  assert.strictEqual(answer.status, 400)
  assert.match(answer.json.error, /Anthropic/)
})
