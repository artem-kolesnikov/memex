<script setup lang="ts">
// Asking for the journal as a file.
//
// A date range because the journal has no ceiling: 800 bytes a row measured,
// so a hundred thousand entries is ~80MB and about twenty seconds of the box's
// time. A range is what bounds that, and the count below the fields is what
// stops it being a surprise — the alternative is pressing Download and finding
// out afterwards.
//
// The file comes straight down the endpoint rather than being prepared and
// linked. nginx buffers the FastCGI response and releases the PHP worker as
// soon as generation ends, so a slow phone never holds one, which is the only
// thing the prepare-then-link shape would have bought (operator, 2026-08-27).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type ActivityQuery } from '@/api/client'

const props = defineProps<{ filters: ActivityQuery; filtered: boolean }>()
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()

const from = ref('')
const to = ref('')
const format = ref<'md' | 'csv'>('md')

const total = ref<number | null>(null)
const counting = ref(false)
const countFailed = ref(false)

const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void } | null = null

// The two calendar days the person picked, turned into the two INSTANTS that
// bound them in their own timezone. The server stores UTC and cannot know the
// zone, so resolving "to 26 August" there cut the range at 26 August 00:00 UTC
// — the evening of the 25th in New York, hiding rows the screen was showing as
// the 26th, and moving again across a DST boundary (Codex, 2026-08-27).
//
// `to` is the start of the day AFTER the one chosen, and the server compares
// with `<`. Constructed field by field rather than parsed from the string,
// because `new Date('2026-08-26')` is UTC midnight while `new Date(2026, 7,
// 26)` is local midnight, which is the whole point.
function dayStart(value: string, plusDays = 0): string | undefined {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (match === null) return undefined
  const [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])]
  const at = new Date(y, m - 1, d + plusDays, 0, 0, 0, 0)
  return Number.isNaN(at.getTime()) ? undefined : at.toISOString()
}

const query = computed<ActivityQuery>(() => ({
  ...props.filters,
  from: from.value ? dayStart(from.value) : undefined,
  to: to.value ? dayStart(to.value, 1) : undefined,
}))

// An inverted range is refused by the server, so it is caught here before it
// becomes a red count and a failed download.
const inverted = computed(() => from.value !== '' && to.value !== '' && from.value > to.value)

const href = computed(() => api.curatorLogExportUrl(query.value, format.value))

// Roughly what the file will weigh, from the 800 bytes a row this journal
// actually measures. Said as "about", because it is an average over rows whose
// descriptions run from one line to a page.
const size = computed(() => {
  if (total.value === null || total.value === 0) return ''
  const bytes = total.value * 800
  return bytes < 950_000
    ? t('activity.download.size_kb', { n: Math.max(1, Math.round(bytes / 1000)) })
    : t('activity.download.size_mb', { n: (bytes / 1_000_000).toFixed(1) })
})

// One pending count, whichever field moved, and a wait — the date inputs fire
// on every keystroke in a typed year.
let pending: number | undefined
// Only the newest count may write: the fields fire on every keystroke in a
// typed year, and an older, slower request landing last shows a number for a
// range nobody is looking at (Codex, 2026-08-27).
let generation = 0
async function count() {
  if (inverted.value) {
    total.value = null
    counting.value = false
    return
  }
  const mine = ++generation
  counting.value = true
  countFailed.value = false
  try {
    const result = await api.curatorLogCount(query.value)
    if (mine !== generation) return
    total.value = result.total
  } catch {
    if (mine !== generation) return
    // Its own state rather than a toast: the count is an estimate offered
    // before a decision, and losing it is not a reason to close the dialog or
    // to stop somebody downloading.
    total.value = null
    countFailed.value = true
  } finally {
    if (mine === generation) counting.value = false
  }
}
watch(query, () => {
  window.clearTimeout(pending)
  pending = window.setTimeout(count, 300)
})

onMounted(() => {
  count()
  if (dialog.value === null) return
  modal = new window.bootstrap.Modal(dialog.value)
  dialog.value.addEventListener('hidden.bs.modal', () => emit('close'))
  modal.show()
})
onBeforeUnmount(() => {
  window.clearTimeout(pending)
  modal?.hide()
})

function close() {
  modal?.hide()
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="journal-download-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title h6 mb-0" id="journal-download-title">{{ $t('activity.download.title') }}</h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="close"></button>
          </div>

          <div class="modal-body">
            <div class="row g-2">
              <div class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="journal-from">{{ $t('activity.download.from') }}</label>
                <input id="journal-from" type="date" class="form-control" v-model="from">
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="journal-to">{{ $t('activity.download.to') }}</label>
                <input id="journal-to" type="date" class="form-control" v-model="to">
              </div>
              <div class="col-12">
                <label class="form-label small mb-1" for="journal-format">{{ $t('activity.download.format') }}</label>
                <select id="journal-format" class="form-select" v-model="format">
                  <option value="md">{{ $t('activity.download.markdown') }}</option>
                  <option value="csv">{{ $t('activity.download.csv') }}</option>
                </select>
              </div>
            </div>

            <p class="small text-muted mb-0 mt-3">
              <template v-if="inverted">{{ $t('activity.download.inverted') }}</template>
              <template v-else-if="counting">{{ $t('activity.download.counting') }}</template>
              <template v-else-if="countFailed">{{ $t('activity.download.count_failed') }}</template>
              <template v-else-if="total === 0">{{ $t('activity.download.empty') }}</template>
              <template v-else-if="total !== null">
                {{ $t('activity.download.entries', { total: total.toLocaleString(), size }, total) }}
              </template>
            </p>
            <!-- Said because it changes what arrives: the dialog downloads what
                 the journal is currently SHOWING, narrowed to these dates. -->
            <p class="small text-muted mb-0 mt-1" v-if="filtered">
              {{ $t('activity.download.filters_apply') }}
            </p>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="close">{{ $t('common.cancel') }}</button>
            <!-- Disabled while the count is catching up, not only when the
                 range is empty: the href follows the fields immediately and
                 the number does not, so a fast click downloaded a range the
                 dialog had not yet described (Codex, 2026-08-27). -->
            <a class="btn btn-primary btn-sm"
               :class="{ disabled: counting || inverted || total === null || total === 0 }"
               :href="href" @click="close">{{ $t('activity.download.download') }}</a>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
