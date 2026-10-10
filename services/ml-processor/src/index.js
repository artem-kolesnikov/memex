// memex.tools ml-processor — AI enrichment for notes: summary, tag suggestions,
// embeddings. Generalized from mm2's production ml-processor (2026-08-05):
// news-workflow endpoints (tonality, translation, article analysis, search-query
// parsing) stripped; the proven operational patterns (admin-editable prompts with
// .dist seeding, per-task model files, retry-with-backoff OpenAI wrapper, key
// caching, config-token gate, crash guards) carried over intact.
const express = require('express')
const sanitizeHTML = require('sanitize-html')
const cors = require('cors')
const fs = require('fs')
const path = require('path')
const crypto = require('crypto')
const { AsyncLocalStorage } = require('node:async_hooks')
const { setTimeout: delay } = require('node:timers/promises')
const localEmbedding = require('./localEmbedding')
const operationContext = new AsyncLocalStorage()

const OPENAI_EMBEDDING_MODEL = 'text-embedding-3-large'
const OPENAI_EMBEDDING_MODELS = [OPENAI_EMBEDDING_MODEL, 'text-embedding-3-small']

// Log only the upstream status and error body for HTTP failures, the stack for
// everything else.
//
// It was written against axios, whose errors carried the full outgoing request
// in err.config/err.request — the Authorization header with the OpenAI API key
// included — so dumping the raw error leaked the key into journald. That
// dependency is gone (2026-08-25) and with it that particular hazard, but this
// stays and is now the RULE rather than a workaround: `providerError()` builds
// err.response deliberately so every provider failure is logged the same
// narrow way. Never log a whole error object here; the next transport to
// arrive may carry the key again, and this is the one place that would print
// it.
const logApiError = (endpoint, err) => {
  if (err && err.response) {
    console.error(`${endpoint}: upstream ${err.response.status}`, JSON.stringify(err.response.data))
  } else {
    console.error(`${endpoint}:`, (err && err.stack) || err)
  }
}

const allowedTypes = [
  'openapi_apikey',
  'openapi_apikey_text',
  'openapi_prompt_summarize',
  'openapi_prompt_suggest_tags',
  'openapi_prompt_suggest_title',
  'openapi_model_summarize',
  'openapi_model_suggest_tags',
  'openapi_model_suggest_title',
]

// Fallbacks when a model config file is missing/invalid. (Embeddings stay
// hardcoded: changing the embedding model would invalidate every stored
// vector.)
const defaultModels = {
  summarize: 'gpt-4o-mini',
  suggest_tags: 'gpt-4o-mini',
  suggest_title: 'gpt-4o-mini',
}

// A key and a model supplied by the caller, for THIS request only.
//
// memex.tools lets a user bring their own provider key and choose the model
// that writes their summaries (backend: team_settings, step 3c). The backend
// decrypts the team's key and sends it per call; a request without one falls
// back to the box's configured key, which is the operator's. Neither value is
// cached and neither is ever logged — the shared error logger already refuses
// to dump the outgoing request, which is where the Authorization header lives.
const requestApiKey = (body) => {
  const key = String((body && body.api_key) || '').trim()
  // Printable ASCII, no whitespace: every provider key in circulation, and
  // nothing that could be a smuggled header.
  return /^[\x21-\x7e]{8,400}$/.test(key) ? key : null
}

const requestModel = (body) => {
  const model = String((body && body.model) || '').trim()
  return /^[A-Za-z0-9._:-]{1,120}$/.test(model) ? model : null
}

// Which provider writes this request's text. memex.tools lets a team bring a
// key from OpenAI, Anthropic or Google (backend: team_ai_credentials); the
// box's own configuration is OpenAI, so that is what an unmarked request means.
const PROVIDERS = ['openai', 'anthropic', 'google']

const providerDefaults = {
  anthropic: 'claude-haiku-4-5-20251001',
  google: 'gemini-2.0-flash',
}

