<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import OperationOutcomeNotice from '@/components/OperationOutcomeNotice.vue'
import { useI18n } from 'vue-i18n'
import {
  api,
  ApiError,
  noteApprovalSnapshot,
  proposalApprovalSnapshot,
  type EditProposalItem,
  type InboxApprovalItem,
  type InboxBatchRequest,
  type NoteApprovalEdits,
  type NoteListItem,
  type OperatorVerdict,
} from '@/api/client'
import ActorMark from '@/components/ActorMark.vue'
import InboxBatchDialog from '@/components/InboxBatchDialog.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import TagInput from '@/components/TagInput.vue'
import ProposedDocument from '@/components/ProposedDocument.vue'
import { toastError, toastSuccess } from '@/components/toastService'
import { useInboxStore } from '@/stores/inbox'
import { useAuthStore } from '@/stores/auth'
import { lineDiffHtml, resolvePatch } from '@/lib/diffs'
import { proposedDoc, type ProposedDoc } from '@/lib/proposedDoc'
import { formatDateTime } from '@/lib/datetime'
import { renderReason } from '@/lib/markdown'

interface Loadable {
  detailLoaded: boolean
  detailError: string | null
  /** Which request the row is listening to. A card closed and reopened has two
   *  in flight, and the first to return is not the one that speaks for it —
   *  without this, a late failure could write an error over evidence that had
   *  already loaded, and Try again returned early on `detailLoaded` without
   *  ever clearing it. */
  detailRequest: number
}

interface ProposalRow extends EditProposalItem, Loadable {
  currentBody?: string
  currentSummary?: string | null
  currentTags?: string[]
  keeperBody?: string
  keeperTags?: string[]
  keeperSummary?: string | null
  backlinks?: { id: number; title: string }[]
}

interface NoteRow extends NoteListItem, Loadable {
  body?: string
}

type FeedEntry =
  | { key: string; kind: 'proposal'; at: string; proposal: ProposalRow }
  | { key: string; kind: 'note'; at: string; note: NoteRow }

const { t, locale } = useI18n()
const inbox = useInboxStore()
const auth = useAuthStore()
const loading = ref(true)
const loadError = ref<string | null>(null)
const items = ref<NoteRow[]>([])
const proposals = ref<ProposalRow[]>([])

// Filtering to one kind, then deciding the selection in bulk. The filter is the
// safety half: approving sixty items unread is the operator's call to make, but
// approving sixty items of MIXED kinds is how a deletion goes through hidden
// among summaries.
const KIND_KEYS: Record<string, string> = {
  summary: 'inbox.kinds.summary',
  tags: 'inbox.kinds.tags',
  content: 'inbox.kinds.content',
  new_note: 'inbox.kinds.new_note',
  report: 'inbox.kinds.report',
  deletion: 'inbox.kinds.deletion',
  merge: 'inbox.kinds.merge',
}
const FIELD_KEYS: Record<string, string> = {
  summary: 'inbox.fields.summary',
  tags: 'inbox.fields.tags',
  content: 'inbox.fields.content',
  new_note: 'inbox.fields.new_note',
  report: 'inbox.fields.report',
  deletion: 'inbox.fields.deletion',
  merge: 'inbox.fields.merge',
}
const kindFilter = ref('')
// Only offer kinds the inbox actually holds. A dropdown listing Merges when
// there are no merges invites a click that empties the screen for no reason,
// and it would have to be kept in step with the server's list by hand.
const availableKinds = computed(() => {
  const present = new Set<string>()
  proposals.value.forEach((pr) => pr.kinds?.forEach((k) => present.add(k)))
  if (items.value.length) present.add('new_note')
  return Object.keys(KIND_KEYS).filter((k) => present.has(k))
})
function kindLabel(kind: string) {
  return KIND_KEYS[kind] ? t(KIND_KEYS[kind]) : kind
}

function matchesKind(kinds: string[] | undefined) {
  return !kindFilter.value || (kinds ?? []).includes(kindFilter.value)
}
const visibleProposals = computed(() => proposals.value.filter((r) => matchesKind(r.kinds)))
const visibleNotes = computed(() =>
  !kindFilter.value || kindFilter.value === 'new_note' ? items.value : [],
)

// One feed, newest first: a proposed edit and a note an agent wrote are the
// same question — apply this or not — and splitting them into two sections made
// the answer depend on where the item happened to land.
const feed = computed<FeedEntry[]>(() => {
  const entries: FeedEntry[] = [
    ...visibleProposals.value.map(
      // A draft its author revised is as old as the text in it, not as old as
      // the first attempt: the author has one item per note and keeps writing
      // into it until a verdict lands.
      (p): FeedEntry => ({ key: `proposal:${p.id}`, kind: 'proposal', at: p.revised_at ?? p.created_at, proposal: p }),
    ),
    ...visibleNotes.value.map((n): FeedEntry => ({ key: `note:${n.id}`, kind: 'note', at: n.created_at, note: n })),
  ]
  return entries.sort((a, b) => b.at.localeCompare(a.at) || b.key.localeCompare(a.key))
})
const pendingTotal = ref(0)
const moreLoading = ref(false)
const moreError = ref<string | null>(null)
const lifetime = useOperationLifetime()
const outcomeUnknown = ref(false)
let queueRequest = 0
let pendingPage = 0
const queueSize = computed(() => pendingTotal.value + proposals.value.length)
const inboxZero = computed(() => queueSize.value === 0)
const nothingVisible = computed(() => !feed.value.length && !inboxZero.value)

/** The age of an item, since triage is about what arrived when and not about dates. */
const AGES: [number, Intl.RelativeTimeFormatUnit][] = [
  [60, 'second'],
  [3600, 'minute'],
  [86400, 'hour'],
  [604800, 'day'],
  [2629800, 'week'],
  [31557600, 'month'],
]
function age(iso: string): string {
  const seconds = (Date.now() - new Date(iso).getTime()) / 1000
  // A negative age is a clock disagreement or bad data, and "just now" is the
  // one thing it certainly is not. Say the date instead of guessing.
  if (seconds < -45) return formatDateTime(iso)
  if (seconds < 45) return t('inbox.just_now')
  const relative = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' })
  const [, unit] = AGES.find(([limit]) => seconds < limit) ?? [0, 'year' as Intl.RelativeTimeFormatUnit]
  const divisor = { second: 1, minute: 60, hour: 3600, day: 86400, week: 604800, month: 2629800, year: 31557600 }[
    unit as 'second' | 'minute' | 'hour' | 'day' | 'week' | 'month' | 'year'
  ]
  return relative.format(-Math.round(seconds / divisor), unit)
}

function words(text: string | null | undefined): number {
  return (text ?? '').trim().split(/\s+/).filter(Boolean).length
}

// ── the card ledger ──────────────────────────────────────────────────────

interface Signal {
  text: string
  risk?: boolean
}

interface Card {
  key: string
  kind: 'proposal' | 'note'
  id: number
  chip: string
  chipClass: string
  /** The note the decision is ABOUT, once the proposal has been applied. */
  title: string
  noteId: number | null
  identity: string
  /** The note this card is titled by, referenced beside its title. Distinct
   *  from `identity`, which numbers the REVIEW ITEM — two numbering spaces
   *  that read as one when both wear a bare `#`. */
  noteRef: string | null
  /** The second note a merge consumes: named once, here. */
  relation: { title: string; noteRef: string } | null
  /** The proposer's own words, in full and never clamped (operator,
   *  2026-09-07): a reason worth writing is worth reading before deciding, and
   *  it was previously said twice — once truncated on the row and once again
   *  inside the panel. */
  reason: string
  /** The same text rendered: one sentence per change, a bullet each where
   *  there is more than one. `reasonBlock` is false when it came back as one
   *  paragraph and can sit beside the label. */
  reasonHtml: string
  reasonBlock: boolean
  preview: string
  signals: Signal[]
  destructive: boolean
  approveLabel: string
  approveDanger: boolean
  rejectLabel: string
  rejectDanger: boolean
}

