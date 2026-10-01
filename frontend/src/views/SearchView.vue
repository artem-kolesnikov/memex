<script setup lang="ts">
// The notes list: search input-group + inline facets + active chips; one row
// per note with a select checkbox; result count + per-page; pagination
// footer; sticky export bar for the selection.
//
// And the same notes as a map: a second view of one search, not a page of its
// own (operator, 2026-09-26). The search, tags and status stay where they are
// and dim whatever they leave out; only the box beneath them changes.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MultiSelect from 'primevue/multiselect'
import { api, type NoteListItem, type TagRef } from '@/api/client'
import { toastError } from '@/components/toastService'
import HelpTip from '@/components/HelpTip.vue'
import TagChipRow from '@/components/TagChipRow.vue'
import ActorMark from '@/components/ActorMark.vue'
import PresetDialog from '@/components/PresetDialog.vue'
import NotesMap from '@/components/NotesMap.vue'
import { formatDate } from '@/lib/datetime'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const loading = ref(true)
const items = ref<NoteListItem[]>([])
const total = ref(0)
// Meaning-based ranking did not run for the current query. See SearchResult.
const semanticUnavailable = ref(false)
// The applied query carried OR, NOT or a phrase, so an empty result is the
// answer to that expression and not a search that fell short.
const keywordOnly = ref(false)
const page = ref(1)
const perPage = ref(50)
const selected = ref<Set<number>>(new Set())

type View = 'list' | 'map'
const VIEWS: { key: View; labelKey: string; icon: string }[] = [
  { key: 'list', labelKey: 'search.view.list', icon: 'fa-solid fa-list' },
  { key: 'map', labelKey: 'search.view.map', icon: 'fa-solid fa-diagram-project' },
]
const view = ref<View>('list')
// Every note the search matches, for the map: the list's page would dim the
// rest of the answer along with what the search left out.
const mapIds = ref<number[] | null>(null)

const query = ref('')
const appliedQuery = ref('')
// The source facet came off this row on 2026-08-23 (operator) and its width
// went to the query box. Nothing else answered "which notes do I want to see"
// so rarely: where a note came from is on the note, and the actor icon in the
// list already says who touched it last, which is the question people were
// actually using this to ask. The API still accepts `source`.
const criteria = ref<{ tagIds: number[]; status: string; addedBy: number | null }>({
  tagIds: [],
  status: '',
  // Which connection ADDED the notes. Deliberately not a control in the filter
  // row: this answers "what did that assistant put in my knowledge base",
  // which an operator asks rarely and never by browsing to it. It arrives as a
  // link from the note page's own provenance line, where the question is
  // already in front of them, and shows here as a chip like any other filter.
  addedBy: null,
})
// The connection named by `addedBy`, for the chip's label. Fetched only when
// the filter is actually in use — every search page load must not pay for a
// token list nothing is going to read.
const addedByName = ref<string>('')

const allTags = ref<(TagRef & { note_count: number })[]>([])
const savingPreset = ref(false)
const presetDraft = computed(() => ({
  q: appliedQuery.value,
  tags: criteria.value.tagIds,
  status: criteria.value.status,
  added_by: criteria.value.addedBy,
}))
// What syncUrl() last put in the address bar, so the route watcher below can
// tell its own write from a preset click.
let lastSynced = ''
// Which URL read is current, so a click that lands while an earlier read is
// still waiting on the token list cannot finish by writing its stale state.
let readRequestId = 0
// Monotonic id of the latest reload — stale responses are ignored (mm2 idiom).
let reloadRequestId = 0

const tagOptions = computed(() =>
  [...allTags.value]
    .sort((a, b) => a.name.localeCompare(b.name))
    .map((t) => ({ label: `${t.name} (${t.note_count})`, value: t.id })),
)
/**
 * Strictly speaking one of these is not a status: `flagged` is a request the
 * curator has not answered yet, and a flagged note is still verified or still
 * pending. It lives here anyway because it answers the same question the
 * operator is asking of this control — "which notes do I want to see" — and a
 * dedicated toggle beside New note put a permanent yellow button in the corner
 * of a page that is not about flags (operator, 2026-08-17).
 */
