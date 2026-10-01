<script setup lang="ts">
// mm2's article view: h1, View/Edit nav-tabs, content column + a dl metadata
// sidebar. Memex extras: pending approve/reject, proposal alert, backlinks,
// MD export, wiki-link routing.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, ApiError, noteApprovalSnapshot, type NoteDetail, type NoteRevision } from '@/api/client'
import { renderNote } from '@/lib/markdown'
import { lineDiffCounts, lineDiffHtml } from '@/lib/diffs'
import { formatCount, noteStats } from '@/lib/noteStats'
import { toastError, toastSuccess } from '@/components/toastService'
import ActorMark from '@/components/ActorMark.vue'
import NoteMap from '@/components/NoteMap.vue'
import PaneToggle from '@/components/PaneToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { useLayoutStore } from '@/stores/layout'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import OperationOutcomeNotice from '@/components/OperationOutcomeNotice.vue'
import { useSystemTags } from '@/lib/systemTags'
import { formatDate, formatDateTime } from '@/lib/datetime'

const { isSystemTag, systemReason } = useSystemTags()
const { t } = useI18n()

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const layout = useLayoutStore()
const lifetime = useOperationLifetime()
const outcomeUnknown = ref(false)

const note = ref<NoteDetail | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const approvalConflict = ref(false)

const rendered = computed(() =>
  note.value ? renderNote(note.value.body_md, note.value.links, auth.user?.team.handle) : ''
)
const danglingTargets = computed(() =>
  (note.value?.links ?? [])
    .filter((l) => l.note_id === null)
    .map((l) => l.target.replace(/[ \t\r\n]+/g, ' ').trim()),
)
// Size/token stats are derived from the body on the fly — never stored, so they
// cannot drift from the text being shown.
const stats = computed(() => noteStats(note.value?.body_md ?? ''))

/**
 * Only the two arrivals the Created line cannot already describe.
 *
 * `manual` and `agent` said "Written here" and "Written by an assistant" beside
 * a Created line naming the same person or connection, on 70 of the operator's
 * 156 notes — the same fact twice (operator, 2026-08-27). A file and a URL are
 * facts of their own, so those two keep the row.
 */
const SOURCE_LABEL_KEYS: Record<string, string> = {
  scrape: 'note.source.scrape',
  upload: 'note.source.upload',
  memex: 'note.source.memex',
}

const sourceLabel = computed(() => {
  const key = note.value ? SOURCE_LABEL_KEYS[note.value.source] : undefined
  return key ? t(key) : null
})

/**
 * A long description is clamped rather than shown whole.
 *
 * The point of a summary is to tell somebody what a note is inside a few
 * seconds (operator, 2026-08-23), and descriptions written before there was a
 * length to write to run to thousands of characters — one is a single
 * 4,000-character paragraph, which is not a summary of the note so much as a
 * second note.
 *
 * MEASURED, not counted, the way `InboxComment` is: the trigger used to be
 * `length > SUMMARY_SOFT_CAP`, and a 356-character summary fills exactly the
 * four lines the clamp allows at 776px — so the button appeared and pressing
 * it changed nothing (operator, 2026-08-27).
 *
 * And it clamps only when it hides MORE than one line: a summary of five lines
 * bought a link that revealed a single line, which is a click to learn nothing
 * (operator, 2026-08-27). So the fifth line is shown instead, and `is-clamped`
 * comes off — which is why the measurement reads the clamp height off the CSS
 * variable rather than off `clientHeight`, since an unclamped element's own
 * height cannot say what clamping it would cost.
 */
const summaryExpanded = ref(false)
const summaryBody = ref<HTMLElement | null>(null)
const summaryClipped = ref(false)

function measureSummary() {
  const el = summaryBody.value
  if (!el || summaryExpanded.value) return
  const style = getComputedStyle(el)
  const lineHeight = parseFloat(style.lineHeight)
  const lines = parseFloat(style.getPropertyValue('--mm-summary-clamp'))
  if (!lineHeight || !lines) {
    summaryClipped.value = false
    return
  }
  // `scrollHeight` is a border-box measurement and the abstract has padding,
  // so the padding has to come off before the height is compared against a
  // count of lines — otherwise every summary looks a padding taller than it is
  // and one exactly at the boundary flips clamped and unclamped forever.
  const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom)
  summaryClipped.value = el.scrollHeight - padding - lines * lineHeight > lineHeight * 1.5
}

