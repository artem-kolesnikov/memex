#!/usr/bin/env node
/**
 * Guard: nothing may lay out past the right edge of a phone.
 *
 * The fourth of these scripts, and the first that needs a browser to have an
 * opinion at all — the other three read source, or run a pure function.
 *
 * WHY A FOURTH ONE. `check-responsive.mjs` reads the CSS for two shapes that
 * cannot fit a phone, and a per-route sweep measures whether the DOCUMENT
 * scrolls sideways. Both were green on `/inbox` on 2026-08-28 while the
 * Approve and Apply buttons sat at x = 398 in a 390px viewport, off the screen.
 * Page overflow was ZERO the whole time, and correctly so: the table carried
 * its own `overflow-x: auto`, so the document never moved. The content still
 * did not fit.
 *
 * That is the hole. `scrollWidth - clientWidth` answers "does the page scroll
 * sideways", which is a real question and not this one. The question here is
 * "does anything END past the edge", and the two are different whenever
 * something between the element and the document has a scroller — which is the
 * normal case for exactly the wide things worth checking.
 *
 * WHAT IT ASSERTS. Two things per fixture, and they catch different defects:
 *
 *   1. The document must not scroll sideways. This is the 2026-08-22 rule, and
 *      it is what an unbreakable title or a bare URL violates.
 *   2. No CONTROL may end past the right edge — a link, a button, a field.
 *      Content is allowed its own scroller (a code block scrolls, and should:
 *      a wrapped command no longer says what it says). A DECISION is not: a
 *      button you must scroll a table sideways to discover is a button that is
 *      not there. That distinction is the whole point of this script, and it is
 *      the line the operator drew on 2026-08-28 when he chose stacked review
 *      cards over merely moving one card's buttons.
 *
 * WHAT IT MEASURES, AND WHAT IT THEREFORE DOES NOT. Each case is a small
 * fixture styled by the REAL stylesheets — `bootstrap.min.css` and `main.css`,
 * read from disk and inlined — laid out in headless Chromium at 390x844. So
 * what is guarded is the RULE: mutate the rule a case names and the case fails.
 *
 * THE STYLESHEETS ARE CHECKED TO HAVE APPLIED, before anything is measured.
 * The first version of this script linked them with `file://` URLs from a
 * `setContent` page, where they silently did not load — so four of its six
 * cases measured UNSTYLED markup and reported "ok". A guard written against
 * vacuous passes that opens with one is not a story worth repeating twice, so
 * the canary below fails loudly rather than measuring an unstyled page.
 *
 * The canary itself then had to be fixed, and the reason is worth keeping: its
 * first version asked whether `.note-body` had a line-height other than
 * `normal`, which BOOTSTRAP already guarantees by setting one on `body`. It
 * would have passed with main.css entirely absent. It now checks one
 * declaration from Bootstrap, one from the head of main.css, and one from its
 * tail INSIDE the phone media query — so a parse error halfway down the file,
 * which is the realistic way to lose exactly the rules under test, is caught
 * rather than measured.
 *
 * It is deliberately NOT a sweep of the running app. That would need the SPA
 * built, the backend up, a database, a session and seeded content in CI, and it
 * is the thing worth building the day this catches its second defect. Until
 * then the honest description is: these rules are pinned, those routes are not.
 * A fixture that stops matching the component it stands for is the failure mode
 * to watch, so each one is written as small as the rule allows.
 *
 * EVERY CASE HERE IS MUTATION-TESTED (2026-08-28): delete the rule it names and
 * it fails. TWO candidate cases were written and then REMOVED for failing that
 * test, and both are worth knowing about:
 *
 *   - The phone header menu. A flat list of rows fits at 390px whether or not a
 *     single one of its rules applies, so the case could only ever pass. What
 *     the header needed was a structural check, and it has one in
 *     `check-responsive.mjs` (rule 4).
 *   - The admin invite row's three action buttons. They overflowed in the TABLE
 *     form, at x = 469, 555 and 642 — but the fix for that is the row stacking
 *     at all, which other cases already pin. Once stacked, three small buttons
 *     fit on one line at 390px with or without the rule that gives them the
 *     full width, so the rule is a preference and the case proved nothing.
 *
 * A guard that cannot fail is the thing these scripts exist to catch, and it
 * does not get an exemption for being one of them.
 *
 * Usage: node scripts/check-wide-content.mjs
 */
