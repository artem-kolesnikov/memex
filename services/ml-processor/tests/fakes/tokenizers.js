'use strict'

const encoded = []

class Tokenizer {
  encode (text) {
    encoded.push(text)
    return { ids: [101, ...Array.from(text, ch => 1000 + (ch.codePointAt(0) % 20000)), 102] }
  }
}

module.exports = { Tokenizer, encoded }
