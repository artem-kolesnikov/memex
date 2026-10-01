/**
 * Where an absolutely-positioned panel should sit relative to the thing that
 * opened it, so that it lands on screen.
 *
 * ## The bug this replaces
 *
 * `HelpTip` used to take an `align` prop — `right` by default — which resolved
 * to a bare `right: 0` or `left: 0` in CSS. That is a side chosen when the
 * component is WRITTEN, by an author who cannot know where on the page the
 * instance will end up. The notes list put one in the second column of a
 * table, 100px from the left edge, and `right: 0` opened a 320px panel
 * leftwards from there: measured 2026-08-23 at a 1280px desktop viewport, its
 * left edge was at **-200px**. Two thirds of the help text was off the screen,
 * on a desktop, not only on a phone.
 *
 * Five call sites had already worked around it by passing `align="left"` by
 * hand. That is the tell: a prop that every author has to reason about, and
 * that is wrong whenever the layout moves, is a calculation the component
 * should be doing. The prop is gone.
 *
 * ## The second bug, and why the panel now escapes its parent
 *
 * Landing inside the viewport is not enough if an ancestor clips. The
 * connections table lives in a `.table-responsive`, which is `overflow-x:
 * auto`, and an absolutely-positioned descendant of a scroll container is both
 * CLIPPED by it and COUNTED as its scrollable content. Measured 2026-08-23 on
 * the Curator column's tip: the panel's own arithmetic put it correctly on
 * screen (right edge 1335 of a 1408px viewport), and the wrapper cut 143px off
 * it anyway — while adding 158px of scrollWidth to a table that had none, so a
 * horizontal scrollbar appeared under it and stole 15px of height.
 *
 * So the panel is teleported to `<body>` and positioned `fixed`, which is why
 * both coordinates are computed here now. Fixed positioning brings its own
 * question — the page can no longer scroll to reveal a panel that hangs below
 * the fold — which is what {@see popoverTop} answers.
 *
 * ## The horizontal rule
 *
 * Prefer the panel's left edge under the tip — a panel that starts where you
 * are looking reads better than one that ends there. If that pushes it past
 * the right edge, slide it back by exactly the overshoot rather than flipping
 * sides, which keeps it as close to the tip as it can be. Clamp against the
 * left edge last, so on a viewport narrower than the panel the left margin
 * wins and the text starts on screen rather than ending on it.
 *
 * Pure and side-effect free so it can be exercised without a browser:
 * `frontend/scripts/check-popover-placement.mjs` is the guard.
 */
export interface PlacementInput {
  /** Viewport x of the element the panel hangs off. */
  hostLeft: number
  /** Rendered width of the panel. */
  panelWidth: number
  /** Width of the viewport the panel has to land inside. */
  viewportWidth: number
  /** How close to either edge the panel may come. */
  margin?: number
}

/**
 * The `left` offset, in pixels, relative to the host element.
 *
 * Returned rather than applied: the caller writes it to `style.left`, and a
 * number is a thing a test can assert about.
 */
export function popoverLeftOffset({
  hostLeft,
  panelWidth,
  viewportWidth,
  margin = 8,
}: PlacementInput): number {
  // Start flush with the tip.
  let left = 0

  // Past the right edge? Slide back by the overshoot, no further.
  const overshootRight = hostLeft + left + panelWidth - (viewportWidth - margin)
  if (overshootRight > 0) {
    left -= overshootRight
  }

  // Past the left edge? Push in. Last, so it wins on a viewport too narrow to
  // satisfy both — the beginning of a sentence is the half worth keeping.
  const overshootLeft = margin - (hostLeft + left)
  if (overshootLeft > 0) {
    left += overshootLeft
  }

  return left
}

export interface VerticalInput {
  /** Viewport y of the host's top edge. */
  hostTop: number
  /** Viewport y of the host's bottom edge. */
  hostBottom: number
  /** Rendered height of the panel. */
  panelHeight: number
  /** Height of the viewport the panel has to land inside. */
  viewportHeight: number
  /** The breathing space between host and panel. */
  gap?: number
  /** How close to either edge the panel may come. */
  margin?: number
}

/**
 * The panel's `top`, in VIEWPORT coordinates.
 *
 * Below the tip by default, because that is where a panel opened from an icon
 * is expected. Flipping above is not a preference but a necessity of `fixed`
 * positioning: an absolutely-positioned panel that hung below the fold could be
 * scrolled to, and a fixed one cannot — it is simply cut off. So it flips only
 * when there is not room below AND there is more room above, which keeps the
 * ordinary case still and moves only the case that would otherwise be unread.
 *
 * The top clamp is last for the same reason the left clamp is: on a viewport
 * too short for either side, the beginning of the text is the half worth
 * keeping.
 */
export function popoverTop({
  hostTop,
  hostBottom,
  panelHeight,
  viewportHeight,
  gap = 6,
  margin = 8,
}: VerticalInput): number {
  const below = hostBottom + gap
  const fitsBelow = below + panelHeight <= viewportHeight - margin
  const roomAbove = hostTop - margin
  const roomBelow = viewportHeight - margin - below

  let top = below
  if (!fitsBelow && roomAbove > roomBelow) {
    top = hostTop - gap - panelHeight
  }

  return Math.max(margin, top)
}
