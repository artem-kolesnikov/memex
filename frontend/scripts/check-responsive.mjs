#!/usr/bin/env node
// Fails if the CSS reintroduces the rule that made memex.tools unusable on a
// phone, or if a view lays out with columns that cannot stack.
//
// The history this guards: from the mm2 restyle on 2026-08-05 until
// 2026-08-22, `main.css` said
//
//     .container { min-width: 1200px !important; }
//
// and `.container` wraps every page. So every route was 1200px wide on every
// device, and a 390px phone showed a window onto it — the whole site scrolled
// sideways, header and nav included. Nobody noticed for two and a half weeks
// because the operator works on a desktop, and nothing in CI looks at a width.
//
// That is the shape of defect worth a guard rather than a memory: invisible to
// `npm run build`, invisible to the type-checker, invisible to anyone not
// holding a phone.
//
// Three rules, each narrow enough to be worth trusting:
//
//   1. No layout wrapper may carry a `min-width` in pixels. A max-width is how
//      you say "do not get too wide"; a min-width is how you say "never fit".
//   2. No view may use a bare `col-N` grid class. Bootstrap's `col-4` is four
//      twelfths at EVERY width, phone included; `col-12 col-lg-4` is the same
//      thing that stacks. The bare form is nearly always somebody thinking
//      about a desktop, and it is what left the note body 180px wide.
//   3. No `<col>` may carry both a `d-none` class and a width. `display: none`
//      makes the width contribute nothing to a fixed-layout table, so it is
//      silently discarded and the table divides itself equally instead.
//
// Usage: node scripts/check-responsive.mjs

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const root = new URL('..', import.meta.url).pathname
const failures = []

/** Every file under a directory with one of these extensions. */
function walk(path, exts) {
  const st = statSync(path)
  if (st.isFile()) return exts.some(e => path.endsWith(e)) ? [path] : []
  return readdirSync(path).flatMap(name => walk(join(path, name), exts))
}

// --- Rule 1: no min-width on a layout wrapper -------------------------------
for (const file of walk(join(root, 'src/assets'), ['.css'])) {
  const css = readFileSync(file, 'utf8')
  // Strip comments so the explanation of this very rule does not trip it.
  const code = css.replace(/\/\*[\s\S]*?\*\//g, '')
  const re = /(^|})\s*([^{}]*\b(?:container|row|col|body|html)\b[^{}]*)\{([^}]*)\}/g
  let m
  while ((m = re.exec(code)) !== null) {
    const [, , selector, body] = m
    const bad = /min-width\s*:\s*(\d{3,})px/.exec(body)
    if (bad) {
      failures.push(
        `${relative(root, file)}: \`${selector.trim()}\` sets min-width: ${bad[1]}px — ` +
        'a layout wrapper with a pixel floor cannot fit a phone. Use max-width.'
      )
    }
  }
}

// --- Rule 2: no bare col-N in a view ---------------------------------------
for (const file of walk(join(root, 'src'), ['.vue'])) {
  const source = readFileSync(file, 'utf8')
  for (const [i, line] of source.split('\n').entries()) {
    // `col-4` yes; `col-md-4`, `col-sm-6`, `col-12 col-lg-4` no.
    const m = /class="[^"]*\bcol-(\d{1,2})\b[^"]*"/.exec(line)
    if (!m) continue
    const classes = /class="([^"]*)"/.exec(line)[1]
    const bare = classes.split(/\s+/).filter(c => /^col-\d{1,2}$/.test(c))
    const responsive = classes.split(/\s+/).some(c => /^col-(sm|md|lg|xl|xxl)-\d{1,2}$/.test(c))
    // `col-12` alone is full width at every size, which is already responsive.
    const meaningful = bare.filter(c => c !== 'col-12')
    if (meaningful.length > 0 && !responsive) {
      failures.push(
        `${relative(root, file)}:${i + 1}: \`${meaningful[0]}\` with no breakpoint — ` +
        'this keeps its fraction on a phone. Pair it, e.g. `col-12 col-lg-4`.'
      )
    }
  }
}