const STATUS_FLAGGED = 'flagged'
// Neither is a status either, and both belong here for the same reason: this
// control answers "which notes do I want to see". `undescribed` is the
// enrichment backlog as the website sees it — the same notes `needs_enrichment`
// hands an assistant, so the operator and their assistant are looking at one
// list rather than two that drift.
const STATUS_UNDESCRIBED = 'undescribed'
const statusOptions = [
  { labelKey: 'search.status.any', value: '' },
  { labelKey: 'search.status.verified', value: 'verified' },
  { labelKey: 'search.status.pending', value: 'pending' },
  { labelKey: 'search.status.flagged', value: STATUS_FLAGGED },
  { labelKey: 'search.status.undescribed', value: STATUS_UNDESCRIBED },
]
const totalPages = computed(() => Math.ceil(total.value / perPage.value))
const pageRangeStart = computed(() => (total.value ? (page.value - 1) * perPage.value + 1 : 0))
const pageRangeEnd = computed(() => Math.min(page.value * perPage.value, total.value))
const onLastPage = computed(() => page.value >= totalPages.value)

interface Chip {
  key: string
  type: 'tag' | 'status' | 'terms' | 'addedBy'
  id?: number
  label: string
}
const activeChips = computed<Chip[]>(() => {
  const chips: Chip[] = []
  for (const id of criteria.value.tagIds) {
    const tag = allTags.value.find((t) => t.id === id)
    chips.push({ key: 'tag-' + id, type: 'tag', id, label: tag ? tag.name : t('search.chip.tag_fallback', { id }) })
  }
  if (criteria.value.status) {
    chips.push({
      key: 'status',
      type: 'status',
      label: (() => {
        const option = statusOptions.find((s) => s.value === criteria.value.status)
        return option ? t(option.labelKey) : criteria.value.status
      })(),
    })
  }
  if (appliedQuery.value) {
    chips.push({ key: 'terms', type: 'terms', label: t('search.chip.terms', { terms: appliedQuery.value }) })
  }
  if (criteria.value.addedBy !== null) {
    chips.push({
      key: 'added-by',
      type: 'addedBy',
      label: t('search.chip.added_by', {
        name: addedByName.value || t('search.chip.connection_fallback', { id: criteria.value.addedBy }),
      }),
    })
  }
  return chips
})

// An account with nothing in it at all, told apart from a search that found
// nothing: with no query and no filter applied, an empty result IS the whole
// knowledge base, and "nothing matches your search" reads to a new owner as a
// search that failed.
const emptyKnowledgeBase = computed(() => total.value === 0 && activeChips.value.length === 0)

/** Nothing narrows the map until something narrows the search. */
const mapFilter = computed(() => (activeChips.value.length ? mapIds.value : null))

/** The list's empty-state reason, as the one line the map has room for. */
const mapNotice = computed(() => {
  if (loading.value || mapFilter.value === null || mapFilter.value.length > 0) return ''
  if (keywordOnly.value) return t('search.no_match.exact')
  if (semanticUnavailable.value) return t('search.no_match.semantic_unavailable')
  return t('search.no_match.body')
})

function removeChip(chip: Chip) {
  switch (chip.type) {
    case 'tag':
      criteria.value.tagIds = criteria.value.tagIds.filter((id) => id !== chip.id)
      break
    case 'status':
      criteria.value.status = ''
      break
    case 'terms':
      query.value = ''
      appliedQuery.value = ''
      break
    case 'addedBy':
      criteria.value.addedBy = null
      addedByName.value = ''
      break
  }
  applyCriteria()
}

