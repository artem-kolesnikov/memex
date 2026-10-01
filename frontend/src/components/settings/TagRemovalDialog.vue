<!--
  Removing a tag from a vocabulary, or moving it into another one.

  ## Why this is a dialog and not an (x)

  The operator asked for a bare (x) beside each tag and asked, in the same
  message, whether that was a mistake. It half was.

  Removing a tag is a BULK EDIT of every note carrying it, and it is the one
  irreversible one in memex: a deleted note retires into limbo and comes back,
  an assistant's edit waits in the inbox, an import shows you what it will do
  first. Deleting `inbox` from ninety notes is undone by re-tagging ninety
  notes. So the number is said out loud before the click that does it, which
  is all a confirm is for — and the number is the confirm, not a "are you
  sure".

  ## Why merge is here

  Because it is what tag cleanup usually IS. Nobody wants to fix "project" and
  "projects" by deleting one and re-tagging forty notes by hand, and a control
  that only removes turns the common case into an afternoon. One dialog, one
  question — what happens to the notes — and two answers.

  There is no (x) on a tag with no notes: a tag exists for exactly as long as
  some note carries it (NoteWriter::gcTags), so every row here is a real edit.
-->
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type TagRef } from '@/api/client'
import { usePresetStore } from '@/stores/presets'
import { toastError, toastSuccess } from '@/components/toastService'

const props = defineProps<{
  tag: TagRef & { note_count: number }
  /** Every other tag, as merge targets. */
  others: (TagRef & { note_count: number })[]
}>()
const emit = defineEmits<{ done: []; close: [] }>()
const { t } = useI18n()
const presets = usePresetStore()

const mode = ref<'remove' | 'merge'>('remove')
const target = ref<number | null>(null)
const busy = ref(false)
const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

const targetName = computed(() => props.others.find((t) => t.id === target.value)?.name ?? '')
const blocked = computed(() => mode.value === 'merge' && target.value === null)

onMounted(() => {
  if (dialog.value === null) return
  modal = new window.bootstrap.Modal(dialog.value)
  // The one exit: Bootstrap fires this for the close button, Escape and a
  // click on the backdrop alike, so the parent is told once however it ended.
  dialog.value.addEventListener('hidden.bs.modal', () => emit('close'))
  modal.show()
})
// `hide()` alone is not a teardown. Bootstrap returns from it immediately
// while the dialog is still transitioning, so unmounting mid-fade leaves its
// deferred show callback to put the element back on <body> — a dialog with no
// component behind it, over a backdrop and a scroll lock nothing will clear.
// `dispose()` drops the instance, its backdrop and its handlers either way.
onBeforeUnmount(() => {
  modal?.hide()
  modal?.dispose()
  modal = null
})

/** Close without doing anything. Bootstrap's `hidden` event tells the parent. */
function cancel() {
  modal?.hide()
}

async function confirm() {
  if (blocked.value) return
  busy.value = true
  try {
    const result = await api.removeTag(
      props.tag.id,
      mode.value === 'merge' ? (target.value ?? undefined) : undefined,
    )
    toastSuccess(
      mode.value === 'merge'
        ? t('content.tag_removal.merged', { removed: result.removed, into: result.merged_into })
        : t('content.tag_removal.removed', { removed: result.removed }),
      t('content.tag_removal.notes_changed', result.notes_changed),
    )
    // Saved filters follow the tag on the server; the sidebar holds its own copy.
    void presets.load()
    emit('done')
    modal?.hide()
  } catch (e) {
    toastError(t('content.tag_removal.nothing_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="tag-removal-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title h6 mb-0" id="tag-removal-title">
              {{ $t('content.tag_removal.header', { tag: tag.name }, tag.note_count) }}
            </h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="cancel"></button>
          </div>

          <div class="modal-body">
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" id="tag-mode-remove" value="remove"
                     v-model="mode" :disabled="busy">
              <label class="form-check-label" for="tag-mode-remove">
                {{ $t('content.tag_removal.remove_option', tag.note_count) }}
              </label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" id="tag-mode-merge" value="merge"
                     v-model="mode" :disabled="busy || !others.length">
              <label class="form-check-label" for="tag-mode-merge">{{ $t('content.tag_removal.merge_option') }}</label>
            </div>

            <select class="form-select mb-3" v-model="target" v-if="mode === 'merge'"
                    :disabled="busy" :aria-label="$t('content.tag_removal.target_aria')">
              <option :value="null">{{ $t('content.tag_removal.choose_tag') }}</option>
              <option v-for="o in others" :key="o.id" :value="o.id">
                {{ o.name }} ({{ o.note_count }})
              </option>
            </select>

            <p class="mm-note mb-0">
              <template v-if="mode === 'merge' && targetName">
                {{ $t('content.tag_removal.merge_preview', { target: targetName, tag: tag.name }) }}
              </template>
              <template v-else-if="mode === 'merge'">
                {{ $t('content.tag_removal.merge_hint') }}
              </template>
              <template v-else>
                {{ $t('content.tag_removal.remove_hint') }}
              </template>
            </p>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="busy"
                    @click="cancel">{{ $t('common.cancel') }}</button>
            <button type="button" class="btn btn-danger btn-sm" :disabled="busy || blocked"
                    @click="confirm">
              {{ mode === 'merge' ? $t('content.tag_removal.merge') : $t('content.tag_removal.remove_from', { n: tag.note_count }) }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
