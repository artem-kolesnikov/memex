'use strict'

const HIDDEN = 768

class Tensor {
  constructor (type, data, dims) {
    this.type = type
    this.data = data
    this.dims = dims
  }
}

const InferenceSession = {
  create: async () => ({
    run: async (feeds) => {
      const [rows, width] = feeds.input_ids.dims
      const data = new Float32Array(rows * width * HIDDEN)
      for (let i = 0; i < rows * width; i++) {
        const id = Number(feeds.input_ids.data[i])
        for (let d = 0; d < HIDDEN; d++) data[i * HIDDEN + d] = ((id * (d + 1)) % 17) - 8
      }
      return { last_hidden_state: new Tensor('float32', data, [rows, width, HIDDEN]) }
    },
  }),
}

module.exports = { Tensor, InferenceSession }