/** The fields a proposal touches, in the words the filter uses for them. */
function scopeOf(kinds: string[] | undefined, exclude: string[] = ['deletion', 'merge']): string[] {
  return (kinds ?? []).filter((k) => !exclude.includes(k)).map((k) => (FIELD_KEYS[k] ? t(FIELD_KEYS[k]) : k))
}

/** What the proposal says it does, and what it reaches — one sentence rather
 *  than a sentence and a row of chips saying the same thing in another shape. */
function withScope(purpose: string, kinds: string[] | undefined): string {
  const scope = scopeOf(kinds)
  // Trim the trailing punctuation FIRST: a purpose of "..." is nothing to
  // build a sentence on, and testing it before the trim produced a line that
  // opened with a comma.
  const stem = purpose.replace(/[.\s]+$/, '').trim()
  if (!scope.length) return stem
  const affected = t('inbox.scope.affected', { fields: scope.join(', ') })
  if (!stem) return affected.charAt(0).toUpperCase() + affected.slice(1)
  return t('inbox.scope.with_stem', { stem, affected })
}

function cardOf(entry: FeedEntry): Card {
  const card = cardBody(entry)
  const { html, block } = renderReason(card.reason)
  return { ...card, reasonHtml: html, reasonBlock: block }
}

function cardBody(entry: FeedEntry): Omit<Card, 'reasonHtml' | 'reasonBlock'> {

  if (entry.kind === 'proposal') {
    const row = entry.proposal
    const chip = row.type === 'delete' ? t('common.delete')
      : row.type === 'merge' ? t('inbox.chip.merge')
      : row.type === 'report' ? t('inbox.chip.report') : t('common.edit')
    // A card is titled by its RESULT. For a merge that is the note left
    // standing, not the one being consumed: the operator is deciding what the
    // survivor ends up saying, and heading the card with a title about to
    // disappear asked them about the corpse (operator, 2026-09-07).
    const keeper = row.type === 'merge' ? row.merge_into : null
    return {
      key: entry.key,
      kind: 'proposal',
      id: row.id,
      chip,
      chipClass: row.type === 'delete' ? 'is-delete'
        : row.type === 'merge' ? 'is-merge'
        : row.type === 'report' ? 'is-report' : 'is-edit',
      title: keeper?.title ?? row.note.title,
      noteId: keeper?.id ?? row.note.id,
      identity: t('inbox.proposal_identity', { id: row.id }),
      noteRef: t('inbox.note_ref', { id: keeper?.id ?? row.note.id }),
      relation: keeper !== null
        ? { title: row.note.title, noteRef: t('inbox.note_ref', { id: row.note.id }) }
        // A delete consumes no second note, so its relation line carries the
        // consequence instead. That sentence is the only thing standing in
        // front of a one-press destructive apply (operator, 2026-09-01), and
        // the notice block it used to live in was withdrawn on 2026-09-07.
        : row.type === 'delete' ? { title: t('inbox.relation.deleting'), noteRef: '' } : null,
      // A report proposes no change, so it has no change_title: what it has to
      // say IS the comment, and there is nothing else to fall back to.
      reason: row.comment ?? row.change_title ?? '',
      preview: '',
      signals: [],
      destructive: row.type === 'delete' || row.type === 'merge',
      // Acknowledged, not applied: the note is untouched either way, so a
      // verdict that said "Apply" would promise something it cannot do.
      approveLabel: row.type === 'delete' ? t('inbox.verdict.delete_note')
        : row.type === 'merge' ? t('inbox.verdict.merge_into_this')
        : row.type === 'report' ? t('inbox.verdict.acknowledge') : t('inbox.verdict.apply_edit'),
      approveDanger: row.type === 'delete' || row.type === 'merge',
      // Rejecting a proposal discards the proposal and leaves a verified note
      // exactly as it was, so it is not painted like deleting one.
      rejectLabel: row.type === 'edit' ? t('inbox.verdict.reject') : row.type === 'report' ? t('inbox.verdict.dismiss') : t('inbox.verdict.reject_proposal'),
      rejectDanger: false,
    }
  }

  const note = entry.note
  return {
    key: entry.key,
    kind: 'note',
    id: note.id,
    chip: t('inbox.chip.new'),
    chipClass: 'is-new',
    title: note.title,
    noteId: note.id,
    // A pending note has no proposal row: the review item IS the note, so the
    // leading slot says "note 4" rather than "#4", which is a proposal number
    // on every other card and would collide with one here.
    identity: t('inbox.note_ref', { id: note.id }),
    noteRef: null,
    relation: null,
    // A note an agent wrote carries no rationale of its own — the note IS the
    // proposal. Its description would be the only candidate, and that is a
    // FIELD of the document below: printing it here too is the repetition this
    // restructure exists to remove.
    reason: '',
    preview: '',
    signals: [],
    destructive: false,
    approveLabel: t('inbox.verdict.approve_note'),
    approveDanger: false,
    // Rejecting a pending note DOES delete it, so this one is the danger.
    rejectLabel: t('inbox.verdict.reject_note'),
    rejectDanger: true,
  }
}

const cards = computed(() => feed.value.map((entry) => ({ entry, card: cardOf(entry) })))

/** Whether the open card has its evidence on screen.
 *
 *  The verdict footer renders with the card, but the delete and merge notices
 *  wait on the detail request — so on a slow one the Delete button was live
 *  while the sentence naming its consequence had not arrived. That sentence is
 *  the ONLY thing standing in front of a one-press destructive apply
 *  (operator, 2026-09-01), so nothing is decidable until it is there. */
function evidenceReady(entry: FeedEntry): boolean {
  if (entry.kind === 'proposal') return entry.proposal.detailLoaded
  return entry.note.detailLoaded
}

/** Whether approving is a thing the server will accept.
 *
 *  An anchored edit that no longer fits, or that would empty the note, is
 *  refused server-side. Leaving the button live to collect that refusal makes
 *  the operator do the experiment; the card already says why. Rejecting is
 *  always available — a proposal that cannot land is exactly one to discard. */
function approvable(entry: FeedEntry): boolean {
  if (hasReviewConflict(entry)) return false
  if (editableNote(entry)) return amendedBody.value.trim() !== '' && amendedTitle.value.trim() !== ''
  if (entry.kind !== 'proposal') return true
  if (editableProposal(entry)) {
    return amendedBody.value.trim() !== '' && (!amendsTitle(entry) || amendedTitle.value.trim() !== '')
  }
  const row = entry.proposal
  if (!row.detailLoaded || !row.proposed_patch?.length) return true
  return resolvePatch(row.currentBody ?? '', row.proposed_patch).ok
}

// ── one open card ────────────────────────────────────────────────────────

const openKey = ref<string | null>(null)
const amendOpen = ref(false)
const amendedBody = ref('')
const amendedTitle = ref('')
const amendedTags = ref<string[]>([])
const amendedSummary = ref('')
/** What the fields held when the pane was unlocked. An amendment is what the
 *  operator changed FROM this, so a pane opened and closed untouched sends
 *  nothing and the item is decided exactly as it was filed. */
const amendSeed = ref<{ title: string; body: string; tags: string[]; summary: string } | null>(null)
/** The tag vocabulary, for the tag field's suggestions. Loaded lazily: the
 *  inbox is read far more often than it is edited in. */
