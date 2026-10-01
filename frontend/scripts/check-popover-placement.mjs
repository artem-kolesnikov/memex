#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: a help panel must land on screen, wherever its tip happens to sit.
 *
 * The third of these scripts, and it exists for the same reason as the other
 * two: the thing that broke is geometry in a browser, and `npm run build` was
 * green throughout. `HelpTip` opened leftwards from a fixed `right: 0`, so the
 * one in the notes list — second column of a table, 100px from the left edge —
 * put 200px of a 320px panel off the screen at a 1280px DESKTOP viewport. Four
 * other call sites had already worked around it by hand with `align="left"`,
 * which is what a component doing an author's arithmetic for them looks like
 * just before somebody adds a fifth.
 *
 * What is guarded is the arithmetic, not the CSS: `popoverLeftOffset` is pure,
 * so this runs it directly rather than reasoning about a stylesheet. Node 22
 * strips the types on the way in, which is why there is no build step here and
 * no second copy of the function to drift.
 *
 * Mutation-tested 2026-08-23: inverting the right-edge branch, dropping the
 * left clamp, and reordering the two clamps each fail at least one case.
 *
 * The VERTICAL cases arrived the same day, with the second half of the same
 * bug. The panel is `fixed` and teleported to <body> now — it had to be, or a
 * scroll container clips it — and a fixed panel that hangs below the fold
 * cannot be scrolled to the way an absolute one could. So `popoverTop` flips
 * above the tip when it must, and these cases are what say when it must.
 */
import { popoverLeftOffset, popoverTop } from '../src/lib/popoverPlacement.ts'

const PANEL = 320
const PANEL_H = 160
const MARGIN = 8
const GAP = 6

/** @type {{ what: string, input: object, expect: (left: number) => string | null }[]} */
const CASES = [
  {
    // The bug, reproduced as a number — and asserted on BOTH properties,
    // because either alone lets the old behaviour through. "On screen" alone
    // passes for `right: 0` once the left clamp drags it back to the margin;
    // "flush" alone says nothing about a tip near an edge. Together they pin
    // the case that was actually measured broken.
    what: 'the notes-list tip, 100px from the left edge of a 1280px desktop',
    input: { hostLeft: 100, panelWidth: PANEL, viewportWidth: 1280 },
    expect: (left) => {
      if (100 + left < MARGIN) return `left edge lands at ${100 + left}px`
      return left === 0 ? null : `opens leftwards from the tip (offset ${left}) where there is room to the right`
    },
  },
  {
    what: 'a tip hard against the right edge of a 1280px desktop',
    input: { hostLeft: 1240, panelWidth: PANEL, viewportWidth: 1280 },
    expect: (left) =>
      1240 + left + PANEL <= 1280 - MARGIN
        ? null
        : `right edge lands at ${1240 + left + PANEL}px of 1280`,
  },
  {
    what: 'a tip in the middle of a phone, where the panel is wider than half the screen',
    input: { hostLeft: 200, panelWidth: PANEL, viewportWidth: 390 },
    expect: (left) => (200 + left >= MARGIN ? null : `left edge lands at ${200 + left}px`),
  },
  {
    what: 'a viewport NARROWER than the panel — the start of the text must be on screen',
    input: { hostLeft: 40, panelWidth: PANEL, viewportWidth: 300 },
    expect: (left) => (40 + left === MARGIN ? null : `left edge lands at ${40 + left}px, not ${MARGIN}`),
  },
  {
    what: 'a tip with room to spare stays flush with its icon rather than drifting',
    input: { hostLeft: 400, panelWidth: PANEL, viewportWidth: 1280 },
    expect: (left) => (left === 0 ? null : `drifted by ${left}px for no reason`),
  },
]

/** @type {{ what: string, input: object, expect: (top: number) => string | null }[]} */
const VERTICAL_CASES = [
  {
    what: 'a tip near the top of an 800px window opens below it, as it should',
    input: { hostTop: 100, hostBottom: 120, panelHeight: PANEL_H, viewportHeight: 800 },
    expect: (top) => (top === 120 + GAP ? null : `opened at ${top} rather than just below the tip`),
  },
  {
    what: 'a tip near the BOTTOM, where a fixed panel below it would be cut off',
    input: { hostTop: 700, hostBottom: 720, panelHeight: PANEL_H, viewportHeight: 800 },
    expect: (top) => {
      if (top + PANEL_H > 800 - MARGIN) return `bottom edge lands at ${top + PANEL_H}px of 800`
      return top === 700 - GAP - PANEL_H ? null : `flipped to ${top} rather than sitting above the tip`
    },
  },
  {
    // The case that catches "flip whenever it does not fit". It does not fit
    // below here either — but there are 22px above and 636 below, so flipping
    // would put MORE of the panel off screen, not less. Written after the
    // first version of this case passed against a mutant that always flipped:
    // it had chosen numbers where the panel fitted below all along, so the
    // branch it meant to test never ran.
    what: 'a tall panel near the top of a short window — does not fit below, but above is worse, so it stays',
    input: { hostTop: 30, hostBottom: 50, panelHeight: 700, viewportHeight: 700 },
    expect: (top) => (top === 50 + GAP ? null : `flipped to ${top} where above is the smaller side`),
  },
  {
    what: 'a window too short for the panel on either side — the text must START on screen',
    input: { hostTop: 300, hostBottom: 320, panelHeight: 600, viewportHeight: 500 },
    expect: (top) => (top >= MARGIN ? null : `top edge lands at ${top}px`),
  },
]

let failed = 0
for (const c of CASES) {
  const left = popoverLeftOffset(c.input)
  const problem = c.expect(left)
  if (problem !== null) {
    console.error(`FAIL  ${c.what}\n      ${problem} (offset ${left})`)
    failed++
  }
}
for (const c of VERTICAL_CASES) {
  const top = popoverTop(c.input)
  const problem = c.expect(top)
  if (problem !== null) {
    console.error(`FAIL  ${c.what}\n      ${problem} (top ${top})`)
    failed++
  }
}

if (failed > 0) {
  console.error(`\n${failed} popover placement case(s) put a panel off screen.`)
  process.exit(1)
}
console.log(
  `Popover placement passed: ${CASES.length} horizontal + ${VERTICAL_CASES.length} vertical cases, every panel on screen.`,
)