function toggleSummary() {
  summaryExpanded.value = !summaryExpanded.value
  // Collapsing has to re-ask: an expanded summary has no clamp to overflow, so
  // nothing measures while it is open and no resize callback comes to correct
  // a `clipped` that was true at another width.
  if (!summaryExpanded.value) requestAnimationFrame(measureSummary)
}

let summaryObserver: ResizeObserver | null = null
// WIDTH only. Clamping changes the summary's HEIGHT, which is a resize of the
// element being observed, which measures again — a loop the browser reports as
// "ResizeObserver loop completed with undelivered notifications" and which
// pins the main thread for as long as a window is being dragged. Only a change
// of width can change how many lines the text takes.
let summaryWidth = 0
onMounted(() => {
  summaryObserver = new ResizeObserver((entries) => {
    const width = entries[0]?.contentRect.width ?? 0
    if (width === summaryWidth) return
    summaryWidth = width
    measureSummary()
  })
})
onBeforeUnmount(() => summaryObserver?.disconnect())
watch(summaryBody, (el) => {
  summaryObserver?.disconnect()
  if (el) {
    summaryObserver?.observe(el)
    requestAnimationFrame(measureSummary)
  }
})

// The server refuses a source_url that is not http(s), because a bound :href is
// not sanitised by Vue and `javascript:` in one runs on click — at review time,
// on the very link whose job is to prove where an agent's note came from. This
// second check is for rows written before that rule existed: they still show
// their recorded provenance, as text, with nothing to click.
const safeSourceUrl = computed(() => {
  const raw = note.value?.source_url
  if (!raw) return null
  try {
    return ['http:', 'https:'].includes(new URL(raw).protocol) ? raw : null
  } catch {
    return null
  }
})

// History. Loaded with the note rather than behind a click: the number of
// previous states is itself the useful signal — a note an unattended pass has
// rewritten four times is worth a second look, and nobody opens a panel to find
// out whether the panel is worth opening.
const revisions = ref<NoteRevision[]>([])
const openRevision = ref<number | null>(null)
const historyLoading = ref(false)
const historyError = ref<string | null>(null)
let historyRequest = 0

async function load() {
  outcomeUnknown.value = false
  lifetime.invalidate()
  const mine = lifetime.capture()
  const id = Number(route.params.id)
  loading.value = true
  loadError.value = null
  note.value = null
  revisions.value = []
  historyRequest++
  historyError.value = null
  historyLoading.value = false
  summaryExpanded.value = false
  approvalConflict.value = false
  flagSaving.value = false
  flagEditorOpen.value = false
  try {
    const result = await api.getNote(id)
    if (!lifetime.current(mine)) return
    note.value = result
  } catch (e) {
    if (!lifetime.current(mine)) return
    loadError.value = e instanceof Error ? e.message : t('common.unknown_error')
  } finally {
    if (lifetime.current(mine)) loading.value = false
  }
  await nextTick()
  if (!lifetime.current(mine)) return
  measureSummary()
  await loadRevisions()
}

async function loadRevisions() {
  const mine = lifetime.capture()
  const request = ++historyRequest
  const current = () => lifetime.current(mine) && request === historyRequest
  openRevision.value = null
  historyError.value = null
  revisions.value = []
  if (!note.value) return
  historyLoading.value = true
  try {
    const history = await api.noteRevisions(note.value.id)
    if (current()) revisions.value = history.revisions
  } catch (e) {
    if (current()) historyError.value = e instanceof Error ? e.message : t('common.unknown_error')
  } finally {
    if (current()) historyLoading.value = false
  }
}

/**
 * The text that REPLACED a given revision.
 *
 * Rows are newest first and each holds what the note contained BEFORE some
 * edit — so the state that edit produced is the row above it, and for the
 * newest row it is the note as it stands. That pairing is what turns a list of
 * old bodies into a list of changes, and it needs no server round trip: the
 * history response already carries every reconstructed body.
 */