const providerLabels = {
  openai: 'OpenAI',
  anthropic: 'Anthropic',
  google: 'Google',
}

const requestProvider = (body) => {
  const provider = String((body && body.provider) || '').trim()
  return PROVIDERS.includes(provider) ? provider : null
}

// Per-task model, admin-editable like the prompts.
const modelFor = (task) => {
  try {
    const m = fs.readFileSync(`./config/openapi_model_${task}.txt`, 'utf-8').trim()
    if (/^[A-Za-z0-9._-]{1,64}$/.test(m)) return m
  } catch (e) { /* not seeded yet — fall back */ }
  return defaultModels[task]
}

// Whether a model accepts a set temperature. Providers are withdrawing it:
// Anthropic refuses one from Opus 4.7 and Sonnet 5 on, OpenAI from its o-series
// and gpt-5 on (with max_tokens). So the list names the older models that still
// take it, and a model released after this was written gets the provider's
// default rather than a refusal on every call.
const takesTemperature = (provider, model) => {
  if (provider === 'openai') return /^(gpt-3\.5|gpt-4|chatgpt-4o)/.test(model)
  if (provider === 'anthropic') return /^claude-(\d|instant)|^claude-(opus|sonnet|haiku)-4(-[0-6])?(-\d{8})?$/.test(model)
  return true
}

// The models a tier may be given on the box's own key: the ones control offers
// (App\Service\ProviderPrices::textModels()). The backend sends the tier's
// choice with no key; any other model named without a key is not the
// operator's choice, and the per-task file decides.
const BOX_TEXT_MODELS = ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1-nano']

const boxModel = model => (BOX_TEXT_MODELS.includes(model) ? model : null)

// Older families need temperature 0 for deterministic extraction.
const chatModelParams = (task, wantZeroTemp, override) => {
  const model = override || modelFor(task)
  return (wantZeroTemp && takesTemperature('openai', model)) ? { model: model, temperature: 0 } : { model: model }
}

// Read provider keys from the shared runtime directory for each operation.
//
// Split in two because the GET config endpoint needs to answer "is there a
// key" without either caching it or failing: a missing key is a thing Admin
// reports, and a thing the spending path refuses on. Only the spending path
// caches, so asking about the key never pins a stale one.
const readApiKey = (file = 'openapi_apikey') => {
  try {
    const key = fs.readFileSync(`./config/${file}.txt`, 'utf-8').trim()
    return key === '' ? null : key
  } catch (e) {
    return null
  }
}

// TWO OpenAI keys, not one, and the split is about the hard spend limit rather
// than about permissions (2026-09-08). Embeddings run on the operator's account
// for every knowledge base on this box and cannot be switched off without
// making notes unfindable; text runs on it only for a team he sponsors. Sharing
// one key means a sponsored account's summaries can exhaust the limit that
// every team's search depends on, and the cap that would stop that is set at
// OpenAI, per key. Separate keys make the two budgets separate.
//
// The text key is OPTIONAL: with none configured, text falls back to the
// embedding key, which is what every box did before this existed.
const getApiKey = () => {
  const key = readApiKey()
  if (key === null) {
    throw new Error('No OpenAI key is configured on this server (config/openapi_apikey.txt)')
  }
  return key
}

// Retained release instances share config. Read current keys for each purchase;
// a config PUT in one process must not leave another spending with an old key.
const getTextApiKey = () => readApiKey('openapi_apikey_text') || getApiKey()

// Request timeout, retry-with-backoff on transient failures (429/5xx/network),
// and a max_tokens cap on chat completions to bound billable output. The three
// providers share all of it — see openaiChat/openaiEmbedding below, beside the
// Anthropic and Google calls they now match.
const OPERATION_TIMEOUT_MS = 60000
const OPENAI_MAX_RETRIES = 2
const OPENAI_MAX_TOKENS = 4096

