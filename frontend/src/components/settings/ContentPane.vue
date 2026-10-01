<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type NoteListItem, type RetiredTag, type TagVocabularyEntry } from '@/api/client'
import { toastError } from '@/components/toastService'
import TagRemovalDialog from '@/components/settings/TagRemovalDialog.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ExportBlock from '@/components/settings/ExportBlock.vue'
import DeletedNotesBlock from '@/components/settings/DeletedNotesBlock.vue'

// --- Import -------------------------------------------------------------
//
// A queue rather than one file: dropping three files used to keep the first and
// discard the rest in silence. Each entry carries its own analysis and its own
// result, because a zip is a vault that needs a pass before it can be counted
// and a lone .md is one note that does not.
//
// The drop zone and the file picker are the same control: a drop appends what a
// chosen file appends, so everything below this line is blind to which happened.

interface Queued {
  id: number
  file: File
  isZip: boolean
  analysis: VaultAnalysis | null
  done: VaultAnalysis | null
  uploaded: NoteListItem[] | null
  busy: boolean
  failed: string | null
  // Per entry, not shared: one archive's "import them again" must not opt in
  // every other queued archive, whose deleted notes are a different decision.
  resurrectRetired: boolean
}

type VaultAnalysis = Awaited<ReturnType<typeof api.importVault>>

const { t } = useI18n()

const queue = ref<Queued[]>([])
const dragging = ref(false)
const skipDuplicates = ref(true)
const folderTags = ref(false)
const extraTags = ref('')
const importing = ref(false)

const ACCEPTED = ['.zip', '.md', '.markdown', '.txt']

let nextId = 1

const hasZip = computed(() => queue.value.some((q) => q.isZip))
// `busy` excludes an entry from both: an Analyze still in flight would
// otherwise be importable, and a late analysis response would then clear the
// `done` that the finished import had just written — putting an imported
// archive back in the queue to be imported a second time.
const pending = computed(() =>
  queue.value.filter((q) => q.done === null && q.uploaded === null && !q.busy),
)
const analysable = computed(() => pending.value.filter((q) => q.isZip && q.analysis === null))
const anyImported = computed(() => queue.value.some((q) => q.done || q.uploaded))

function accepted(file: File): boolean {
  return ACCEPTED.some((ext) => file.name.toLowerCase().endsWith(ext))
}

/** Same file dropped twice is the same file, and importing it twice is not
 *  something anybody asks for on purpose. */
function alreadyQueued(file: File): boolean {
  return queue.value.some((q) => q.file.name === file.name && q.file.size === file.size)
}

function add(files: FileList | File[] | null) {
  const incoming = [...(files ?? [])]
  if (incoming.length === 0) return

  const rejected: string[] = []
  for (const file of incoming) {
    // Checked here rather than left to the server, which would accept the
    // upload and then explain: the name is enough to know, and refusing a
    // .pages file before it is sent is faster and clearer.
    if (!accepted(file)) {
      rejected.push(file.name)
      continue
    }
    if (alreadyQueued(file)) continue
    queue.value.push({
      id: nextId++,
      file,
      isZip: file.name.toLowerCase().endsWith('.zip'),
      analysis: null,
      done: null,
      uploaded: null,
      busy: false,
      failed: null,
      // Notes this knowledge base deleted on purpose are held out of an import
      // unless this is on. Off by default, and the entry is re-analysed when it
      // changes, so its count is what will actually be created.
      resurrectRetired: false,
    })
  }

  if (rejected.length) {
    toastError(
      t('content.import.not_added', rejected.length),
      t('content.import.skipped', { files: rejected.join(', ') }),
    )
  }
}

function onFileChosen(event: Event) {
  const input = event.target as HTMLInputElement
  add(input.files)
  // Cleared so choosing the SAME file again still fires a change event.
  input.value = ''
}

function onDrop(event: DragEvent) {
  dragging.value = false
  add(event.dataTransfer?.files ?? null)
}

function drop(entry: Queued) {
  queue.value = queue.value.filter((q) => q.id !== entry.id)
}

function clearQueue() {
  queue.value = []
}

/** The shared ZIP options change what every archive would import, so every
 *  count they produced stops being true. */
function optionsChanged() {
  for (const entry of queue.value) entry.analysis = null
}

async function analyze(entry: Queued) {
  if (entry.busy) return
  entry.busy = true
  entry.failed = null
  try {
    entry.analysis = await api.importVault(entry.file, {
      dryRun: true,
      skipDuplicates: skipDuplicates.value,
      folderTags: folderTags.value,
      tags: extraTags.value,
      resurrectRetired: entry.resurrectRetired,
    })
    entry.done = null
  } catch (e) {
    entry.failed = e instanceof Error ? e.message : t('common.unknown_error')
    toastError(t('content.import.archive_unreadable'), entry.failed)
  } finally {
    entry.busy = false
  }
}