// --- Rule 3: no width on a <col> that Bootstrap hides -----------------------
//
// `.d-none` is `display: none !important`, and **a <col> that is display:none
// contributes no width to a fixed-layout table**. Declaring both is therefore
// a width that is silently discarded — no error, no warning, nothing in the
// build.
//
// What it looked like when it happened (2026-08-23, operator's screenshot):
// the notes list declared 34/34/auto/260/110/120 across six columns and hid
// four of them with `d-none d-*-table-column`. Five widths were thrown away,
// the browser split the table into six EQUAL columns, and the word "verified"
// got exactly as much room as a title wrapped onto three lines.
//
// The fix is not to remove `d-none` — it is to put the width on the <th>,
// which is genuinely absent when hidden, so the width leaves with the column.
// Hence the narrow rule: a <col> may hide, or it may size, but not both.
for (const file of walk(join(root, 'src'), ['.vue'])) {
  const source = readFileSync(file, 'utf8')
  for (const [i, line] of source.split('\n').entries()) {
    if (!/<col\b/.test(line)) continue
    if (!/\bd-none\b/.test(line)) continue
    if (!/width\s*:\s*\d/.test(line)) continue
    failures.push(
      `${relative(root, file)}:${i + 1}: a <col> carries both \`d-none\` and a width — ` +
      'display:none makes that width contribute nothing, so a fixed-layout table ' +
      'divides itself equally instead. Put the width on the <th>.'
    )
  }
}

// --- Rule 4: exactly one account menu at every width ------------------------
//
// Reaching every destination on a phone.
//
// The header's account menu used to be a Bootstrap `dropdown` INSIDE the
// collapsed navbar. On a wide screen that was fine; below `lg` the collapse
// stacked, which put the toggle at the left edge while `dropdown-menu-end`
// went on right-aligning the menu to it. At 390px it opened at left: -17px,
// and rather more than that on the operator's own phone (2026-08-28).
//
// The shell is a sidebar now, and below `lg` it is a sheet that slides over
// the canvas from a control in the top bar. That removes the nested-dropdown
// shape entirely and leaves ONE list of destinations rather than two copies
// that could drift. Four things have to stay true, and each can be deleted
// without the build noticing:
//
//   1. There is a control that opens the sheet below `lg`. Without it a phone
//      cannot reach anything, because the sidebar is translated off-screen.
//   2. The sidebar renders both lists. Drop either and a phone loses the four
//      workspace destinations, or Settings and Deleted Notes.
//   3. The stylesheet actually makes it a sheet under a max-width query. A
//      sidebar that stays a 232px rail leaves 158px of a 390px screen for the
//      page, which is the defect this whole check exists for.
//   4. No `dropdown-menu-end` survives in the shell. That is the exact shape
//      that opened off the left edge, and it must not come back.
//
// Geometry is not what is checked here, deliberately. `check-wide-content.mjs`
// measures in a browser and could not fail on this one: a sheet fits at any
// width whether or not it can be opened. What went wrong was structural, so
// this is a structural check.
{
  const app = join(root, 'src/App.vue')
  const source = readFileSync(app, 'utf8')
  const css = readFileSync(join(root, 'src/assets/app.css'), 'utf8')

  if (!/class="app-topbar"/.test(source)) {
    failures.push(
      'src/App.vue: no `.app-topbar`. Below `lg` the sidebar is translated off-screen, so the ' +
      'bar holding its toggle is the only way to open it.'
    )
  } else if (!/menuOpen = !menuOpen/.test(source) || !/:aria-expanded="menuOpen"/.test(source)) {
    failures.push(
      'src/App.vue: the top bar has no control that opens the sidebar and reports it with ' +
      '`aria-expanded`. A phone cannot reach any destination without one.'
    )
  }

  for (const list of ['WORKSPACE_LINKS', 'ACCOUNT_LINKS']) {
    if (!new RegExp('v-for="link in ' + list + '"').test(source)) {
      failures.push(
        `src/App.vue: the sidebar does not render ${list}. It is the only navigation at every ` +
        'width, so a list it does not render is a set of destinations nobody can reach on a phone.'
      )
    }
  }

  if (!/@media \(max-width: 991\.98px\)[\s\S]{0,900}?\.app-sidebar\s*\{[^}]*position:\s*fixed/.test(css)) {
    failures.push(
      'src/assets/app.css: `.app-sidebar` is not taken out of flow under a max-width query. As a ' +
      'grid column it keeps 232px of a 390px screen and leaves 158px for the page.'
    )
  }

  if (/dropdown-menu-end/.test(source)) {
    failures.push(
      'src/App.vue: a `dropdown-menu-end` is back in the shell. Right-aligning a menu to a toggle ' +
      'at the left edge is what opened it at left: -17px on a phone.'
    )
  }
}

if (failures.length > 0) {
  console.error('Responsive check failed:\n')
  for (const f of failures) console.error('  ' + f)
  console.error(
    '\nmemex.tools was 1200px wide on every device from 2026-08-05 to 2026-08-22 ' +
    'because one rule said so and nothing looked.'
  )
  process.exit(1)
}

console.log('Responsive check passed: no pixel floors on layout wrappers, no unbreakable columns, no widths on hidden <col>s, one account menu at every width.')