// --- Text generation, whichever provider the team chose ---
//
// One shape in, one string out, so the three endpoints below do not each grow
// a provider switch. Anthropic and Google are plain REST over the built-in
// fetch: no SDK, no dependency, and nothing to keep in step with a vendor's
// release cycle. Only the request body differs.
//
// Embeddings are deliberately NOT here: the provider is the vault's vector
// space, named by the backend, never a text provider the team chose.

const withRetry = async (fn) => {
  const signal = operationContext.getStore().signal
  for (let attempt = 0; ; attempt++) {
    signal.throwIfAborted()
    try {
      return await fn()
    } catch (err) {
      signal.throwIfAborted()
      const status = err.status || (err.response && err.response.status)
      const transient = status === 429 || status >= 500 || status === undefined
      if (!transient || attempt >= OPENAI_MAX_RETRIES) {
        throw err
      }
      await delay(1000 * Math.pow(2, attempt), undefined, { signal })
    }
  }
}

// A provider HTTP failure carried the same way an axios one is, so logApiError
// keeps working and still never sees the outgoing request (where the key is).
/**
 * A request this service refuses, as against a provider that refused it.
 *
 * The distinction has to be carried on the error because the route wrappers
 * catch everything and answer 500: the "this provider needs its own key" guard
 * built a 400 that nothing ever read, so a misconfigured caller was told the
 * server had broken (found by Codex, 2026-09-08). An upstream failure is a
 * 502 — the caller did nothing wrong and cannot fix it.
 */
const badRequest = (message) => {
  const err = new Error(message)
  err.clientError = true
  return err
}

// The provider failed rather than this service: it refused the request,
// answered something unusable, or could not be reached. Answered 502, so the
// backend can tell a refused key or model from this service breaking.
const upstream = (err) => {
  err.upstream = true
  return err
}

const providerError = (name, status, body) => {
  const err = new Error(`${name} returned ${status}`)
  err.status = status
  err.response = { status: status, data: body }
  return upstream(err)
}

const postJson = async (url, headers, body, label) => {
  const signal = operationContext.getStore().signal
  signal.throwIfAborted()
  let response
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json', ...headers },
      body: JSON.stringify(body),
      signal,
    })
  } catch (err) {
    signal.throwIfAborted()
    throw upstream(err)
  }
  let data = null
  try {
    data = await response.json()
  } catch (e) {
    signal.throwIfAborted()
    // An error page rather than JSON; status carries the meaning.
  }
  if (!response.ok) {
    throw providerError(label, response.status, data)
  }
  return data
}

/**
 * What one provider call cost, in ONE shape.
 *
 * Every provider reports usage differently — OpenAI as `prompt_tokens` /
 * `completion_tokens`, Anthropic as `input_tokens` / `output_tokens`, Google as
 * `usageMetadata.promptTokenCount` / `candidatesTokenCount` — and until
 * 2026-08-27 this service read none of them: each handler pulled the text out
 * of the response and let the usage fall out of scope beside it. The backend
 * therefore had no idea what anything cost, and `note_embeddings.token_est`
 * (characters over four) was the closest thing to accounting in the product.
 *
 * Normalising HERE rather than in the backend is the point. Three dialects
 * would otherwise become three branches at every reader, and a fourth provider
 * would add a fourth. What crosses the wire is input tokens, output tokens, the
 * model that actually ran, and whose key paid.
 *
 * `key` is OBSERVED, never assumed (operator's decision, 2026-08-27). The
 * backend knows which key it MEANT to use; only this service knows which one
 * was used, because `openaiHeaders` falls back to the box key when the caller
 * sent none. That fallback is the documented embeddings arrangement, and it is
 * also the shape of a defect if it ever fires for text — so it is recorded as
 * a fact rather than inferred from intent.
 *
 * Missing counts stay null. A provider that reports nothing is not a call that
 * cost nothing, and a zero would be indistinguishable from one that did.
 */
