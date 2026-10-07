'use strict'

const { test, before, after, beforeEach, describe } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const helper = require('./helper.js')

let app
let restoreFetch
const originalCwd = process.cwd()

before(async () => {
  restoreFetch = helper.installFetchStub()
  helper.useThrowawayConfig()
  app = await helper.startApp()
})

after(async () => {
  if (app) await app.stop()
  if (restoreFetch) restoreFetch()
  process.chdir(originalCwd)
})

beforeEach(() => helper.reset())

const tagsAnswer = { status: 200, body: { content: [{ type: 'text', text: '{"tags":["work"],"new_tags":[]}' }] } }

const suggestTags = (provider, model) => app.post('/api/v1/suggest-tags', {
  title: 'A note',
  content: 'Something worth tagging.',
  tags: [{ id: 1, name: 'work' }],
  provider: provider,
  api_key: 'sk-the-callers-own-key',
  model: model,
})

describe('temperature, sent only to a model that takes it', () => {
  // The account that picked Sonnet 5.5 on its own key got no tags at all:
  // Anthropic refused every request for carrying temperature 0.
  for (const model of ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-1', 'claude-sonnet-5', 'claude-opus-4-8', 'claude-opus-4-7']) {
    test(`${model} gets the provider's default`, async () => {
      helper.responses['/v1/messages'] = tagsAnswer

      const res = await suggestTags('anthropic', model)

      assert.equal(res.status, 201)
      assert.deepEqual(res.json.tag_ids, [1])
      assert.equal(helper.lastCall('/v1/messages').body.temperature, undefined)
    })
  }

  for (const model of ['claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'claude-opus-4-1-20250805', 'claude-sonnet-4-20250514', 'claude-3-5-haiku-20241022']) {
    test(`${model} keeps temperature 0`, async () => {
      helper.responses['/v1/messages'] = tagsAnswer

      await suggestTags('anthropic', model)

      assert.equal(helper.lastCall('/v1/messages').body.temperature, 0)
    })
  }

  for (const model of ['o4-mini', 'o3', 'gpt-5-mini']) {
    test(`OpenAI's ${model} gets neither temperature nor max_tokens`, async () => {
      await app.post('/api/v1/summarize', { content: 'A note.', api_key: 'sk-the-callers-own-key', model: model })

      const body = helper.lastCall('/chat/completions').body
      assert.equal(body.model, model)
      assert.equal(body.temperature, undefined)
      assert.equal(body.max_tokens, undefined)
    })
  }

  test("the box's own gpt-4o-mini keeps temperature 0 and the cap", async () => {
    await app.post('/api/v1/summarize', { content: 'A note.' })

    const body = helper.lastCall('/chat/completions').body
    assert.equal(body.model, 'gpt-4o-mini')
    assert.equal(body.temperature, 0)
    assert.equal(body.max_tokens, 4096)
  })

  test('Gemini keeps temperature 0', async () => {
    await app.post('/api/v1/summarize', { content: 'A note.', provider: 'google', api_key: 'sk-the-callers-own-key', model: 'gemini-2.5-flash' })

    assert.equal(helper.lastCall(':generateContent').body.generationConfig.temperature, 0)
  })
})

// 502 when the provider failed, 500 when this service did: the backend alerts
// the operator about a refused key only when the key is his.
describe('whose failure it was', () => {
  test('a provider refusing the request is a 502', async () => {
    helper.responses['/v1/messages'] = { status: 400, body: { type: 'error', error: { type: 'invalid_request_error', message: 'refused' } } }

    const res = await suggestTags('anthropic', 'claude-sonnet-5-5')

    assert.equal(res.status, 502)
    assert.equal(res.json.error, 'anthropic returned 400')
    assert.ok(!res.text.includes('sk-the-callers-own-key'))
    assert.equal(helper.callCount('/v1/messages'), 1)
  })

  test('a provider that cannot be reached is a 502', async () => {
    helper.responses['/chat/completions'] = () => { throw new TypeError('fetch failed') }

    const res = await app.post('/api/v1/summarize', { content: 'A note.' })

    assert.equal(res.status, 502)
  })

  test('an answer that is not the JSON asked for is a 502', async () => {
    helper.responses['/v1/messages'] = { status: 200, body: { content: [{ type: 'text', text: 'Not JSON.' }] } }

    const res = await suggestTags('anthropic', 'claude-sonnet-5-5')

    assert.equal(res.status, 502)
  })

  test('this service failing before any provider call is a 500', async () => {
    const prompt = './config/openapi_prompt_summarize.txt'
    const kept = fs.readFileSync(prompt, 'utf8')
    fs.rmSync(prompt)
    try {
      const res = await app.post('/api/v1/summarize', { content: 'A note.' })

      assert.equal(res.status, 500)
      assert.equal(helper.callCount('/chat/completions'), 0)
    } finally {
      fs.writeFileSync(prompt, kept)
    }
  })
})
