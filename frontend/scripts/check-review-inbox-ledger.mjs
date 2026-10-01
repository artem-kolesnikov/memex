#!/usr/bin/env node
/**
 * Contract for the Review Inbox card ledger — SPELLING, not behaviour.
 *
 * Two halves. The first keeps the reference package beside this script
 * inspectable. The second is a tripwire on the shape of the production source:
 * the classes, the ordering and the declarations the approved design is built
 * from.
 *
 * **It does not prove the properties it names, and must not be read as if it
 * did.** A Codex review on 2026-09-01 took an earlier version of this file and
 * found eleven refactors that reintroduced multi-open cards, a checkbox that
 * expands, hidden selection surviving a filter change, Inbox zero after a
 * failed load and an ungated precedent flag — every one of them left all thirty
 * contracts green, because a regex reads text and not a running page. The
 * cheapest of those bypasses was to leave the matched string behind in a
 * comment, so comments are stripped before anything below is matched.
 *
 * `check-review-inbox-behaviour.mjs` is where those properties are actually
 * asserted, in a browser. This file is the fast one that catches a rename.
 */
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..')
const reference = join(frontend, 'scripts', 'references', 'desktop-review-inbox')
const paths = {
  html: join(reference, 'index.html'),
  css: join(reference, 'styles.css'),
  readme: join(reference, 'README.md'),
  view: join(frontend, 'src', 'views', 'InboxView.vue'),
  dialog: join(frontend, 'src', 'components', 'InboxBatchDialog.vue'),
  evidence: join(frontend, 'src', 'components', 'ProposedDocument.vue'),
  builder: join(frontend, 'src', 'lib', 'proposedDoc.ts'),
  app: join(frontend, 'src', 'assets', 'app.css'),
}

const missing = Object.entries(paths).filter(([, path]) => !existsSync(path))
if (missing.length) {
  console.error('Review Inbox ledger check failed:\n')
  for (const [name, path] of missing) console.error(`  ${name} is missing: ${path}`)
  process.exit(1)
}

/** Source with its comments removed.
 *
 *  Every check below asks whether the CODE says something. A commented-out
 *  line says nothing, and matching one is how a guard passes over the very
 *  refactor it exists to stop. */
function stripComments(text) {
  // A scan rather than a set of replacements. The regex version corrupted any
  // string holding `//` — a URL, a path — and everything after it vanished
  // from the source these contracts read, which is a negative contract passing
  // because the code it forbids was eaten rather than absent.
  let out = ''
  let i = 0
  let quote = null
  while (i < text.length) {
    const c = text[i]
    const next = text[i + 1]
    if (quote !== null) {
      out += c
      if (c === '\\') { out += text[i + 1] ?? ''; i += 2; continue }
      if (c === quote) quote = null
      i += 1
      continue
    }
    if (c === '"' || c === "'" || c === '`') { quote = c; out += c; i += 1; continue }
    if (c === '/' && next === '*') { const end = text.indexOf('*/', i + 2); i = end === -1 ? text.length : end + 2; out += ' '; continue }
    if (c === '/' && next === '/') { const end = text.indexOf('\n', i); i = end === -1 ? text.length : end; out += ' '; continue }
    if (c === '<' && text.startsWith('<!--', i)) { const end = text.indexOf('-->', i + 4); i = end === -1 ? text.length : end + 3; out += ' '; continue }
    out += c
    i += 1
  }
  return out
}

/** String literals emptied of their contents, keeping the quotes.
 *
 *  A decoy is as cheap in a string as it was in a comment: `const hint = 'use
 *  @click.stop'` satisfied a positive contract while the real handler had
 *  none. Vue attribute VALUES are strings too, so this runs only over the
 *  `<script>` half of a component and the whole of a plain script. */