/**
 * The provider was PAID and the answer is unusable.
 *
 * Found by Codex reviewing the first version of this accounting, 2026-08-27,
 * and it was the same asymmetry the capture side had already been built to
 * avoid. Usage used to be attached only on the success path, so any call whose
 * answer failed a validity check below — Anthropic replying with no text part,
 * OpenAI stopping at `max_tokens` with an empty message, an embedding response
 * missing its vector — threw before its cost was ever read, and the backend
 * recorded that the call had never happened.
 *
 * That is the wrong way round twice over: the money is spent either way, and
 * the calls that fail validation are the ones somebody would want to find.
 * So the cost rides out on the error, and the routes below put it in the error
 * body for the backend to record with `succeeded: false`.
 */
const billed = (err, usage) => {
  err.usage = usage
  return upstream(err)
}

// A provider failure's message names the provider and its status, never the
// request, so it can go back to the caller for the operator's alert.
const answerFailure = (res, err) => err.upstream
  ? res.status(502).json({ error: err.message, usage: err.usage || null })
  : res.status(500).json({ error: 'Server Error', usage: err.usage || null })

const usageFrom = (provider, model, apiKey, data) => {
  const n = value => (Number.isFinite(value) ? value : null)
  let input = null
  let output = null
  if (provider === 'openai') {
    const u = (data && data.usage) || {}
    input = n(u.prompt_tokens)
    output = n(u.completion_tokens)
  } else if (provider === 'anthropic') {
    const u = (data && data.usage) || {}
    input = n(u.input_tokens)
    output = n(u.output_tokens)
  } else if (provider === 'google') {
    const u = (data && data.usageMetadata) || {}
    input = n(u.promptTokenCount)
    output = n(u.candidatesTokenCount)
  }
  return {
    provider: provider,
    model: model,
    input_tokens: input,
    output_tokens: output,
    key: apiKey ? 'caller' : 'box',
  }
}

const anthropicText = async (model, apiKey, system, user, wantZeroTemp) => {
  const data = await postJson(
      'https://api.anthropic.com/v1/messages',
      { 'x-api-key': apiKey, 'anthropic-version': '2023-06-01' },
      {
        model: model,
        max_tokens: OPENAI_MAX_TOKENS,
        system: system,
        messages: [{ role: 'user', content: user }],
        ...(wantZeroTemp && takesTemperature('anthropic', model) ? { temperature: 0 } : {}),
      },
      'anthropic'
  )
  // Read the cost BEFORE judging the answer: the call is billed either way.
  const usage = usageFrom('anthropic', model, apiKey, data)
  const text = (data.content || []).filter(b => b.type === 'text').map(b => b.text).join('').trim()
  if (!text) {
    throw billed(new Error('No response from Anthropic API'), usage)
  }
  return { text: text, usage: usage }
}

const googleText = async (model, apiKey, system, user, wantZeroTemp) => {
  const data = await postJson(
      `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent`,
      { 'x-goog-api-key': apiKey },
      {
        systemInstruction: { parts: [{ text: system }] },
        contents: [{ role: 'user', parts: [{ text: user }] }],
        generationConfig: {
          maxOutputTokens: OPENAI_MAX_TOKENS,
          ...(wantZeroTemp ? { temperature: 0 } : {}),
        },
      },
      'google'
  )
  const parts = ((data.candidates || [])[0] || {}).content
  // Thinking models put a reasoning part alongside the answer; only parts that
  // carry text are the answer.
  const usage = usageFrom('google', model, apiKey, data)
  const text = (((parts || {}).parts) || []).map(p => p.text || '').join('').trim()
  if (!text) {
    throw billed(new Error('No response from Google API'), usage)
  }
  return { text: text, usage: usage }
}

