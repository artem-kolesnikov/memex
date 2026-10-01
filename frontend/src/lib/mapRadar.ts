import type { GraphEdge, GraphNode } from '@/api/client'

export type MapMode = 'all' | 'orphans' | 'hubs' | 'neighbourhood' | 'blast' | 'islands'

/**
 * Per-mode drawing, in terms both renderers can honour: `scale` multiplies a
 * node's RADIUS, `group` says which notes are drawn alike, `hot` names link
 * edges to draw in front. Dimming is `matched` and stays where it was.
 */
export interface MapEmphasis {
  scale?: Record<number, number>
  /** 0 for the palest note, 1 for the busiest — a colour ramp, not a colour. */
  shade?: Record<number, number>
  group?: Record<number, number>
  hot?: string[]
}

export function edgeKey(from: number, to: number): string {
  return `link-${from}-${to}`
}

/** Links either way. Suggestions are not links, and never count here. */
export function linkDegree(edges: GraphEdge[]): Map<number, number> {
  const counts = new Map<number, number>()
  for (const edge of edges) {
    if (edge.kind !== 'link') continue
    counts.set(edge.from, (counts.get(edge.from) ?? 0) + 1)
    counts.set(edge.to, (counts.get(edge.to) ?? 0) + 1)
  }

  return counts
}

/**
 * Nothing points at them and they point at nothing, so an assistant following
 * links never arrives and never leaves. A note whose only wiki-links lead
 * nowhere is one of these: an unresolved link is not an edge.
 */
export function orphans(nodes: GraphNode[], degree: Map<number, number>): number[] {
  return nodes.filter((n) => (degree.get(n.id) ?? 0) === 0).map((n) => n.id)
}

const SCALE_FLOOR = 0.7
const SCALE_CEILING = 2.3

/**
 * Each count against the largest, as 0 to 1. Square-rooted, because a hub with
 * forty links beside one with four is eight times the disc at a linear scale
 * and the map becomes one dot.
 */
export function weightedRatio(counts: Map<number, number>): Record<number, number> {
  const top = Math.max(1, ...counts.values())
  const out: Record<number, number> = {}
  for (const [id, count] of counts) out[id] = Math.sqrt(Math.max(0, count) / top)

  return out
}

/**
 * The same ratio as a radius, floored above zero: a node scaled to nothing has
 * been deleted rather than de-emphasised.
 */
export function weightedScale(counts: Map<number, number>): Record<number, number> {
  const out: Record<number, number> = {}
  for (const [id, ratio] of Object.entries(weightedRatio(counts))) {
    out[Number(id)] = SCALE_FLOOR + (SCALE_CEILING - SCALE_FLOOR) * ratio
  }

  return out
}

/**
 * The linked collection cut into pieces, largest first. Singletons are pieces
 * too — an orphan is an island of one — and the caller decides whether to say
 * so, because "eleven islands" is a different claim from "two".
 */
export function components(nodes: GraphNode[], edges: GraphEdge[]): number[][] {
  const parent = new Map<number, number>()
  const find = (id: number): number => {
    const up = parent.get(id)
    if (up === undefined || up === id) return id
    const root = find(up)
    parent.set(id, root)

    return root
  }
  for (const n of nodes) parent.set(n.id, n.id)
  for (const edge of edges) {
    if (edge.kind !== 'link') continue
    const a = find(edge.from)
    const b = find(edge.to)
    if (a !== b) parent.set(a, b)
  }

  const groups = new Map<number, number[]>()
  for (const n of nodes) {
    const root = find(n.id)
    const members = groups.get(root)
    if (members === undefined) groups.set(root, [n.id])
    else members.push(n.id)
  }

  return [...groups.values()].sort((a, b) => b.length - a.length || (a[0] ?? 0) - (b[0] ?? 0))
}

/**
 * How far each note sits from the centre, following links either way, out to
 * `maxHop`. The centre is hop 0 and is always present.
 */
export function hopsFrom(edges: GraphEdge[], centre: number, maxHop: number): Map<number, number> {
  const neighbours = new Map<number, number[]>()
  const join = (from: number, to: number) => {
    const list = neighbours.get(from)
    if (list === undefined) neighbours.set(from, [to])
    else list.push(to)
  }
  for (const edge of edges) {
    if (edge.kind !== 'link') continue
    join(edge.from, edge.to)
    join(edge.to, edge.from)
  }

  const hops = new Map<number, number>([[centre, 0]])
  let frontier = [centre]
  for (let hop = 1; hop <= maxHop && frontier.length > 0; ++hop) {
    const next: number[] = []
    for (const near of frontier) {
      for (const far of neighbours.get(near) ?? []) {
        if (hops.has(far)) continue
        hops.set(far, hop)
        next.push(far)
      }
    }
    frontier = next
  }

  return hops
}

const HOP_SCALE = [2.1, 1.25, 0.85]

export function hopScale(hops: Map<number, number>): Record<number, number> {
  const out: Record<number, number> = {}
  for (const [id, hop] of hops) out[id] = HOP_SCALE[Math.min(hop, HOP_SCALE.length - 1)] ?? 1

  return out
}

/**
 * Island colours. Mid-lightness on purpose: one palette has to read on the
 * light ground and the dark one, and a per-theme set would mean an island
 * changing colour when the theme does. Comma notation because cytoscape's own
 * colour parser rejects the space-separated form CSS now prefers.
 */
const GROUP_HUES = [210, 152, 275, 33, 328, 95, 188, 15]

export function groupColour(index: number): string {
  return `hsl(${GROUP_HUES[index % GROUP_HUES.length]}, 48%, 58%)`
}