import { chromium } from 'playwright'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const root = join(here, '..')
const BOOTSTRAP = join(root, 'node_modules/bootstrap/dist/css/bootstrap.min.css')
const MAIN = join(root, 'src/assets/main.css')
const APP = join(root, 'src/assets/app.css')

const WIDTH = 390
const HEIGHT = 844
/** Sub-pixel rounding only. A real defect is tens of pixels, never one. */
const TOLERANCE = 1

/**
 * Each case names the rule it defends, so a failure says what to look at
 * rather than only where.
 */
/**
 * One notes-list row, built the way `SearchView.vue` builds it. A builder
 * rather than hand-copied blocks, so the fixtures drift in one place.
 *
 * Font Awesome is not loaded here, so an `<i>` glyph measures zero.
 */
function notesRow({ title, summary = 'What this note is for, in one line.', flagged = true, actor = 'dev-agent', tags = ['testing'] }) {
  const mark = flagged ? '<i class="app-note-mark fa-solid fa-flag text-warning"></i>' : '<!--v-if-->'
  const by = actor
    ? `<span class="app-note-by"><span class="mm-agent"><span class="mm-agent-mark"><i class="fa-solid fa-robot"></i></span>` +
      `<span class="mm-agent-name">${actor}</span></span></span>`
    : '<!--v-if-->'
  const tagCell = tags.length
    ? `<span class="app-note-tags"><div class="mm-tag-row">${tags.map((t) => `<span class="mm-tag">${t}</span>`).join('')}</div></span>`
    : '<!--v-if-->'
  return `<div class="container"><div class="row"><div class="col"><div class="app-tray">
      <div class="app-note-list">
        <div class="app-note-list-head">
          <div class="dropdown"><a class="dropdown-toggle mm-select-toggle" href="#"></a></div>
          <span class="app-note-list-title">Title</span>
          <span class="app-note-list-modified">Modified</span>
        </div>
        <article class="app-note-row">
          <label class="app-note-check"><input type="checkbox" class="form-check-input"></label>
          <a class="app-note-main" href="#">
            <span class="app-note-title">${mark}${title}</span>
            <span class="app-note-summary">${summary}</span>
          </a>
          <div class="app-note-meta">
            <span class="app-note-date">Aug 26, 2026</span>
            ${by}
            ${tagCell}
          </div>
        </article>
      </div>
    </div></div></div></div>`
}