// OpenAI over the same `postJson` the two providers above use (2026-08-25,
// AUDIT2 M-20's open half). It was the `openai@3.3.0` SDK, abandoned upstream
// and dragging `axios@0.26.1` into the service that holds the operator's
// OpenAI key, while the file it lives in already called Anthropic and Google
// through bare fetch. Two calls; no dependency left.
//
// The fallback to the BOX's key, and for embeddings it is the documented
// exception in CLAUDE.md §Spend rather than an oversight — a vault on
// text-embedding-3-large with no key of its own embeds on this box's. Losing
// this line stops embeddings for every such vault.
//
// Which of his two keys is the caller's to say, because the two budgets are
// separate: `getTextApiKey` for a completion, `getApiKey` for a vector.
const openaiHeaders = (apiKey, boxKey) => ({ authorization: `Bearer ${apiKey || boxKey()}` })

const openaiChat = (params, apiKey) => withRetry(() => postJson(
    'https://api.openai.com/v1/chat/completions',
    openaiHeaders(apiKey, getTextApiKey),
    // The models that refuse a temperature refuse max_tokens too (they take
    // max_completion_tokens, which also counts their reasoning); leave them
    // uncapped rather than break the call. `params` spreads LAST so a caller
    // can still override.
    takesTemperature('openai', params.model) ? { max_tokens: OPENAI_MAX_TOKENS, ...params } : params,
    'openai'
))

const openaiEmbedding = (params, apiKey) => withRetry(() => postJson(
    'https://api.openai.com/v1/embeddings',
    openaiHeaders(apiKey, getApiKey),
    params,
    'openai'
))

/**
 * @param task one of summarize | suggest_tags | suggest_title — decides the
 *             box's own default model when the caller named none
 */
const generateText = async (task, body, system, user, wantZeroTemp) => {
  const provider = requestProvider(body) || 'openai'
  const apiKey = requestApiKey(body)
  // A team that named a provider other than OpenAI must have sent its key: the
  // alternative is quietly answering with the operator's OpenAI account while
  // the settings screen says Anthropic, which is exactly the confusion this
  // whole feature exists to remove.
  if (provider !== 'openai' && !apiKey) {
    throw badRequest(`${providerLabels[provider]} requests need ${providerLabels[provider]}'s own key`)
  }

  if (provider === 'openai') {
    // **A MODEL WITHOUT A CALLER KEY IS NOT THE CALLER'S TO CHOOSE.** A model
    // belongs to the account that lists it, and with no key in the request the
    // account is the operator's — so the only model honoured here is one he can
    // give a tier in control, which is what the backend sends
    // (App\Service\EnrichmentSettings). The service must not trust that its one
    // caller today is its only caller, so anything else falls to the box's own.
    const params = chatModelParams(task, wantZeroTemp, apiKey ? requestModel(body) : boxModel(requestModel(body)))
    const completion = await openaiChat({
      ...params,
      messages: [
        { role: 'system', content: system },
        { role: 'user', content: user },
      ]
    }, apiKey)
    // `params.model` rather than the request's: the box resolves a default per
    // task when the caller named none, and the accounting has to say which
    // model actually ran, not which one was asked for.
    //
    // Computed on its own line rather than inside the return expression, which
    // is where the lost-usage defect lived: `messageContent()` throws on an
    // empty completion, and evaluated first it took the cost down with it.
    const usage = usageFrom('openai', params.model, apiKey, completion)
    let text
    try {
      text = messageContent(completion)
    } catch (err) {
      throw billed(err, usage)
    }
    return { text: text, usage: usage }
  }

  // No key, no branch: the guard above already refused a non-OpenAI provider
  // without one, so the model override here is always the caller's own.
  const model = requestModel(body) || providerDefaults[provider]
  return withRetry(() => (provider === 'anthropic' ? anthropicText : googleText)(model, apiKey, system, user, wantZeroTemp))
}

const stripAll = value => sanitizeHTML(String(value || ''), { allowedTags: [], allowedAttributes: [] })

