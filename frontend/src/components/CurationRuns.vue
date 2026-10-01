<script setup lang="ts">
// What each pass did.
//
// **Silence is the trust signal.** A row speaks only when something is wrong.
// A tick on every clean row trains the eye to skip the badge column, so the one
// row that matters is scanned past too — and on this vault every pass is clean
// and unbounded, so a badge on the normal case would be a badge on everything.
//
// **One fixed skeleton, three slots, always in the same order.** Did it do
// anything · does its report match the journal · what is on me now. Every field
// lands in exactly one of them and nothing appears twice; the old card rendered
// `edited` three times — a chip, a facts-grid term and a count button — which
// was most of the salad by volume.
//
// **`read` is the one count always attributable to the pass.** `examinedByRun`
// selects on `run_id`, never on the window (CurationDigest §68), so an
// unbounded window widens `applied` and `filed` and leaves `read` alone. That
// is why the scope caption marks two numbers rather than the row, and why
// `read` leads the triple instead of being a parenthetical.
//
// **`applied` and `filed` are never summed.** They are the two states the
// review gate exists to keep apart: one changed the knowledge base, the other
// changed nothing and added to the owner's queue. A pass that filed eighteen
// deletes and applied nothing would otherwise score as one that applied
// eighteen edits, and only one of those left more work than it found.
//
// No score, grade or percentage. A percentage implies a target that does not
// exist — a verification sweep that reads forty notes and rightly changes two
// would read as a failure. Fractions in words carry the same information and
// refuse to grade.
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { CurationRun } from '@/api/client'
import { formatDateTime } from '@/lib/datetime'
import { reasonWords } from '@/lib/curationReasons'
import ClampedMarkdown from '@/components/ClampedMarkdown.vue'

const props = defineProps<{ runs: CurationRun[]; runsTotal: number; loading: boolean }>()
const router = useRouter()
const { t } = useI18n()

const query = ref('')
const filter = ref<'all' | 'mismatch' | 'waiting' | 'defective' | 'changed' | 'unbounded'>('all')
const openId = ref<number | null>(null)

// The select mirrors the three slots, so the list and the card teach the same
// model. The old "Needs attention" merged a misreport with a housekeeping fact
// — two different problems with two different owners.
const FILTERS = [
  { key: 'all', labelKey: 'activity.runs.filters.all' },
  { key: 'mismatch', labelKey: 'activity.runs.filters.mismatch' },
  { key: 'waiting', labelKey: 'activity.runs.filters.waiting' },
  { key: 'defective', labelKey: 'activity.runs.filters.defective' },
  { key: 'changed', labelKey: 'activity.runs.filters.changed' },
  { key: 'unbounded', labelKey: 'activity.runs.filters.unbounded' },
] as const

const APPLIED: { key: keyof CurationRun['counts']; labelKey: string; action: string }[] = [
  { key: 'edited', labelKey: 'activity.runs.counts.edited', action: 'edit' },
  { key: 'created', labelKey: 'activity.runs.counts.created', action: 'create' },
  { key: 'flags_resolved', labelKey: 'activity.runs.counts.flags_resolved', action: 'flag-resolved' },
]
const FILED: { key: keyof CurationRun['counts']; labelKey: string; action: string }[] = [
  { key: 'held', labelKey: 'activity.runs.counts.held', action: 'edit-proposed' },
  // `proposed` is `delete-proposed` plus `merge-proposed`; a link naming only
  // one lands the reader on a row set that is not what was counted.
  { key: 'proposed', labelKey: 'activity.runs.counts.proposed', action: 'delete-proposed,merge-proposed' },
]

const applied = (run: CurationRun) => APPLIED.reduce((n, c) => n + run.counts[c.key], 0)
const filed = (run: CurationRun) => FILED.reduce((n, c) => n + run.counts[c.key], 0)

