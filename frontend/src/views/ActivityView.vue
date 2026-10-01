<script setup lang="ts">
// Activity: one workspace, two views of the same history.
//
// **Journal** is the ledger — every write, decision, flag and observation, in
// order, filterable, exportable. **Curation** is the digest — what each pass
// claimed, set beside what the log actually records.
//
// A ledger and a per-run report answer different questions about the same
// events, so they belong in one destination and not in one table. A link from
// a pass in the digest opens the Journal scoped to that run, which is the raw
// evidence behind its counts.
//
// The view lives in the URL as `?view=`, so both halves are linkable, and the
// digest and the journal load separately — a failure in one is not an empty
// state in the other.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, type CurationRun } from '@/api/client'
import ActivityJournal from '@/components/ActivityJournal.vue'
import CurationRuns from '@/components/CurationRuns.vue'
import { toastError } from '@/components/toastService'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const VIEWS = [
  { key: 'journal', labelKey: 'activity.views.journal', hintKey: 'activity.views.journal_hint' },
  { key: 'curation', labelKey: 'activity.views.curation', hintKey: 'activity.views.curation_hint' },
] as const
type ViewKey = (typeof VIEWS)[number]['key']

// Journal is the default: an unrecognised value is not an error worth a blank
// page, and the ledger is the answer to "what happened here" either way.
const view = computed<ViewKey>(() => {
  const wanted = Array.isArray(route.query.view) ? route.query.view[0] : route.query.view
  return VIEWS.some((v) => v.key === wanted) ? (wanted as ViewKey) : 'journal'
})

const runs = ref<CurationRun[]>([])
const runsTotal = ref(0)
const runsLoading = ref(true)
const runsFailed = ref(false)

// Loaded once, when Curation is first opened. Fetching the digest for
// everybody who opens the Journal would buy a report nobody asked for.
//
// Two flags, not one. A single "have we asked" latch set before the await was
// never cleared by a request that neither resolved nor rejected — the fetch
// carries no timeout — so a stalled digest left this view on its loading line
// for ever, with switching away and back a no-op and no Retry to press,
// because the failure branch had not been reached either. `inFlight` is
// released in `finally`, and `loaded` is set only by an answer.
let inFlight = false
const loaded = ref(false)

async function loadRuns() {
  if (inFlight || loaded.value) return
  inFlight = true
  runsLoading.value = true
  try {
    const result = await api.curationDigest(50)
    runs.value = result.runs
    runsTotal.value = result.runs_total
    runsFailed.value = false
    loaded.value = true
  } catch (e) {
    runsFailed.value = true
    toastError(t('activity.passes_load_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    inFlight = false
    runsLoading.value = false
  }
}

// What Retry presses. It is allowed to ask again while a stalled request is
// still open, because from here that request is indistinguishable from a dead
// one and the user has said which they think it is.
function retryRuns() {
  inFlight = false
  loaded.value = false
  runsFailed.value = false
  loadRuns()
}

// The journal's filters live in the same query and mean nothing to the digest,
// but they are the user's workspace and discarding them made a glance at
// Curation cost a rebuilt filter, a search and a page number. They are carried
// across untouched; only `view` changes.
function open(key: ViewKey) {
  const query = { ...route.query }
  if (key === 'journal') delete query.view
  else query.view = key
  router.push({ query })
}

watch(view, (v) => {
  if (v === 'curation') loadRuns()
})
onMounted(() => {
  if (view.value === 'curation') loadRuns()
})
</script>

<template>
  <div class="container">
    <header class="app-page-head d-flex align-items-end justify-content-between flex-wrap gap-3">
      <div>
        <h1>{{ $t('activity.title') }}</h1>
        <p class="app-page-lede">{{ $t('activity.lede') }}</p>
      </div>

      <div class="app-head-actions">
        <nav class="app-segmented" :aria-label="$t('activity.view_label')">
        <button
          v-for="v in VIEWS"
          :key="v.key"
          type="button"
          class="app-segment"
          :class="{ 'is-active': view === v.key }"
          :aria-pressed="view === v.key"
          :title="$t(v.hintKey)"
          @click="open(v.key)"
        >
          {{ $t(v.labelKey) }}
        </button>
        </nav>
      </div>
    </header>

    <ActivityJournal v-if="view === 'journal'" />

    <template v-else>
      <p class="alert alert-warning" v-if="runsFailed">
        {{ $t('activity.passes_failed') }}
        <button type="button" class="btn btn-link p-0 align-baseline" @click="retryRuns">
          {{ $t('activity.try_again') }}
        </button>
      </p>
      <CurationRuns v-else :runs="runs" :runs-total="runsTotal" :loading="runsLoading" />
    </template>
  </div>
</template>