// `completion.choices`, not `completion.data.choices`: the SDK wrapped every
// response in an axios envelope and `postJson` returns the parsed body itself.
// Checked defensively rather than indexed hopefully — nothing validates the
// shape now, and the failure this replaces would have been a 500 with a
// TypeError in the journal instead of the sentence below.
const messageContent = completion => {
  const choices = (completion && completion.choices) || []
  if (!choices.length || !choices[0].message || !choices[0].message.content) {
    throw new Error('No response from OpenAI API')
  }
  return choices[0].message.content.trim()
}

const app = express()
const PORT = 8201

app.use(express.json({ limit: '10mb' }))
app.use(cors())
app.get('/health', (_req, res) => res.json({ status: 'ok', service: 'ml-processor', release: process.env.MEMEX_RELEASE_ID || 'legacy' }))
// An edition's own routes (src/edition/<name>/index.js), ahead of the
// per-operation deadline below, which is for the work this service does.
const editions = path.join(__dirname, 'edition')
for (const name of fs.existsSync(editions) ? fs.readdirSync(editions) : []) {
  require(path.join(editions, name)).install(app, { allowedTypes, readApiKey, modelFor, logApiError })
}
app.use((req, res, next) => {
  const supplied = req.headers['x-memex-deadline']
  const deadline = supplied === undefined ? Date.now() + OPERATION_TIMEOUT_MS : Number(supplied)
  if (!Number.isSafeInteger(deadline) || deadline <= Date.now()) return res.status(408).json({ error: 'Operation deadline expired', usage: null })
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(new Error('Operation deadline exceeded')), Math.min(OPERATION_TIMEOUT_MS, deadline - Date.now()))
  timer.unref()
  req.once('aborted', () => controller.abort(new Error('Caller disconnected')))
  res.once('close', () => { clearTimeout(timer); if (!res.writableEnded) controller.abort(new Error('Caller disconnected')) })
  res.once('finish', () => clearTimeout(timer))
  operationContext.run({ signal: controller.signal }, next)
})

// Plain-text summary of a note's content. memex notes are markdown, not HTML —
// the summary comes back as plain text, no markup added.
app.post('/api/v1/summarize', async (req, res) => {
  try {
    const content = stripAll(req.body.content)
    if (!content.trim()) {
      return res.status(400).json({ message: 'content is empty' })
    }

    const prompt = fs.readFileSync('./config/openapi_prompt_summarize.txt', 'utf-8')

    // temperature 0: at the default 1.0, instruction-heavy content (training
    // decks, how-to notes) occasionally derails the model into echoing a
    // fragment of the text instead of summarizing (seen on prod).
    const { text: summary, usage } = await generateText('summarize', req.body, prompt, content, true)

    res
        .status(201)
        .json({ summary: stripAll(summary), usage: usage })
  } catch (err) {
    logApiError('summarize', err)
    if (err.clientError) {
      return res.status(400).json({ error: err.message, usage: null })
    }
    answerFailure(res, err)
  }
})