const tagVocabulary = ref<string[]>([])
const decisionBusy = ref(false)
const decisionError = ref<string | null>(null)
const reviewConflicts = ref(new Set<string>())
const hasReviewConflict = (entry: FeedEntry) => reviewConflicts.value.has(entry.key)
const discardDialogOpen = ref(false)
let resolveDiscard: ((discard: boolean) => void) | null = null
let discardOpener: HTMLElement | null = null

function mayDiscardAmendment(): Promise<boolean> {
  if (discardDialogOpen.value) return Promise.resolve(false)
  if (amendedFields(true) === undefined) return Promise.resolve(true)
  discardOpener = document.activeElement as HTMLElement | null
  discardDialogOpen.value = true
  return new Promise(resolve => { resolveDiscard = resolve })
}

function finishDiscard(discard: boolean) {
  discardDialogOpen.value = false
  const resolve = resolveDiscard
  resolveDiscard = null
  const opener = discardOpener
  discardOpener = null
  if (!discard) void nextTick(() => { if (opener?.isConnected) opener.focus() })
  resolve?.(discard)
}

onBeforeUnmount(() => finishDiscard(false))

function resetDecision() {
  amendOpen.value = false
  amendedBody.value = ''
  amendedTitle.value = ''
  amendedTags.value = []
  amendedSummary.value = ''
  amendSeed.value = null
  decisionBusy.value = false
  decisionError.value = null
}

/** The card a verdict should leave focus on: the one after it, or the one
 *  before when it was last.
 *
 *  Read BEFORE the decided row is spliced out — asked afterwards, the key is
 *  already gone from the feed and `indexOf` answers -1, which walks focus to
 *  the top of the queue on every verdict instead of to the neighbour. */
function neighbourOf(key: string): string | null {
  const order = feed.value.map((entry) => entry.key)
  const at = order.indexOf(key)
  if (at === -1) return null
  return order[at + 1] ?? order[at - 1] ?? null
}

/** Move focus to that neighbour, or to the heading when the queue empties.
 *
 *  The neighbour is a hint read before the verdict, and the filter can change
 *  while one is in flight — so it is checked against the queue as it now
 *  stands, and the first visible card is better than the heading when the
 *  remembered one is no longer on screen. */
async function focusAfter(neighbour: string | null) {
  const mine = lifetime.capture()
  await nextTick()
  if (!lifetime.current(mine)) return
  const visible = feed.value.map((entry) => entry.key)
  const key = neighbour !== null && visible.includes(neighbour) ? neighbour : (visible[0] ?? null)
  const target = key === null ? null : document.getElementById(expandId(key))
  ;(target ?? document.getElementById('inbox-heading'))?.focus()
}

function expandId(key: string) {
  return `review-${key.replace(':', '-')}`
}
function panelId(key: string) {
  return `evidence-${key.replace(':', '-')}`
}

/** A click anywhere on the row opens the card — but a link in the proposer's
 *  reason is a link, and letting it through here closed the card and took an
 *  amendment in progress with it (Codex, 2026-09-16). */
function summaryClick(entry: FeedEntry, event: MouseEvent): void {
  if ((event.target as HTMLElement | null)?.closest('a') !== null) return
  void toggleCard(entry)
}

async function toggleCard(entry: FeedEntry) {
  if (decisionBusy.value || !(await mayDiscardAmendment())) return
  if (openKey.value === entry.key) {
    openKey.value = null
    resetDecision()
    await nextTick()
    document.getElementById(expandId(entry.key))?.focus()
    return
  }
  openKey.value = entry.key
  resetDecision()
  if (hasReviewConflict(entry)) decisionError.value = t('inbox.review_changed')
  // A row that already has its evidence keeps it; the error a losing request
  // left behind is not news about the evidence that did arrive.
  const row = entry.kind === 'proposal' ? entry.proposal : entry.note
  if (row.detailLoaded) row.detailError = null
  if (entry.kind === 'proposal') await loadProposal(entry.proposal)
  else await loadNote(entry.note)
}

async function openForEditing(seed: { title: string; body: string; tags: string[]; summary: string }) {
  amendedTitle.value = seed.title
  amendedBody.value = seed.body
  amendedTags.value = [...seed.tags]
  amendedSummary.value = seed.summary
  amendSeed.value = seed
  amendOpen.value = true
  if (tagVocabulary.value.length === 0) {
    try {
      tagVocabulary.value = (await api.tags()).tags.map((tag) => tag.name)
    } catch {
      // Suggestions are a convenience; typing a name still works without them.
      tagVocabulary.value = []
    }
  }
}

const noteSeed = (row: NoteRow) => ({
  title: row.title,
  body: row.body ?? '',
  tags: row.tags.map((tag) => tag.name),
  summary: row.summary ?? '',
})

/** What the proposal would leave behind, which is what the operator edits. */
/** Seeded from the SAME document the locked pane renders, so unlocking cannot
 *  put different text in front of the operator than the text they just read —
 *  and so a merge starts from the keeper's fields rather than the absorbed
 *  note's, which is what a naive read of the proposal row gives you. */
const proposalSeed = (row: ProposalRow) => {
  const doc = proposedDoc(row, t)

  return { title: doc.title, body: doc.body.after, tags: doc.tags.after, summary: doc.summary.after }
}

/** A detail that will not load closes its own expansion and says which item it
 *  was, rather than leaving an empty viewer that reads as "nothing changes". */
function failedDetail(row: Loadable, key: string, e: unknown) {
  row.detailError = e instanceof Error ? e.message : t('common.unknown_error')
  if (openKey.value === key) openKey.value = null
}

let detailTicket = 0

/** Start a request for this row, or refuse when one has already answered it. */
function beginDetail(row: Loadable) {
  if (row.detailLoaded) return null
  row.detailError = null
  row.detailRequest = ++detailTicket
  return { request: row.detailRequest, owner: lifetime.capture() }
}

/** Whether this response is still the one the row is waiting for. */
const stillWanted = (row: Loadable, ticket: NonNullable<ReturnType<typeof beginDetail>>) => lifetime.current(ticket.owner) && row.detailRequest === ticket.request && !row.detailLoaded

async function loadProposal(row: ProposalRow) {
  const ticket = beginDetail(row)
  if (ticket === null) return
  try {
    const detail = await api.getProposal(row.id)
    if (!stillWanted(row, ticket)) return
    // Every field the document is built from, not two of the three: a response
    // that omits only the description renders the keeper as having none, which
    // is a claim about the note rather than a gap in the response (Codex,
    // 2026-09-07). `null` is a real answer here and `undefined` is not.
    if (detail.type === 'merge' && (detail.merge_into?.body_md === undefined
      || detail.merge_into?.tags === undefined
      || detail.merge_into?.summary === undefined)) {
      // Without the keeper's own fields the card would seed the unlock from
      // the ABSORBED note, and approving would replace the survivor's tags
      // with them. A response this shape is broken, and is reported as one
      // rather than rendered as a merge (Codex, 2026-09-07).
      throw new Error(t('inbox.merge.no_keeper'))
    }
    proposalApprovalSnapshot(detail)
    Object.assign(row, detail, {
      currentBody: detail.note.body_md,
      currentSummary: detail.note.summary,
      currentTags: detail.note.tags,
      keeperBody: detail.merge_into?.body_md,
      keeperTags: detail.merge_into?.tags,
      keeperSummary: detail.merge_into?.summary,
      backlinks: detail.note.backlinks,
      detailLoaded: true,
    })
  } catch (e) {
    if (stillWanted(row, ticket)) failedDetail(row, `proposal:${row.id}`, e)
  }
}

async function loadNote(row: NoteRow) {
  const ticket = beginDetail(row)
  if (ticket === null) return
  try {
    const note = await api.getNote(row.id)
    if (!stillWanted(row, ticket)) return
    noteApprovalSnapshot(note.version)
    Object.assign(row, note, { body: note.body_md, detailLoaded: true })
  } catch (e) {
    if (stillWanted(row, ticket)) failedDetail(row, `note:${row.id}`, e)
  }
}

