<script setup lang="ts">
// The whole of limbo, in its own box because a month of curation can retire a
// hundred notes and the Content pane is itself inside a dialog.
//
// Paged and searched on the SERVER: `GET /api/deleted` answers one page and its
// own total, so nothing here can show a count the listing cannot reach.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type DeletedNote } from '@/api/client'
import { toastError, toastSuccess } from '@/components/toastService'
import { formatDateTime } from '@/lib/datetime'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const emit = defineEmits<{ changed: []; close: [] }>()

const { t } = useI18n()

const PER_PAGE = 20

const loading = ref(true)
const failed = ref(false)
const busy = ref<number | null>(null)
const notes = ref<DeletedNote[]>([])
const limboDays = ref(30)
const showPurged = ref(false)
const query = ref('')
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const purging = ref<DeletedNote | null>(null)

const searching = computed(() => query.value.trim() !== '')

// Every fetch carries the number of the request that started it; a slower
// earlier one finding a newer number has been overtaken and stops. Typing in
// the search box is the case that produces them.
let generation = 0
const lifetime = useOperationLifetime()

async function load() {
  const mine = ++generation
  const owner = lifetime.capture()
  loading.value = true
  try {
    const result = await api.deletedNotes(showPurged.value, page.value, PER_PAGE, query.value.trim())
    if (mine !== generation || !lifetime.current(owner)) return
    notes.value = result.notes
    limboDays.value = result.limbo_days
    total.value = result.total
    pages.value = result.pages
    // The server clamps a page past the end, and says which one it answered.
    page.value = result.page
    failed.value = false
  } catch (e) {
    if (mine !== generation || !lifetime.current(owner)) return
    failed.value = true
    toastError(t('content.deleted.load_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (mine === generation && lifetime.current(owner)) loading.value = false
  }
}

// A new filter is a new listing, so it starts at the front. `page` is watched
// separately, or setting it back to 1 here would fetch twice.
let typing: ReturnType<typeof setTimeout> | undefined
watch([query, showPurged], () => {
  clearTimeout(typing)
  typing = setTimeout(() => {
    page.value = 1
    load()
  }, 200)
})
watch(page, load)
watch(sessionEpoch, load)

async function restore(note: DeletedNote) {
  const owner = lifetime.capture()
  busy.value = note.note_id
  try {
    await api.restoreNote(note.note_id)
    if (!lifetime.current(owner)) return
    toastSuccess(
      t('content.deleted.restored'),
      t('content.deleted.restored_body', { title: note.title, id: note.note_id }),
    )
    emit('changed')
    await load()
  } catch (e) {
    if (!lifetime.current(owner)) return
    toastError(t('content.deleted.restore_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (lifetime.current(owner)) busy.value = null
  }
}

async function purge() {
  const owner = lifetime.capture()
  const note = purging.value
  if (note === null) return
  busy.value = note.note_id
  try {
    await api.purgeNote(note.note_id)
    if (!lifetime.current(owner)) return
    toastSuccess(t('content.deleted.purged'), t('content.deleted.purged_body', { title: note.title }))
    purging.value = null
    emit('changed')
    await load()
  } catch (e) {
    if (!lifetime.current(owner)) return
    toastError(t('content.deleted.purge_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (lifetime.current(owner)) busy.value = null
  }
}

const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

onMounted(() => {
  load()
  if (dialog.value === null) return
  modal = new window.bootstrap.Modal(dialog.value)
  dialog.value.addEventListener('hidden.bs.modal', () => emit('close'))
  modal.show()
})

// `hide()` alone is not a teardown: Bootstrap returns from it while the dialog
// is still transitioning, and unmounting mid-fade strands the backdrop.
onBeforeUnmount(() => {
  clearTimeout(typing)
  generation++
  modal?.hide()
  modal?.dispose()
  modal = null
})

function close() {
  modal?.hide()
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade app-dialog mm-deleted-dialog" tabindex="-1" ref="dialog"
         aria-labelledby="deleted-notes-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h3 id="deleted-notes-title" class="modal-title h5">{{ $t('content.deleted.title') }}</h3>
              <p class="mm-map-dialog-note">
                {{ $t('content.deleted.dialog_note', { days: limboDays }) }}
              </p>
            </div>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="close"></button>
          </div>

          <div class="modal-body">
            <div class="mm-deleted-tools">
              <div class="mm-map-find">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input v-model="query" type="search" class="form-control form-control-sm"
                       :placeholder="$t('content.deleted.find_placeholder')"
                       :aria-label="$t('content.deleted.find_aria')">
              </div>
              <div class="form-check form-switch mb-0">
                <input id="deleted-purged" v-model="showPurged" class="form-check-input" type="checkbox">
                <label for="deleted-purged" class="form-check-label small">{{ $t('content.deleted.include_purged') }}</label>
              </div>
            </div>

            <p class="mm-note mb-0" v-if="loading">{{ $t('common.loading') }}</p>
            <p class="mm-note mb-0" v-else-if="failed">
              {{ $t('content.deleted.list_failed') }}
            </p>

            <template v-else>
              <ul class="mm-deleted-list mm-deleted-scroll" v-if="notes.length">
                <li v-for="note in notes" :key="note.note_id" :class="{ 'is-tombstone': !note.restorable }">
                  <div class="mm-deleted-copy">
                    <span class="mm-deleted-title">{{ note.title }}</span>
                    <span class="mm-deleted-meta">
                      {{ formatDateTime(note.deleted_at) }} · {{ note.deleted_by }}
                    </span>
                    <span class="mm-deleted-why" v-if="note.deleted_reason" :title="note.deleted_reason">
                      {{ note.deleted_reason }}
                    </span>
                  </div>

                  <template v-if="note.restorable">
                    <span class="mm-deleted-left" :class="{ 'is-urgent': note.days_left <= 3 }">
                      {{ $t('content.deleted.days_left', note.days_left) }}
                    </span>
                    <div class="mm-deleted-actions">
                      <button type="button" class="btn btn-outline-primary btn-sm"
                              :disabled="busy === note.note_id" @click="restore(note)">{{ $t('content.deleted.restore') }}</button>
                      <button type="button" class="btn btn-link btn-sm mm-deleted-purge"
                              :disabled="busy === note.note_id" @click="purging = note">{{ $t('content.deleted.purge_now') }}</button>
                    </div>
                  </template>
                  <span class="mm-deleted-left" v-else>{{ $t('content.deleted.content_gone') }}</span>
                </li>
              </ul>

              <p class="mm-note mb-0" v-else-if="searching">
                {{ $t('content.deleted.no_match', { query: query.trim() }) }}
              </p>
              <p class="mm-note mb-0" v-else>
                {{ $t('content.deleted.empty', { days: limboDays }) }}
              </p>
            </template>
          </div>

          <div class="modal-footer mm-deleted-footer">
            <span class="small text-muted">
              {{ $t('content.deleted.total', total) }}<template
                v-if="!showPurged"> · {{ $t('content.deleted.tombstones_hidden') }}</template>
            </span>
            <div class="mm-deleted-pager" v-if="pages > 1">
              <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="page <= 1"
                      @click="page = page - 1">
                <i class="fa-solid fa-chevron-left"></i>
              </button>
              <i18n-t keypath="content.deleted.page" tag="span" class="small text-muted" scope="global">
                <template #page><strong>{{ page }}</strong></template>
                <template #pages>{{ pages }}</template>
              </i18n-t>
              <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="page >= pages"
                      @click="page = page + 1">
                <i class="fa-solid fa-chevron-right"></i>
              </button>
            </div>
          </div>
        </div>
      </div>

      <ConfirmDialog v-if="purging" :title="$t('content.deleted.purge_confirm_title')"
                     :confirm-label="$t('content.deleted.purge_confirm_label')"
                     danger :busy="busy !== null" @confirm="purge" @close="purging = null">
        <i18n-t keypath="content.deleted.purge_confirm_body" scope="global">
          <template #title><strong>{{ purging.title }}</strong></template>
        </i18n-t>
      </ConfirmDialog>
    </div>
  </Teleport>
</template>
