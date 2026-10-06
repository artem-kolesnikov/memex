<script setup lang="ts">
// The Journal: ONE unified ledger of what has been DONE to this knowledge
// base — curation passes, ad-hoc agent work, the owner's own decisions — in
// one chronological table. The default view of /activity.
//
// What is NOT here: the per-run curation digest, which is the other view of
// /activity, and the queue of what still wants doing, which the browser does
// not show at all. A ledger answers "what happened"; a digest answers "what
// did that pass claim"; a queue answers "what is left". Nor any statistic —
// a count of the vault is not a record of anything.
//
// The curation and run filters below carry the digest's links: one from a pass
// opens this table scoped to that pass, which is the raw evidence behind its
// counts.
//
// Filters are applied by the SERVER, before the page is cut. Filtering the
// returned page here would answer "which of these fifty rows match", which is
// a different question and one whose answer drifts as the log grows.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  api,
  type ActivityFilterOptions,
  type ActivityQuery,
  type ActivityRunScope,
  type CuratorLogRow,
} from '@/api/client'
import JournalDownloadDialog from '@/components/JournalDownloadDialog.vue'
import JournalEntryBody from '@/components/JournalEntryBody.vue'
import ProposedDocument from '@/components/ProposedDocument.vue'
import { toastError } from '@/components/toastService'
import { formatDateTime } from '@/lib/datetime'
import { proposedDoc, type DocSource, type ProposedDoc } from '@/lib/proposedDoc'

interface LogRow extends CuratorLogRow {
  expanded: boolean
}

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const loading = ref(true)
const failed = ref(false)
const entries = ref<LogRow[]>([])
const filters = ref<ActivityFilterOptions>({ actions: [], writers: [] })

const action = ref('')
const writer = ref('')
const query = ref('')
// The run this table is scoped to, and what the server said that run covers.
// The digest on /curation sets it through the URL; the chip clears it.
const run = ref(0)
const runScope = ref<ActivityRunScope | null>(null)
// Curation, as against everything else an agent does. The server marks a row
// when the pass that wrote it declared itself, so this is a fact about the row
// rather than a guess about the connection.
const curationOnly = ref(false)

const PER_PAGE_OPTIONS = [25, 50, 100, 200]
const perPage = ref(50)
const page = ref(1)
const total = ref(0)
const pages = ref(1)

const rangeStart = computed(() => (total.value === 0 ? 0 : (page.value - 1) * perPage.value + 1))
const rangeEnd = computed(() => Math.min(page.value * perPage.value, total.value))
const onLastPage = computed(() => page.value >= pages.value)

const filtered = computed(
  () =>
    action.value !== '' ||
    writer.value !== '' ||
    query.value.trim() !== '' ||
    run.value !== 0 ||
    curationOnly.value,
)

// A composite action scope — `delete-proposed,merge-proposed`, set by a digest count —
// is not one of the select's options, so the control would render BLANK and
// read as "All actions" while the table was in fact filtered. It gets an
// option of its own instead, named after what it holds.
const compositeAction = computed(() =>
  action.value.includes(',') ? action.value.split(',').join(' + ') : '',
)

const currentQuery = computed<ActivityQuery>(() => ({
  action: action.value,
  writer: writer.value,
  q: query.value.trim(),
  run: run.value || undefined,
  curationOnly: curationOnly.value,
  page: page.value,
  perPage: perPage.value,
}))

// What the download dialog inherits: the filters, never the paging.
const downloadFilters = computed<ActivityQuery>(() => ({
  action: action.value,
  writer: writer.value,
  q: query.value.trim(),
  run: run.value || undefined,
  curationOnly: curationOnly.value,
}))
const downloading = ref(false)

// Which shape the journal takes, as a real breakpoint rather than a pair of
// display utilities. Bootstrap's `d-none`/`d-md-none` would render BOTH — 200
// rows of table and 200 cards, each card carrying a ClampedMarkdown with a
// ResizeObserver measuring an element that is `display: none`. 768px is `md`.
const wide = window.matchMedia('(min-width: 768px)')
const isWide = ref(wide.matches)
const onWidth = (e: MediaQueryListEvent) => {
  isWide.value = e.matches
}
onMounted(() => wide.addEventListener('change', onWidth))
onBeforeUnmount(() => wide.removeEventListener('change', onWidth))