// Tag suggestions, guardrailed to the caller-provided vocabulary: [{ id, name }].
// The model returns tag NAMES, not ids — mm2's analyze-article showed that
// models read content correctly but reliably fumble name→numeric-id mapping.
// Names are mapped to ids here, deterministically; unknown names come back in
// new_tags for the operator to accept or discard.
app.post('/api/v1/suggest-tags', async (req, res) => {
  try {
    const title = stripAll(req.body.title)
    const content = stripAll(req.body.content)
    if (!content.trim() && !title.trim()) {
      return res.status(400).json({ message: 'content is empty' })
    }

    const tagVocab = Array.isArray(req.body.tags) ? req.body.tags : []
    const tagIds = new Set(tagVocab.map(t => t.id))
    const tagIdByName = new Map(tagVocab.map(t => [String(t.name).trim().toLowerCase(), t.id]))

    const prompt = fs.readFileSync('./config/openapi_prompt_suggest_tags.txt', 'utf-8')

    const { text: answer, usage } = await generateText(
        'suggest_tags',
        req.body,
        prompt,
        JSON.stringify({ title: title, content: content, tags: tagVocab.map(t => t.name) }),
        true
    )

    let parsed
    try {
      // Models that cannot be told to answer in JSON mode wrap it in a fenced
      // block; strip one if it is there before giving up on the answer.
      parsed = JSON.parse(answer.replace(/^\s*```(?:json)?\s*|\s*```\s*$/g, ''))
    } catch (e) {
      // The call succeeded and was billed; only the shape of the answer is
      // wrong. `usage` is already in hand here, so it goes out with the error
      // rather than being dropped on the floor.
      throw billed(new Error('The model did not return valid JSON'), usage)
    }

    // --- Hard guardrails: never trust the model's ids / shape ---
    const suggested = Array.isArray(parsed.tags) ? parsed.tags : []
    const existingIds = [...new Set(suggested
        .map(v => typeof v === 'number'
            ? (tagIds.has(v) ? v : undefined)
            : tagIdByName.get(stripAll(v).trim().toLowerCase()))
        .filter(id => id !== undefined))]

    const knownNames = new Set(tagIdByName.keys())
    const newTags = (Array.isArray(parsed.new_tags) ? parsed.new_tags : [])
        .map(v => stripAll(v).trim().toLowerCase())
        .filter(name => name.length > 0 && name.length <= 64 && !knownNames.has(name))

    res
        .status(201)
        .json({
          tag_ids: existingIds.slice(0, 5),
          new_tags: [...new Set(newTags)].slice(0, 5),
          usage: usage,
        })
  } catch (err) {
    logApiError('suggest-tags', err)
    if (err.clientError) {
      return res.status(400).json({ error: err.message, usage: null })
    }
    answerFailure(res, err)
  }
})