async function refreshReview(entry: FeedEntry) {
  if (!(await mayDiscardAmendment())) return
  const row = entry.kind === 'proposal' ? entry.proposal : entry.note
  row.detailLoaded = false
  resetDecision()
  if (entry.kind === 'proposal') await loadProposal(entry.proposal)
  else await loadNote(entry.note)
  if (row.detailLoaded) reviewConflicts.value.delete(entry.key)
}

/** The document each card is deciding on, built the same way for every kind.
 *  {@see proposedDoc} — the card draws one shape and the reader learns it once. */
function docOf(entry: FeedEntry): ProposedDoc | null {
  if (entry.kind === 'note') {
    const row = entry.note
    if (!row.detailLoaded) return null

    return proposedDoc(
      {
        type: 'create',
        note: null,
        merge_into: null,
        proposed_title: row.title,
        proposed_body_md: row.body ?? '',
        proposed_patch: null,
        proposed_tags: row.tags.map((tag) => tag.name),
        proposed_summary: row.summary,
      },
      t,
    )
  }
  const row = entry.proposal
  if (!row.detailLoaded) return null

  return proposedDoc(row, t)
}
// ── selection and batches ────────────────────────────────────────────────

const selected = ref<Set<string>>(new Set())
const batchComment = ref('')
const batchBusy = ref(false)
const batchAction = ref<'approve' | 'reject' | null>(null)
/** The control the dialog was opened from, so closing it does not drop focus. */
const batchOpener = ref<HTMLElement | null>(null)
const batchReport = ref<{ action: 'approve' | 'reject'; done: number; total: number; failed: string[] } | null>(null)

function toggleSelected(key: string) {
  const next = new Set(selected.value)
  next.has(key) ? next.delete(key) : next.add(key)
  selected.value = next
}
function selectAllVisible() {
  selected.value = new Set(feed.value.map((entry) => entry.key))
}
function clearSelection() {
  selected.value = new Set()
  batchComment.value = ''
}

// Changing the filter drops the selection. Keeping it would let the operator
// approve items that are no longer on screen, which is the one thing a filtered
// bulk action must never do.
watch(kindFilter, clearSelection)

const selectedItems = computed(() =>
  [...selected.value].map((key) => {
    const [kind, id] = key.split(':')
    return { kind: kind as 'proposal' | 'note', id: Number(id) }
  }),
)
// Destructive work in the selection earns a second question, whatever the
// filter says: a mixed selection is exactly where a delete gets missed.
const selectionDestroys = computed(() =>
  selectedItems.value.some((item) => {
    if (item.kind === 'proposal') {
      return proposals.value.find((p) => p.id === item.id)?.kinds.some((k) => k === 'deletion' || k === 'merge')
    }
    return false
  }),
)
const selectionHasNotes = computed(() => selectedItems.value.some((item) => item.kind === 'note'))

const batchWarning = computed(() => {
  const count = selectedItems.value.length
  if (batchAction.value === 'reject') {
    return selectionHasNotes.value ? t('inbox.batch.reject_with_notes') : t('inbox.batch.reject')
  }
  if (selectionDestroys.value) {
    return t('inbox.batch.destructive')
  }
  // A report applies nothing, so counting it among the items that "will be
  // applied" promises the operator a change that cannot happen — the same
  // wrong promise the single-item verdict avoids by reading Acknowledge.
  const reports = selectedItems.value.filter((item) =>
    item.kind === 'proposal' && proposals.value.find((p) => p.id === item.id)?.type === 'report',
  ).length
  if (reports === count) {
    return t('inbox.batch.acknowledged', count)
  }
  if (reports > 0) {
    const edits = count - reports
    return t('inbox.batch.mixed', {
      applied: t('inbox.batch.applied', edits),
      reports: t('inbox.batch.mixed_reports', reports),
    })
  }
  return t('inbox.batch.applied', count)
})

async function askBatch(action: 'approve' | 'reject', event: MouseEvent) {
  const mine = lifetime.capture()
  const opener = event.currentTarget as HTMLElement
  if (outcomeUnknown.value || decisionBusy.value || batchBusy.value || !(await mayDiscardAmendment()) || !lifetime.current(mine)) return
  resetDecision()
  // Remove the discard dialog before mounting the batch confirmation.
  await nextTick()
  if (!lifetime.current(mine)) return
  batchOpener.value = opener
  batchAction.value = action
}

/** Closing the dialog returns focus to whatever opened it, or to the heading
 *  when the batch cleared the selection and took that control with it. */
async function closeBatch() {
  const mine = lifetime.capture()
  batchAction.value = null
  await nextTick()
  if (!lifetime.current(mine)) return
  const opener = batchOpener.value
  batchOpener.value = null
  ;(opener?.isConnected ? opener : document.getElementById('inbox-heading'))?.focus()
}

async function runBatch() {
  if (batchBusy.value || decisionBusy.value || outcomeUnknown.value) return
  queueRequest++
  moreLoading.value = false
  const mine = lifetime.capture()
  const action = batchAction.value
  const count = selectedItems.value.length
  if (action === null || !count) return

  batchBusy.value = true
  try {
    const comment = batchComment.value.trim() || undefined
    const body: InboxBatchRequest = action === 'approve'
      ? { action, items: selectedItems.value.map(batchApprovalItem), comment }
      : { action, items: selectedItems.value, comment }
    const result = await api.inboxBatch(body)
    if (!lifetime.current(mine)) return
    batchReport.value = {
      action,
      done: result.done,
      total: count,
      failed: result.failed.map((f) => `${f.kind} ${f.id}: ${f.error}`),
    }
    if (!result.failed.length) {
      toastSuccess(t(action === 'approve' ? 'inbox.batch.approved_toast' : 'inbox.batch.rejected_toast', result.done))
    } else {
      const failed = new Set(result.failed.map(item => `${item.kind}:${item.id}`))
      if (action === 'approve') failed.forEach(key => reviewConflicts.value.add(key))
      const succeeded = new Set(body.items.map(item => `${item.kind}:${item.id}`).filter(key => !failed.has(key)))
      pendingTotal.value = Math.max(0, pendingTotal.value - items.value.filter(row => succeeded.has(`note:${row.id}`)).length)
      pendingPage = 0
      items.value = items.value.filter(row => !succeeded.has(`note:${row.id}`))
      proposals.value = proposals.value.filter(row => !succeeded.has(`proposal:${row.id}`))
      selected.value = failed
      await closeBatch()
      if (!lifetime.current(mine)) return
      await inbox.refresh()
      return
    }
    // Cleared first: clearing unmounts the panel the opener lives in, so
    // handing focus back before it goes hands it to an element about to be
    // removed, and focus lands on the document a tick later.
    clearSelection()
    await closeBatch()
    if (!lifetime.current(mine)) return
    await Promise.all([load(), inbox.refresh()])
  } catch (e) {
    if (!lifetime.current(mine)) return
    batchReport.value = {
      action,
      done: 0,
      total: count,
      failed: [e instanceof Error ? e.message : t('common.unknown_error')],
    }
    await closeBatch()
  } finally {
    if (lifetime.current(mine)) batchBusy.value = false
  }
}

function batchApprovalItem(item: { kind: 'proposal' | 'note'; id: number }): InboxApprovalItem {
  if (reviewConflicts.value.has(`${item.kind}:${item.id}`)) throw new Error(t('inbox.review_changed'))
  if (item.kind === 'note') {
    const row = items.value.find(row => row.id === item.id)
    return { kind: 'note', id: item.id, ...noteApprovalSnapshot(row?.version) }
  }
  const row = proposals.value.find(row => row.id === item.id)
  if (!row) throw new Error(t('inbox.review_changed'))
  return { kind: 'proposal', id: item.id, ...proposalApprovalSnapshot(row) }
}