function blankStrings(text) {
  return text.replace(/(['"`])(?:\\.|(?!\1)[\s\S])*\1/g, (m) => m[0] + m[0])
}

const src = Object.fromEntries(
  Object.entries(paths).map(([name, path]) => [name, stripComments(readFileSync(path, 'utf8'))]),
)

/** The script half of the view, with string contents blanked — for contracts
 *  about what the CODE does rather than what the template renders. */
const viewScript = blankStrings(src.view.slice(0, src.view.indexOf('</script>') + 1))

/** The template half. Contracts about MARKUP read this and not the whole file,
 *  or a string in the script satisfies them: a decoy holding the exact
 *  attribute text passed the precedent check while the real input was
 *  ungated. A decoy cannot hide here, because an attribute in the template is
 *  the thing itself. */
const viewTemplate = src.view.slice(src.view.indexOf('</script>'))

const locale = JSON.parse(readFileSync(join(frontend, 'src', 'locales', 'en.json'), 'utf8'))

/** How many times a pattern occurs — for declarations a later rule could undo. */
const times = (text, pattern) => (text.match(pattern) ?? []).length

/** The checkbox's own attributes: a bare `@click.stop` and nothing that turns
 *  the stop into an expansion. Scoped to the element, because the Review button
 *  three lines below legitimately carries `@click.stop="toggleCard(entry)"`. */
function checkboxStopsTheClick(view) {
  const at = view.indexOf('mm-review-check')
  if (at === -1) return false
  const attributes = view.slice(at, view.indexOf('>', at))
  return /@click\.stop(?!=)/.test(attributes) && !/@click\.stop=/.test(attributes)
}

const contracts = [
  // ── the reference package stays inspectable ──
  [src.html.includes('inbox-cards-reference'), 'reference: the card-ledger frame is absent'],
  [src.html.includes('class="inbox-card is-open"'), 'reference: no card is shown expanded'],
  [(src.html.match(/class="inbox-card/g) ?? []).length >= 5, 'reference: fewer than five sample cards'],
  [src.html.includes('kind delete'), 'reference: the delete chip is absent'],
  [src.css.includes('-webkit-line-clamp: 2'), 'reference: the two-line clamp is absent'],
  [src.css.includes('max-height: 124px'), 'reference: the bounded collapsed-card height is absent'],
  [src.readme.includes('Codex'), 'reference: README does not name the review owner'],
  [!/(?:href|src)=["']https?:\/\//i.test(src.html), 'reference: depends on a remote asset'],

  // ── one open card, and a checkbox that is not a disclosure ──
  [
    /const openKey = ref<string \| null>/.test(viewScript),
    'production: expansion is not a single key, so more than one card can open',
  ],
  [
    !/\b(expanded|isOpen|detailsVisible)\b\s*:|\['expanded'\]|\["expanded"\]/.test(viewScript),
    'production: a per-row `expanded` flag is back, which breaks the one-open-card invariant',
  ],
  [
    checkboxStopsTheClick(viewTemplate),
    'production: the selection checkbox does not stop the click, or stops it only to expand the card itself',
  ],
  [
    /:aria-expanded="openKey === entry\.key"/.test(viewTemplate) && /:aria-controls="panelId\(entry\.key\)"/.test(viewTemplate),
    'production: the expansion control has lost aria-expanded/aria-controls',
  ],

  // ── decisions sit below the evidence, never beside the textarea ──
  [
    viewTemplate.indexOf('mm-evidence') < viewTemplate.lastIndexOf('mm-decision-footer'),
    'production: the verdict footer no longer follows the evidence',
  ],
  // Operator reasoning was withdrawn from this card on 2026-09-07 — barely
  // used, and a second decision in front of every first one. The three
  // contracts that guarded its shape (above the footer, progressive
  // disclosure, precedent gated on non-empty text) went with it rather than
  // being left to pass over markup nobody can reach. What replaces them is the
  // one thing that must stay true while it is gone: no verdict carries a
  // comment, so nothing half-wired writes an empty one into the journal.
  [
    /const verdictPayload = \(\): OperatorVerdict \| undefined => undefined/.test(viewScript),
    'production: a verdict carries reasoning again — see the memex note "memex.tools — TODO" before re-adding the field',
  ],

  // ── the safety model the redesign inherited ──
  [
    /watch\(kindFilter, clearSelection\)/.test(viewScript),
    'production: changing the kind filter no longer clears the hidden selection',
  ],
  [
    !/window\.confirm|[^.\w]confirm\(|\bwindow\s*\[|\bglobalThis\s*\[|defaultView/.test(viewScript),
    'production: a decision is back on window.confirm instead of the shared dialog',
  ],
  [
    /InboxBatchDialog/.test(src.view) && /useBootstrapModal/.test(src.dialog),
    'production: the batch confirmation is not a focus-trapped dialog',
  ],
  [
    /loadError\.value = e instanceof Error/.test(viewScript),
    'production: a failed load no longer records its error',
  ],
  [
    !/(items|proposals)\.value\s*=\s*(\[\]|Array|new Array)|(items|proposals)\.value\.(splice|length\s*=)|\.filter\(\(\)\s*=>\s*false\)/.test(viewScript),
    'production: a failed load empties the lists and reads as Inbox zero',
  ],
  [
    /v-else-if="inboxZero"/.test(viewTemplate) && viewTemplate.indexOf('v-if="loadError"') < viewTemplate.indexOf('v-else-if="inboxZero"'),
    'production: Inbox zero is not guarded behind the load-failure state',
  ],

  // ── destructive wording is earned, not decorative ──
  [
    /rejectDanger: true/.test(viewScript) && /rejectDanger: false/.test(viewScript),
    'production: rejection is styled the same for a pending note and a proposal',
  ],
  [
    /approveLabel: row\.type === 'delete' \? t\('inbox\.verdict\.delete_note'\)/.test(src.view)
      && locale.inbox.verdict.delete_note === 'Delete note',
    'production: destructive approval has lost its type-specific wording',
  ],

  // ── one bounded evidence viewer, labelled and keyboard-scrollable ──
  [
    /role="region"[\s\S]{0,120}tabindex="0"/.test(src.view),
    'production: the evidence viewer is not a labelled, focusable region',
  ],
  [
    /\.mm-evidence \.mm-diff-body \{[^}]*max-height: none/.test(src.app) &&
      times(src.app, /\.mm-evidence \.mm-diff-body/g) === 1,
    'production: the diff keeps or regains its own scroller inside the viewer, nesting two scrollbars',
  ],
  // The card scrolls nowhere. Every bounded pane inside it was removed on
  // 2026-09-07: a scroller nested in a page that already scrolls hid the end of
  // any document a little over the cap, and the operator hit exactly that.
  [
    !/\.mm-evidence\s*\{[^}]*max-height/.test(src.app),
    'production: the evidence pane has its own height cap again, so a long document scrolls inside the card',
  ],
  [
    !/\.mm-doc-field\s*\{[^}]*overflow-y:\s*(auto|scroll)/.test(src.app),
    'production: a document field scrolls inside itself',
  ],
  [
    !/alert[ -]alert-warning|['"`]alert['"`]|alert alert-/.test(src.evidence),
    'production: the stale-anchor warning is back on a route-local alert instead of the shared notice',
  ],

  // ── one shape for every kind of proposal ──
  //
  // The card asks the same question whatever the agent filed, so the answer is
  // built in ONE place and drawn in one. A second builder is how a delete stops
  // rendering its tags, or an edit grows a field a merge does not have.
  [
    /export function proposedDoc\(/.test(src.builder)
      && /import \{ proposedDoc/.test(viewScript)
      && /import \{ proposedDoc/.test(stripComments(readFileSync(join(frontend, 'src', 'components', 'ActivityJournal.vue'), 'utf8'))),
    'production: the review card and the activity journal no longer build their evidence the same way',
  ],
  [
    ['create', 'delete', 'merge', 'report'].every((kind) => new RegExp(`source\\.type === '${kind}'`).test(src.builder)),
    'production: the document builder has stopped answering for every kind of proposal',
  ],
  [
    /doc\.body\.before/.test(src.evidence) && /doc\.tags\.before/.test(src.evidence) && /doc\.summary\.before/.test(src.evidence),
    'production: a field is drawn without what it replaces, so it can no longer read as a change',
  ],
  [
    src.evidence.indexOf("inbox.doc.body") < src.evidence.indexOf("inbox.doc.tags")
      && src.evidence.indexOf("inbox.doc.tags") < src.evidence.indexOf("inbox.doc.summary"),
    'production: the three fields are no longer body, tags, description in that order',
  ],

  // ── the card is titled by its RESULT ──
  [
    /title: keeper\?\.title \?\? row\.note\.title/.test(viewScript),
    'production: a merge card is titled by the note being consumed rather than the one that survives',
  ],
  [
    /noteId: keeper\?\.id \?\? row\.note\.id/.test(viewScript),
    'production: a merge card references the absorbed note where the surviving one belongs',
  ],
  [
    /body: \{ before: source\.keeperBody/.test(src.builder),
    'production: a merge diffs something other than the keeper, so the evidence is not the result',
  ],

  // A title an agent proposes to CHANGE is the one thing the card header
  // cannot carry, because the header names the note as it stands. It was
  // rendered nowhere at all between the restructure and this contract.
  [
    /\{\{ doc\.title \}\}/.test(src.evidence) && /<del>\{\{ doc\.titleBefore \}\}<\/del>/.test(src.evidence),
    'production: a proposed title is not shown against the one it replaces, so a rename is invisible until it is unlocked',
  ],
  [
    /const showTitle = computed\(\(\) => props\.doc\.titleBefore !== null \|\| props\.headed === false\)/.test(src.evidence),
    'production: the document decides on its own whether to name itself, so an Activity row can go unnamed',
  ],
  [
    /titleBefore: source\.proposed_title === null \? null : currentTitle/.test(src.builder),
    'production: an edit no longer records the title it replaces',
  ],

  // The keeper is the note that SURVIVES a merge, and it is the one the card
  // has to diff against. Codex found this read off the absorbed note on
  // 2026-09-07 with every contract here green, so the source is named.
  [
    times(viewScript, /keeperBody: detail\.merge_into\?\.body_md/g) === 1
      && times(viewScript, /keeperTags: detail\.merge_into\?\.tags/g) === 1
      && times(viewScript, /keeperSummary: detail\.merge_into\?\.summary/g) === 1
      && times(viewScript, /keeper(?:Body|Tags|Summary): /g) === 3,
    'production: a merge reads its keeper fields from somewhere other than merge_into',
  ],

  // ── two numbering spaces, told apart ──
  //
  // `#5` is a review item and `note 7` is a note. Both wore a bare `#` until
  // the operator asked what the numbers were (2026-09-07).
  // Read against the unblanked source: these are i18n KEYS, so a contract about
  // them is a contract about string literals.
  [
    /inbox\.proposal_identity/.test(src.view) && /inbox\.note_ref/.test(src.view)
      && locale.inbox.proposal_identity === '#{id}' && locale.inbox.note_ref === 'note {id}',
    'production: proposal numbers and note numbers are formatted the same way again',
  ],

  // ── the proposer's reason, in full ──
  [
    !/\.mm-review-reason\s*\{[^}]*line-clamp/.test(src.app) && !/\.mm-review-summary\s*\{[^}]*max-height/.test(src.app),
    'production: the reason is clamped again, or the row that carries it is capped',
  ],

  // ── every merge is amendable ──
  //
  // Approving one produces a new version of the surviving note whether or not
  // the agent also proposed wording, and the operator may take it over. The
  // second half is what keeps that honest: unlocking is not amending.
  [
    /if \(row\.type === 'merge'\) return row\.keeperBody !== undefined$/m.test(src.view),
    'production: a merge is amendable only where it proposed a body again',
  ],
  [
    /if \(amendedBody\.value !== seed\.body\)/.test(viewScript)
      && /if \(amendedSummary\.value !== seed\.summary\)/.test(viewScript),
    'production: an amendment is sent without comparing it to what was proposed, so reading a card records an edit',
  ],

  // ── route styles compose primitives, they do not redefine them ──
  [
    !/(\.mm-|#)[a-z-]+[^{}]*\{[^}]*--bs-btn/.test(src.app),
    'production: a route rule restyles the shared button primitive',
  ],
  [
    /role="region"[\s\S]{0,160}:aria-label=/.test(viewTemplate) && !/mm-evidence[\s\S]{0,200}aria-hidden/.test(viewTemplate),
    'production: the evidence viewer has lost its accessible name, or been hidden from assistive technology',
  ],
]

const failures = contracts.filter(([ok]) => !ok).map(([, message]) => message)
if (failures.length) {
  console.error('Review Inbox ledger check failed:\n')
  for (const failure of failures) console.error(`  ${failure}`)
  process.exit(1)
}

console.log(`Review Inbox ledger check passed: ${contracts.length} contracts hold.`)
