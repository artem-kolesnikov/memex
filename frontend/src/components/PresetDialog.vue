<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, ApiError, type SearchPreset, type SearchPresetInput, type TagRef } from '@/api/client'
import TagInput from '@/components/TagInput.vue'
import { toastError } from '@/components/toastService'
import { useBootstrapModal } from '@/lib/bootstrapModal'
import { usePresetStore } from '@/stores/presets'

const props = defineProps<{
  preset: SearchPreset | null
  initial?: Omit<SearchPresetInput, 'name' | 'icon'>
}>()
const emit = defineEmits<{ close: []; saved: [preset: SearchPreset] }>()

const { t } = useI18n()
const store = usePresetStore()

const dialog = ref<HTMLElement | null>(null)
const nameField = ref<HTMLInputElement | null>(null)
const { hide } = useBootstrapModal(dialog, {
  onHidden: () => emit('close'),
  onShown: () => nameField.value?.focus(),
})

const name = ref(props.preset?.name ?? '')
const icon = ref(props.preset?.icon ?? 'filter')
const query = ref(props.preset?.q ?? props.initial?.q ?? '')
const status = ref(props.preset?.status ?? props.initial?.status ?? '')
const addedBy = ref<number | null>(props.preset?.added_by ?? props.initial?.added_by ?? null)
const addedByName = ref('')

// The tag control works in names, as the note editor's does; ids are what the
// server stores, so the vocabulary translates both ways.
const allTags = ref<TagRef[]>([])
const vocabularyReady = ref(false)
const tagNames = ref<string[]>(props.preset ? props.preset.tags.map((tag) => tag.name) : [])
const existingTagNames = computed(() => allTags.value.map((tag) => tag.name).sort((a, b) => a.localeCompare(b)))
// The preset's own tags resolve by their stored id as well, so a name the
// vocabulary no longer lists still keeps its id rather than dropping out.
const tagIds = computed(() =>
  tagNames.value
    .map((wanted) => [...allTags.value, ...(props.preset?.tags ?? [])]
      .find((tag) => tag.name.toLowerCase() === wanted.toLowerCase())?.id)
    .filter((id): id is number => typeof id === 'number'),
)

const chosenIcon = computed(() => store.icons.find((choice) => choice.key === icon.value))
const iconGroups = computed(() => {
  const groups = new Map<string, typeof store.icons>()
  for (const choice of store.icons) {
    const list = groups.get(choice.group) ?? []
    list.push(choice)
    groups.set(choice.group, list)
  }
  return [...groups.entries()].map(([group, choices]) => ({ group, choices }))
})

const statusOptions = ['', 'verified', 'pending', 'flagged', 'undescribed'].map((value) => ({
  value,
  labelKey: 'search.status.' + (value || 'any'),
}))

const busy = ref(false)
const error = ref('')
const hasCriterion = computed(
  () => query.value.trim() !== '' || tagIds.value.length > 0 || status.value !== '' || addedBy.value !== null,
)

onMounted(async () => {
  if (store.icons.length === 0) void store.load()
  try {
    allTags.value = (await api.tags()).tags
    vocabularyReady.value = true
  } catch {
    error.value = t('presets.dialog.tags_failed')
    return
  }
  if (props.preset === null && props.initial) {
    tagNames.value = props.initial.tags
      .map((id) => allTags.value.find((tag) => tag.id === id)?.name)
      .filter((tagName): tagName is string => typeof tagName === 'string')
  }
  if (addedBy.value !== null) {
    try {
      const found = (await api.tokens()).tokens.find((token) => token.id === addedBy.value)
      addedByName.value = found?.display_name ?? found?.label ?? ''
    } catch {
      addedByName.value = ''
    }
  }
})

async function save() {
  if (busy.value) return
  error.value = ''
  if (name.value.trim() === '') {
    error.value = t('presets.dialog.name_required')
    nameField.value?.focus()
    return
  }
  if (!hasCriterion.value) {
    error.value = t('presets.dialog.no_criteria')
    return
  }
  const input: SearchPresetInput = {
    name: name.value.trim(),
    icon: icon.value,
    q: query.value.trim(),
    tags: tagIds.value,
    status: status.value,
    added_by: addedBy.value,
  }
  busy.value = true
  try {
    const saved = props.preset === null ? await store.create(input) : await store.update(props.preset.id, input)
    emit('saved', saved)
    hide()
  } catch (e) {
    if (e instanceof ApiError && (e.status === 400 || e.status === 409)) {
      error.value = e.message
    } else {
      toastError(t('presets.dialog.save_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div ref="dialog" class="modal fade app-dialog" tabindex="-1" aria-labelledby="preset-dialog-title" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" @submit.prevent="save">
          <div class="modal-header">
            <h3 id="preset-dialog-title" class="modal-title h5">
              {{ preset === null ? $t('presets.dialog.create_title') : $t('presets.dialog.edit_title') }}
            </h3>
            <button type="button" class="btn-close" @click="hide" :aria-label="$t('common.cancel')"></button>
          </div>
          <div class="modal-body mm-preset-form">
            <label class="form-label" for="preset-name">{{ $t('presets.dialog.name') }}</label>
            <div class="mm-preset-name-row">
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle mm-icon-box" type="button"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                        :disabled="busy" :aria-label="$t('presets.dialog.choose_icon')">
                  <i :class="chosenIcon?.icon ?? 'fa-solid fa-filter'"></i>
                </button>
                <div class="dropdown-menu p-2 mm-icon-menu">
                  <div class="mm-icon-picker mm-preset-icons">
                    <template v-for="section in iconGroups" :key="section.group">
                      <span class="mm-preset-icons-group">{{ section.group }}</span>
                      <button v-for="choice in section.choices" :key="choice.key" type="button" class="mm-icon-choice"
                              :class="{ 'is-chosen': icon === choice.key }" :title="choice.label"
                              :aria-label="choice.label" @click="icon = choice.key">
                        <i :class="choice.icon"></i>
                      </button>
                    </template>
                  </div>
                </div>
              </div>
              <input id="preset-name" ref="nameField" v-model="name" type="text" class="form-control" maxlength="60" required>
            </div>

            <label class="form-label" for="preset-terms">{{ $t('presets.dialog.terms') }}</label>
            <input id="preset-terms" v-model="query" type="text" class="form-control" maxlength="500"
                   :placeholder="$t('search.placeholder')">

            <span class="form-label d-block">{{ $t('presets.dialog.tags') }}</span>
            <TagInput v-model="tagNames" :options="existingTagNames" :allow-new="false" :disabled="busy"
                      :placeholder="$t('presets.dialog.tags_placeholder')" />

            <label class="form-label" for="preset-status">{{ $t('presets.dialog.status') }}</label>
            <select id="preset-status" v-model="status" class="form-select">
              <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ $t(opt.labelKey) }}</option>
            </select>

            <div class="mm-preset-added-by" v-if="addedBy !== null">
              <span class="badge mm-chip">
                {{ $t('search.chip.added_by', { name: addedByName || $t('search.chip.connection_fallback', { id: addedBy }) }) }}
                <a href="javascript:void(0)" class="mm-chip-x" :aria-label="$t('common.remove')" @click="addedBy = null">×</a>
              </span>
            </div>

            <p class="text-danger small mb-0 mt-3" v-if="error" role="alert">{{ error }}</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="hide">{{ $t('common.cancel') }}</button>
            <button type="submit" class="btn btn-primary" :disabled="busy || !vocabularyReady">
              <span v-if="busy" class="spinner-border spinner-border-sm me-1" role="status"></span>
              {{ $t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>
