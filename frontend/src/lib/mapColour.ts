let swatch: CanvasRenderingContext2D | null = null

/**
 * Any CSS colour as three channels.
 *
 * Read by the canvas rather than by a regexp, because a regexp that takes the
 * first three numbers it finds reads `hsl(210, 50%, 40%)` as the colour
 * (210, 50, 40) and is wrong without saying so. `fillStyle` normalises every
 * notation the browser accepts, and rejects what it does not by leaving the
 * previous value in place — which is what the sentinel detects.
 */
export function parseColour(colour: string): [number, number, number] | null {
  swatch ??= document.createElement('canvas').getContext('2d')
  if (swatch === null) return null

  swatch.fillStyle = '#000000'
  swatch.fillStyle = colour
  const first = swatch.fillStyle
  swatch.fillStyle = '#ffffff'
  swatch.fillStyle = colour
  if (swatch.fillStyle !== first) return null

  const normalised = String(first)
  if (/^#[0-9a-f]{6}$/i.test(normalised)) {
    const n = parseInt(normalised.slice(1), 16)

    return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
  }
  const parts = normalised.match(/[\d.]+/g) ?? []

  return parts.length >= 3 ? [Number(parts[0]), Number(parts[1]), Number(parts[2])] : null
}

/** `from` at 0, `to` at 1. Both ends come from the theme, so the ramp follows it. */
export function mixColour(from: string, to: string, t: number): string {
  const a = parseColour(from)
  const b = parseColour(to)
  if (a === null || b === null) return from
  const at = Math.min(1, Math.max(0, t))

  return `rgb(${a.map((v, i) => Math.round(v + ((b[i] ?? v) - v) * at)).join(', ')})`
}
