'use strict'

const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { MODEL, FILES, modelDir } = require('../src/localEmbedding')

const REVISION = 'e9b6763023c676ca8431644204f50c2b100d9aab'
const SHA256 = {
  [FILES.tokenizer]: 'd241a60d5e8f04cc1b2b3e9ef7a4921b27bf526d9f6050ab90f9267a1f9e5c66',
  [FILES.tokenizerConfig]: 'd7e0000bcc80134debd2222220427e6bf5fa20a669f40a0d0d1409cc18e0a9bc',
  [FILES.model]: 'b4342336debaea79de872370664b0aaeb67dea4605513d00ee236ea871a81f27',
}

const sha256 = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')

const main = async () => {
  const dir = modelDir()
  for (const [file, expected] of Object.entries(SHA256)) {
    const target = path.join(dir, file)
    if (fs.existsSync(target) && sha256(target) === expected) {
      console.log(`${file}: present`)
      continue
    }
    const url = `https://huggingface.co/nomic-ai/${MODEL}/resolve/${REVISION}/${file}`
    const response = await fetch(url)
    if (!response.ok) throw new Error(`${url} answered ${response.status}`)
    const body = Buffer.from(await response.arrayBuffer())
    const actual = crypto.createHash('sha256').update(body).digest('hex')
    if (actual !== expected) throw new Error(`${file}: sha256 ${actual}, expected ${expected}`)
    fs.mkdirSync(path.dirname(target), { recursive: true })
    fs.writeFileSync(`${target}.part`, body)
    fs.renameSync(`${target}.part`, target)
    console.log(`${file}: fetched`)
  }
  console.log(`${MODEL} is in ${dir}`)
}

main().catch((err) => {
  console.error(err.message)
  process.exit(1)
})
