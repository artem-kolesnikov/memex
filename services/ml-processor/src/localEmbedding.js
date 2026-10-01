'use strict'

const fs = require('fs')
const path = require('path')

const MODEL = 'nomic-embed-text-v1.5'
const DIMENSIONS = 768
// The model was trained to 2048 positions; past them its vectors drift.
const MAX_TOKENS = 2048
const BATCH = 8
const PREFIX = { document: 'search_document: ', query: 'search_query: ' }
const FILES = {
  tokenizer: 'tokenizer.json',
  tokenizerConfig: 'tokenizer_config.json',
  model: 'onnx/model_int8.onnx',
}

const modelDir = () => process.env.LOCAL_EMBEDDING_MODEL_DIR || path.join(__dirname, '..', 'models', MODEL)

const notInstalled = (detail) => {
  const err = new Error(`The local embedding model is not installed (${detail}). Run \`npm run fetch-model\` in services/ml-processor.`)
  err.notInstalled = true
  return err
}

const loadRuntime = async () => {
  const dir = modelDir()
  for (const file of Object.values(FILES)) {
    if (!fs.existsSync(path.join(dir, file))) throw notInstalled(`${file} is missing from ${dir}`)
  }
  let ort, Tokenizer
  try {
    ort = require('onnxruntime-node')
    ;({ Tokenizer } = require('@huggingface/tokenizers'))
  } catch (err) {
    throw notInstalled('its optional dependencies were omitted')
  }
  const read = file => JSON.parse(fs.readFileSync(path.join(dir, file), 'utf-8'))
  return {
    ort,
    tokenizer: new Tokenizer(read(FILES.tokenizer), read(FILES.tokenizerConfig)),
    session: await ort.InferenceSession.create(path.join(dir, FILES.model)),
  }
}

let runtime = null
const loaded = () => {
  runtime ??= loadRuntime().catch((err) => {
    runtime = null
    throw err
  })
  return runtime
}

const truncate = (ids) => (ids.length <= MAX_TOKENS ? ids : [...ids.slice(0, MAX_TOKENS - 1), ids[ids.length - 1]])

const meanPool = (hidden, row, length, width, dims) => {
  const vector = new Array(dims).fill(0)
  for (let t = 0; t < length; t++) {
    const offset = (row * width + t) * dims
    for (let d = 0; d < dims; d++) vector[d] += hidden[offset + d]
  }
  let norm = 0
  for (let d = 0; d < dims; d++) {
    vector[d] /= length
    norm += vector[d] * vector[d]
  }
  norm = Math.sqrt(norm)
  return norm > 0 ? vector.map(v => v / norm) : vector
}

const runBatch = async ({ ort, tokenizer, session }, texts) => {
  const encoded = texts.map(text => truncate(tokenizer.encode(text).ids))
  const width = Math.max(...encoded.map(ids => ids.length))
  const size = texts.length * width
  const inputIds = new BigInt64Array(size)
  const mask = new BigInt64Array(size)
  encoded.forEach((ids, row) => ids.forEach((id, t) => {
    inputIds[row * width + t] = BigInt(id)
    mask[row * width + t] = 1n
  }))
  const shape = [texts.length, width]
  const output = await session.run({
    input_ids: new ort.Tensor('int64', inputIds, shape),
    token_type_ids: new ort.Tensor('int64', new BigInt64Array(size), shape),
    attention_mask: new ort.Tensor('int64', mask, shape),
  })
  const hidden = output.last_hidden_state
  return encoded.map((ids, row) => meanPool(hidden.data, row, ids.length, width, hidden.dims[2]))
}

let queue = Promise.resolve()
const oneAtATime = (work) => {
  const run = queue.then(work, work)
  queue = run.catch(() => {})
  return run
}

const embed = async (texts, purpose, signal) => {
  const prefix = PREFIX[purpose] || PREFIX.document
  const loadedRuntime = await loaded()
  return oneAtATime(async () => {
    const vectors = []
    for (let i = 0; i < texts.length; i += BATCH) {
      if (signal) signal.throwIfAborted()
      vectors.push(...await runBatch(loadedRuntime, texts.slice(i, i + BATCH).map(text => prefix + text)))
    }
    return vectors
  })
}

module.exports = { MODEL, DIMENSIONS, MAX_TOKENS, FILES, embed, truncate, meanPool, modelDir }