const CASES = [
  {
    what: 'a note title with no break in it',
    rule: '.mm-note-title { overflow-wrap: anywhere }',
    // 616px of overflow at 390 before the rule existed (2026-08-28). The list
    // table survived the same fixture; the article that shows the same content
    // did not.
    html: `<div class="container"><div class="row my-4"><div class="col">
      <div class="d-flex align-items-center flex-wrap">
        <h1 class="mb-0 mm-note-title">Supercalifragilisticexpialidociousantidisestablishmentarianismfloccinaucinihilipilif</h1>
      </div></div></div></div>`,
  },
  {
    what: 'a bare URL in rendered markdown',
    rule: '.note-body { overflow-wrap: anywhere }',
    // 781px wide inside a 390px screen, as a captured page routinely contains.
    html: `<div class="container"><div class="note-body">
      <p>Source: <a href="#">https://example.com/a/very/long/path/that/never/breaks/anywhere/at/all/because/it/has/no/hyphens/or/slashes?query=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa&amp;more=bbbbbbbbbbbbbbbbbbbbbbbb</a></p>
    </div></div>`,
  },
  {
    what: 'a long code line, which scrolls rather than wrapping',
    // Named honestly after Codex pointed out that main.css contains no
    // `white-space` for `pre` at all: what holds this line is the BROWSER
    // default, and what this case defends is that nothing in main.css overrides
    // it. That is still a rule somebody can break — with `pre-wrap`, which is
    // how a wide code block gets "fixed" — and breaking it fails this case.
    rule: 'nothing in main.css may override the browser default `white-space: pre` on <pre>',
    // The counter-case: the fix above must not be bought by making everything
    // breakable. This asserts the code block STILL SCROLLS, which is the only
    // form of it that can fail — the first version asserted "no overflow" and
    // passed with the rule mutated away, because `pre` gets its scroller from
    // Bootstrap's reboot and its refusal to wrap from `white-space`, neither of
    // which is ours. What a future author can still break is `white-space`, by
    // "fixing" a wide code block with `pre-wrap`. That is what this catches.
    html: `<div class="container"><div class="note-body">
      <pre><code>const averyLongLineOfCodeThatWillNotWrapNaturally = someFunction(argumentOne, argumentTwo, argumentThree, argumentFour)</code></pre>
    </div></div>`,
    mustScroll: 'pre',
    // `mustScroll` alone was not enough: `white-space: normal` with one
    // unbreakable token still scrolls, so the assertion passed on a mutation
    // that had wrapped the command at every space (Codex, 2026-08-28). An
    // inline element reports one client rect PER LINE BOX it spans, so this
    // says "still one line" exactly.
    mustStayOnOneLine: 'pre code',
  },
  {
    what: 'a decision button in a stacked table row',
    rule: '.mm-stack-sm (below md) — the row becomes a card, actions at full width',
    // The defect this whole script exists for. In the table form the button was
    // at x = 398 with the page reporting zero overflow. It was found on the
    // review inbox, which is cards rather than a table since 2026-08-27; the
    // rule still carries the Deleted list, the Activity journal and the
    // connections table, and this fixture stands for those.
    html: `<div class="container"><div class="row"><div class="col">
      <table class="table bg-white small border table-hover mm-stack-sm">
        <colgroup><col style="width: 34px;"><col style="width: 34px;"><col><col style="width: 220px;"><col style="width: 150px;"><col style="width: 130px;"></colgroup>
        <thead><tr><th></th><th></th><th>Note</th><th>Proposed by</th><th>When</th><th class="text-end">Actions</th></tr></thead>
        <tbody><tr>
          <td class="text-center mm-stack-inline"><input class="form-check-input" type="checkbox"></td>
          <td class="text-center mm-stack-inline"><a href="#">&gt;</a></td>
          <td><span class="mm-proposal-chip me-2">Edit</span><strong>Rewrote the summary so it says what the note is for</strong></td>
          <td data-label="Proposed by">dev-mobile-agent</td>
          <td class="nowrap" data-label="When">Aug 25, 2026, 10:35 PM</td>
          <td class="text-end mm-stack-actions"><div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-success nowrap">Apply</button>
            <button type="button" class="btn btn-outline-danger">x</button>
          </div></td>
        </tr></tbody>
      </table>
    </div></div></div>`,
  },
  {
    what: 'a stacked table row holding a title of the maximum legal length',
    rule: '.mm-stack-sm > tbody > tr > td { overflow-wrap: anywhere }',
    // Codex found this in review of the commit that added the stacking, and it
    // is the same omission that commit was fixing one file away. A note title
    // may be 500 characters and need contain no space; the fixture below is
    // exactly that, as a LINK, which is what the Deleted list renders.
    // Measured before the rule: the anchor ended at x = 6489 and the document
    // scrolled 6114px. The earlier fixture used a short title, which is why the
    // suite was green over it.
    html: `<div class="container"><div class="row"><div class="col">
      <table class="table bg-white small border table-striped table-hover mm-stack-sm">
        <thead><tr><th></th><th></th><th>Title</th><th>Tags</th><th>When</th><th class="text-end">Actions</th></tr></thead>
        <tbody><tr>
          <td class="text-center mm-stack-inline"><input class="form-check-input" type="checkbox"></td>
          <td class="text-center text-muted mm-stack-inline">*</td>
          <td><span class="text-muted me-1">#14</span><a href="#">${'W'.repeat(500)}</a></td>
          <td data-label="Tags"><span class="mm-tag me-1">testing</span></td>
          <td class="nowrap" data-label="When">Aug 26, 2026, 02:35 AM</td>
          <td class="text-end mm-stack-actions"><div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-success nowrap">Approve</button>
            <button type="button" class="btn btn-outline-danger">x</button>
          </div></td>
        </tr></tbody>
      </table>
    </div></div></div>`,
  },
  {
    what: 'a stacked table row naming a token whose display name is 80 characters',
    rule: '.mm-stack-sm > tbody > tr > td { overflow-wrap: anywhere }',
    // The second half of the same finding. `ApiToken` allows 80 characters in a
    // display name with no requirement that any of them be a space, and every
    // table that names who did something prints it unwrapped.
    html: `<div class="container"><div class="row"><div class="col">
      <table class="table bg-white small border table-hover mm-stack-sm">
        <thead><tr><th></th><th></th><th>Note</th><th>Proposed by</th><th>When</th><th class="text-end">Actions</th></tr></thead>
        <tbody><tr>
          <td class="text-center mm-stack-inline"><input class="form-check-input" type="checkbox"></td>
          <td class="text-center mm-stack-inline"><a href="#">&gt;</a></td>
          <td><span class="mm-proposal-chip me-2">Edit</span><strong>An edit with an ordinary title</strong></td>
          <td data-label="Proposed by">${'W'.repeat(80)}</td>
          <td class="nowrap" data-label="When">Aug 26, 2026, 02:35 AM</td>
          <td class="text-end mm-stack-actions"><div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-success nowrap">Apply</button>
            <button type="button" class="btn btn-outline-danger">x</button>
          </div></td>
        </tr></tbody>
      </table>
    </div></div></div>`,
  },
  {
    what: 'a notes-list row on a phone, whose metadata moves under the title',
    rule: '.app-note-row (below lg) — two columns, the date, byline and tags beneath the title',
    // On a wide screen the metadata is a 380px column beside the title. Drop
    // the media rule and that column keeps its width on a 390px screen, past
    // the right edge, and the page scrolls sideways to it.
    html: notesRow({ title: 'A note with an ordinary title' }),
    mustBeVisible: ['.app-note-date', '.app-note-by', '.app-note-tags'],
  },
  {
    what: 'a notes-list row whose description is a paragraph',
    rule: '.app-note-summary { white-space: nowrap; text-overflow: ellipsis }',
    // The description is one line, cut with an ellipsis, so a row costs the
    // same height whatever the assistant wrote. Let it wrap and a list of
    // fifty is a page of paragraphs. 104px measured; 120 leaves room for drift.
    html: notesRow({ title: 'A note with an ordinary title', summary: 'A description '.repeat(40) }),
    mustShareALine: [['.app-note-check input', '.app-note-title']],
    maxHeight: { selector: '.app-note-row', px: 120 },
  },
  {
    what: 'a notes-list row whose byline names a token with an 80-character display name',
    rule: '.mm-agent-name { overflow: hidden; text-overflow: ellipsis }',
    // Without the clip the name holds its 80 characters and pushes the
    // metadata past the edge. The name is not a control, so the offscreen
    // sweep cannot see it; the metadata's own scroller can.
    html: notesRow({ title: 'A note with an ordinary title', actor: 'W'.repeat(80) }),
    mustNotScrollSideways: '.app-note-meta',
    maxHeight: { selector: '.app-note-row', px: 120 },
  },
  {
    what: 'a notes-list row for a title of the maximum legal length',
    rule: '.app-note-title { overflow-wrap: anywhere }',
    // 500 characters with no space in them, which the title column allows.
    html: notesRow({ title: 'W'.repeat(500) }),
    // The tray clips its overflow, so the page never scrolls and the sweep
    // sees a link the size of its cell: only the link's own scroller tells.
    mustNotScrollSideways: '.app-note-main',
  },
  // NO CASE FOR THE REVIEW INBOX, AND NONE FOR THE ACTIVITY JOURNAL. Both were
  // tables and are Bootstrap `.card`s on a phone now — the inbox from
  // 2026-08-27, the journal from 2026-08-29 — and `.card` carries
  // `word-wrap: break-word`, so a 500-character title, an 80-character token
  // name and a journal description quoting either all wrap without a rule of
  // ours. Cases were written for both anyway and every one passed with the
  // rule it named deleted. The journal's was checked harder, because it also
  // renders agent markdown: with EVERY overflow-wrap, word-break and word-wrap
  // declaration stripped out of main.css, the card still fits 390px. A fixture
  // that cannot fail is worse than no fixture: it reads as coverage.
  //
  // The `.mm-stack-sm` cases below stay — the Deleted list and two settings
  // panes are still stacked tables.
  {
    what: 'a curation queue row naming a note whose title is 500 characters',
    rule: '.mm-curation-queue > li { overflow-wrap: anywhere }',
    // The C-6 pane lists the top of the curation queue as note LINKS, so it
    // meets the same 500-character unbreakable title the review inbox met one
    // release earlier — and it is not a table, so none of the `.mm-stack-sm`
    // rules above cover it. Written from the column length rather than from
    // the vault: every title the operator writes is a sentence, which is
    // exactly why the vault cannot be the fixture (see app:seed-extremes).
    html: `<div class="container"><div class="row"><div class="col">
      <ul class="mm-curation-queue">
        <li>
          <a href="#">${'W'.repeat(500)}</a>
          <span class="mm-curation-why">no description, no links either way</span>
        </li>
      </ul>
    </div></div></div>`,
  },
  {
    what: 'a curation row carrying an operator flag comment with no break in it',
    rule: '.mm-curation-queue > li { overflow-wrap: anywhere } — inherited by .mm-curation-why',
    // The second half of the case above, and it exists because the first half
    // did not cover it (Codex, 2026-08-29): the fixture protected the TITLE
    // link, so scoping the rule to `.mm-curation-queue a` would have kept that
    // case green while leaving the comment unwrapped. A flag comment is up to
    // CurationFlag::COMMENT_MAX — 5,000 characters — of prose somebody typed,
    // and both this pane and the queue rows print it whole.
    html: `<div class="container"><div class="row"><div class="col">
      <ul class="mm-curation-queue">
        <li>
          <a href="#">A note with an ordinary title</a>
          <span class="mm-curation-why">${'W'.repeat(900)}</span>
        </li>
      </ul>
    </div></div></div>`,
  },
  {
    what: "a curation digest quoting a run-summary that contains an unbreakable string",
    rule: '.mm-digest-runs > li { overflow-wrap: anywhere } — inherited by the quote, which has a scroller of its own',
    // A run-summary is up to 10,000 characters of the curator's OWN prose,
    // quoted whole and never summarised, and agent prose routinely carries a
    // bare URL or a long identifier with no break opportunity in it. The
    // element also keeps its line breaks, which is what makes `overflow-wrap`
    // load-bearing rather than incidental: `pre-wrap` alone will not break a
    // token, and the block would carry the page sideways with it.
    html: `<div class="container"><div class="row"><div class="col">
      <section class="mm-digest">
        <ul class="mm-digest-runs"><li>
          <div class="mm-digest-body">
            <blockquote class="mm-digest-prose">Nightly curator pass.\n\nSource: https://example.com/${'a'.repeat(600)}?x=1</blockquote>
            <div class="small"><a href="#">Show this pass's rows</a></div>
          </div>
        </li></ul>
      </section>
    </div></div></div>`,
    // The page-level reading cannot catch this one and reports 0px throughout:
    // the quote carries `max-height` with `overflow-y: auto`, and CSS computes
    // `overflow-x: auto` alongside it, so the block absorbs any overflow into a
    // scroller of its own. Without this assertion the case passes with the rule
    // mutated away — checked, twice, before it was written.
    mustNotScrollSideways: '.mm-digest-prose',
  },
  {
    what: 'a curation digest listing a note title with no break in it',
    rule: '.mm-digest-runs > li { overflow-wrap: anywhere }',
    // The second half, and it exists for the reason the curation-queue pair
    // does: the case above protects the QUOTE, so scoping the rule to
    // `.mm-digest-prose` alone would keep it green while a 500-character title
    // in the still-defective list dragged the page sideways. Titles are 500
    // characters and need contain no space, and this panel prints them in two
    // of its lists.
    html: `<div class="container"><div class="row"><div class="col">
      <section class="mm-digest">
        <ul class="mm-digest-runs"><li>
          <div class="mm-digest-body">
            <ul class="mm-digest-defects">
              <li><a href="#">${'T'.repeat(500)}</a> <span class="small">— oversized</span></li>
            </ul>
          </div>
        </li></ul>
      </section>
    </div></div></div>`,
  },
]