// A filter narrowed twice in quick succession issues two requests, and the
// first may answer last — which puts the OLD rows under the NEW controls.
// Only the newest request is allowed to write (Codex, 2026-08-27).
let generation = 0

async function load() {
  const mine = ++generation
  loading.value = true
  try {
    const result = await api.curatorLog(currentQuery.value)
    if (mine !== generation) return
    failed.value = false
    entries.value = result.entries.map((e) => ({ ...e, expanded: false }))
    runScope.value = result.run
    total.value = result.total
    pages.value = result.pages
    // The server clamps a page past the end rather than answering with an
    // empty table, so the page shown may not be the page asked for — and the
    // URL has to follow, or a ?page=999 link keeps saying 999 over page 3.
    if (result.page !== page.value) {
      page.value = result.page
      writeUrl()
    }
    // The menus describe the WHOLE log, not the filtered view: options that
    // vanished as soon as you picked one would make a second choice
    // impossible to reach.
    filters.value = result.filters
  } catch (e) {
    if (mine !== generation) return
    // Cleared, not left standing. A failed reload under new filters used to
    // leave the PREVIOUS filter's rows on screen indefinitely, with only a
    // toast that disappears to say otherwise (Codex, 2026-08-27).
    entries.value = []
    total.value = 0
    failed.value = true
    toastError(t('activity.journal.load_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (mine === generation) loading.value = false
  }
}

// Reading the URL writes every filter ref at once, and each of those refs is
// watched. Without this the watchers fire on the way IN and reset the page a
// link had asked for — opening `?page=3` landed on page 1.
let syncing = false

/** The whole state of this screen lives in the URL, so it can be linked. */
function readUrl() {
  syncing = true
  action.value = String(route.query.action ?? '')
  writer.value = String(route.query.writer ?? '')
  query.value = String(route.query.q ?? '')
  run.value = Number(route.query.run ?? 0) || 0
  curationOnly.value = route.query.curation === '1'
  page.value = Math.max(1, Number(route.query.page ?? 1) || 1)
  perPage.value = PER_PAGE_OPTIONS.includes(Number(route.query.per_page))
    ? Number(route.query.per_page)
    : 50
  nextTick(() => {
    syncing = false
  })
}

function writeUrl() {
  // `view` is the workspace's own state and does not belong to any control
  // here, but this replaces the WHOLE query — dropping it would send an
  // explicit ?view=journal link back to the default on the first keystroke.
  const next: Record<string, string> = {}
  if (route.query.view) next.view = String(route.query.view)
  if (action.value) next.action = action.value
  if (writer.value) next.writer = writer.value
  if (query.value.trim()) next.q = query.value.trim()
  if (run.value) next.run = String(run.value)
  if (curationOnly.value) next.curation = '1'
  if (page.value > 1) next.page = String(page.value)
  if (perPage.value !== 50) next.per_page = String(perPage.value)
  // replace() rather than push(): typing a five-letter search would otherwise
  // leave five entries in history and Back would walk through them instead of
  // leaving the page.
  router.replace({ query: next })
}

// A #log-123 link arrives before the row it names: the browser resolves the
// fragment while the table is still a request in flight, and the shell scrolls
// the stage to the top on every arrival. So the scroll is done again once the
// rows exist, and only for a row actually on this page.
async function scrollToHash() {
  const id = route.hash.replace('#', '')
  if (id === '') return
  await nextTick()
  document.getElementById(id)?.scrollIntoView({ block: 'center' })
}

onMounted(async () => {
  readUrl()
  await load()
  scrollToHash()
})

// The address bar is the source of truth, so a Back, a pasted link and a
// digest count all arrive the same way.
watch(
  () => route.query,
  () => {
    readUrl()
    load()
  },
)

// One pending request, whichever control moved. The selects reload at once and
// typing waits, so a five-letter word is one request rather than five.
let pending: number | undefined
function schedule(delay: number) {
  window.clearTimeout(pending)
  pending = window.setTimeout(writeUrl, delay)
}
watch([action, writer, run, curationOnly, perPage], () => {
  if (syncing) return
  // Any change to what is being shown puts you back at the start of it: page 7
  // of the old filter is not page 7 of the new one.
  page.value = 1
  schedule(0)
})
watch(query, () => {
  if (syncing) return
  page.value = 1
  schedule(250)
})

function goTo(target: number) {
  // The "Go to" box hands over NaN when it is cleared, and NaN survives both
  // clamps to leave the page blank.
  if (!Number.isFinite(target)) return
  page.value = Math.min(Math.max(1, target), pages.value)
  schedule(0)
  // The stage scrolls, not the window: the shell is locked to the viewport.
  document.querySelector('.app-stage')?.scrollTo({ top: 0, behavior: 'smooth' })
}

function clearFilters() {
  action.value = ''
  writer.value = ''
  query.value = ''
  run.value = 0
  curationOnly.value = false
}

// Badge palette: green = things that happened, amber = waiting on the
// operator, blue = the curator talking, red = rejected.
const ACTION_BADGE: Record<string, string> = {
  create: 'text-bg-success',
  edit: 'text-bg-success',
  delete: 'text-bg-success',
  restore: 'text-bg-success',
  purge: 'text-bg-success',
  import: 'text-bg-success',
  'history-forgotten': 'text-bg-success',
  approved: 'text-bg-success',
  'create-proposed': 'text-bg-warning',
  'edit-proposed': 'text-bg-warning',
  'delete-proposed': 'text-bg-warning',
  'merge-proposed': 'text-bg-warning',
  rejected: 'text-bg-danger',
  // Amber for the same reason the proposals are: work is waiting on somebody.
  // A raised flag waits on the curator, a resolved one is an answer delivered.
  'flag-raised': 'text-bg-warning',
  'flag-resolved': 'text-bg-success',
  'run-summary': 'text-bg-primary',
  observation: 'text-bg-info',
  'tooling-gap': 'text-bg-secondary',
}

/** The document this entry left behind, in the review inbox's shape: the
 *  previous state plays "current", and the heading is rewritten in the past
 *  tense, because the journal records what a change DID. */
function panelOp(row: LogRow): ProposedDoc {
  return {
    ...proposedDoc(docSource(row), t),
    heading: t('activity.journal.what_changed'),
  }
}

function docSource(row: LogRow): DocSource {
  const d = row.diff!

  return {
    type: d.type,
    note:
      d.type === 'create'
        ? null
        : { id: row.note?.id ?? 0, title: d.prev_title ?? row.note_title ?? t('activity.journal.deleted_note') },
    merge_into: null,
    proposed_title: d.proposed_title,
    proposed_body_md: d.proposed_body_md,
    // An APPLIED edit records the body it produced (EditProposal::
    // recordResolvedBody), so there is never an unresolved patch to show here
    // — and re-resolving one would diff against the note as it stands today
    // rather than against what was applied.
    proposed_patch: null,
    proposed_tags: d.proposed_tags,
    proposed_summary: d.proposed_summary,
    currentBody: d.prev_body_md ?? undefined,
    currentSummary: d.prev_summary,
    currentTags: d.prev_tags ?? undefined,
  }
}
</script>

<template>
  <div>
    <!-- Four controls, one line above `md`. The search box gives up the width
         for it: the three selects have fixed vocabularies and cannot be
         narrowed without cutting their labels, and a search box that is a
         little short still takes any word you type. -->
    <div class="row g-2 align-items-center mb-3">
      <div class="col-12 col-md-3">
        <div class="input-group mm-search-group">
          <input type="text" class="form-control" v-model="query" :placeholder="$t('common.search')">
        </div>
      </div>
      <div class="col-6 col-md-3">
        <select class="form-select" v-model="action" :aria-label="$t('activity.journal.action_label')">
          <option value="">{{ $t('activity.journal.all_actions') }}</option>
          <option v-if="compositeAction" :value="action">{{ compositeAction }}</option>
          <option v-for="a in filters.actions" :key="a.value" :value="a.value">
            {{ a.value }} ({{ a.count }})
          </option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <select class="form-select" v-model="writer" :aria-label="$t('activity.journal.agent_label')">
          <option value="">{{ $t('activity.journal.all_agents') }}</option>
          <option v-for="w in filters.writers" :key="w.value" :value="w.value">
            {{ w.label }} ({{ w.count }})
          </option>
        </select>
      </div>
      <!-- Curation is a filter, so it lives with the filters rather than as a
           button in the header. It is its own control rather than another
           action option: it cuts ACROSS actions — a pass edits, proposes,
           observes and examines — and combining it with one is a real
           question ("what did passes edit?"). -->
      <div class="col-12 col-md-3">
        <select class="form-select" v-model="curationOnly" :aria-label="$t('activity.journal.scope_label')">
          <option :value="false">{{ $t('activity.journal.all_activity') }}</option>
          <option :value="true">{{ $t('activity.journal.curation_only') }}</option>
        </select>
      </div>
    </div>

    <!-- The run scope is a chip rather than a select: it is not a choice among
         options, it is one thing you clicked into and can click out of. -->
    <div class="d-flex align-items-center flex-wrap gap-2 mb-2" v-if="runScope">
      <span class="badge text-bg-light border fw-normal">
        <i class="fa-solid fa-filter me-1 text-muted"></i>
        {{ $t('activity.journal.run_chip', { id: runScope.log_id, by: runScope.by, at: formatDateTime(runScope.at) }) }}
        <button type="button" class="btn-close btn-close-sm ms-2 align-middle"
                :aria-label="$t('activity.journal.clear_run_scope')" @click="run = 0"></button>
      </span>
      <span class="small text-muted" v-if="!runScope.window_bounded">
        {{ $t('activity.journal.run_unbounded', { by: runScope.by }) }}
      </span>
    </div>

    <div class="d-flex justify-content-end mb-2" v-if="filtered && !loading">
      <a href="javascript:void(0)" class="small" @click="clearFilters">{{ $t('activity.journal.clear_filters') }}</a>
    </div>

    <template v-if="loading">
      <div class="row my-4">
        <div class="col text-center">
          <div class="spinner-border" role="status"><span class="visually-hidden">{{ $t('activity.journal.loading') }}</span></div>
        </div>
      </div>
    </template>

    <template v-else>
      <div class="row mt-2 mb-4" v-if="!entries.length">
        <div class="col text-muted">
          <template v-if="failed">{{ $t('activity.journal.failed') }}</template>
          <template v-else>
            {{ filtered ? $t('activity.journal.no_match') : $t('activity.journal.empty') }}
          </template>
        </div>
      </div>

      <template v-else>
        <!-- A TABLE above `md` and CARDS below it (operator, 2026-08-27). The
             stacked-table treatment this replaced kept the column headings as
             labels — DATE, ACTION, BY down the left of every row — which is a
             table pretending to be a card. Two wrappers, one shared body. -->
        <div class="row" v-if="isWide">
          <div class="col">
            <div class="app-tray">
            <div class="app-tray-scroll">
            <table class="table app-table small align-middle table-striped table-hover">
              <thead>
                <tr>
                  <th style="width: 4rem">{{ $t('activity.journal.columns.entry') }}</th>
                  <th style="width: 11rem">{{ $t('activity.journal.columns.date') }}</th>
                  <th style="width: 10rem">{{ $t('activity.journal.columns.action') }}</th>
                  <th>{{ $t('activity.journal.columns.description') }}</th>
                  <th style="width: 6rem">{{ $t('activity.journal.columns.by') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="row in entries" :key="row.id">
                  <tr :id="`log-${row.id}`">
                    <!-- The curator refers to its own entries by number in
                         later prose. Until 2026-08-09 that number appeared
                         nowhere in this view, so every such reference was
                         unresolvable. The anchor makes a row linkable, too. -->
                    <td class="text-muted">
                      <a :href="`#log-${row.id}`" class="text-muted text-decoration-none">#{{ row.id }}</a>
                    </td>
                    <td class="text-nowrap text-muted">{{ formatDateTime(row.created_at) }}</td>
                    <td>
                      <span class="badge" :class="ACTION_BADGE[row.action] ?? 'text-bg-light border'">{{ row.action }}</span>
                      <div v-if="row.curation_run" class="small text-muted mt-1">
                        <router-link class="text-muted"
                                     :to="{ name: 'activity', query: { run: String(row.curation_run) } }">
                          {{ $t('activity.journal.pass_link', { id: row.curation_run }) }}
                        </router-link>
                      </div>
                    </td>
                    <td>
                      <JournalEntryBody :row="row" :expanded="row.expanded"
                                        @toggle="row.expanded = !row.expanded" />
                    </td>
                    <td class="text-muted">{{ row.by }}</td>
                  </tr>
                  <tr v-if="row.expanded && row.diff">
                    <td colspan="5" class="bg-light">
                      <ProposedDocument :doc="panelOp(row)" :headed="false" />
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
            </div>

            <footer class="app-tray-footer">
              <span class="app-tray-range">
                {{ $t('activity.journal.showing', { from: rangeStart, to: rangeEnd, total: total.toLocaleString() }) }}
              </span>

              <nav class="app-pagination" :aria-label="$t('activity.journal.pagination')" v-if="pages > 1">
                <button class="btn btn-sm btn-outline-secondary" :disabled="page <= 1"
                        @click="goTo(page - 1)">
                  <i class="fa-solid fa-chevron-left"></i> {{ $t('activity.journal.newer') }}
                </button>
                <i18n-t keypath="activity.journal.page_of" tag="span" scope="global">
                  <template #page><strong>{{ page }}</strong></template>
                  <template #total>{{ pages }}</template>
                </i18n-t>
                <button class="btn btn-sm btn-outline-secondary" :disabled="onLastPage"
                        @click="goTo(page + 1)">
                  {{ $t('activity.journal.older') }} <i class="fa-solid fa-chevron-right"></i>
                </button>
                <label class="app-rows-control">
                  <span>{{ $t('activity.journal.go_to') }}</span>
                  <input type="number" min="1" :max="pages" class="form-control form-control-sm"
                         :value="page"
                         @change="goTo(parseInt(($event.target as HTMLInputElement).value))">
                </label>
              </nav>

              <label class="app-rows-control">
                <span>{{ $t('activity.journal.rows') }}</span>
                <select class="form-select form-select-sm" v-model.number="perPage"
                        :aria-label="$t('activity.journal.rows_per_page')">
                  <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
                </select>
                <span>{{ $t('activity.journal.per_page') }}</span>
              </label>
            </footer>
            </div>
          </div>
        </div>

        <div class="mb-4" v-else>
          <div class="card mb-2" v-for="row in entries" :key="row.id" :id="`log-m-${row.id}`">
            <div class="card-body py-2 px-3">
              <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                <a :href="`#log-m-${row.id}`" class="text-muted small text-decoration-none">#{{ row.id }}</a>
                <span class="badge" :class="ACTION_BADGE[row.action] ?? 'text-bg-light border'">{{ row.action }}</span>
                <router-link v-if="row.curation_run" class="small text-muted ms-auto"
                             :to="{ name: 'activity', query: { run: String(row.curation_run) } }">
                  {{ $t('activity.journal.pass_link', { id: row.curation_run }) }}
                </router-link>
              </div>
              <div class="small text-muted mb-2">
                {{ $t('activity.journal.at_by', { at: formatDateTime(row.created_at), by: row.by }) }}
              </div>
              <JournalEntryBody :row="row" :expanded="row.expanded"
                                @toggle="row.expanded = !row.expanded" />
              <div v-if="row.expanded && row.diff" class="mt-2 bg-light rounded p-2">
                <ProposedDocument :doc="panelOp(row)" :headed="false" />
              </div>
            </div>
          </div>
        </div>
      </template>

      <div class="row mb-5">
        <div class="col d-flex justify-content-end">
          <a href="javascript:void(0)" class="small" @click="downloading = true">
            {{ $t('activity.journal.download_link') }}
          </a>
        </div>
      </div>
    </template>

    <JournalDownloadDialog v-if="downloading" :filters="downloadFilters" :filtered="filtered"
                           @close="downloading = false" />
  </div>
</template>
