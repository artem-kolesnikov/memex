'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const path = require('path')

// Control offers a tier the text models ProviderPrices prices; a model it offers
// that this service will not buy on the box's key runs the per-task file instead,
// whatever control says.
test('every text model control offers a tier is one the box will buy', () => {
  const prices = fs.readFileSync(path.join(__dirname, '../../../backend/src/Service/ProviderPrices.php'), 'utf-8')
  const offered = [...prices.matchAll(/'([A-Za-z0-9._-]+)' => \[/g)]
    .map(m => m[1])
    .filter(model => !model.startsWith('text-embedding-'))
  assert.ok(offered.length > 0, 'no text models found in ProviderPrices')

  const { BOX_TEXT_MODELS } = require('../src/index.js')
  assert.deepStrictEqual(offered.filter(model => !BOX_TEXT_MODELS.includes(model)), [])
})