async function analyzeAll() {
  for (const entry of analysable.value) await analyze(entry)
}

async function importOne(entry: Queued) {
  if (entry.busy) return
  entry.busy = true
  entry.failed = null
  try {
    if (entry.isZip) {
      entry.done = await api.importVault(entry.file, {
        dryRun: false,
        skipDuplicates: skipDuplicates.value,
        folderTags: folderTags.value,
        tags: extraTags.value,
        resurrectRetired: entry.resurrectRetired,
      })
      entry.analysis = null
    } else {
      const result = await api.upload([entry.file])
      const failure = result.errors[0]
      if (failure) {
        entry.failed = failure.error
        toastError(
          t('content.import.not_imported'),
          t('content.import.file_error', { file: entry.file.name, error: failure.error }),
        )
        return
      }
      entry.uploaded = result.created
    }
  } catch (e) {
    entry.failed = e instanceof Error ? e.message : t('common.unknown_error')
    toastError(
      t('content.import.failed'),
      t('content.import.file_error', { file: entry.file.name, error: entry.failed }),
    )
  } finally {
    entry.busy = false
  }
}

// One at a time rather than in parallel: each archive is up to 32 MB and the
// server counts duplicates against what is already there, so two running
// together would each decide against a knowledge base the other is changing.
async function importAll() {
  importing.value = true
  try {
    for (const entry of pending.value) await importOne(entry)
  } finally {
    importing.value = false
    loadTags()
  }
}

// --- Tags ---------------------------------------------------------------
type TagRow = TagVocabularyEntry

const tags = ref<TagRow[]>([])
const retired = ref<RetiredTag[]>([])
const tagsLoaded = ref(false)
const tagsFailed = ref(false)

const sortedTags = computed(() =>
  [...tags.value].sort((a, b) => b.note_count - a.note_count || a.name.localeCompare(b.name)),
)

// Which tag's dialog is open, if any. Held as an ID and resolved, because the
// list is reloaded when the dialog finishes and a held object would be a row
// that no longer exists.
const editingTag = ref<number | null>(null)
const editing = computed(() => tags.value.find((t) => t.id === editingTag.value) ?? null)
// The words memex reads, if this knowledge base has any yet — named in the
// line under the list, so the padlocks are explained rather than just seen.
const systemTags = computed(() => sortedTags.value.filter((t) => t.system))
// System tags are NOT filtered out of the merge targets. Consolidating
// `skills` into `skill` is exactly the tag hygiene the curator charter asks
// for, and the lock is on retiring the word, not on notes arriving at it —
// see App\Service\SystemTags.
const mergeTargets = computed(() => sortedTags.value.filter((t) => t.id !== editingTag.value))

const restoringTag = ref<string | null>(null)
const restoring = ref<string | null>(null)