// A title for a note whose own is missing or filename-shaped. The caller
// decides when to ask (backend: CaptureController::isWeakTitle) — this endpoint
// always spends. Returned as plain text, capped so a runaway completion can't
// become a 4000-character title.
app.post('/api/v1/suggest-title', async (req, res) => {
  try {
    const content = stripAll(req.body.content)
    if (!content.trim()) {
      return res.status(400).json({ message: 'content is empty' })
    }

    const prompt = fs.readFileSync('./config/openapi_prompt_suggest_title.txt', 'utf-8')

    // temperature 0, same reasoning as summarize: instruction-heavy notes
    // otherwise pull the model into echoing the content.
    const { text: answer, usage } = await generateText('suggest_title', req.body, prompt, content, true)

    // Models like to wrap titles in quotes despite the instruction.
    const title = stripAll(answer)
        .replace(/^["'«»""]+|["'«»""]+$/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 200)

    res
        .status(201)
        .json({ title: title, usage: usage })
  } catch (err) {
    logApiError('suggest-title', err)
    if (err.clientError) {
      return res.status(400).json({ error: err.message, usage: null })
    }
    answerFailure(res, err)
  }
})

app.post('/api/v1/create-embeddings', async (req, res) => {
  try {
    // `contents` is the chunks of one note, embedded in one round trip and
    // answered in the same order; `content` is the single text every other
    // caller sends, answered as one vector. Which shape came in decides which
    // goes out.
    const batch = Array.isArray(req.body.contents)
    const content = batch ? req.body.contents.map(stripAll) : stripAll(req.body.content)
    if (batch && (content.length === 0 || content.length > 256)) {
      res.status(400).json({ error: 'contents must hold between 1 and 256 texts', usage: null })
      return
    }

    // A vault's vectors are all one model's, and the backend names which; who
    // pays never changes it.
    const space = req.body.model === undefined ? OPENAI_EMBEDDING_MODEL : req.body.model
    if (space === localEmbedding.MODEL) {
      const texts = batch ? content : [content]
      const vectors = await localEmbedding.embed(texts, req.body.purpose, operationContext.getStore().signal)
      res.status(201).json({ embeddings: batch ? vectors : vectors[0], usage: null })
      return
    }
    if (!OPENAI_EMBEDDING_MODELS.includes(space)) {
      res.status(400).json({ error: `model must be one of ${[...OPENAI_EMBEDDING_MODELS, localEmbedding.MODEL].join(', ')}`, usage: null })
      return
    }

    // The key is the caller's to send, so a team that brought its own pays
    // for its own embeddings.
    const embeddingKey = requestApiKey(req.body)
    const embedding = await openaiEmbedding({
      model: space,
      input: content,
      dimensions: 1536,
    }, embeddingKey)

    // An embedding has no completion, so `output_tokens` is null rather than
    // 0 — the same distinction usageFrom() draws everywhere else. Read before
    // the vector is validated, because a response that arrived without one was
    // still bought.
    const usage = usageFrom('openai', space, embeddingKey, embedding)

    // `embedding.data[0]`, not `embedding.data.data[0]`: the outer `data` was
    // axios's envelope, the inner one is OpenAI's array. Only one is left.
    const rows = (embedding && embedding.data) || []
    if (batch) {
      const ordered = rows.slice().sort((a, b) => (a.index || 0) - (b.index || 0))
      // Position is what the caller maps back onto its chunks, so every index
      // has to be present exactly once: a duplicate or a gap would store a
      // vector against the wrong text and nothing downstream could tell.
      const wellIndexed = ordered.length === content.length
        && ordered.every((r, i) => r && Number.isInteger(r.index) && r.index === i && r.embedding && r.embedding.length)
      if (!wellIndexed) {
        throw billed(new Error('No response from OpenAI API'), usage)
      }
      res
          .status(201)
          .json({ embeddings: ordered.map(r => r.embedding), usage: usage })
      return
    }
    const vector = rows[0]
    if (!vector || !vector.embedding || !vector.embedding.length) {
      throw billed(new Error('No response from OpenAI API'), usage)
    }

    res
        .status(201)
        .json({ embeddings: vector.embedding, usage: usage })
  } catch (err) {
    logApiError('create-embeddings', err)
    if (err.notInstalled) {
      return res.status(503).json({ error: err.message, usage: null })
    }
    answerFailure(res, err)
  }
})

// Everything below happens only when this file IS the process — `node
// src/index.js` under systemd. Required as a module (tests/) it exports the
// express app and starts nothing, binds no port and installs no process
// handlers.
//
// The handlers are the reason this guard is not optional. They call
// process.exit(1), which under the test runner would kill the run itself on
// the first rejection and report it as something other than a failing test.
if (require.main === module) {
  // Last-resort guards: an unforeseen async throw must not take the service
  // down with an unlogged crash. Log and exit non-zero so the supervisor
  // restarts a clean process (all request handlers have their own try/catch;
  // these fire only for truly unexpected failures).
  process.on('unhandledRejection', (reason) => {
    console.error('unhandledRejection:', reason)
    process.exit(1)
  })
  process.on('uncaughtException', (err) => {
    console.error('uncaughtException:', err)
    process.exit(1)
  })

  let server
  if (process.env.LISTEN_SOCKET) {
    if (!process.env.LISTEN_SOCKET.startsWith('/')) throw new Error('LISTEN_SOCKET must be absolute')
    process.umask(0o007)
    server = app.listen(process.env.LISTEN_SOCKET, () => fs.chmodSync(process.env.LISTEN_SOCKET, 0o660))
  } else {
    server = app.listen(PORT, '127.0.0.1', () => console.log(`Server started on http://localhost:${PORT}`))
  }
  process.on('SIGTERM', () => server.close(() => process.exit(0)))
}

module.exports = app
module.exports.BOX_TEXT_MODELS = BOX_TEXT_MODELS
