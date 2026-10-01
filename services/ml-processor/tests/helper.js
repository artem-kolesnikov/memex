// Test harness for ml-processor. No dependencies: Node 22's own test runner,
// its own fetch, and a real listening express app.
//
// The service reads every config file as `./config/<name>.txt`, relative to the
// process working directory. So the harness builds a throwaway config dir and
// chdir's into it BEFORE requiring the app — which is also why no fake API key
// ever has to be written inside the repository.
'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')

const BOX_KEY = 'sk-test-box-key-not-real'

/** Requests the stubbed provider received, newest last. */
const calls = []

/**
 * Canned provider answers, keyed by URL fragment. A test sets one to change
 * what the provider says; anything unset gets a sensible success.
 *
 * Each entry is either {status, body} or a function (call) => {status, body},
 * so a test can answer differently per attempt — which is the only way to
 * check that a retry actually retried.
 */
const responses = {}

function reset() {
  calls.length = 0
  for (const key of Object.keys(responses)) delete responses[key]
}

const defaultAnswers = {
  '/chat/completions': { status: 200, body: { choices: [{ message: { content: '  A summary.  ' } }] } },
  '/v1/embeddings': { status: 200, body: { data: [{ embedding: Array(1536).fill(0.5) }] } },
  '/v1/messages': { status: 200, body: { content: [{ type: 'text', text: 'Anthropic said this.' }] } },
  ':generateContent': { status: 200, body: { candidates: [{ content: { parts: [{ text: 'Google said this.' }] } }] } },
}

function answerFor(url) {
  for (const fragment of Object.keys(responses)) {
    if (url.includes(fragment)) return responses[fragment]
  }
  for (const fragment of Object.keys(defaultAnswers)) {
    if (url.includes(fragment)) return defaultAnswers[fragment]
  }
  return { status: 404, body: { error: { message: 'no stub for ' + url } } }
}

/**
 * Replace global fetch with a recorder, passing our OWN requests through.
 *
 * The tests below talk to the express app over real HTTP on 127.0.0.1, and
 * those requests go through the same global. Without the passthrough the
 * harness would stub itself.
 */
function installFetchStub() {
  const realFetch = globalThis.fetch
  globalThis.fetch = async (url, options = {}) => {
    const target = String(url)
    if (target.startsWith('http://127.0.0.1:')) return realFetch(url, options)

    const body = options.body ? JSON.parse(options.body) : null
    const headers = options.headers || {}
    calls.push({ url: target, headers, body })

    const answer = answerFor(target)
    const { status, body: payload } = typeof answer === 'function' ? answer(calls.length) : answer

    return new Response(JSON.stringify(payload), {
      status,
      headers: { 'content-type': 'application/json' },
    })
  }
  return () => { globalThis.fetch = realFetch }
}

/** A throwaway config dir with everything the service reads, then chdir into it. */
function useThrowawayConfig() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ml-processor-test-'))
  fs.mkdirSync(path.join(dir, 'config'))
  const write = (name, value) => fs.writeFileSync(path.join(dir, 'config', name + '.txt'), value)

  write('openapi_apikey', BOX_KEY)
  write('openapi_prompt_summarize', 'Summarise this.')
  write('openapi_prompt_suggest_tags', 'Suggest tags.')
  write('openapi_prompt_suggest_title', 'Suggest a title.')
  write('openapi_model_summarize', 'gpt-4o-mini')
  write('openapi_model_suggest_tags', 'gpt-4o-mini')
  write('openapi_model_suggest_title', 'gpt-4o-mini')

  process.chdir(dir)
  return dir
}

/** Start the app on an ephemeral port and hand back a caller + a stopper. */
async function startApp() {
  const app = require('../src/index.js')
  const server = app.listen(0, '127.0.0.1')
  await new Promise(resolve => server.once('listening', resolve))
  const base = `http://127.0.0.1:${server.address().port}`

  const post = async (route, payload) => {
    const response = await fetch(base + route, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const text = await response.text()
    let json = null
    try { json = JSON.parse(text) } catch (e) { /* an error page */ }
    return { status: response.status, text, json }
  }

  // `base` is exposed as well as `post` because the config endpoints are
  // GET/PUT and carry a header, which the post helper cannot express.
  return { base, post, stop: () => new Promise(resolve => server.close(resolve)) }
}

/** The last request the stubbed provider received for a URL fragment. */
function lastCall(fragment) {
  const matching = calls.filter(c => c.url.includes(fragment))
  return matching.length ? matching[matching.length - 1] : null
}

function callCount(fragment) {
  return calls.filter(c => c.url.includes(fragment)).length
}

module.exports = { BOX_KEY, calls, responses, reset, installFetchStub, useThrowawayConfig, startApp, lastCall, callCount }
