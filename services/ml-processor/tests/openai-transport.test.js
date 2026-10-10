// The service's first tests (AUDIT2 M-20's open half, 2026-08-25).
//
// Written alongside the removal of `openai@3.3.0`, and aimed at what that
// removal could plausibly break rather than at coverage for its own sake. The
// SDK wrapped every response in an axios envelope, so the port had to strip one
// `.data` from two places — a mistake that would not have failed loudly, it
// would have 500'd with a TypeError in the journal and looked like the provider
// misbehaving.
//
// The other properties here are the ones CLAUDE.md §Spend calls decisions
// rather than implementation: whose key pays, and what bounds the spend.
'use strict'

const { test, before, after, beforeEach, describe } = require('node:test')
const assert = require('node:assert/strict')
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

describe('the response envelope the SDK used to add', () => {
  // THE regression the port could introduce. `postJson` returns the parsed body;
  // the SDK returned {data: body}. Read one level too deep and this 500s.
  test('an embedding comes back from data[0], not data.data[0]', async () => {
    const vector = Array.from({ length: 1536 }, (_, i) => i / 1536)
    helper.responses['/v1/embeddings'] = { status: 200, body: { data: [{ embedding: vector }] } }

    const res = await app.post('/api/v1/create-embeddings', { content: 'A note.' })

    assert.equal(res.status, 201)
    assert.equal(res.json.embeddings.length, 1536)
    assert.equal(res.json.embeddings[0], 0)
    assert.equal(res.json.embeddings[1], vector[1])
  })

  // The chunks of one note travel as `contents` and come back as one vector
  // each, in the order sent — OpenAI's `index` decides, not array position.
  test('a batch of contents answers with a vector per text, in order', async () => {
    const a = Array.from({ length: 1536 }, () => 0.1)
    const b = Array.from({ length: 1536 }, () => 0.2)
    helper.responses['/v1/embeddings'] = { status: 200, body: { data: [{ index: 1, embedding: b }, { index: 0, embedding: a }] } }

    const res = await app.post('/api/v1/create-embeddings', { contents: ['First chunk.', 'Second chunk.'] })

    assert.equal(res.status, 201)
    assert.equal(res.json.embeddings.length, 2)
    assert.equal(res.json.embeddings[0][0], 0.1)
    assert.equal(res.json.embeddings[1][0], 0.2)
    assert.deepEqual(helper.lastCall('/v1/embeddings').body.input, ['First chunk.', 'Second chunk.'])
  })

  test('a batch answered short is an error, not a half-stored note', async () => {
    helper.responses['/v1/embeddings'] = { status: 200, body: { data: [{ index: 0, embedding: [0.1] }] } }
    const res = await app.post('/api/v1/create-embeddings', { contents: ['One.', 'Two.'] })
    assert.equal(res.status, 502)

    // Two rows both claiming index 1: position is what the caller maps back
    // onto its chunks, so this would store a vector against the wrong text.
    helper.responses['/v1/embeddings'] = { status: 200, body: { data: [{ index: 1, embedding: [0.1] }, { index: 1, embedding: [0.2] }] } }
    const dup = await app.post('/api/v1/create-embeddings', { contents: ['One.', 'Two.'] })
    assert.equal(dup.status, 502)

    const empty = await app.post('/api/v1/create-embeddings', { contents: [] })
    assert.equal(empty.status, 400)
  })

  test('a summary comes back from choices[0], not data.choices[0]', async () => {
    helper.responses['/chat/completions'] = {
      status: 200,
      body: { choices: [{ message: { content: '  Trimmed on the way out.  ' } }] },
    }

    const res = await app.post('/api/v1/summarize', { content: 'A note.' })

    assert.equal(res.status, 201)
    assert.equal(res.json.summary, 'Trimmed on the way out.')
  })

  // And the shape being WRONG must produce the sentence, not a TypeError. This
  // is what "defensively rather than hopefully" buys.
  test('a malformed provider answer is an error, not a crash', async () => {
    helper.responses['/v1/embeddings'] = { status: 200, body: { data: [] } }
    const embeddings = await app.post('/api/v1/create-embeddings', { content: 'A note.' })
    assert.equal(embeddings.status, 502)

    helper.responses['/chat/completions'] = { status: 200, body: {} }
    const summary = await app.post('/api/v1/summarize', { content: 'A note.' })
    assert.equal(summary.status, 502)
  })
})

describe('whose key pays', () => {
  // CLAUDE.md §Spend: embeddings run on the OPERATOR's key for every team,
  // always, because every stored vector is text-embedding-3-large and two
  // models mean two vector spaces. `apiKey || getApiKey()` is that rule.
  test("a request with no key falls back to the box's key", async () => {
    await app.post('/api/v1/create-embeddings', { content: 'A note.' })

    const call = helper.lastCall('/v1/embeddings')
    assert.equal(call.headers.authorization, `Bearer ${helper.BOX_KEY}`)
  })

  test("a request carrying a key uses that one, not the box's", async () => {
    await app.post('/api/v1/create-embeddings', { content: 'A note.', api_key: 'sk-a-teams-own-key' })

    const call = helper.lastCall('/v1/embeddings')
    assert.equal(call.headers.authorization, 'Bearer sk-a-teams-own-key')
    assert.ok(!JSON.stringify(call.headers).includes(helper.BOX_KEY))
  })

  // The embedding MODEL is one of the vector spaces a vault can hold: a model
  // outside them is refused rather than bought.
  test('the embedding model is one a vault can hold', async () => {
    const refused = await app.post('/api/v1/create-embeddings', { content: 'A note.', model: 'text-embedding-ada-002' })
    assert.equal(refused.status, 400)
    assert.equal(helper.lastCall('/v1/embeddings'), null)

    await app.post('/api/v1/create-embeddings', { content: 'A note.' })
    const call = helper.lastCall('/v1/embeddings')
    assert.equal(call.body.model, 'text-embedding-3-large')
    assert.equal(call.body.dimensions, 1536)

    const small = await app.post('/api/v1/create-embeddings', { content: 'A note.', model: 'text-embedding-3-small' })
    assert.equal(small.status, 201)
    assert.equal(helper.lastCall('/v1/embeddings').body.model, 'text-embedding-3-small')
    assert.equal(helper.lastCall('/v1/embeddings').body.dimensions, 1536)
    assert.equal(small.json.usage.model, 'text-embedding-3-small')
  })
})

