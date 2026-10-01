#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: what the map's view modes claim about a collection is true of it.
 *
 * Each mode makes a factual claim — these notes are unreachable, these are the
 * pieces, this is two links out — and every one of them fails silently. A map
 * that calls a linked note an orphan draws a dot in the right place with the
 * wrong meaning, and nothing in a build, a type-check or a screenshot says so.
 * The arithmetic is pure and lives in `src/lib/mapRadar.ts`, so it is run here
 * directly rather than reasoned about through a canvas.
 *
 * Mutation-tested 2026-09-11: dropping the `kind !== 'link'` guard in
 * `linkDegree`, following links one way only in `hopsFrom`, and counting
 * singletons as islands each fail at least one case below.
 */
import {
  components,
  edgeKey,
  hopsFrom,
  linkDegree,
  orphans,
  weightedScale,
} from '../src/lib/mapRadar.ts'

const node = (id) => ({ id, title: `Note ${id}`, status: 'verified', summary: null, tags: [], updated_at: null, hop: null, defects: [], flagged: false })
const link = (from, to) => ({ from, to, kind: 'link' })
const near = (from, to) => ({ from, to, kind: 'semantic', distance: 0.2 })

let failed = 0
const check = (what, condition) => {
  if (condition) return
  console.error(`FAIL  ${what}`)
  failed++
}

// 1 → 2 → 3, 4 alone, 5 ↔ 6, and a suggestion between 4 and 1.
const NODES = [1, 2, 3, 4, 5, 6].map(node)
const EDGES = [link(1, 2), link(2, 3), link(5, 6), near(4, 1)]

const degree = linkDegree(EDGES)
const alone = orphans(NODES, degree)

check('a note nothing links to and that links to nothing is an orphan', alone.includes(4))
check('a note that only links away is not an orphan', !alone.includes(1))
check('a note that is only linked to is not an orphan', !alone.includes(3))
check('a suggestion does not rescue a note from being an orphan', alone.length === 1)

const pieces = components(NODES, EDGES)
check('the pieces come back largest first', pieces[0].length === 3)
check('a pair with no bridge is its own piece', pieces.some((p) => p.length === 2 && p.includes(5)))
check('an orphan is a piece of one', pieces.some((p) => p.length === 1 && p[0] === 4))
check('every note lands in exactly one piece', pieces.flat().length === NODES.length)
check(
  'a suggestion never joins two pieces',
  pieces.filter((p) => p.length > 1).length === 2,
)

const hops = hopsFrom(EDGES, 1, 2)
check('the centre is hop zero', hops.get(1) === 0)
check('a link away is one hop', hops.get(2) === 1)
check('two links away is two hops', hops.get(3) === 2)
check('the walk stops at the depth asked for', !hops.has(5) && hops.size === 3)

// Links are followed AGAINST their direction too: what an assistant pulls in
// from a note includes the notes that cite it.
const backwards = hopsFrom(EDGES, 3, 2)
check('a link is followed backwards as well as forwards', backwards.get(1) === 2)

const scale = weightedScale(new Map([[1, 0], [2, 4], [3, 16]]))
check('the busiest note is the biggest', scale[3] > scale[2] && scale[2] > scale[1])
check('a note with no links is still drawn', scale[1] > 0)
// Measured above the floor, where the difference lives: four times the links
// is twice the radius under a square root and four times it under a line.
check('the scale is square-rooted, not linear', (scale[3] - scale[1]) / (scale[2] - scale[1]) < 3)

check('an edge key carries its direction', edgeKey(7, 8) === 'link-7-8' && edgeKey(8, 7) === 'link-8-7')

if (failed > 0) {
  console.error(`\n${failed} map radar case(s) would draw a claim that is not true.`)
  process.exit(1)
}
console.log('Map radar passed: orphans, islands, hops, hub sizing and edge keys all answer what they claim.')