function clearAllCriteria() {
  criteria.value = { tagIds: [], status: '', addedBy: null }
  addedByName.value = ''
  query.value = ''
  appliedQuery.value = ''
  applyCriteria()
}

async function applyCriteria() {
  appliedQuery.value = query.value.trim()
  page.value = 1
  syncUrl()
  await reload()
}

async function showView(next: View) {
  if (view.value === next) return
  view.value = next
  syncUrl()
  await reload()
}

/** A tag picked on the map shows that tag's notes, the way the map always has. */
async function filterByTag(name: string) {
  const tag = allTags.value.find((t) => t.name === name)
  if (tag === undefined) return
  criteria.value.tagIds = [tag.id]
  await applyCriteria()
}

/**
 * Put the search in the address bar, so it can be linked, bookmarked and
 * reopened (AUDIT3, P2).
 *
 * THREE KEYS, and no more. `q`, `tag` and `status` are what the mount handler
 * below already reads, so this closes a loop that was half-built rather than
 * inventing a second vocabulary for the same state. Page and rows-per-page are
 * deliberately absent: sharing a search should share the search, and a link
 * that also pins somebody to page 4 of a result set they have never seen is
 * worse than one that does not. `view` does ride along: a link to the map
 * should open the map.
 *
 * TAG NAMES, NOT IDS, for the reason the mount handler gives — a link is
 * written and read by people, and tag ids are not stable across knowledge
 * bases. An id in a URL is a link that silently means something else on
 * somebody else's memex.
 *
 * ALWAYS `replace`, NEVER `push`. Every applied search would otherwise become
 * a history entry, and Back would walk backwards through them one at a time
 * instead of leaving the page — which is the behaviour people actually expect
 * Back to have.
 *
 * NOT DEBOUNCED, and the backlog said it should be — it described this as
 * "debounced on the query text", which assumes the search runs as you type. It does not: `applyCriteria` is called from Enter, the Search button,
 * a facet change or a chip being removed, so the applied state moves in
 * discrete steps and there is nothing continuous to debounce. A timer here
 * would only delay the address bar behind the results it describes.
 */
function syncUrl() {
  const tagNames = criteria.value.tagIds
    .map((id) => allTags.value.find((t) => t.id === id)?.name)
    .filter((name): name is string => typeof name === 'string')

  // Every value an ARRAY, including the single ones. vue-router renders a
  // one-element array as `?tag=infra`, identical to a bare string, so nothing
  // in the address bar looks different — but it means this function and
  // `currentUrlCriteria()` below produce the same shape for the same state,
  // which is what makes comparing them meaningful rather than a source of
  // false differences.
  const next: Record<string, string[]> = {}
  if (appliedQuery.value) next.q = [appliedQuery.value]
  if (tagNames.length) next.tag = tagNames
  if (criteria.value.status) next.status = [criteria.value.status]
  if (criteria.value.addedBy !== null) next.added_by = [String(criteria.value.addedBy)]
  if (view.value === 'map') next.view = ['map']
  lastSynced = JSON.stringify(next)

  // Vue Router warns about a navigation to the address you are already at, and
  // this runs on every apply — including the ones that change nothing, like
  // pressing Search twice.
  if (JSON.stringify(next) === JSON.stringify(currentUrlCriteria())) return

  router.replace({ query: next })
}

/**
 * One key from the address bar, always as a list of non-empty strings.
 *
 * vue-router gives a string for `?k=v`, an array for `?k=a&k=b`, and null for
 * a bare `?k`. Every reader here goes through this so none of them has to
 * remember which — the `status` reader did not, and a link carrying it twice
 * silently lost the filter.
 */
function queryValues(key: string): string[] {
  const raw = route.query[key]
  return (Array.isArray(raw) ? raw : [raw]).filter(
    (v): v is string => typeof v === 'string' && v !== '',
  )
}