// Inlined rather than linked. A `setContent` page has no origin a `file://`
// stylesheet will load into, and the failure is silent: the page renders
// unstyled and measures however unstyled markup happens to measure.
const CSS = [BOOTSTRAP, MAIN, APP].map((path) => {
  const css = readFileSync(path, 'utf8')
  if (css.length < 1000) {
    console.error(`check-wide-content: ${path} is ${css.length} bytes — that is not a stylesheet.`)
    process.exit(1)
  }
  return css
})

function page(html) {
  return `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>${CSS[0]}</style>
<style>${CSS[1]}</style>
<style>${CSS[2]}</style>
</head><body>${html}</body></html>`
}

const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: WIDTH, height: HEIGHT } })
const tab = await ctx.newPage()
const failures = []

// The canary. Two declarations that have been in these stylesheets for months
// and are not the subject of any case below, so this says "the CSS arrived",
// never "the rule under test still exists". Without it, a stylesheet that fails
// to load turns every case into a measurement of unstyled markup — which is how
// the first draft of this script reported four passes it had not earned.
await tab.setContent(
  page(`<div class="container">
    <span class="mm-tag">x</span>
    <div class="app-tray">x</div>
    <ul class="mm-mobile-menu"><li class="nav-item"><a class="nav-link mm-mobile-menu-item" href="#">x</a></li></ul>
  </div>`),
  { waitUntil: 'load' },
)
const canary = await tab.evaluate(() => ({
  // Bootstrap, which nothing in main.css sets: `.container` has side padding.
  containerPadding: getComputedStyle(document.querySelector('.container')).paddingLeft,
  // main.css EARLY (~line 1100), and a radius Bootstrap never gives a span.
  tagRadius: getComputedStyle(document.querySelector('.mm-tag')).borderRadius,
  // app.css, which carries the notes list: the tray's corner.
  trayRadius: getComputedStyle(document.querySelector('.app-tray')).borderRadius,
  // main.css LATE, and inside the very media query the cases depend on. A parse
  // error anywhere above this loses it while the two above still pass.
  mobileMenuItemMinHeight: getComputedStyle(document.querySelector('.mm-mobile-menu-item')).minHeight,
}))
const canaryFailures = [
  canary.containerPadding === '0px' ? `bootstrap: .container padding is "${canary.containerPadding}"` : null,
  canary.tagRadius !== '999px' ? `main.css head: .mm-tag border-radius is "${canary.tagRadius}"` : null,
  canary.trayRadius !== '10px' ? `app.css: .app-tray border-radius is "${canary.trayRadius}"` : null,
  canary.mobileMenuItemMinHeight !== '44px'
    ? `main.css tail, inside the phone media query: .mm-mobile-menu-item min-height is "${canary.mobileMenuItemMinHeight}"`
    : null,
].filter(Boolean)
if (canaryFailures.length) {
  console.error(
    'check-wide-content: the stylesheets did not fully apply — ' +
      canaryFailures.join('; ') +
      '. Every measurement below would be of markup that is not styled the way the app is, ' +
      'so none is worth reporting.',
  )
  await browser.close()
  process.exit(1)
}