async function refreshQueue() {
  if (!(await mayDiscardAmendment())) return
  clearSelection()
  await load()
}

// ── loading ──────────────────────────────────────────────────────────────

async function load() {
  const mine = lifetime.capture()
  const request = ++queueRequest
  const current = () => lifetime.current(mine) && request === queueRequest
  moreLoading.value = false
  moreError.value = null
  loading.value = true
  loadError.value = null
  try {
    const [search, proposalList] = await Promise.all([
      api.searchNotes({ status: 'pending', per_page: 100 }),
      api.proposals(),
    ])
    if (!current()) return
    pendingTotal.value = search.total
    pendingPage = 1
    items.value = search.items.map((n) => ({ ...n, detailLoaded: false, detailError: null, detailRequest: 0 }))
    proposals.value = proposalList.proposals.map((p) => ({ ...p, detailLoaded: false, detailError: null, detailRequest: 0 }))
    reviewConflicts.value.clear()
    openKey.value = null
    resetDecision()
  } catch (e) {
    if (!current()) return
    // Not an empty inbox: an inbox nobody could read. Saying "Nothing to review"
    // would report a failed load as work already done.
    loadError.value = e instanceof Error ? e.message : t('common.unknown_error')
  } finally {
    if (current()) loading.value = false
  }
}

async function loadMore() {
  if (moreLoading.value || loading.value) return
  const mine = lifetime.capture()
  const request = queueRequest
  const current = () => lifetime.current(mine) && request === queueRequest
  moreLoading.value = true
  moreError.value = null
  try {
    const page = pendingPage + 1
    const result = await api.searchNotes({ status: 'pending', per_page: 100, page })
    if (!current()) return
    const existing = new Set(items.value.map(row => row.id))
    items.value.push(...result.items.filter(row => !existing.has(row.id)).map(row => ({ ...row, detailLoaded: false, detailError: null, detailRequest: 0 })))
    pendingTotal.value = result.total
    // Concurrent inserts can overlap pages and leave unseen rows at the head.
    pendingPage = page * 100 >= result.total ? 0 : page
  } catch (e) {
    if (current()) moreError.value = e instanceof Error ? e.message : t('common.unknown_error')
  } finally {
    if (current()) moreLoading.value = false
  }
}
watch(sessionEpoch, () => {
  lifetime.invalidate()
  if (loading.value) {
    loading.value = false
    loadError.value = t('common.session_changed')
  }
  if (decisionBusy.value || batchBusy.value) outcomeUnknown.value = true
  // Leave the write locked, but expose the reload notice outside the modal.
  batchAction.value = null
  moreLoading.value = false
})
watch(() => auth.user?.team.handle, () => { outcomeUnknown.value = false; batchBusy.value = false; clearSelection(); openKey.value = null; resetDecision(); void load() })
onMounted(load)

/** Retry removes the button that was pressed, so focus is placed before it goes. */
async function retry() {
  document.getElementById('inbox-heading')?.focus()
  await load()
}

// ── verdicts ─────────────────────────────────────────────────────────────

/**
 * No operator reasoning is collected here (operator, 2026-09-07).
 *
 * The comment-and-precedent field was hidden rather than deleted: the route,
 * the journal column and the curator's reading of it are all still in place,
 * and a verdict simply carries nothing. It was barely used and it put a second
 * decision in front of every first one. What an operator should be asked to
 * say, and when, is its own piece of work.
 * Do not re-add the box without that decision.
 */
const verdictPayload = (): OperatorVerdict | undefined => undefined

/** A proposal carrying text the operator can take over.
 *
 *  Offered behind a button rather than open by default: the card's job is to
 *  show what somebody else proposes, and the diff is the thing being decided.
 *  A delete proposes no content and a report proposes no change, so neither
 *  has anything to unlock — {@see EditProposal::amend}.
 *
 *  EVERY merge is amendable, including one that proposes no new body (operator,
 *  2026-09-07). Approving a merge produces a new version of the note that
 *  survives — merged tags, retargeted links, one fewer note beside it — and
 *  whether the agent also rewrote the text does not change that. The cost of
 *  letting somebody edit that version if they CHOOSE to is zero, and a card
 *  where the button appears and disappears by a rule nobody can see costs
 *  more than the edit it withholds. Unlocking is not amending: what leaves
 *  here is the proposal as filed unless a character actually differs
 *  ({@see amendedFields}). */
function amendableProposal(entry: FeedEntry): entry is FeedEntry & { kind: 'proposal' } {
  if (entry.kind !== 'proposal') return false
  const row = entry.proposal
  if (row.type === 'merge') return row.keeperBody !== undefined
  return row.type === 'edit' && row.currentBody !== undefined
}

/** Everything the operator can take over before approving: a proposal carrying
 *  text, and a note an agent wrote — which is nothing BUT text.
 *
 *  A pending note used to open straight into an editor while every other card
 *  opened into a read view. One structure for all four now (operator,
 *  2026-09-07): read what is proposed, then choose to change it. */
function amendable(entry: FeedEntry): boolean {
  return amendableProposal(entry) || (entry.kind === 'note' && entry.note.detailLoaded)
}

/** What approving does with the text in the pane.
 *
 *  A merge that proposed no body has nothing to apply "instead of", so saying
 *  so would describe a proposal that was never made. */
function amendHint(entry: FeedEntry): string {
  if (entry.kind === 'note') return t('inbox.amend.note_own')
  if (entry.kind === 'proposal' && entry.proposal.type === 'merge' && entry.proposal.proposed_body_md === null) {
    return t('inbox.amend.note_merge_unproposed')
  }
  return t('inbox.amend.note')
}

/** Whether the TITLE is the operator's to change here.
 *
 *  A merge is headed by the keeper's own name, which the merge never proposes
 *  to change — so a title field would be an edit nothing on the card asked
 *  about, and `EditProposal::amend` refuses it. Everything else about a merge
 *  IS amendable: approving one produces a new version of the surviving note,
 *  and the operator may take over any of it (operator, 2026-09-07). */
function amendsTitle(entry: FeedEntry): boolean {
  return entry.kind === 'note' || (entry.kind === 'proposal' && entry.proposal.type === 'edit')
}

/** The text a change is read against: the note as it stands, or for a merge the
 *  note that is kept. */
function trackAgainst(entry: FeedEntry): string | undefined {
  if (entry.kind === 'note') return undefined
  if (entry.kind !== 'proposal') return undefined

  return entry.proposal.type === 'merge' ? entry.proposal.keeperBody : entry.proposal.currentBody
}

/** Tags the pane started with that are no longer in the field — a removal has
 *  nowhere to show itself in a list of chips otherwise. */
function droppedTags(entry: FeedEntry): string[] {
  const seed = amendSeed.value
  if (seed === null || !amendOpen.value) return []

  return seed.tags.filter((tag) => !amendedTags.value.includes(tag))
}

/** The proposal pane, unlocked. Not a type predicate: it asks about the pane's
 *  state as much as the entry's kind, so a false answer narrows nothing. */
function editableProposal(entry: FeedEntry): boolean {
  return amendOpen.value && amendableProposal(entry)
}

/** A pending note, open for editing rather than only for reading. */
function editableNote(entry: FeedEntry): boolean {
  return entry.kind === 'note' && entry.note.detailLoaded && amendOpen.value
}