/**
 * At most one badge, by fixed precedence.
 *
 * A mismatch contaminates every other number on the row, so it outranks. Then
 * the thing needing the owner's hand — which is present-tense and half of why
 * they opened the page, so a pass with three deletes on their verdict must not
 * look identical to a clean one. Then housekeeping.
 *
 * An unbounded window is never a badge: it is the majority case, and marking
 * the common case as an alarm is what teaches an owner to ignore the colour
 * that must never be ignored.
 */
function badge(run: CurationRun): { text: string; tone: 'bad' | 'warn' } | null {
  if (run.mismatches.length) return { text: t('activity.runs.badge.mismatch'), tone: 'bad' }
  if (run.waiting_total) return { text: t('activity.runs.badge.waiting', run.waiting_total), tone: 'warn' }
  if (run.still_defective_total) {
    return { text: t('activity.runs.badge.defective', run.still_defective_total), tone: 'warn' }
  }
  return null
}

/**
 * Slot 1. `filed` is stated separately, never folded into a single figure.
 *
 * The "N of the M it read" phrasing asserts a subset, and there are TWO ways
 * that can be false. Unbounded, `applied` is widened while `read` is not,
 * which on real data produced "24 of the 18 notes it read came out changed".
 * And `read` is the agent's own list — `recordExamined()` writes whatever ids
 * the pass sent — while `applied` is counted from rows the server wrote, so a
 * pass that edits more notes than it lists breaks the subset in a bounded
 * window too (Codex, on review). The subset sentence is used only when both
 * hold; otherwise the two are stated as separate facts.
 */
function didItDoAnything(run: CurationRun): string {
  const a = applied(run)
  const f = filed(run)
  const read = run.counts.examined
  if (read === 0) {
    if (a === 0 && f === 0) return t('activity.runs.did.nothing')
    return t('activity.runs.did.no_read', { applied: t('activity.runs.did.changes', a), filed: f })
  }

  if (!run.window_bounded || a > read) {
    return `${t('activity.runs.did.read_notes', read)} ${t('activity.runs.did.window_applied', { filed: f }, a)}`
  }

  const changed =
    a === 0
      ? t('activity.runs.did.none_changed', read)
      : t('activity.runs.did.some_changed', { applied: a }, read)

  return f === 0
    ? t('activity.runs.did.subset_nothing_filed', { changed })
    : t('activity.runs.did.subset_more_filed', { changed, tail: t('activity.runs.did.filed_more', f) })
}

/**
 * Slot 2, always present in one of three states.
 *
 * The slot stays even when there is nothing to check: if it only appeared on
 * failure, an owner would never learn how many of their passes make no
 * checkable claim at all — which is a fact about their wiring, and the one
 * nudge on this card toward briefing assistants to declare them.
 */
function itsReport(run: CurationRun): { text: string; tone: 'bad' | 'plain' | 'quiet' } {
  if (run.mismatches.length) {
    const agreed = Object.keys(run.claims ?? {}).filter(
      (k) => !run.mismatches.some((m) => m.claim === k),
    )
    const parts = [t('activity.runs.report.disagree')]
    if (agreed.length) {
      const claims = agreed.join(` ${t('activity.runs.report.and')} `)
      parts.push(t('activity.runs.report.matched', { claims }, agreed.length))
    }
    return { text: parts.join(' '), tone: 'bad' }
  }
  const claims = Object.entries(run.claims ?? {})
  if (claims.length) {
    return {
      text: t('activity.runs.report.claimed_agrees', {
        claims: claims.map(([k, v]) => `${v} ${k}`).join(', '),
      }),
      tone: 'plain',
    }
  }
  return { text: t('activity.runs.report.claimed_nothing'), tone: 'quiet' }
}

/**
 * Slot 3. `defectsAmong` is a present-tense query over the notes this pass
 * read: memex does not know whether the pass caused the defect, found it, or
 * could not fix it. So "still fail", never "failed to fix" — `untagged` on a
 * note a pass deliberately skipped is not a failure and must not read as one.
 */
