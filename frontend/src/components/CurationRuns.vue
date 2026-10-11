<script setup lang="ts">
// What each pass did.
//
// **One item per note, always the same three parts.** The note and what was
// applied to it; why the pass took it up; what exactly was done. Every note
// the pass read is an item, the ones it left alone included, so nothing about
// a pass lives in a second list. A note that still fails a check after the
// pass is marked failed on its own item, with the pass's reason for leaving
// it. The pass's summary is capped at sixty words server-side and sits above
// the items as an overview.
//
// **Silence is the trust signal.** A row speaks only when something is wrong.
//
// **`read` is the one count always attributable to the pass.** `examinedByRun`
// selects on `run_id`, never on the window (CurationDigest §68), so an
// unbounded window widens `applied` and `filed` and leaves `read` alone.
//
// **`applied` and `filed` are never summed.** They are the two states the
// review gate exists to keep apart: one changed the knowledge base, the other
// changed nothing and added to the owner's queue.
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { CurationRun, CurationRunAction, CurationRunNote } from '@/api/client'
import { formatDateTime } from '@/lib/datetime'
import { reasonWords } from '@/lib/curationReasons'
import { renderLogEntry } from '@/lib/markdown'
import { actionBadge } from '@/lib/actionBadge'
import ClampedMarkdown from '@/components/ClampedMarkdown.vue'

const props = defineProps<{ runs: CurationRun[]; runsTotal: number; loading: boolean }>()
const router = useRouter()
const { t } = useI18n()

const query = ref('')
const filter = ref<'all' | 'mismatch' | 'waiting' | 'failed' | 'changed' | 'unbounded'>('all')
const openId = ref<number | null>(null)

const FILTERS = [
  { key: 'all', labelKey: 'activity.runs.filters.all' },
  { key: 'mismatch', labelKey: 'activity.runs.filters.mismatch' },
  { key: 'waiting', labelKey: 'activity.runs.filters.waiting' },
  { key: 'failed', labelKey: 'activity.runs.filters.failed' },
  { key: 'changed', labelKey: 'activity.runs.filters.changed' },
  { key: 'unbounded', labelKey: 'activity.runs.filters.unbounded' },
] as const

const applied = (run: CurationRun) => run.counts.edited + run.counts.created + run.counts.flags_resolved
const filed = (run: CurationRun) => run.counts.held + run.counts.proposed

const KIND_KEYS: Record<CurationRunAction, string> = {
  create: 'activity.runs.kinds.create',
  edit: 'activity.runs.kinds.edit',
  'edit-proposed': 'activity.runs.kinds.edit_proposed',
  'delete-proposed': 'activity.runs.kinds.delete_proposed',
  'merge-proposed': 'activity.runs.kinds.merge_proposed',
  'flag-resolved': 'activity.runs.kinds.flag_resolved',
  examined: 'activity.runs.kinds.examined',
}

const failedWords = (n: CurationRunNote) =>
  n.failed.map((r) => (r === 'operator_flag' ? t('activity.runs.flag_open') : reasonWords(r))).join(' · ')

/**
 * At most one badge, by fixed precedence: a mismatch contaminates every other
 * number on the row, then what needs the owner's hand, then what failed. An
 * unbounded window is never a badge: it is the common case.
 */
function badge(run: CurationRun): { text: string; tone: 'bad' | 'warn' } | null {
  if (run.mismatches.length) return { text: t('activity.runs.badge.mismatch'), tone: 'bad' }
  if (run.waiting_total) return { text: t('activity.runs.badge.waiting', run.waiting_total), tone: 'warn' }
  if (run.failed_total) return { text: t('activity.runs.badge.failed', run.failed_total), tone: 'warn' }
  return null
}

function matches(run: CurationRun): boolean {
  switch (filter.value) {
    case 'mismatch':
      if (!run.mismatches.length) return false
      break
    case 'waiting':
      if (!run.waiting_total) return false
      break
    case 'failed':
      if (!run.failed_total) return false
      break
    case 'changed':
      if (applied(run) === 0) return false
      break
    case 'unbounded':
      if (run.window_bounded) return false
      break
  }

  const q = query.value.trim().toLowerCase()
  if (!q) return true
  // Searchable by the notes a pass touched and what it said of each, which is
  // how you find "the pass that broke that note" without opening ten of them.
  const haystack = [
    run.by,
    run.description,
    ...run.notes.flatMap((n) => [`${n.note_id ?? ''} ${n.title}`, n.why ?? '', ...n.done.map((d) => d.text)]),
  ]
    .join(' ')
    .toLowerCase()

  return haystack.includes(q)
}

const visible = computed(() => props.runs.filter(matches))

const moreNotes = (run: CurationRun) => Math.max(0, run.notes_total - run.notes.length)

function toggle(id: number) {
  openId.value = openId.value === id ? null : id
}