function replacedBy(index: number): string | null {
  if (index === 0) return note.value?.body_md ?? null
  return revisions.value[index - 1]?.body_md ?? null
}

/**
 * What that edit did, as +added / −removed lines.
 *
 * On the collapsed row, because most rows will never have a written headline —
 * everything from before 2026-08-23 and every edit made in the editor — and a
 * bare date does not tell you whether a row is worth opening.
 */
function changeSize(revision: NoteRevision, index: number): { added: number; removed: number } | null {
  const after = replacedBy(index)
  if (after === null || revision.body_md === null) return null

  return lineDiffCounts(revision.body_md, after)
}

/** The diff itself: what the edit removed and added, git-style. */
function changeDiff(revision: NoteRevision, index: number): string | null {
  const after = replacedBy(index)
  if (after === null || revision.body_md === null) return null

  return lineDiffHtml(revision.body_md, after)
}

/**
 * What the edit that replaced this state DID, in the vocabulary of the write
 * paths rather than of the actor's role.
 *
 * `replaced_by` says only what kind of actor wrote, so every edit by a token
 * with curator rights read "replaced by curator" — a curation pass that never
 * happened (operator, 2026-08-27). Rows written before that carry no
 * `operation`, and 90 of them could not be matched to the activity log either;
 * those keep the wording they have always had rather than being given a verb
 * nobody recorded.
 */
const OPERATION_LABEL_KEYS: Record<string, string> = {
  edit: 'note.operation.edit',
  proposal: 'note.operation.proposal',
  merge: 'note.operation.merge',
  restore: 'note.operation.restore',
}

function operationLabel(revision: NoteRevision): string {
  const key = revision.operation ? OPERATION_LABEL_KEYS[revision.operation] : null
  if (key) return t(key)

  return revision.replaced_by_actor
    ? t('note.operation.replaced')
    : t('note.operation.replaced_by', { by: revision.replaced_by })
}

/** Whole previous text instead of the change — for checking before a restore. */
const showFullText = ref<number | null>(null)