/** The edit as it would land, the same text the evidence panel diffs; a stale patch has none, so the note as it stands. */
function proposedBodyOf(row: ProposalRow): string {
  if (row.type === 'merge') return row.proposed_body_md ?? row.keeperBody ?? ''
  if (row.proposed_patch?.length) {
    const resolved = resolvePatch(row.currentBody ?? '', row.proposed_patch)
    if (resolved.ok) return resolved.body
    return resolved.reason === 'empties' ? '' : row.currentBody ?? ''
  }
  return row.proposed_body_md ?? row.currentBody ?? ''
}

async function toggleAmend(entry: FeedEntry) {
  if (!amendable(entry)) return
  if (amendOpen.value) {
    if (!(await mayDiscardAmendment())) return
    amendOpen.value = false
    amendSeed.value = null
    amendedBody.value = ''
    amendedTitle.value = ''
    amendedTags.value = []
    amendedSummary.value = ''
    return
  }
  if (entry.kind === 'note') {
    await openForEditing(noteSeed(entry.note))

    return
  }
  if (entry.kind === 'proposal') await openForEditing(proposalSeed(entry.proposal))
}

/** Whenever the amendment is open: the text as it stands is what gets applied, which is also how a stale patch is rescued. A blank one disables Apply instead. */
function amendment(entry: FeedEntry): NoteApprovalEdits | undefined {
  if (!editableProposal(entry)) return undefined

  return amendedFields(amendsTitle(entry))
}

const asSet = (tags: string[]) => [...new Set(tags)].sort()

/** What the operator changed, or nothing when they changed nothing.
 *
 *  Nothing is the common case and the one that matters: an item read and
 *  approved without typing must be decided AS FILED. Sending the fields back
 *  unchanged would make every verdict an operator edit — a revision, their name
 *  on the text, and a journal row saying they rewrote what they only read. */
function amendedFields(withTitle: boolean): NoteApprovalEdits | undefined {
  const seed = amendSeed.value
  if (!amendOpen.value || seed === null) return undefined

  const edits: NoteApprovalEdits = {}
  if (amendedBody.value !== seed.body) edits.body_md = amendedBody.value
  if (amendedSummary.value !== seed.summary) edits.summary = amendedSummary.value
  // As a SET. Compared by position, removing a tag and adding it back sent a
  // replacement list that changed nothing but the order, and every verdict
  // that touched the tag row became an operator rewrite.
  const tags = amendedTags.value
  if (asSet(tags).join('\u0000') !== asSet(seed.tags).join('\u0000')) {
    edits.tags = [...tags]
  }
  if (withTitle && amendedTitle.value !== seed.title) edits.title = amendedTitle.value.trim()

  return Object.keys(edits).length === 0 ? undefined : edits
}

function noteEdits(): NoteApprovalEdits | undefined {
  const edits = amendedFields(true)
  if (edits === undefined) return undefined

  return {
    title: amendedTitle.value.trim(),
    body_md: amendedBody.value,
    tags: [...amendedTags.value],
    ...(edits.summary !== undefined ? { summary: edits.summary } : {}),
  }
}

async function decide(entry: FeedEntry, approve: boolean) {
  if (decisionBusy.value || batchBusy.value || outcomeUnknown.value) return
  queueRequest++
  moreLoading.value = false
  const mine = lifetime.capture()
  if (approve && hasReviewConflict(entry)) return
  decisionBusy.value = true
  decisionError.value = null
  const v = verdictPayload()
  const neighbour = neighbourOf(entry.key)
  try {
    if (entry.kind === 'proposal') {
      const row = entry.proposal
      if (approve) {
        await api.approveProposal(row.id, proposalApprovalSnapshot(row), v, amendment(entry))
        if (!lifetime.current(mine)) return
        toastSuccess(
          row.type === 'delete' ? t('inbox.toast.note_deleted') : row.type === 'merge' ? t('inbox.toast.notes_merged') : t('inbox.toast.proposal_applied'),
          row.note.title,
        )
      } else {
        await api.rejectProposal(row.id, v)
        if (!lifetime.current(mine)) return
        toastSuccess(t('inbox.toast.proposal_rejected'))
      }
      proposals.value = proposals.value.filter((p) => p.id !== row.id)
    } else {
      const row = entry.note
      if (approve) {
        const edits = noteEdits()
        await api.approveNote(row.id, noteApprovalSnapshot(row.version), v, edits)
        if (!lifetime.current(mine)) return
        toastSuccess(edits ? t('inbox.toast.note_approved_amended') : t('inbox.toast.note_approved'))
      } else {
        await api.rejectNote(row.id, v)
        if (!lifetime.current(mine)) return
        toastSuccess(t('inbox.toast.note_rejected'))
      }
      pendingTotal.value = Math.max(0, pendingTotal.value - 1)
      pendingPage = 0
      items.value = items.value.filter((n) => n.id !== row.id)
    }
    selected.value = new Set([...selected.value].filter((key) => key !== entry.key))
    reviewConflicts.value.delete(entry.key)
    await focusAfter(neighbour)
    if (!lifetime.current(mine)) return
    openKey.value = null
    resetDecision()
    // Every single-item decision runs through here, so this is the one place
    // that has to say the badge is out of date. The handlers splice the decided
    // row out of the local list and never navigate, which is why the header's
    // route watch never noticed (operator, 2026-08-23).
    await inbox.refresh()
  } catch (e) {
    if (!lifetime.current(mine)) return
    // The expansion, the reason and the selection all stay: a verdict that
    // failed is a verdict still to be made.
    const message = e instanceof Error ? e.message : t('common.unknown_error')
    if (e instanceof ApiError && e.status === 409) reviewConflicts.value.add(entry.key)
    decisionError.value = hasReviewConflict(entry) ? `${message} ${t('inbox.review_changed')}` : message
  } finally {
    if (lifetime.current(mine)) decisionBusy.value = false
  }
}
</script>