function mismatchLine(run: CurationRun): string {
  return run.mismatches
    .map((m) => t('activity.runs.mismatch_item', { claimed: m.claimed, claim: m.claim, logged: m.logged }))
    .join(' · ')
}

// Into the Journal, scoped to this pass. `view` is explicit rather than left
// to the default because the digest is the other view of the same route.
function scope(run: number, action: string) {
  router.push({ name: 'activity', query: { view: 'journal', run: String(run), action } })
}
</script>

<template>
  <div>
      <h3 class="app-section-title">{{ $t('activity.runs.title') }}</h3>
      <p class="mm-note">{{ $t('activity.runs.lede') }}</p>

      <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
        <input
          class="form-control form-control-sm mm-runs-search"
          v-model="query"
          type="search"
          :placeholder="$t('activity.runs.search_placeholder')"
          :aria-label="$t('activity.runs.search_label')"
        />
        <select class="form-select form-select-sm w-auto" v-model="filter" :aria-label="$t('activity.runs.filter_label')">
          <option v-for="f in FILTERS" :key="f.key" :value="f.key">{{ $t(f.labelKey) }}</option>
        </select>
      </div>

      <p v-if="loading" class="mm-step-wait">{{ $t('activity.runs.reading') }}</p>
      <i18n-t v-else-if="!runs.length" keypath="activity.runs.none_recorded" tag="p" class="mm-step-wait" scope="global">
        <template #code><code>{{ $t('activity.runs.none_recorded_code') }}</code></template>
      </i18n-t>
      <p v-else-if="!visible.length" class="mm-step-wait">{{ $t('activity.runs.no_match') }}</p>

      <div class="app-tray">
        <div class="app-tray-scroll">
        <table class="table app-table small align-middle table-hover">
          <thead>
            <tr>
              <th style="width: 2.5rem"><span class="visually-hidden">{{ $t('activity.runs.columns.expand') }}</span></th>
              <th style="width: 5rem">{{ $t('activity.runs.columns.pass') }}</th>
              <th style="width: 11rem">{{ $t('activity.runs.columns.date') }}</th>
              <th style="width: 10rem">{{ $t('activity.runs.columns.assistant') }}</th>
              <th style="width: 5rem" class="text-end">{{ $t('activity.runs.columns.read') }}</th>
              <th style="width: 5.5rem" class="text-end">{{ $t('activity.runs.columns.applied') }}</th>
              <th style="width: 5rem" class="text-end">{{ $t('activity.runs.columns.filed') }}</th>
              <th>{{ $t('activity.runs.columns.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="run in visible" :key="run.log_id">
                <tr class="mm-run-tr" :class="{ 'is-open': openId === run.log_id }"
                    @click="toggle(run.log_id)">
                  <td>
                    <button type="button" class="mm-run-disclosure"
                            :aria-expanded="openId === run.log_id"
                            :aria-label="$t('activity.runs.details_of', { id: run.log_id })"
                            @click.stop="toggle(run.log_id)">
                      <i class="fa-solid fa-chevron-right"></i>
                    </button>
                  </td>
                  <td class="text-muted">#{{ run.log_id }}</td>
                  <td class="text-nowrap text-muted">{{ formatDateTime(run.at) }}</td>
                  <td class="mm-run-who">{{ run.by }}</td>
                  <td class="text-end">{{ run.counts.examined }}</td>
                  <td class="text-end" :class="{ widened: !run.window_bounded }">{{ applied(run) }}</td>
                  <td class="text-end" :class="{ widened: !run.window_bounded }">{{ filed(run) }}</td>
                  <td>
                    <span v-if="badge(run)" class="badge" :class="badge(run)!.tone === 'bad' ? 'text-bg-danger' : 'text-bg-warning'">
                      {{ badge(run)!.text }}
                    </span>
                    <span class="mm-run-scope" v-if="!run.window_bounded">
                      {{ $t('activity.runs.since_last_pass') }}
                    </span>
                  </td>
                </tr>

                <tr v-if="openId === run.log_id" class="mm-run-detail-row">
                  <td colspan="8">
                    <div class="mm-run-detail">
            <h5 class="mm-run-touched-head">
              {{ $t('activity.runs.summary_head') }}
              <router-link v-if="run.waiting_total" class="mm-run-head-link" :to="{ name: 'inbox' }">{{ $t('activity.runs.review_inbox') }}</router-link>
            </h5>
            <div class="mm-run-overview" v-if="run.description">
              <ClampedMarkdown :text="run.description" :lines="5" />
            </div>

            <p class="mm-run-alert tone-bad" v-if="run.mismatches.length">
              {{ $t('activity.runs.mismatch_lead') }} {{ mismatchLine(run) }}
            </p>
            <p class="mm-curation-why" v-if="!run.window_bounded">
              {{ $t('activity.runs.unbounded_why', { read: run.counts.examined }) }}
            </p>

            <ul class="mm-run-items" v-if="run.notes.length">
              <li v-for="n in run.notes" :key="n.note_id ?? n.title">
                <div class="mm-run-item-head">
                  <router-link v-if="n.note_id !== null" :to="{ name: 'note', params: { id: n.note_id } }">{{ n.title }}</router-link>
                  <template v-else>{{ n.title }}</template>
                  <span class="mm-run-id" v-if="n.note_id !== null">#{{ n.note_id }}</span>
                  <span class="mm-run-id" v-else>{{ $t('activity.runs.since_deleted') }}</span>
                  <span v-for="a in n.outcome" :key="a" class="badge" :class="actionBadge(a)">{{ $t(KIND_KEYS[a]) }}</span>
                  <span v-if="n.waiting" class="badge text-bg-warning">{{ $t('activity.runs.waiting_tag') }}</span>
                  <span v-if="n.failed.length" class="badge text-bg-danger">{{ $t('activity.runs.failed_flag') }}</span>
                </div>
                <ul class="mm-run-item-body">
                  <li v-if="n.flag">
                    <span class="mm-run-label">{{ $t('activity.runs.label_flag') }}</span>
                    <div class="mm-run-value">{{ n.flag }}</div>
                  </li>
                  <li v-if="n.why || !n.flag">
                    <span class="mm-run-label">{{ $t('activity.runs.label_why') }}</span>
                    <div class="mm-run-value" :class="{ 'mm-curation-why': !n.why }">{{ n.why ?? $t('activity.runs.not_given') }}</div>
                  </li>
                  <li v-for="(d, i) in n.done" :key="i">
                    <span class="mm-run-label">{{ n.failed.length && d.action === 'examined' ? $t('activity.runs.label_failed_why') : $t('activity.runs.label_done') }}</span>
                    <div class="mm-run-value">
                      <span v-if="n.done.length > 1" class="mm-curation-why">{{ $t(KIND_KEYS[d.action]) }}</span>
                      <div class="mm-run-done" v-html="renderLogEntry(d.text)"></div>
                    </div>
                  </li>
                  <li v-if="!n.done.length">
                    <span class="mm-run-label">{{ $t('activity.runs.label_done') }}</span>
                    <div class="mm-run-value">{{ $t('activity.runs.nothing_needed') }}</div>
                  </li>
                  <li v-if="n.failed.length" class="mm-run-failed">
                    <span class="mm-run-label">{{ $t('activity.runs.label_failed') }}</span>
                    <div class="mm-run-value">{{ failedWords(n) }}</div>
                  </li>
                </ul>
              </li>
            </ul>
            <p class="mm-curation-why" v-if="moreNotes(run)">
              {{ $t('activity.runs.more', moreNotes(run)) }}
            </p>
            <p class="mm-step-wait" v-if="!run.notes.length">{{ $t('activity.runs.did_nothing') }}</p>

            <p class="mm-run-foot">
              <template v-if="run.window_bounded">{{ $t('activity.runs.recorded_window', { at: formatDateTime(run.at), from: formatDateTime(run.window_from) }) }}</template>
              <template v-else>{{ $t('activity.runs.recorded', { at: formatDateTime(run.at) }) }}</template>
              <template v-if="run.counts.observations || run.counts.tooling_gaps">
                {{ $t('activity.runs.also_logged') }}
                <button
                  v-if="run.counts.observations"
                  type="button"
                  class="mm-run-inline-link"
                  @click="scope(run.log_id, 'observation')"
                >
                  {{ $t('activity.runs.observations', run.counts.observations) }}
                </button>
                <template v-if="run.counts.observations && run.counts.tooling_gaps">, </template>
                <button
                  v-if="run.counts.tooling_gaps"
                  type="button"
                  class="mm-run-inline-link"
                  @click="scope(run.log_id, 'tooling-gap')"
                >
                  {{ $t('activity.runs.tooling_gaps', run.counts.tooling_gaps) }}
                </button>
                .
              </template>
              <button type="button" class="mm-run-inline-link" @click="scope(run.log_id, '')">
                {{ $t('activity.runs.every_row') }}
              </button>
            </p>
                    </div>
                  </td>
                </tr>
            </template>
          </tbody>
        </table>
        </div>
      </div>

      <i18n-t v-if="runs.length" keypath="activity.runs.ledger" tag="p" class="mm-step-wait mt-3" scope="global">
        <template #shown>{{ runs.length }}</template>
        <template #total>{{ runsTotal }}</template>
        <template #link>
          <router-link :to="{ name: 'activity', query: { view: 'journal' } }">{{ $t('activity.runs.ledger_link') }}</router-link>
        </template>
      </i18n-t>
  </div>
</template>