async function restoreTag() {
  const name = restoringTag.value
  if (name === null) return
  restoring.value = name
  try {
    await api.restoreTag(name)
    restoringTag.value = null
    await loadTags()
  } catch (e) {
    toastError(t('content.tags.not_restored'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    restoring.value = null
  }
}

function openTagDialog(tag: TagRow) {
  editingTag.value = tag.id
}

async function loadTags() {
  try {
    const result = await api.tags()
    tags.value = result.tags
    retired.value = result.retired
    tagsFailed.value = false
  } catch (e) {
    tagsFailed.value = true
    toastError(t('content.tags.load_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    tagsLoaded.value = true
  }
}

onMounted(loadTags)
</script>

<template>
  <section class="mm-pane">
    <div class="mm-block" id="import">
      <h3 class="mm-block-title">{{ $t('content.import.title') }}</h3>
      <p class="mm-note">{{ $t('content.import.intro') }}</p>

      <div class="mm-dropzone" :class="{ 'is-dragging': dragging }"
           @dragover.prevent="dragging = true"
           @dragleave.prevent="dragging = false"
           @drop.prevent="onDrop">
        <i class="fa-solid fa-file-arrow-up mm-dropzone-icon"></i>
        <p class="mm-dropzone-title mb-1">{{ $t('content.import.dropzone_title') }}</p>
        <i18n-t keypath="content.import.dropzone_hint" tag="p" class="mm-note mb-1" scope="global">
          <template #choose>
            <span class="btn btn-link p-0 align-baseline mm-file-picker-button"
                  :class="{ disabled: importing }">
              <span aria-hidden="true">{{ $t('content.import.choose_files') }}</span>
              <input type="file" class="mm-file-input" multiple accept=".zip,.md,.txt,.markdown"
                     :disabled="importing" :aria-label="$t('content.import.choose_files_aria')"
                     @change="onFileChosen">
            </span>
          </template>
        </i18n-t>
        <p class="mm-note mb-0">{{ $t('content.import.zip_max') }}</p>
      </div>

      <template v-if="queue.length">
        <div class="d-flex align-items-center flex-wrap gap-2 mt-3">
          <strong v-if="pending.length">
            {{ $t('content.import.files_ready', pending.length) }}
          </strong>
          <strong v-else>
            {{ $t('content.import.files_imported', queue.length) }}
          </strong>
          <span class="btn btn-link btn-sm p-0 align-baseline mm-file-picker-button"
                :class="{ disabled: importing }">
            <span aria-hidden="true">{{ $t('content.import.add_more') }}</span>
            <input type="file" class="mm-file-input" multiple accept=".zip,.md,.txt,.markdown"
                   :disabled="importing" :aria-label="$t('content.import.add_more_aria')"
                   @change="onFileChosen">
          </span>
          <button type="button" class="btn btn-link btn-sm p-0 align-baseline"
                  :disabled="importing" @click="clearQueue">{{ $t('content.import.clear') }}</button>
        </div>

        <template v-if="hasZip">
          <div class="d-flex flex-wrap align-items-center my-2" style="gap: .25rem 1.25rem;">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" id="skip-dup"
                     v-model="skipDuplicates" @change="optionsChanged">
              <label class="form-check-label small" for="skip-dup">{{ $t('content.import.skip_duplicates') }}</label>
            </div>
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" id="folder-tags"
                     v-model="folderTags" @change="optionsChanged">
              <label class="form-check-label small" for="folder-tags">{{ $t('content.import.folder_tags') }}</label>
            </div>
          </div>
          <div class="mb-2">
            <input type="text" class="form-control form-control-sm" v-model="extraTags"
                   :placeholder="$t('content.import.extra_tags_placeholder')"
                   @change="optionsChanged">
          </div>
        </template>

        <ul class="mm-import-queue">
          <li v-for="entry in queue" :key="entry.id">
            <div class="mm-import-row">
              <i class="fa-solid fa-fw"
                 :class="entry.isZip ? 'fa-file-zipper' : 'fa-file-lines'"></i>
              <span class="mm-import-name">{{ entry.file.name }}</span>

              <span class="mm-import-state" v-if="entry.busy">{{ $t('content.import.working') }}</span>
              <span class="mm-import-state is-bad" v-else-if="entry.failed">{{ entry.failed }}</span>
              <span class="mm-import-state is-good" v-else-if="entry.done">
                {{ $t('content.import.notes_imported', entry.done.created ?? 0) }}
              </span>
              <span class="mm-import-state is-good" v-else-if="entry.uploaded?.length">
                <router-link v-for="n in entry.uploaded" :key="n.id"
                             :to="{ name: 'note', params: { id: n.id } }">{{ n.title }}</router-link>
              </span>
              <span class="mm-import-state" v-else-if="entry.analysis">
                {{ $t('content.import.notes_ready', { n: entry.analysis.importable }) }}<template
                  v-if="entry.analysis.duplicates?.length"> · {{ skipDuplicates
                    ? $t('content.import.duplicates_skipped', { n: entry.analysis.duplicates.length })
                    : $t('content.import.duplicates_imported', { n: entry.analysis.duplicates.length }) }}</template>
              </span>
              <span class="mm-import-state" v-else-if="entry.isZip">{{ $t('content.import.not_analysed') }}</span>
              <span class="mm-import-state" v-else>{{ $t('content.import.ready') }}</span>

              <button type="button" class="mm-tag-x"
                      :aria-label="$t('content.import.remove_file', { file: entry.file.name })"
                      :disabled="importing || entry.busy" @click="drop(entry)">×</button>
            </div>

            <!-- The archive contains notes this knowledge base deleted on
                 purpose. Re-importing an old export is the ordinary way to hit
                 this, and putting them back silently would undo curation
                 nobody asked to undo. -->
            <div class="mm-import-retired" v-if="entry.analysis?.previously_retired?.length">
              <div class="fw-semibold small">
                {{ $t('content.import.previously_retired', { n: entry.analysis.previously_retired.length }) }}
              </div>
              <span v-for="r in entry.analysis.previously_retired.slice(0, 8)" :key="r.note_id"
                    class="d-block small text-muted">
                {{ r.title }}<template v-if="r.reason"> — {{ r.reason }}</template>
              </span>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" :id="`import-resurrect-${entry.id}`"
                       v-model="entry.resurrectRetired" :disabled="importing || entry.busy"
                       @change="analyze(entry)">
                <label class="form-check-label small" :for="`import-resurrect-${entry.id}`">
                  {{ $t('content.import.resurrect') }}
                </label>
              </div>
            </div>
          </li>
        </ul>

        <div class="d-flex align-items-center gap-2 mt-2">
          <button class="btn btn-outline-secondary btn-sm" v-if="analysable.length"
                  :disabled="importing" @click="analyzeAll">
            <i class="fa-solid fa-magnifying-glass-chart me-1"></i>
            {{ $t('content.import.analyze', { n: analysable.length }) }}
          </button>
          <button class="btn btn-primary btn-sm" v-if="pending.length" :disabled="importing"
                  @click="importAll">
            <i class="fa-solid fa-file-arrow-up me-1"></i>
            {{ $t('content.import.import_files', pending.length) }}
          </button>
          <div class="spinner-border spinner-border-sm" role="status" v-if="importing">
            <span class="visually-hidden">{{ $t('content.import.working_status') }}</span>
          </div>
        </div>

        <i18n-t v-if="anyImported" keypath="content.import.browse"
                tag="p" class="mm-note mb-0 mt-2" scope="global">
          <template #link>
            <router-link :to="{ name: 'search' }">{{ $t('content.import.browse_link') }}</router-link>
          </template>
        </i18n-t>
      </template>
    </div>

    <ExportBlock />

    <div class="mm-block" id="tags">
      <div class="mm-block-head">
        <h3 class="mm-block-title mb-0">{{ $t('content.tags.title') }}</h3>
        <span class="small text-muted" v-if="tags.length">{{ $t('content.tags.in_use', { n: tags.length }) }}</span>
      </div>

      <p class="mm-note">{{ $t('content.tags.intro') }}</p>

      <div class="alert alert-warning" v-if="tagsFailed">
        {{ $t('content.tags.load_failed_note') }}
      </div>

      <div class="mb-0" v-else>
        <span v-for="tag in sortedTags" :key="tag.id" class="mm-tag mm-tag-editable me-1 mb-1"
              :class="{ 'mm-tag-system': tag.system }">
          <router-link :to="{ name: 'search', query: { tag: tag.name } }">
            {{ tag.name }} {{ tag.note_count }}
          </router-link>
          <!-- A padlock where the × would be, rather than a disabled × : a ×
               that refuses when you click it teaches the refusal one click too
               late, and the chip keeps its shape either way. -->
          <i v-if="tag.system" class="fa-solid fa-lock mm-tag-lock"
             :title="tag.system_reason ?? undefined"
             :aria-label="$t('content.tags.system_aria', { tag: tag.name })"></i>
          <button v-else type="button" class="mm-tag-x"
                  :aria-label="$t('content.tags.remove_aria', { tag: tag.name })"
                  @click="openTagDialog(tag)">×</button>
        </span>
        <span v-if="!tags.length && tagsLoaded" class="small text-muted">{{ $t('content.tags.empty') }}</span>
      </div>

      <p class="mm-note mb-0 mt-2" v-if="systemTags.length">
        <i class="fa-solid fa-lock me-1"></i>
        {{ $t('content.tags.system_note', systemTags.length) }}
      </p>

      <!-- Mounted outside the list, once: a Bootstrap modal inside the element
           that opened it is a modal inside a stacking context it does not own. -->
      <TagRemovalDialog
        v-if="editing"
        :key="editing.id"
        :tag="editing"
        :others="mergeTargets"
        @done="loadTags"
        @close="editingTag = null"
      />
      <div class="mt-4" id="blocked-tags">
      <h4 class="mm-role-title">{{ $t('content.retired.title') }}</h4>
      <p class="mm-note">{{ $t('content.retired.intro') }}</p>

      <div v-if="retired.length">
        <span v-for="r in retired" :key="r.name" class="mm-tag mm-tag-retired mm-tag-editable me-1 mb-1">
          {{ r.name }}<template v-if="r.merged_into"> → {{ r.merged_into }}</template>
          <button type="button" class="mm-tag-x" :disabled="restoring === r.name"
                  :aria-label="$t('content.retired.restore_aria', { tag: r.name })"
                  @click="restoringTag = r.name">×</button>
        </span>
      </div>
      <p class="mm-note mb-0" v-else-if="tagsLoaded && !tagsFailed">
        {{ $t('content.retired.empty') }}
      </p>

      <ConfirmDialog v-if="restoringTag" :title="$t('content.retired.confirm_title')"
                     :confirm-label="$t('content.retired.confirm_label', { tag: restoringTag })"
                     :busy="restoring !== null"
                     @confirm="restoreTag" @close="restoringTag = null">
        <i18n-t keypath="content.retired.confirm_body" scope="global">
          <template #tag><strong>{{ restoringTag }}</strong></template>
        </i18n-t>
      </ConfirmDialog>
      </div>
    </div>


    <DeletedNotesBlock />
  </section>
</template>