describe('what bounds the spend', () => {
  test('chat completions carry the max_tokens cap', async () => {
    await app.post('/api/v1/summarize', { content: 'A note.' })

    const call = helper.lastCall('/chat/completions')
    assert.equal(call.body.max_tokens, 4096)
  })

  // GPT-5-family models reject max_tokens outright (they take
  // max_completion_tokens), so they are left uncapped rather than broken. A
  // deliberate exception, and one nothing else would notice was lost.
  // With a key, because a model override is only honoured for the account that
  // will pay for it: a caller that sent none is spending the operator's money
  // and does not get to choose what on.
  test('a gpt-5 model is sent uncapped rather than refused', async () => {
    await app.post('/api/v1/summarize', { content: 'A note.', api_key: 'sk-the-callers-own-key', model: 'gpt-5-mini' })

    const call = helper.lastCall('/chat/completions')
    assert.equal(call.body.model, 'gpt-5-mini')
    assert.equal(call.body.max_tokens, undefined)
  })
})

describe('retry, which the SDK used to own', () => {
  test('a 429 is retried and can then succeed', async () => {
    helper.responses['/chat/completions'] = attempt => attempt === 1
      ? { status: 429, body: { error: { message: 'slow down' } } }
      : { status: 200, body: { choices: [{ message: { content: 'Second time.' } }] } }

    const res = await app.post('/api/v1/summarize', { content: 'A note.' })

    assert.equal(res.status, 201)
    assert.equal(res.json.summary, 'Second time.')
    assert.equal(helper.callCount('/chat/completions'), 2)
  })

  test('a 500 is retried', async () => {
    helper.responses['/chat/completions'] = attempt => attempt === 1
      ? { status: 500, body: { error: { message: 'upstream trouble' } } }
      : { status: 200, body: { choices: [{ message: { content: 'Recovered.' } }] } }

    const res = await app.post('/api/v1/summarize', { content: 'A note.' })

    assert.equal(res.status, 201)
    assert.equal(helper.callCount('/chat/completions'), 2)
  })

  // A 400 is the caller's mistake and will be a mistake again. Retrying it
  // spends money three times to be told the same thing.
  test('a 400 is not retried', async () => {
    helper.responses['/chat/completions'] = { status: 400, body: { error: { message: 'bad request' } } }

    const res = await app.post('/api/v1/summarize', { content: 'A note.' })

    assert.equal(res.status, 502)
    assert.equal(helper.callCount('/chat/completions'), 1)
  })

  test('retries stop rather than going forever', async () => {
    helper.responses['/chat/completions'] = { status: 503, body: { error: { message: 'down' } } }

    await app.post('/api/v1/summarize', { content: 'A note.' })

    // The first call plus OPENAI_MAX_RETRIES.
    assert.equal(helper.callCount('/chat/completions'), 3)
  })
})

describe('the key must not reach the logs', () => {
  // The reason logApiError exists. It was written against axios, whose errors
  // carried the outgoing request — Authorization header included. The
  // dependency is gone; the rule is not, because the next transport may carry
  // it again and this is the one place that would print it.
  test('a provider failure logs the status and body, never the key', async () => {
    helper.responses['/chat/completions'] = { status: 401, body: { error: { message: 'Incorrect API key provided.' } } }

    const written = []
    const realError = console.error
    console.error = (...args) => written.push(args.map(a => (typeof a === 'string' ? a : JSON.stringify(a))).join(' '))
    try {
      await app.post('/api/v1/summarize', { content: 'A note.', api_key: 'sk-a-teams-own-key' })
    } finally {
      console.error = realError
    }

    const logged = written.join('\n')
    assert.ok(logged.includes('upstream 401'), 'the status was not logged: ' + logged)
    assert.ok(!logged.includes('sk-a-teams-own-key'), "the caller's key reached the log")
    assert.ok(!logged.includes(helper.BOX_KEY), "the box's key reached the log")
  })
})

test('another retained release can rotate the shared key without leaving a stale process cache', async () => {
  const fs = require('node:fs')
  const keyFile = './config/openapi_apikey.txt'
  const previous = fs.readFileSync(keyFile, 'utf8')
  try {
    await app.post('/api/v1/create-embeddings', { content: 'Before rotation.' })
    fs.writeFileSync(keyFile+'.fixture-next', 'synthetic-new-shared-key')
    fs.renameSync(keyFile+'.fixture-next', keyFile)
    const result = await app.post('/api/v1/create-embeddings', { content: 'After rotation.' })
    assert.equal(result.status, 201)
    const headers = helper.lastCall('/v1/embeddings').headers
    assert.equal(headers.Authorization || headers.authorization, 'Bearer synthetic-new-shared-key')
  } finally {
    fs.writeFileSync(keyFile, previous)
  }
})
