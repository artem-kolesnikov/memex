'use strict'

const { test, before, after, beforeEach } = require('node:test')
const assert = require('node:assert')
const { BOX_KEY, responses, reset, installFetchStub, useThrowawayConfig, startApp, lastCall } = require('./helper')

let restoreFetch
let app

before(async () => {
  restoreFetch = installFetchStub()
  useThrowawayConfig()
  app = await startApp()
})

after(async () => {
  await app.stop()
  restoreFetch()
})

beforeEach(reset)

const bearer = call => String(call.headers.authorization || '').replace(/^Bearer /, '')

// A MODEL WITHOUT A CALLER KEY IS NOT THE CALLER'S TO CHOOSE. With no key in
// the request the account is the operator's, so honouring the override would be
// his money buying a model he never picked, at whatever that model costs.
test('a model sent without a key does not reach the operator\'s account', async () => {
  const answer = await app.post('/api/v1/summarize', {
    content: 'Something to summarise.',
    model: 'gpt-4o-with-an-expensive-price',
  })

  assert.strictEqual(answer.status, 201)
  const call = lastCall('/chat/completions')
  assert.strictEqual(bearer(call), BOX_KEY, 'the box key did not pay')
  assert.strictEqual(call.body.model, 'gpt-4o-mini', 'a caller with no key chose the model the operator paid for')
})

// The backend sends the model the operator gave the account's tier in control,
// with no key. Dropping it ran the box's file for every tier whatever control
// said.
test('a tier\'s model sent without a key is the one the box buys', async () => {
  for (const model of ['gpt-4.1-nano', 'gpt-4.1-mini']) {
    const answer = await app.post('/api/v1/summarize', { content: 'Something to summarise.', model: model })

    assert.strictEqual(answer.status, 201)
    const call = lastCall('/chat/completions')
    assert.strictEqual(bearer(call), BOX_KEY)
    assert.strictEqual(call.body.model, model)
  }
})

test('a model sent WITH a key is the caller\'s to choose', async () => {
  const answer = await app.post('/api/v1/summarize', {
    content: 'Something to summarise.',
    api_key: 'sk-the-callers-own-key',
    model: 'gpt-4o-with-an-expensive-price',
  })

  assert.strictEqual(answer.status, 201)
  const call = lastCall('/chat/completions')
  assert.strictEqual(bearer(call), 'sk-the-callers-own-key')
  assert.strictEqual(call.body.model, 'gpt-4o-with-an-expensive-price')
})

// Embeddings take a caller key too, and the OpenAI model is pinned whoever
// pays: a vault's vectors are one model's, so a second model is a second
// vector space.
test('an embedding on the caller\'s key keeps the pinned model and reports the payer', async () => {
  const answer = await app.post('/api/v1/create-embeddings', {
    content: 'Something to embed.',
    api_key: 'sk-the-callers-own-key',
    model: 'text-embedding-3-large',
  })

  assert.strictEqual(answer.status, 201)
  const call = lastCall('/v1/embeddings')
  assert.strictEqual(bearer(call), 'sk-the-callers-own-key')
  assert.strictEqual(call.body.model, 'text-embedding-3-large')
  assert.strictEqual(call.body.dimensions, 1536)
  assert.strictEqual(answer.json.usage.key, 'caller')
})

test('an embedding with no caller key is reported against the box', async () => {
  const answer = await app.post('/api/v1/create-embeddings', { content: 'Something to embed.' })

  assert.strictEqual(answer.status, 201)
  assert.strictEqual(bearer(lastCall('/v1/embeddings')), BOX_KEY)
  assert.strictEqual(answer.json.usage.key, 'box')
})