for (const c of CASES) {
  await tab.setContent(page(c.html), { waitUntil: 'load' })
  const found = await tab.evaluate(
    ([width, tolerance, mustScroll, mustStayOnOneLine, mustBeVisible, mustNotScroll, mustShareALine, mustBeHidden, maxHeight, maxLeft]) => {
      // Only CONTROLS. Content is allowed to be wider than the screen as long
      // as it brought its own scroller — that is what `pre` does, deliberately.
      // A control is not: reaching it must not depend on discovering that some
      // ancestor scrolls sideways.
      const CONTROLS = 'a, button, input, select, textarea, [role="button"], summary'
      const offscreen = []
      document.querySelectorAll(CONTROLS).forEach((el) => {
        const r = el.getBoundingClientRect()
        if (r.width === 0 && r.height === 0) return
        if (r.right > width + tolerance) {
          offscreen.push({
            tag: el.tagName.toLowerCase(),
            cls: (el.className || '').toString().slice(0, 40),
            right: Math.round(r.right),
            text: (el.textContent || '').trim().slice(0, 30),
          })
        }
      })
      const scroller = mustScroll ? document.querySelector(mustScroll) : null
      const oneLine = mustStayOnOneLine ? document.querySelector(mustStayOnOneLine) : null
      /**
       * Missing is not hidden: a selector matching nothing would otherwise look
       * like a cell that correctly stood down, so it fails in both directions.
       * `visibility: hidden` and a zero-size box are not visible either —
       * `.mm-tag-row` sets that visibility on itself while it measures.
       */
      const resolve = (sel) => {
        const els = [...document.querySelectorAll(sel)]
        if (!els.length) return { state: 'missing' }
        const shown = els.filter((el) => {
          const st = getComputedStyle(el)
          if (st.display === 'none' || st.visibility === 'hidden' || st.visibility === 'collapse') return false
          const r = el.getBoundingClientRect()
          return r.width > 0 && r.height > 0
        })
        if (!shown.length) return { state: 'hidden' }
        if (shown.length < els.length) return { state: 'partly', el: shown[0], of: els.length, shown: shown.length }
        return { state: 'shown', el: shown[0] }
      }
      const rendered = (sel) => {
        const r = resolve(sel)
        return r.state === 'shown' || r.state === 'partly' ? r.el : null
      }
      const hidden = (mustBeVisible ?? [])
        .map((sel) => {
          const r = resolve(sel)
          if (r.state === 'shown') return null
          return `${sel} (${r.state === 'missing' ? 'matches nothing — the fixture has drifted' : r.state})`
        })
        .filter(Boolean)
      // The inverse, and it is not pedantry. A cell whose content was moved
      // somewhere else has to actually stand down: leave it rendered and the
      // card shows the same fact twice, on two lines, which is the shape the
      // compacting was undoing.
      const stillShowing = (mustBeHidden ?? [])
        .map((sel) => {
          const r = resolve(sel)
          if (r.state === 'hidden') return null
          return `${sel} (${r.state === 'missing' ? 'matches nothing — the fixture has drifted, so this asserts nothing' : r.state})`
        })
        .filter(Boolean)
      // Overlapping vertically at all, not sharing a top: a checkbox and a text
      // baseline do not share one, and a wrapped inline box reports the union
      // of its line boxes.
      const apart = (mustShareALine ?? [])
        .map(([selA, selB]) => {
          const a = rendered(selA)
          const b = rendered(selB)
          if (!a || !b) return { selA, selB, why: 'one of them is not rendered at all' }
          const ra = a.getBoundingClientRect()
          const rb = b.getBoundingClientRect()
          if (!(ra.bottom <= rb.top || rb.bottom <= ra.top)) return null
          return {
            selA,
            selB,
            why:
              `${selA} occupies y ${Math.round(ra.top)}-${Math.round(ra.bottom)} and ` +
              `${selB} occupies y ${Math.round(rb.top)}-${Math.round(rb.bottom)} — no overlap`,
          }
        })
        .filter(Boolean)
      // An empty inline-block has a zero-width box and still carries its margin,
      // so a cell rendering nothing pushes the title right — invisible to every
      // other reading here, including `mustBeHidden`.
      const tooFarRight = (() => {
        if (!maxLeft) return null
        const r = resolve(maxLeft.selector)
        if (r.state === 'missing') return { sel: maxLeft.selector, why: 'matches nothing — the fixture has drifted' }
        if (!r.el) return { sel: maxLeft.selector, why: 'is not rendered' }
        const left = Math.round(r.el.getBoundingClientRect().left)
        return left > maxLeft.px ? { sel: maxLeft.selector, left, limit: maxLeft.px } : false
      })()
      // A ceiling, in pixels, on something that is supposed to be compact. The
      // only reading here that is about SIZE rather than fit, and it exists
      // because a card can gain a line without overflowing anything at all.
      const tooTall = (() => {
        if (!maxHeight) return null
        const el = document.querySelector(maxHeight.selector)
        if (!el) return { sel: maxHeight.selector, why: 'not rendered' }
        const h = Math.round(el.getBoundingClientRect().height)
        return h > maxHeight.px ? { sel: maxHeight.selector, h, limit: maxHeight.px } : false
      })()
      return {
        stillShowing,
        apart,
        tooFarRight,
        tooTall,
        innerWidth: window.innerWidth,
        pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        offscreen: offscreen.slice(0, 3),
        count: offscreen.length,
        // Whether the element that is SUPPOSED to hold its content on one line
        // still does. Null when the case does not ask.
        scrolls: scroller ? scroller.scrollWidth > scroller.clientWidth + tolerance : null,
        // The inverse, and it needs its own reading. An element with its own
        // sideways scroller absorbs any overflow, so the page never moves and
        // every measurement above stays at zero however far the content runs.
        // Content is allowed a scroller (a code block earns one); a QUOTE is
        // not, and without this the case for one can never fail.
        wronglyScrolls: (() => {
          const el = mustNotScroll ? document.querySelector(mustNotScroll) : null
          if (!el) return null
          return el.scrollWidth > el.clientWidth + tolerance
            ? { by: Math.round(el.scrollWidth - el.clientWidth), sel: mustNotScroll }
            : false
        })(),
        // An inline box reports one rect per line box, so this counts the lines
        // the text actually occupies. Null when the case does not ask.
        lines: oneLine ? oneLine.getClientRects().length : null,
        hidden,
      }
    },
    [
      WIDTH,
      TOLERANCE,
      c.mustScroll ?? null,
      c.mustStayOnOneLine ?? null,
      c.mustBeVisible ?? null,
      c.mustNotScrollSideways ?? null,
      c.mustShareALine ?? null,
      c.mustBeHidden ?? null,
      c.maxHeight ?? null,
      c.maxLeft ?? null,
    ],
  )

  // The viewport each reading was taken at, printed beside the reading. A sweep
  // on this project once iterated four viewport sizes without resizing between
  // them and reported twelve zeroes from one of them.
  const where = `at ${found.innerWidth}px`
  const problems = []
  if (found.pageOverflow > TOLERANCE) {
    problems.push(`the document scrolls sideways by ${found.pageOverflow}px`)
  }
  if (found.hidden.length) {
    problems.push(
      `${found.hidden.length} cell(s) that must be on screen are not rendered — ${found.hidden.join(', ')}. ` +
        'A stacked row has no columns to drop, so a cell the priority ladder hides here is a cell nobody sees',
    )
  }
  if (found.stillShowing.length) {
    problems.push(
      `${found.stillShowing.length} cell(s) that must stand down are still rendered — ${found.stillShowing.join(', ')}. ` +
        'Their content is shown somewhere else on this card, so rendering them here says the same thing twice',
    )
  }
  if (found.apart.length) {
    problems.push(
      found.apart
        .map((p) => `<${p.selA}> and <${p.selB}> are not on the same line: ${p.why}`)
        .join('; ') +
        '. Nothing here overflows and nothing is unreachable — the card is simply a line taller ' +
        'per note than the operator asked for, which is why no fit assertion can catch it',
    )
  }
  if (found.tooFarRight) {
    problems.push(
      found.tooFarRight.why
        ? `<${found.tooFarRight.sel}> ${found.tooFarRight.why}, so where it starts could not be read`
        : `<${found.tooFarRight.sel}> starts at x=${found.tooFarRight.left} against a limit of ` +
          `${found.tooFarRight.limit}px — something ahead of it is taking room while showing nothing. ` +
          'A zero-width box still carries its margin, which is why no fit or height reading sees this',
    )
  }
  if (found.tooTall) {
    problems.push(
      found.tooTall.why
        ? `<${found.tooTall.sel}> is ${found.tooTall.why}, so its height could not be read`
        : `<${found.tooTall.sel}> is ${found.tooTall.h}px tall against a ceiling of ${found.tooTall.limit}px — ` +
          'it has gained a line. Nothing overflows and nothing is unreachable; the list is simply ' +
          'taller per note than it was asked to be, and that is the whole defect',
    )
  }
  if (found.lines !== null && found.lines !== 1) {
    problems.push(
      `<${c.mustStayOnOneLine}> wrapped onto ${found.lines} lines — it was written as one, and a ` +
        'command broken across lines is not the command that was written',
    )
  }
  if (found.scrolls === false) {
    problems.push(
      `<${c.mustScroll}> no longer scrolls its content — it wrapped instead, and a wrapped ` +
        'command is not the command that was written',
    )
  }
  if (found.wronglyScrolls) {
    problems.push(
      `<${found.wronglyScrolls.sel}> scrolls sideways by ${found.wronglyScrolls.by}px — its content ` +
        'does not fit and it is not the kind of content allowed a sideways scroller' +
        (found.pageOverflow <= TOLERANCE
          ? '. Note that the PAGE does not move when this happens, which is exactly why the ' +
            'page-level reading is 0px and a page-level check does not see it'
          : ''),
    )
  }
  if (found.count > 0) {
    const which = found.offscreen.map((w) => `<${w.tag} class="${w.cls}"> ends at x=${w.right} ("${w.text}")`)
    problems.push(
      `${found.count} control(s) end past the right edge — ${which.join('; ')}` +
        (found.pageOverflow <= TOLERANCE
          ? `, while page overflow is ${found.pageOverflow}px, which is why a page-level check does not see it`
          : ''),
    )
  }

  if (problems.length) {
    failures.push(`${c.what} ${where}:\n      ${problems.join('\n      ')}\n      the rule this case defends: ${c.rule}`)
  } else {
    console.log(`  ok  ${c.what} ${where}`)
  }
}

await browser.close()

if (failures.length) {
  console.error('\nContent that does not fit a phone:\n')
  for (const f of failures) console.error(`  - ${f}\n`)
  process.exit(1)
}
console.log(`\nNothing lays out past ${WIDTH}px in ${CASES.length} cases.`)