/** The same keys as they currently stand in the address bar, same shape. */
function currentUrlCriteria(): Record<string, string[]> {
  const current: Record<string, string[]> = {}
  for (const key of ['q', 'tag', 'status', 'added_by', 'view'] as const) {
    const values = queryValues(key)
    if (values.length) current[key] = values
  }
  return current
}

async function reload() {
  const requestId = ++reloadRequestId
  loading.value = true
  try {
    const search = {
      q: appliedQuery.value || undefined,
      tags: criteria.value.tagIds.length ? criteria.value.tagIds : undefined,
      // "Flagged" occupies the status control but is not one: it must not be
      // sent as `status`, which the API validates against verified/pending and
      // would reject.
      status:
        criteria.value.status === STATUS_FLAGGED || criteria.value.status === STATUS_UNDESCRIBED
          ? undefined
          : criteria.value.status || undefined,
      flagged: criteria.value.status === STATUS_FLAGGED || undefined,
      undescribed: criteria.value.status === STATUS_UNDESCRIBED || undefined,
      added_by: criteria.value.addedBy ?? undefined,
    }
    if (view.value === 'map') {
      const found = await api.matchingNoteIds(search)
      if (requestId !== reloadRequestId) return
      mapIds.value = found.ids
      total.value = found.ids.length
      semanticUnavailable.value = found.semantic_unavailable
      keywordOnly.value = found.keyword_only
      return
    }
    const result = await api.searchNotes({ ...search, page: page.value, per_page: perPage.value })
    if (requestId !== reloadRequestId) return
    items.value = result.items
    total.value = result.total
    semanticUnavailable.value = result.semantic_unavailable
    keywordOnly.value = result.keyword_only
  } catch (e) {
    if (requestId === reloadRequestId) {
      // Unknown is not the last answer: the map would go on dimming by a
      // search the chips no longer describe.
      if (view.value === 'map') mapIds.value = null
      toastError(t('search.load_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
    }
  } finally {
    if (requestId === reloadRequestId) {
      loading.value = false
    }
  }
}

watch(page, reload)
watch(perPage, () => {
  page.value = 1
  reload()
})

function goToPage(n: number) {
  const p = Math.min(Math.max(1, Math.floor(n)), totalPages.value || 1)
  if (!isNaN(p) && p !== page.value) {
    page.value = p
  }
}

function toggleSelected(id: number) {
  const next = new Set(selected.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  selected.value = next
}

function selectAll() {
  selected.value = new Set(items.value.map((n) => n.id))
}

function selectNone() {
  selected.value = new Set()
}

function exportSelection() {
  // One note → its own .md (keeps the slug filename); a set → zip.
  const ids = Array.from(selected.value)
  window.location.href = ids.length === 1 ? api.exportNoteUrl(ids[0]!) : api.exportSetUrl({ ids })
}


/**
 * The status column, as one icon.
 *
 * **A verified note shows nothing at all** (operator, 2026-08-23). That is the
 * overwhelming majority of rows, and a column of identical grey ticks is a
 * column you learn to stop seeing — the point of the icon was to take less
 * space than the word, and an empty cell takes least of all. What is left
 * marks only the rows that want something from you.
 *
 * Flagged outranks pending because it is the operator's own instruction to the
 * curator, and a note can be both.
 */
const STATUS_MARKS: Record<string, { icon: string; cls: string; labelKey: string }> = {
  flagged: {
    icon: 'fa-solid fa-flag',
    cls: 'text-warning',
    labelKey: 'search.marks.flagged_title',
  },
  pending: {
    icon: 'fa-regular fa-clock',
    cls: 'mm-status-pending-icon',
    labelKey: 'search.marks.pending_title',
  },
}

function statusMark(note: NoteListItem) {
  if (note.flagged) return STATUS_MARKS.flagged
  if (note.status === 'pending') return STATUS_MARKS.pending

  return null
}

onMounted(async () => {
  try {
    allTags.value = (await api.tags()).tags
  } catch {
    allTags.value = []
  }
  const requestId = ++readRequestId
  await readUrl(requestId)
  if (requestId !== readRequestId) return
  // Rewrite the address bar to what was actually understood. A link can name a
  // tag this knowledge base does not have, or a status that is not one, and
  // those are dropped above — leaving the URL advertising a filter the page is
  // not applying until the next click happened to correct it. `syncUrl()`
  // no-ops when there is nothing to change, so a canonical link causes no
  // navigation at all.
  syncUrl()
  await reload()
})

// A saved filter clicked in the sidebar lands here as a new query on a page
// that is already mounted, so the address bar is read again. Skipped when the
// change is syncUrl()'s own, and while Settings is showing this page as its
// backdrop with another route's query.
watch(
  () => route.query,
  async () => {
    if (route.name !== 'search') return
    const arrived = JSON.stringify(currentUrlCriteria())
    if (arrived === lastSynced) return
    lastSynced = arrived
    const requestId = ++readRequestId
    // A search still in flight for the previous URL must not land under
    // this one's chips while its own read waits on the token list.
    ++reloadRequestId
    loading.value = true
    criteria.value = { tagIds: [], status: '', addedBy: null }
    addedByName.value = ''
    query.value = ''
    appliedQuery.value = ''
    page.value = 1
    await readUrl(requestId)
    if (requestId !== readRequestId) return
    syncUrl()
    await reload()
  },
)

async function readUrl(requestId: number) {
  // `?tag=name` preselects the filter, so anywhere in the app can link to
  // "the notes tagged X" instead of growing its own copy of this list. Names
  // rather than ids: a link is written by a person, and tag ids are not stable
  // across knowledge bases.
  //
  // Several are accepted since 2026-08-25, because `syncUrl()` now WRITES this
  // key and the facet has always allowed more than one tag. Reading only the
  // first would have made a two-tag search unshareable by the very link the
  // address bar had just offered.
  const wanted = queryValues('tag')
  // DEDUPED, because a hand-written or edited link can carry the same tag
  // twice and the facet cannot. `?tag=infra&tag=infra` otherwise put the id in
  // `tagIds` twice, which gave two active chips with the same `:key` (a Vue
  // duplicate-key warning) and drew the tag twice inside the MultiSelect. The
  // backend was never affected — one EXISTS per id, ANDed, so a repeat is a
  // no-op — which is exactly why nothing downstream would have complained.
  const matched = [
    ...new Set(
      wanted
        .map((name) => allTags.value.find((t) => t.name === name)?.id)
        .filter((id): id is number => typeof id === 'number'),
    ),
  ]
  if (matched.length) {
    criteria.value.tagIds = matched
  }
  // `?status=` preselects the status control the same way, so a link can point
  // at "the notes nobody has described" without that list existing twice.
  // Read through the same helper as `tag`: a link carrying `status` twice
  // arrives as an ARRAY, and a reader that only accepted a string dropped the
  // filter silently rather than using the first value.
  const [status] = queryValues('status')
  if (status !== undefined && statusOptions.some((o) => o.value === status)) {
    criteria.value.status = status
  }
  // `?q=` completes the loop: the address bar now carries the terms, so opening
  // that address has to put them back in the box as well as in the results.
  // Without this a shared link searched correctly and showed an empty search
  // field, which reads as a bug in the search rather than in the link.
  const [terms] = queryValues('q')
  if (terms !== undefined && terms.trim()) {
    query.value = terms.trim()
    appliedQuery.value = terms.trim()
  }
  // `?view=map` draws the same search as the map; anything else is the list.
  const [wantedView] = queryValues('view')
  view.value = wantedView === 'map' ? 'map' : 'list'
  // `?added_by=` points the page at one connection's writes. An id rather than
  // a name, unlike `tag` above, and for the opposite reason: a connection can
  // be renamed and two can share a name, so the id is the only thing that
  // identifies it — which is the same rule the activity journal's writer filter
  // already follows. The link is generated by the note page, never typed.
  const [addedBy] = queryValues('added_by')
  const addedById = addedBy === undefined ? Number.NaN : Number.parseInt(addedBy, 10)
  if (Number.isInteger(addedById) && addedById > 0) {
    criteria.value.addedBy = addedById
    // Only now is the token list worth fetching, and a failure here costs the
    // chip its name and nothing else — the filter is the id, and it is already
    // set. A connection deleted since the link was made simply has no name to
    // show, which the chip's fallback already handles.
    try {
      const found = (await api.tokens()).tokens.find((t) => t.id === addedById)
      if (requestId === readRequestId) addedByName.value = found?.display_name ?? found?.label ?? ''
    } catch {
      if (requestId === readRequestId) addedByName.value = ''
    }
  }
}
</script>

<template>
  <div class="container" :class="{ 'mm-has-report-bar': selected.size > 0 && view === 'list' }">
    <header class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <div class="mm-notes-title">
          <h1>{{ $t('search.title') }}</h1>
          <nav class="app-segmented mm-notes-views" :aria-label="$t('search.view.label')">
            <button v-for="v in VIEWS" :key="v.key" type="button" class="app-segment"
                    :class="{ 'is-active': view === v.key }" :aria-pressed="view === v.key"
                    :title="$t(v.labelKey)" :aria-label="$t(v.labelKey)" @click="showView(v.key)">
              <i :class="v.icon" aria-hidden="true"></i>
            </button>
          </nav>
        </div>
        <p class="app-page-lede" v-if="total">{{ $t('search.notes_in_memex', total) }}</p>
      </div>
      <div class="app-head-actions">
        <router-link class="btn btn-primary" :to="{ name: 'note-new' }">
          <i class="fa-solid fa-plus me-1"></i> {{ $t('search.new_note') }}
        </router-link>
      </div>
    </header>

    <!-- Hybrid search (semantic + keyword server-side). The facets used to hide
         behind an "Advanced search" toggle; they sit beside the box instead —
         one row, nothing to reveal, and the query keeps only the width it needs. -->
    <!-- Stacks below md and pairs up at sm: at 390 these were a truncated
         "All ta…" and two selects showing nothing but their chevron, because
         col-5/3/2/2 divides a phone into four unusable columns (2026-08-22). -->
    <div class="app-notes-toolbar">
      <div>
        <div class="input-group mm-search-group">
          <input
            type="text"
            class="form-control"
            v-model="query"
            :placeholder="$t('search.placeholder')"
            @keyup.enter="applyCriteria"
          >
          <button class="btn btn-primary" type="button" :disabled="loading" @click="applyCriteria">
            <span class="spinner-border spinner-border-sm me-1" role="status" v-if="loading"><span class="visually-hidden">{{ $t('search.loading') }}</span></span>
            {{ $t('common.search') }}
          </button>
        </div>
      </div>
      <div>
        <MultiSelect
          v-model="criteria.tagIds"
          :options="tagOptions"
          filter
          optionLabel="label"
          optionValue="value"
          :placeholder="$t('search.all_tags')"
          :maxSelectedLabels="2"
          class="w-100"
          @update:modelValue="applyCriteria"
        />
      </div>
      <div>
        <select class="form-select" v-model="criteria.status" @change="applyCriteria" :aria-label="$t('search.status_label')">
          <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ $t(opt.labelKey) }}</option>
        </select>
      </div>
    </div>

    <!-- Active filter chips -->
    <div class="d-flex align-items-center flex-wrap mb-2" style="gap: .4rem;" v-if="activeChips.length">
      <span class="small text-muted">{{ $t('search.active') }}</span>
      <span v-for="chip in activeChips" :key="chip.key" class="badge mm-chip">
        {{ chip.label }}
        <a href="javascript:void(0)" class="mm-chip-x" @click="removeChip(chip)" :aria-label="$t('common.remove')">×</a>
      </span>
      <a href="javascript:void(0)" class="small ms-1" @click="clearAllCriteria">{{ $t('search.clear_all') }}</a>
      <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="savingPreset = true">
        <i class="fa-regular fa-bookmark me-1"></i>{{ $t('presets.save') }}
      </button>
    </div>

    <NotesMap v-if="view === 'map'" :filter-ids="mapFilter" :notice="mapNotice" :searching="loading"
              @clear-filters="clearAllCriteria" @tag="filterByTag" />

    <template v-else-if="loading">
      <div class="row my-4">
        <div class="col">
          <div class="text-center">
            <div class="spinner-border" role="status">
              <span class="visually-hidden">{{ $t('search.loading') }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <template v-else>
      <div class="row mt-2 mb-4" v-if="items.length">
        <div class="col">
          <div class="app-tray">
          <div class="app-note-list">
            <div class="app-note-list-head">
              <div class="dropdown">
                <a class="dropdown-toggle mm-select-toggle" href="javascript:void(0)" role="button"
                   id="dropdownSelect"
                   data-bs-toggle="dropdown" aria-expanded="false"
                   :title="$t('search.select_rows')" :aria-label="$t('search.select_rows')">
                  <i class="fa-regular fa-square-check"></i>
                </a>
                <ul class="dropdown-menu app-menu" aria-labelledby="dropdownSelect">
                  <li>
                    <a class="dropdown-item" href="javascript:void(0)" @click="selectAll">
                      <i class="fa-regular fa-square-check fa-fw"></i>{{ $t('search.select_all_on_page') }}
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item" href="javascript:void(0)" @click="selectNone">
                      <i class="fa-regular fa-square fa-fw"></i>{{ $t('common.none') }}
                    </a>
                  </li>
                </ul>
              </div>
              <span class="app-note-list-title">
                {{ $t('search.columns.title') }}
                <HelpTip :label="$t('search.marks.help')">
                  <p>{{ $t('search.marks.intro') }}</p>
                  <ul class="mm-actor-legend mb-0">
                    <li>
                      <i class="fa-regular fa-clock mm-status-pending-icon"></i>
                      {{ $t('search.marks.pending') }}
                    </li>
                    <li>
                      <i class="fa-solid fa-flag text-warning"></i>
                      {{ $t('search.marks.flagged') }}
                    </li>
                  </ul>
                </HelpTip>
              </span>
              <span class="app-note-list-modified">{{ $t('search.columns.modified') }}</span>
            </div>

            <article v-for="note in items" :key="note.id" class="app-note-row"
                     :class="{ 'is-selected': selected.has(note.id) }">
              <label class="app-note-check">
                <input type="checkbox" class="form-check-input" :checked="selected.has(note.id)" @change="toggleSelected(note.id)">
              </label>
              <router-link class="app-note-main" :to="{ name: 'note', params: { id: note.id } }">
                <span class="app-note-title">
                  <i v-if="statusMark(note)" class="app-note-mark"
                     :class="[statusMark(note)!.icon, statusMark(note)!.cls]"
                     :title="$t(statusMark(note)!.labelKey)"></i>{{ note.title }}
                </span>
                <span class="app-note-summary" v-if="note.summary">{{ note.summary }}</span>
                <span class="app-note-summary app-note-summary-none" v-else>{{ $t('search.status.undescribed') }}</span>
              </router-link>
              <div class="app-note-meta">
                <span class="app-note-date">{{ formatDate(note.updated_at) }}</span>
                <span class="app-note-by" v-if="note.edited_by"><ActorMark :actor="note.edited_by" /></span>
                <span class="app-note-tags" v-if="note.tags.length"><TagChipRow :tags="note.tags" /></span>
              </div>
            </article>
          </div>

          <footer class="app-tray-footer">
            <span class="app-tray-range">{{ $t('search.showing', { from: pageRangeStart, to: pageRangeEnd, total }) }}</span>

            <nav class="app-pagination" :aria-label="$t('search.pagination')" v-if="totalPages > 1">
              <button class="btn btn-sm btn-outline-secondary" :disabled="page <= 1" @click="goToPage(page - 1)">
                <i class="fa-solid fa-chevron-left"></i> {{ $t('search.prev') }}
              </button>
              <i18n-t keypath="search.page_of" tag="span" scope="global">
                <template #page><strong>{{ page }}</strong></template>
                <template #total>{{ totalPages }}</template>
              </i18n-t>
              <button class="btn btn-sm btn-outline-secondary" :disabled="onLastPage" @click="goToPage(page + 1)">
                {{ $t('search.next') }} <i class="fa-solid fa-chevron-right"></i>
              </button>
              <label class="app-rows-control">
                <span>{{ $t('search.go_to') }}</span>
                <input type="number" min="1" :max="totalPages" class="form-control form-control-sm"
                       :value="page" @change="goToPage(parseInt(($event.target as HTMLInputElement).value))">
              </label>
            </nav>

            <label class="app-rows-control">
              <span>{{ $t('search.rows') }}</span>
              <select class="form-select form-select-sm" v-model.number="perPage" :aria-label="$t('search.rows_per_page')">
                <option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ n }}</option>
              </select>
              <span>{{ $t('search.per_page') }}</span>
            </label>
          </footer>
          </div>
        </div>
      </div>

      <div class="row mt-2 mb-4" v-if="!items.length">
        <div class="col">
          <!--
            There is deliberately no "degraded, and here are some results anyway"
            banner: that state cannot occur. When the vector tier is skipped,
            HybridSearch falls back to the keyword clause it has ALREADY probed
            and found no match for, so a degraded search returns an empty set
            every time. Which is exactly why this sentence matters — the empty
            set is the whole answer, and unexplained it reads as a verdict on
            the knowledge base.
          -->
          <div class="app-state app-state-blank" v-if="emptyKnowledgeBase">
            <h4>{{ $t('search.empty.title') }}</h4>
            <p>{{ $t('search.empty.keep') }}</p>
            <p>{{ $t('search.empty.start') }}</p>
            <div class="app-state-actions">
              <router-link class="btn btn-primary" :to="{ name: 'note-new' }">
                <i class="fa-solid fa-plus me-1"></i> {{ $t('search.new_note') }}
              </router-link>
              <router-link class="btn btn-secondary" :to="{ name: 'settings', params: { pane: 'content' } }">
                {{ $t('search.empty.import') }} <i class="fa-solid fa-arrow-right ms-1"></i>
              </router-link>
            </div>
          </div>
          <div class="app-state" v-else>
            <h4>{{ $t('search.no_match.title') }}</h4>
            <p v-if="keywordOnly">{{ $t('search.no_match.exact') }}</p>
            <p v-else-if="semanticUnavailable">{{ $t('search.no_match.semantic_unavailable') }}</p>
            <p v-else>{{ $t('search.no_match.body') }}</p>
          </div>
        </div>
      </div>
    </template>
  </div>

  <PresetDialog v-if="savingPreset" :preset="null" :initial="presetDraft" @close="savingPreset = false" />

  <Transition name="slide-fade">
    <div class="app-selection-bar" role="status" v-if="selected.size > 0 && view === 'list'">
      <i18n-t keypath="search.selected" tag="span" :plural="selected.size" scope="global">
        <template #size><strong>{{ selected.size }}</strong></template>
      </i18n-t>
      <button type="button" class="btn btn-sm" @click="exportSelection">
        <i class="fa-regular fa-circle-down me-1"></i>{{ $t('search.export_markdown', { ext: selected.size === 1 ? '.md' : '.zip' }) }}
      </button>
      <button type="button" class="btn btn-sm app-selection-clear" @click="selectNone">{{ $t('search.clear') }}</button>
    </div>
  </Transition>
</template>