<template>
  <div class="container mm-inbox">
    <OperationOutcomeNotice v-if="outcomeUnknown" />
    <nav class="app-breadcrumbs mm-inbox-breadcrumbs" :aria-label="$t('inbox.breadcrumb')">
      <RouterLink :to="{ name: 'search' }">{{ $t('inbox.notes') }}</RouterLink>
      <span aria-hidden="true">›</span>
      <strong aria-current="page">{{ $t('inbox.title') }}</strong>
    </nav>

    <header class="app-page-head mm-inbox-head">
      <div>
        <div class="app-title-line">
          <h1 id="inbox-heading" tabindex="-1">{{ $t('inbox.title') }}</h1>
          <span class="app-status app-status-pending" v-if="queueSize">{{ $t('inbox.waiting', { n: queueSize }) }}</span>
          <button type="button" class="btn btn-sm btn-secondary mm-inbox-refresh" :disabled="loading || decisionBusy || batchBusy" @click="refreshQueue">
            {{ $t('common.refresh') }}
          </button>
        </div>
        <p class="app-page-lede">
          {{ $t('inbox.lede') }}
        </p>
      </div>
      <div class="mm-inbox-tools" v-if="!loading && !loadError && !inboxZero">
        <label class="visually-hidden" for="kind-filter">{{ $t('inbox.filter_label') }}</label>
        <select id="kind-filter" class="form-select form-select-sm mm-kind-filter" v-model="kindFilter">
          <option value="">{{ $t('inbox.everything') }}</option>
          <option v-for="kind in availableKinds" :key="kind" :value="kind">{{ kindLabel(kind) }}</option>
        </select>
      </div>
    </header>

    <div class="app-notice app-notice-warning mm-inbox-report" v-if="batchReport">
      <span class="app-notice-mark">{{ batchReport.failed.length ? '!' : '✓' }}</span>
      <div>
        <strong>{{
          $t(batchReport.action === 'approve' ? 'inbox.batch.approved_report' : 'inbox.batch.rejected_report', {
            done: batchReport.done,
            total: batchReport.total,
          })
        }}</strong>
        <p v-if="batchReport.failed.length">{{ batchReport.failed.join('; ') }}</p>
        <p v-else>{{ $t('inbox.batch.all_decided') }}</p>
        <button type="button" class="btn btn-sm btn-link" @click="batchReport = null">{{ $t('inbox.verdict.dismiss') }}</button>
      </div>
    </div>

    <!-- The batch panel says what a bulk decision costs, because that is the
         thing the operator is choosing to give up. -->
    <section class="app-panel mm-batch" v-if="selected.size" :aria-label="$t('inbox.batch.label')">
      <div class="mm-batch-line">
        <strong>{{ $t('inbox.batch.selected', { n: selected.size }) }}</strong>
        <input
          type="text"
          class="form-control form-control-sm mm-batch-comment"
          :placeholder="$t('inbox.batch.reason_placeholder')"
          :aria-label="$t('inbox.batch.reason_label')"
          v-model="batchComment"
        >
        <button type="button" class="btn btn-sm btn-primary nowrap" :disabled="batchBusy" @click="askBatch('approve', $event)">
          <i class="fa-regular fa-circle-check"></i>{{ $t('inbox.batch.approve_selected') }}
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger nowrap" :disabled="batchBusy" @click="askBatch('reject', $event)">
          <i class="fa-solid fa-xmark"></i>{{ $t('inbox.batch.reject_selected') }}
        </button>
      </div>
      <p class="mm-batch-note">{{ $t('inbox.batch.hint') }}</p>
    </section>

    <div class="app-state app-state-error mm-inbox-state" v-if="loadError">
      <h4>{{ $t('inbox.load_failed') }}</h4>
      <p>{{ loadError }}</p>
      <button type="button" class="btn btn-sm btn-secondary" @click="retry">{{ $t('common.retry') }}</button>
    </div>

    <div class="app-tray mm-review-tray" v-else-if="loading" aria-busy="true">
      <header class="mm-review-head">
        <span class="app-skeleton mm-skeleton-count"></span>
      </header>
      <div class="mm-review-body">
        <div class="mm-review-card is-skeleton" v-for="n in 3" :key="n">
          <div class="mm-review-summary">
            <span class="app-skeleton mm-skeleton-check"></span>
            <div class="mm-review-copy">
              <span class="app-skeleton mm-skeleton-title"></span>
              <span class="app-skeleton mm-skeleton-meta"></span>
              <span class="app-skeleton mm-skeleton-preview"></span>
            </div>
            <span class="app-skeleton mm-skeleton-action"></span>
          </div>
        </div>
      </div>
      <p class="visually-hidden" role="status">{{ $t('inbox.loading') }}</p>
    </div>

    <div class="app-state mm-inbox-state" v-else-if="inboxZero">
      <h4>{{ $t('inbox.empty') }}</h4>
      <p>{{ $t('inbox.empty_body') }}</p>
    </div>

    <section class="app-tray mm-review-tray" v-else :aria-label="$t('inbox.pending_reviews')">
      <header class="mm-review-head">
        <button
          type="button"
          class="btn btn-sm btn-secondary"
          v-if="feed.length"
          @click="selected.size ? clearSelection() : selectAllVisible()"
        >{{ selected.size ? $t('inbox.clear_selection') : $t('inbox.select_all_shown') }}</button>
      </header>

      <div class="mm-review-body" v-if="nothingVisible">
        <div class="app-state mm-review-filtered">
          <h4>{{ $t('inbox.filtered_empty', { kind: kindLabel(kindFilter).toLowerCase() }) }}</h4>
          <p>{{ $t('inbox.filtered_empty_body') }}</p>
          <button type="button" class="btn btn-sm btn-secondary" @click="kindFilter = ''">{{ $t('inbox.show_everything') }}</button>
        </div>
      </div>

      <ul class="mm-review-body" v-else>
        <li
          class="mm-review-card"
          :class="{ 'is-open': openKey === entry.key }"
          v-for="{ entry, card } in cards"
          :key="entry.key"
        >
          <div class="mm-review-summary" @click="summaryClick(entry, $event)">
            <input
              class="form-check-input mm-review-check"
              type="checkbox"
              :aria-label="$t('inbox.select_item', { kind: card.chip.toLowerCase(), identity: card.identity })"
              :checked="selected.has(entry.key)"
              @click.stop
              @change="toggleSelected(entry.key)"
            >

            <div class="mm-review-copy">
              <div class="mm-review-title-line">
                <!-- The curator names these by number in its log and its
                     comments, so the number has to be on screen or the
                     reference goes nowhere. -->
                <span class="mm-review-id">{{ card.identity }}</span>
                <span class="mm-review-kind" :class="card.chipClass">{{ card.chip }}</span>
                <span class="mm-review-title">{{ card.title }}</span>
                <span class="mm-review-noteref" v-if="card.noteRef">{{ card.noteRef }}</span>
              </div>
              <div class="mm-review-meta">
                <span :title="formatDateTime(entry.at)">{{
                  openKey === entry.key ? formatDateTime(entry.at) : age(entry.at)
                }}</span>
                <span>{{ $t('inbox.by') }}</span>
                <ActorMark v-if="entry.kind === 'note' && entry.note.edited_by" :actor="entry.note.edited_by" />
                <span v-else-if="entry.kind === 'note'">{{ entry.note.source }}</span>
                <span v-else>{{ entry.proposal.proposed_by }}</span>
              </div>
              <!-- The note a merge consumes, named ONCE on the card. The icons
                   carry what happens to it: it is folded in, and then it is
                   gone. -->
              <p class="mm-review-relation" v-if="card.relation">
                <i class="fa-solid fa-code-merge mm-relation-merge" v-if="card.chipClass === 'is-merge'" aria-hidden="true"></i>
                <i class="fa-solid fa-trash-can mm-relation-delete" aria-hidden="true"></i>
                <span>{{ card.relation.title }}</span>
                <span class="mm-review-noteref" v-if="card.relation.noteRef">{{ card.relation.noteRef }}</span>
              </p>
              <div class="mm-review-reason" v-if="card.reason">
                <b>{{ $t('inbox.reason') }}</b>
                <div class="mm-reason-md" v-if="card.reasonBlock" v-html="card.reasonHtml"></div>
                <span v-else v-html="card.reasonHtml"></span>
              </div>
              <p class="mm-review-preview" v-if="card.preview">{{ card.preview }}</p>
              <div class="mm-review-signals" v-if="card.signals.length">
                <span class="mm-signal" :class="{ 'is-risk': signal.risk }" v-for="signal in card.signals" :key="signal.text">
                  {{ signal.text }}
                </span>
              </div>
            </div>

            <button
              type="button"
              class="btn btn-sm btn-secondary mm-review-expand"
              :id="expandId(entry.key)"
              :aria-expanded="openKey === entry.key"
              :aria-controls="panelId(entry.key)"
              @click.stop="toggleCard(entry)"
            >
              {{ openKey === entry.key ? $t('inbox.hide_review') : $t('inbox.review') }}
              <i class="fa-solid fa-chevron-down mm-review-chevron"></i>
            </button>
          </div>

          <div class="mm-review-detail-error" v-if="entry.kind === 'proposal' && entry.proposal.detailError">
            <span class="app-status app-status-danger">{{ $t('inbox.could_not_load', { identity: card.identity }) }}</span>
            <span>{{ entry.proposal.detailError }}</span>
            <button type="button" class="btn btn-sm btn-link" @click="toggleCard(entry)">{{ $t('inbox.try_again') }}</button>
          </div>
          <div class="mm-review-detail-error" v-else-if="entry.kind === 'note' && entry.note.detailError">
            <span class="app-status app-status-danger">{{ $t('inbox.could_not_load', { identity: card.identity }) }}</span>
            <span>{{ entry.note.detailError }}</span>
            <button type="button" class="btn btn-sm btn-link" @click="toggleCard(entry)">{{ $t('inbox.try_again') }}</button>
          </div>

          <section class="mm-review-panel" :id="panelId(entry.key)" v-if="openKey === entry.key">
            <div class="mm-review-intro">
              <div class="mm-loading-evidence" v-if="entry.kind === 'proposal' && !entry.proposal.detailLoaded">
                <span class="spinner-border spinner-border-sm" role="status"></span> {{ $t('inbox.loading_changes') }}
              </div>
              <div class="mm-loading-evidence" v-else-if="entry.kind === 'note' && !entry.note.detailLoaded">
                <span class="spinner-border spinner-border-sm" role="status"></span> {{ $t('inbox.loading_note') }}
              </div>

              <template v-else>
                <div
                  class="mm-evidence"
                  :class="{ 'is-editing': editableNote(entry) || editableProposal(entry) }"
                  role="region"
                  tabindex="0"
                  :aria-label="$t('inbox.evidence_label', { title: card.title })"
                >
                  <!-- Unlocked: the SAME pane, editable, field for field in
                       the same order. Not a second box below the diff — two
                       panes of one note left the operator reading the change
                       in one place and making it in another. -->
                  <div class="mm-note-form" v-if="editableProposal(entry) || editableNote(entry)">
                    <template v-if="amendsTitle(entry)">
                      <label class="form-label" :for="`${expandId(entry.key)}-amend-title`">{{ $t('inbox.amend.title_label') }}</label>
                      <input
                        :id="`${expandId(entry.key)}-amend-title`"
                        v-model="amendedTitle"
                        type="text"
                        class="form-control mm-amend-title"
                        maxlength="500"
                        :disabled="decisionBusy"
                      >
                      <!-- What the title is replacing. A one-line field has
                           nowhere to draw a diff, so the old text goes under
                           it, struck through, exactly when it differs. -->
                      <div class="mm-was" v-if="amendSeed && entry.kind === 'proposal' && amendedTitle !== entry.proposal.note.title">
                        <span>{{ $t('inbox.amend.was') }}</span> <del>{{ entry.proposal.note.title }}</del>
                      </div>
                    </template>

                    <label class="form-label" :for="`${expandId(entry.key)}-amend-body`">{{ $t('inbox.amend.body_label') }}</label>
                    <MarkdownEditor
                      v-model="amendedBody"
                      :disabled="decisionBusy"
                      :label="$t('inbox.amend.label')"
                      :track-changes-against="trackAgainst(entry)"
                    />

                    <label class="form-label">{{ $t('inbox.amend.tags_label') }}</label>
                    <TagInput v-model="amendedTags" :options="tagVocabulary" :disabled="decisionBusy" />
                    <div class="mm-was" v-if="droppedTags(entry).length">
                      <span>{{ $t('inbox.amend.removed') }}</span>
                      <del v-for="tag in droppedTags(entry)" :key="tag">{{ tag }}</del>
                    </div>

                    <label class="form-label" :for="`${expandId(entry.key)}-amend-summary`">{{ $t('inbox.amend.summary_label') }}</label>
                    <textarea
                      :id="`${expandId(entry.key)}-amend-summary`"
                      v-model="amendedSummary"
                      class="form-control"
                      rows="2"
                      :disabled="decisionBusy"
                    ></textarea>

                    <div class="mm-note">
                      {{ amendHint(entry) }}<span v-if="amendedBody.trim() === ''"> {{ $t('inbox.amend.cannot_empty') }}</span>
                    </div>

                    <!-- Unlocking a merge swaps the keeper's fields for
                         editors, and this is the text being folded into them —
                         so it stays, rather than the source disappearing at
                         the moment it is being used. -->
                    <template v-if="docOf(entry)?.destroyed">
                      <label class="form-label">{{ $t('inbox.amend.folding_in') }}</label>
                      <!-- eslint-disable-next-line vue/no-v-html — lineDiffHtml escapes both sides -->
                      <div class="mm-diff mm-diff-body mm-doc-destroyed" v-html="lineDiffHtml(docOf(entry)!.destroyed!.body, '')"></div>
                    </template>
                  </div>
                  <ProposedDocument
                    v-else-if="docOf(entry)"
                    :doc="docOf(entry)!"
                    :headed="true"
                  />
                </div>
              </template>
            </div>

            <div class="app-notice app-notice-danger mm-decision-error" v-if="decisionError || hasReviewConflict(entry)">
              <span class="app-notice-mark">!</span>
              <div>
                <strong>{{ $t('inbox.verdict_failed') }}</strong>
                <p>{{ decisionError ?? $t('inbox.review_changed') }}</p>
                <button type="button" class="btn btn-sm btn-secondary" :disabled="decisionBusy" @click="refreshReview(entry)">
                  {{ $t('inbox.refresh_review') }}
                </button>
              </div>
            </div>

            <footer class="mm-decision-footer">
              <button
                type="button"
                class="btn btn-sm btn-secondary mm-amend-toggle"
                v-if="amendable(entry)"
                :disabled="decisionBusy"
                :aria-pressed="amendOpen"
                @click="toggleAmend(entry)"
              >
                <i class="fa-solid fa-pen"></i>{{ amendOpen ? $t('inbox.amend.discard') : $t('inbox.amend.open') }}
              </button>
              <span v-else></span>
              <div class="mm-decision-actions">
                <button
                  type="button"
                  class="btn btn-sm nowrap"
                  :class="card.rejectDanger ? 'btn-danger' : 'btn-secondary'"
                  :disabled="decisionBusy || !evidenceReady(entry)"
                  @click="decide(entry, false)"
                >
                  <i class="fa-solid fa-xmark"></i>{{ card.rejectLabel }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm nowrap"
                  :class="card.approveDanger ? 'btn-danger' : 'btn-primary'"
                  :disabled="decisionBusy || !evidenceReady(entry) || !approvable(entry)"
                  @click="decide(entry, true)"
                >
                  <span v-if="decisionBusy" class="spinner-border spinner-border-sm" role="status"></span>
                  <i v-else class="fa-regular fa-circle-check"></i>{{ card.approveLabel }}
                </button>
              </div>
            </footer>
          </section>
        </li>
      </ul>

      <footer class="app-tray-footer">
        <span>{{ $t('inbox.items_waiting', queueSize) }}</span>
      </footer>
    </section>

    <InboxBatchDialog
      v-if="batchAction"
      :action="batchAction"
      :count="selected.size"
      :warning="batchWarning"
      :destructive="batchAction === 'reject' ? selectionHasNotes : selectionDestroys"
      :busy="batchBusy"
      @close="closeBatch"
      @confirm="runBatch"
    />
    <div v-if="!loading && !loadError && pendingTotal > items.length" class="app-panel">
      <p>{{ $t('inbox.loaded_pending', { shown: items.length, total: pendingTotal }) }}</p>
      <p v-if="moreError" role="alert">{{ moreError }}</p>
      <button type="button" class="btn btn-secondary mm-inbox-more" :disabled="moreLoading || decisionBusy || batchBusy" @click="loadMore">
        {{ $t(moreLoading ? 'common.loading' : 'inbox.load_more') }}
      </button>
    </div>

    <ConfirmDialog v-if="discardDialogOpen"
      :title="$t('inbox.discard_amendment_title')"
      :confirm-label="$t('inbox.discard_amendment_confirm')"
      danger
      @confirm="finishDiscard(true)"
      @close="finishDiscard(false)">
      {{ $t('inbox.discard_amendment_body') }}
    </ConfirmDialog>
  </div>
</template>