function onYouNow(run: CurationRun): string[] {
  const out: string[] = []
  if (run.waiting_total) out.push(t('activity.runs.on_you.waiting', run.waiting_total))
  if (run.still_defective_total) out.push(t('activity.runs.on_you.defective', run.still_defective_total))
  return out.length ? out : [t('activity.runs.on_you.nothing')]
}

function matches(run: CurationRun): boolean {
  switch (filter.value) {
    case 'mismatch':
      if (!run.mismatches.length) return false
      break
    case 'waiting':
      if (!run.waiting_total) return false
      break
    case 'defective':
      if (!run.still_defective_total) return false
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
  // Searchable by the notes a pass touched, not only its own words — which is
  // how you find "the pass that broke that note" without opening ten of them.
  const haystack = [
    run.by,
    run.description,
    ...run.still_defective.map((d) => `${d.note_id} ${d.title}`),
    ...run.waiting.map((w) => `${w.note_id} ${w.title}`),
    ...run.written.map((w) => `${w.note_id ?? ''} ${w.title}`),
    ...run.examined.map((e) => `${e.note_id ?? ''} ${e.title}`),
  ]
    .join(' ')
    .toLowerCase()

  return haystack.includes(q)
}

const visible = computed(() => props.runs.filter(matches))

const moreWaiting = (run: CurationRun) => Math.max(0, run.waiting_total - run.waiting.length)
const moreDefective = (run: CurationRun) => Math.max(0, run.still_defective_total - run.still_defective.length)
const moreWritten = (run: CurationRun) => Math.max(0, run.written_total - run.written.length)
const moreExamined = (run: CurationRun) => Math.max(0, run.counts.examined - run.examined.length)

function toggle(id: number) {
  openId.value = openId.value === id ? null : id
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
                    <span v-if="badge(run)" class="mm-run-badge" :class="'tone-' + badge(run)!.tone">
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
            <dl class="mm-run-slots">
              <div>
                <dt>{{ $t('activity.runs.slot_did') }}</dt>
                <dd>
                  {{ didItDoAnything(run) }}
                  <span class="mm-run-breakdown">
                    <span>{{ $t('activity.runs.applied') }}</span>
                    <span v-for="c in APPLIED" :key="c.key" :class="{ zero: run.counts[c.key] === 0 }">
                      {{ run.counts[c.key] }} {{ $t(c.labelKey) }}
                    </span>
                  </span>
                  <span class="mm-run-breakdown">
                    <span>{{ $t('activity.runs.filed') }}</span>
                    <span v-for="c in FILED" :key="c.key" :class="{ zero: run.counts[c.key] === 0 }">
                      {{ run.counts[c.key] }} {{ $t(c.labelKey) }}
                    </span>
                  </span>
                  <!-- Names the number that is NOT widened, which is what stops
                       this reading as "the counts are wrong". -->
                  <span class="mm-curation-why" v-if="!run.window_bounded">
                    {{ $t('activity.runs.unbounded_why', { read: run.counts.examined }) }}
                  </span>
                </dd>
              </div>

              <div>
                <dt>{{ $t('activity.runs.slot_report') }}</dt>
                <dd :class="'tone-' + itsReport(run).tone">
                  {{ itsReport(run).text }}
                  <table class="table table-sm mm-run-mismatch mt-2" v-if="run.mismatches.length">
                    <tbody>
                      <tr v-for="m in run.mismatches" :key="m.claim">
                        <td>{{ $t('activity.runs.claimed_row', { n: m.claimed, claim: m.claim }) }}</td>
                        <td>{{ $t('activity.runs.journal_holds', { n: m.logged }) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </dd>
              </div>

              <div>
                <dt>{{ $t('activity.runs.slot_on_you') }}</dt>
                <dd>
                  <span v-for="line in onYouNow(run)" :key="line" class="d-block">{{ line }}</span>
                </dd>
              </div>
            </dl>

            <div class="mm-run-todo" v-if="run.waiting.length">
              <h5 class="mm-run-touched-head">
                {{ $t('activity.runs.awaiting_verdict') }}
                <router-link class="mm-run-head-link" :to="{ name: 'inbox' }">{{ $t('activity.runs.review_inbox') }}</router-link>
              </h5>
              <ul class="mm-curation-queue">
                <li v-for="w in run.waiting" :key="w.log_id">
                  <router-link :to="{ name: 'note', params: { id: w.note_id } }">
                    {{ w.title }}
                  </router-link>
                  <span class="mm-curation-why">{{ w.kind }}</span>
                </li>
              </ul>
              <p class="mm-curation-why" v-if="moreWaiting(run)">
                {{ $t('activity.runs.more', moreWaiting(run)) }}
              </p>
            </div>

            <div class="mm-run-todo" v-if="run.still_defective.length">
              <h5 class="mm-run-touched-head">{{ $t('activity.runs.still_defective') }}</h5>
              <ul class="mm-curation-queue">
                <li v-for="d in run.still_defective" :key="d.note_id">
                  <router-link :to="{ name: 'note', params: { id: d.note_id } }">
                    {{ d.title }}
                  </router-link>
                  <span class="mm-curation-why">{{ d.reasons.map(reasonWords).join(' · ') }}</span>
                </li>
              </ul>
              <p class="mm-curation-why" v-if="moreDefective(run)">
                {{ $t('activity.runs.more', moreDefective(run)) }}
              </p>
            </div>

            <div class="mm-run-todo" v-if="run.written.length">
              <h5 class="mm-run-touched-head">{{ $t('activity.runs.written') }}</h5>
              <ul class="mm-curation-queue">
                <li v-for="w in run.written" :key="w.note_id ?? w.title">
                  <router-link v-if="w.note_id !== null" :to="{ name: 'note', params: { id: w.note_id } }">
                    {{ w.title }}
                  </router-link>
                  <template v-else>{{ w.title }}</template>
                  <span class="mm-curation-why">
                    {{ w.kind === 'created' ? $t('activity.runs.counts.created') : $t('activity.runs.counts.edited') }}<template v-if="w.rows > 1"> · {{ $t('activity.runs.edits_to_it', w.rows) }}</template><template v-if="w.note_id === null"> · {{ $t('activity.runs.since_deleted') }}</template>
                  </span>
                </li>
              </ul>
              <p class="mm-curation-why" v-if="moreWritten(run)">
                {{ $t('activity.runs.more', moreWritten(run)) }}
              </p>
            </div>

            <!-- Inline rather than a list: a pass reads twenty notes and the
                 defective ones are already listed above, so a second column
                 of bullets would be most of the card by height. -->
            <div class="mm-run-todo" v-if="run.examined.length">
              <h5 class="mm-run-touched-head">{{ $t('activity.runs.examined_list') }}</h5>
              <p class="mm-run-read">
                <template v-for="(e, i) in run.examined" :key="e.note_id ?? e.title"><template v-if="i > 0"> · </template><router-link v-if="e.note_id !== null" :to="{ name: 'note', params: { id: e.note_id } }">{{ e.title }}</router-link><template v-else>{{ e.title }} <span class="mm-curation-why">({{ $t('activity.runs.since_deleted') }})</span></template></template>
                <span class="mm-curation-why" v-if="moreExamined(run)"> {{ $t('activity.runs.more', moreExamined(run)) }}</span>
              </p>
            </div>

            <div class="mm-run-said" v-if="run.description">
              <h5 class="mm-run-touched-head">
                {{ $t('activity.runs.own_words', { by: run.by }) }}
                <span class="mm-curation-why">{{ $t('activity.runs.quoted_whole') }}</span>
              </h5>
              <ClampedMarkdown :text="run.description" :lines="4" />
            </div>

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