async function restoreRevision(revision: NoteRevision) {
  if (!note.value) return
  if (!window.confirm(t('note.history.restore_confirm'))) return
  const mine = lifetime.capture()
  try {
    await api.restoreRevision(note.value.id, revision.id)
    if (!lifetime.current(mine)) return
    toastSuccess(t('note.history.restored_title'), t('note.history.restored_body', { date: formatDateTime(revision.content_updated_at) }))
    await load()
  } catch (e) {
    if (!lifetime.current(mine)) return
    toastError(t('note.history.restore_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

async function forgetHistory() {
  if (!note.value) return
  // Two sentences, because the second is the whole reason this button exists
  // and the first is the reason it is dangerous.
  if (!window.confirm(t('note.history.forget_confirm', { n: revisions.value.length }))) return
  const mine = lifetime.capture()
  try {
    const result = await api.forgetRevisions(note.value.id)
    if (!lifetime.current(mine)) return
    toastSuccess(t('note.history.forgotten_title'), t('note.history.forgotten_body', { n: result.forgotten }))
    await loadRevisions()
  } catch (e) {
    if (!lifetime.current(mine)) return
    toastError(t('note.history.forget_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}


// Rendered wiki-links are plain <a href="/notes/N">, and so is a link a note
// makes to a page of this knowledge base (the welcome notes link to Settings)
// — route them through the SPA router.
function onBodyClick(event: MouseEvent) {
  const anchor = (event.target as HTMLElement).closest('a')
  const href = anchor?.getAttribute('href')
  const own = auth.user ? `/${auth.user.team.handle}/` : null
  if (anchor && href && (anchor.classList.contains('wiki-link') || (own !== null && href.startsWith(own)))) {
    event.preventDefault()
    router.push(href)
  }
}

// Flagging for curation. The comment is the whole feature: the queue can find
// a note with no tags on its own, but "this section is out of date since we
// moved the box" is knowledge that exists nowhere in the schema, and this is
// the only channel that carries it to the curator in the operator's own words.
const flagEditorOpen = ref(false)
const flagComment = ref('')
const flagSaving = ref(false)

function openFlagEditor() {
  flagComment.value = note.value?.curation_flag?.comment ?? ''
  flagEditorOpen.value = true
}

async function saveFlag() {
  if (!note.value || flagSaving.value || outcomeUnknown.value) return
  const comment = flagComment.value.trim()
  if (!comment) {
    toastError(t('note.flag.needs_comment_title'), t('note.flag.needs_comment_body'))
    return
  }
  flagSaving.value = true
  const mine = lifetime.capture()
  try {
    const result = await api.flagNote(note.value.id, comment)
    if (!lifetime.current(mine)) return
    note.value.curation_flag = result.curation_flag
    note.value.flagged = true
    flagEditorOpen.value = false
    toastSuccess(t('note.flag.flagged_for_curation'), t('note.flag.flagged_body'))
  } catch (e) {
    if (!lifetime.current(mine)) return
    toastError(t('note.flag.flag_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (lifetime.current(mine)) flagSaving.value = false
  }
}

async function withdrawFlag() {
  if (!note.value?.curation_flag) return
  // Withdrawing is "never mind", not "done" — the curator's own answer comes
  // back through the log instead, which is why this asks before dropping it.
  if (!window.confirm(t('note.flag.withdraw_confirm'))) return
  const mine = lifetime.capture()
  try {
    await api.unflagNote(note.value.id)
    if (!lifetime.current(mine)) return
    note.value.curation_flag = null
    note.value.flagged = false
    flagEditorOpen.value = false
    toastSuccess(t('note.flag.withdrawn'))
  } catch (e) {
    if (!lifetime.current(mine)) return
    toastError(t('note.flag.withdraw_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

// The two verdicts, and the only two mutations in this file that used to run
// bare — no try/catch, an unconditional success toast, and for reject a
// router.push that ran whether or not the reject had happened. Four other
// mutations in this same file (saveFlag, withdrawFlag, restoreVersion, forget)
// were written correctly; these two were skipped, which is why the pattern is
// spelled out rather than assumed.
//
// What that cost: a 403 (token lost its role), a 409 (someone else ruled on
// this note first) or a 500 all rendered as "Note approved", and reject then
// navigated to the inbox as if it had worked. This is the review gate's own
// screen. It is the one place in the product where a false success is not a
// cosmetic bug — the operator's entire reason to trust the gate is that what
// it says happened, happened. Filed by the 2026-08-21 audit as L-5, confirmed,
// then lost between the report and the remediation tracker; refound 2026-08-24.
async function approve() {
  if (!note.value || approvalConflict.value) return
  const mine = lifetime.capture()
  try {
    await api.approveNote(note.value.id, noteApprovalSnapshot(note.value.version))
    if (!lifetime.current(mine)) return
    toastSuccess(t('note.review.approved'), note.value.title)
    await load()
  } catch (e) {
    if (!lifetime.current(mine)) return
    approvalConflict.value = e instanceof ApiError && e.status === 409
    toastError(t('note.review.approve_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

async function reject() {
  if (!note.value) return
  if (!window.confirm(t('note.review.reject_confirm'))) return
  const mine = lifetime.capture()
  try {
    await api.rejectNote(note.value.id)
    if (!lifetime.current(mine)) return
    toastSuccess(t('note.review.rejected'))
    // Inside the try, after the call: navigating away from a reject that
    // failed is what made the failure invisible.
    router.push({ name: 'inbox' })
  } catch (e) {
    if (!lifetime.current(mine)) return
    toastError(t('note.review.reject_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
    await load()
  }
}

watch(sessionEpoch, () => {
  lifetime.invalidate()
  if (loading.value) {
    loading.value = false
    loadError.value = t('common.session_changed')
  }
  if (historyLoading.value) {
    historyLoading.value = false
    historyError.value = t('common.session_changed')
  }
  if (flagSaving.value) outcomeUnknown.value = true
})
watch([() => route.params.id, () => route.params.handle, () => auth.user?.team.handle], load, { immediate: true })
</script>

<template>
  <div class="container">
    <OperationOutcomeNotice v-if="outcomeUnknown" />
    <div v-if="loadError" class="app-state app-state-error">
      <h1>{{ $t('note.load_failed') }}</h1>
      <p>{{ loadError }}</p>
      <button type="button" class="btn btn-secondary" @click="load">{{ $t('common.retry') }}</button>
    </div>
    <template v-if="loading">
      <div class="row my-4">
        <div class="col">
          <div class="text-center">
            <div class="spinner-border" role="status">
              <span class="visually-hidden">{{ $t('note.loading') }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <template v-if="!loading && note">
      <nav class="app-breadcrumbs mm-note-breadcrumbs" :aria-label="$t('note.breadcrumb.label')">
        <RouterLink :to="{ name: 'search' }">{{ $t('note.breadcrumb.notes') }}</RouterLink>
        <span aria-hidden="true">›</span>
        <strong aria-current="page">{{ $t('note.breadcrumb.current') }}</strong>
      </nav>

      <header class="mm-note-heading">
        <div class="mm-note-identity">
          <p class="app-eyebrow mm-note-eyebrow">{{ $t('note.eyebrow', { id: note.id }) }}</p>
          <h1 class="mm-note-title">{{ note.title }}</h1>
          <div class="mm-note-subline">
            <span
              class="app-status"
              :class="note.status === 'pending' ? 'app-status-pending' : 'app-status-verified'"
            >
              <i :class="note.status === 'pending' ? 'fa-regular fa-clock' : 'fa-solid fa-check'"></i>
              {{ note.status === 'pending' ? $t('note.status.pending') : $t('note.status.verified') }}
            </span>
            <span>{{ $t('note.modified_at', { date: formatDateTime(note.updated_at) }) }}</span>
            <template v-if="note.edited_by">
              <span aria-hidden="true">·</span>
              <ActorMark :actor="note.edited_by" />
            </template>
          </div>
        </div>

        <div class="mm-note-actions">
          <template v-if="note.status === 'pending'">
            <button type="button" class="btn btn-sm btn-success" :disabled="approvalConflict" @click="approve">
              <i class="fa-regular fa-circle-check"></i> {{ $t('note.review.approve') }}
            </button>
            <span v-if="approvalConflict" role="alert">{{ $t('note.review.changed') }}</span>
            <button v-if="approvalConflict" type="button" class="btn btn-sm btn-secondary" @click="load">
              {{ $t('common.refresh') }}
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="reject">
              <i class="fa-solid fa-xmark"></i> {{ $t('note.review.reject') }}
            </button>
          </template>
          <RouterLink class="btn btn-sm btn-primary" :to="{ name: 'note-edit', params: { id: note.id } }">
            <i class="fa-solid fa-pen"></i> {{ $t('note.actions.edit') }}
          </RouterLink>
          <button
            type="button"
            class="btn btn-sm btn-warning"
            :title="note.curation_flag ? $t('note.flag.title_flagged') : $t('note.flag.title_unflagged')"
            @click="openFlagEditor"
          >
            <i class="fa-solid fa-flag"></i>
            {{ note.curation_flag ? $t('note.flag.flagged') : $t('note.flag.flag_for_curation') }}
          </button>
          <a class="btn btn-sm btn-charcoal" :href="api.exportNoteUrl(note.id)"
             :title="$t('note.actions.download_title')">
            <i class="fa-regular fa-circle-down"></i> {{ $t('note.actions.download') }}
          </a>
        </div>
      </header>

      <!-- The standing instruction, shown above the note itself: anyone reading
           this page should see the operator's complaint before the text it is
           about, exactly as the curator does. -->
      <div v-if="note.curation_flag && !flagEditorOpen" class="app-notice app-notice-warning mm-flag-notice">
        <span class="app-notice-mark"><i class="fa-solid fa-flag"></i></span>
        <div>
          <div class="mm-flag-head">
            <div>
              <strong>{{ $t('note.flag.flagged_for_curation') }}</strong>
              <span class="mm-flag-meta">
                {{ $t('note.flag.meta', { by: note.curation_flag.flagged_by, date: formatDateTime(note.curation_flag.flagged_at) }) }}
                <template v-if="note.curation_flag.reworded_at">
                  {{ $t('note.flag.reworded', { date: formatDateTime(note.curation_flag.reworded_at) }) }}
                </template>
              </span>
            </div>
            <div class="mm-flag-actions">
              <button type="button" class="btn btn-sm btn-outline-secondary" @click="openFlagEditor">{{ $t('common.edit') }}</button>
              <button type="button" class="btn btn-sm btn-outline-danger" @click="withdrawFlag">{{ $t('note.flag.withdraw') }}</button>
            </div>
          </div>
          <p class="mm-flag-comment">{{ note.curation_flag.comment }}</p>
        </div>
      </div>

      <div v-if="flagEditorOpen" class="app-notice app-notice-warning mm-flag-editor">
        <span class="app-notice-mark"><i class="fa-solid fa-flag"></i></span>
        <div>
          <label class="form-label" for="flag-comment">
            <strong>{{ $t('note.flag.editor_label') }}</strong>
          </label>
          <textarea
            id="flag-comment"
            class="form-control"
            rows="4"
            v-model="flagComment"
            :placeholder="$t('note.flag.placeholder')"
          ></textarea>
          <div class="mm-flag-actions mt-2">
            <button type="button" class="btn btn-sm btn-warning" :disabled="flagSaving || outcomeUnknown" @click="saveFlag">
              <span v-if="flagSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
              {{ note.curation_flag ? $t('common.save') : $t('note.flag.flag_for_curation') }}
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="flagEditorOpen = false">
              {{ $t('common.cancel') }}
            </button>
            <button
              v-if="note.curation_flag"
              type="button"
              class="btn btn-sm btn-outline-danger"
              @click="withdrawFlag"
            >
              {{ $t('note.flag.withdraw_flag') }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="note.pending_proposals > 0" class="app-notice app-notice-pending mm-proposal-notice">
        <span class="app-notice-mark"><i class="fa-solid fa-file-pen"></i></span>
        <i18n-t keypath="note.proposals.pending" tag="p" :plural="note.pending_proposals" scope="global">
          <template #link><router-link :to="{ name: 'inbox' }">{{ $t('note.proposals.pending_link') }}</router-link></template>
        </i18n-t>
      </div>

      <div class="mm-reading-desk" :class="{ 'is-wide': layout.railHidden.note }">
        <article class="app-paper mm-note-paper">
          <PaneToggle pane="note" class="mm-desk-toggle" />
          <template v-if="note.summary">
            <div ref="summaryBody" class="mm-note-abstract mm-summary"
                 :class="{ 'is-clamped': summaryClipped && !summaryExpanded }">
              {{ note.summary }}
            </div>
            <button
              v-if="summaryClipped || summaryExpanded"
              type="button"
              class="btn btn-link btn-sm px-0 mm-summary-toggle"
              :aria-expanded="summaryExpanded"
              @click="toggleSummary"
            >
              {{ summaryExpanded ? $t('note.summary.show_less') : $t('note.summary.show_more') }}
            </button>
          </template>
          <!-- eslint-disable-next-line vue/no-v-html — renderNote escapes source markdown -->
          <div class="note-body" v-html="rendered" @click="onBodyClick"></div>
        </article>

        <aside class="mm-note-context" :aria-label="$t('note.context_label')">
          <section class="app-panel mm-context-panel mm-context-details">
            <h2 class="mm-context-title">{{ $t('note.details.title') }}</h2>
            <dl class="mm-note-facts">
              <dt>{{ $t('note.details.created') }}</dt>
              <dd class="mm-note-byline">
                {{ formatDate(note.created_at) }}
                <template v-if="note.added_by">
                  <span class="text-muted">{{ $t('note.details.by') }}</span>
                  <!-- A link only when a CONNECTION added it. "Everything this
                       assistant put here" is a real question with a real answer;
                       "everything you added yourself" is the whole knowledge base
                       for a solo operator, so a person's name stays plain text. -->
                  <RouterLink
                    v-if="note.added_by_token_id !== null"
                    :to="{ name: 'search', query: { added_by: String(note.added_by_token_id) } }"
                    class="mm-added-by-link"
                    :title="$t('note.details.added_by_title', { name: note.added_by.name })"
                  >
                    <ActorMark :actor="note.added_by" />
                  </RouterLink>
                  <ActorMark v-else :actor="note.added_by" />
                </template>
              </dd>

              <dt>{{ $t('note.details.modified') }}</dt>
              <dd class="mm-note-byline">
                {{ formatDateTime(note.updated_at) }}
                <template v-if="note.edited_by">
                  <span class="text-muted">{{ $t('note.details.by') }}</span>
                  <ActorMark :actor="note.edited_by" />
                </template>
              </dd>

              <template v-if="sourceLabel || note.source_url">
                <dt>{{ $t('note.details.source') }}</dt>
                <dd>
                  <template v-if="sourceLabel">{{ sourceLabel }}</template>
                  <!-- The URL sits under its own label rather than on the Source
                       line: it is the one value here that can be 200 characters
                       long, and inlining it pushed everything else about. -->
                  <div v-if="safeSourceUrl">
                    <a :href="safeSourceUrl" target="_blank" rel="noopener" class="text-break">{{ safeSourceUrl }}</a>
                  </div>
                  <div v-else-if="note.source_url" class="text-break text-muted">{{ note.source_url }}</div>
                </dd>
              </template>

              <dt>{{ $t('note.details.size') }}</dt>
              <dd>{{ $t('note.details.size_value', { words: formatCount(stats.words), tokens: formatCount(stats.tokens) }) }}</dd>
            </dl>

            <div class="mm-note-tags" v-if="note.tags.length">
              <span v-for="tag in note.tags" :key="tag.id" class="mm-tag"
                    :class="{ 'mm-tag-system': isSystemTag(tag.name) }"
                    :title="systemReason(tag.name) ?? undefined">{{ tag.name }}</span>
            </div>
          </section>

          <section class="app-panel mm-context-panel mm-context-map">
            <h2 class="mm-context-title">{{ $t('map.neighbourhood') }}</h2>
            <NoteMap :note-id="note.id" height="178px" compact>
              <template #caption-action>
                <router-link
                  class="btn btn-link btn-sm mm-open-map"
                  :to="{ name: 'search', query: { view: 'map', note: note.id } }"
                >
                  {{ $t('note.map.open_full') }}
                </router-link>
              </template>
            </NoteMap>
            <div class="mm-dangling" v-if="danglingTargets.length">
              <p class="mm-dangling-head">{{ $t('note.dangling.head', danglingTargets.length) }}</p>
              <ul class="mm-dangling-list">
                <li v-for="target in danglingTargets" :key="target">
                  <i class="fa-solid fa-link-slash"></i>
                  <span>{{ target }}</span>
                </li>
              </ul>
            </div>
          </section>

          <section class="app-panel mm-context-panel mm-context-backlinks" v-if="note.backlinks.length">
            <h2 class="mm-context-title">{{ $t('note.backlinks') }}</h2>
            <ul class="mm-backlink-list">
              <li v-for="backlink in note.backlinks" :key="backlink.note_id">
                <i class="fa-solid fa-link"></i>
                <router-link :to="{ name: 'note', params: { id: backlink.note_id } }">{{ backlink.title }}</router-link>
              </li>
            </ul>
          </section>
        </aside>
      </div>

      <section class="app-ledger mm-history-ledger">
        <header class="mm-history-heading">
          <div>
            <h2>{{ $t('note.history.title') }}</h2>
            <p>{{ $t('note.history.lede') }}</p>
          </div>
          <span class="mm-history-count" v-if="!historyLoading && !historyError">
            {{ $t('note.history.count', revisions.length) }}
          </span>
        </header>

        <p v-if="historyLoading" class="mm-note">{{ $t('common.loading') }}</p>
        <div v-else-if="historyError" class="app-notice app-notice-danger mm-history-error" role="alert">
          <span>{{ historyError }}</span>
          <button type="button" class="btn btn-sm btn-secondary" @click="loadRevisions">{{ $t('common.retry') }}</button>
        </div>

        <div
          v-for="(revision, index) in revisions"
          :key="revision.id"
          class="mm-revision-entry"
          :class="{ 'is-open': openRevision === revision.id }"
        >
          <button
            type="button"
            class="mm-revision-row"
            :aria-expanded="openRevision === revision.id"
            @click="openRevision = openRevision === revision.id ? null : revision.id"
          >
            <span class="mm-revision-disclosure"><i class="fa-solid fa-chevron-right"></i></span>
            <!--
              What the edit did, and WHEN THE EDIT HAPPENED — `replaced_at`, not
              `content_updated_at`, which is when the text this edit removed was
              itself written. Every other thing on this row describes the edit,
              so a date describing the version it replaced reads as the edit's
              own and is off by however long that version stood.
            -->
            <span class="mm-revision-title">
              {{ revision.change_title || operationLabel(revision) }}
            </span>
            <span class="mm-revision-date">{{ formatDateTime(revision.replaced_at) }}</span>
            <span class="mm-revision-by">
              <ActorMark v-if="revision.replaced_by_actor" :actor="revision.replaced_by_actor" />
              <!-- Approving is a gate rather than authorship, so the mark above
                   stays the proposer's. This says the text that landed was not
                   the text they filed. -->
              <span class="mm-revision-amended" v-if="revision.amended_by_operator">{{ $t('note.history.amended') }}</span>
            </span>
            <!--
              The size of the change, for the rows that have no headline — which
              is every row written before 2026-08-23 and every edit made in the
              editor. memex writes no prose of its own to fill that gap, but a
              line count is a fact about the two texts rather than something
              invented, and it is the difference between a typo fix and a
              rewrite at a glance.
            -->
            <span class="mm-revision-delta">
              <template v-if="changeSize(revision, index)">
                <span class="mm-diff-add" v-if="changeSize(revision, index)!.added">
                  +{{ changeSize(revision, index)!.added }}
                </span>
                <span class="mm-diff-del" v-if="changeSize(revision, index)!.removed">
                  −{{ changeSize(revision, index)!.removed }}
                </span>
              </template>
            </span>
          </button>

          <div v-if="openRevision === revision.id" class="mm-revision-detail">
            <p class="mm-revision-meta">
              <strong>{{ revision.title }}</strong>
              <span v-if="revision.tags.length">· {{ revision.tags.join(', ') }}</span>
            </p>
            <!--
              WHAT CHANGED, not the whole document (operator, 2026-08-23). A
              revision row holds a full previous body, and printing it was a wall
              of text in which the edit — usually two lines of a forty-line note
              — was invisible. The whole version stays one click away, because
              that is what somebody reads before pressing Restore.
            -->
            <template v-if="showFullText !== revision.id && changeDiff(revision, index) !== null">
              <!-- eslint-disable-next-line vue/no-v-html — lineDiffHtml escapes both sides -->
              <div class="mm-diff-body" v-html="changeDiff(revision, index)"></div>
            </template>
            <pre v-else class="mm-revision-body small">{{ revision.body_md }}</pre>

            <div class="mm-revision-detail-footer">
              <button type="button" class="btn btn-sm btn-outline-primary" @click="restoreRevision(revision)">
                <i class="fa-solid fa-rotate-left"></i> {{ $t('note.history.restore') }}
              </button>
              <button
                type="button"
                class="btn btn-link btn-sm mm-whole-version"
                @click="showFullText = showFullText === revision.id ? null : revision.id"
              >
                {{ showFullText === revision.id ? $t('note.history.show_diff') : $t('note.history.show_whole') }}
              </button>
            </div>
          </div>
        </div>

        <footer class="mm-history-footer">
          <button type="button" class="btn btn-link btn-sm mm-forget-history" :disabled="historyLoading || !!historyError" @click="forgetHistory">
            <i class="fa-solid fa-eraser"></i> {{ $t('note.history.forget') }}
          </button>
          <small>{{ $t('note.history.forget_hint') }}</small>
        </footer>
      </section>
    </template>
  </div>
</template>
