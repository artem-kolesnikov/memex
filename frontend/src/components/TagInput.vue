<!-- Tag entry as a token field: added tags are pills with an × ; the caret sits
     in the same box, and typing filters the existing vocabulary into a
     suggestion list. Enter (or click) takes the highlighted suggestion; Enter on
     text that matches nothing creates that tag. Backspace on an empty input
     removes the last pill.

     Replaces a PrimeVue MultiSelect, which needed a separate "new tag" field
     for the create case and a dialog-ish panel for the pick case. -->
<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSystemTags } from '@/lib/systemTags'

const { t } = useI18n()
const { isSystemTag, systemReason } = useSystemTags()

const props = withDefaults(
  defineProps<{
    modelValue: string[]
    /** Existing tag names, for suggestions. */
    options: string[]
    disabled?: boolean
    placeholder?: string
    /** false = typing a name nothing matches does nothing (filter mode). */
    allowNew?: boolean
  }>(),
  { disabled: false, placeholder: undefined, allowNew: true },
)
const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>()

const placeholderText = computed(() => props.placeholder ?? t('editor.tag_input.placeholder'))

const input = ref<HTMLInputElement | null>(null)
const draft = ref('')
const open = ref(false)
const active = ref(0)

const suggestions = computed(() => {
  const query = draft.value.trim().toLowerCase()
  const chosen = new Set(props.modelValue.map((t) => t.toLowerCase()))
  return props.options
    .filter((name) => !chosen.has(name.toLowerCase()) && name.toLowerCase().includes(query))
    .sort((a, b) => {
      const aStarts = a.toLowerCase().startsWith(query) ? 0 : 1
      const bStarts = b.toLowerCase().startsWith(query) ? 0 : 1
      // A system tag leads its group: the list is cut to eight, and the two
      // words memex itself reads must not be the ones that fall off.
      const aSystem = isSystemTag(a) ? 0 : 1
      const bSystem = isSystemTag(b) ? 0 : 1
      return aStarts - bStarts || aSystem - bSystem || a.localeCompare(b)
    })
    .slice(0, 8)
})

/** Typed text that is not an existing tag — offered as "create". */
const isNewTag = computed(() => {
  const value = draft.value.trim().toLowerCase()
  if (!props.allowNew || value === '') return false
  return !props.options.some((name) => name.toLowerCase() === value)
    && !props.modelValue.some((name) => name.toLowerCase() === value)
})

function add(name: string) {
  const value = name.trim()
  if (value === '') return
  if (!props.modelValue.some((t) => t.toLowerCase() === value.toLowerCase())) {
    emit('update:modelValue', [...props.modelValue, value])
  }
  draft.value = ''
  active.value = 0
  // Stay open: adding one tag usually means adding the next.
  nextTick(() => input.value?.focus())
}

function remove(name: string) {
  emit('update:modelValue', props.modelValue.filter((t) => t !== name))
}

function onEnter() {
  const chosen = suggestions.value[active.value]
  if (chosen && !(isNewTag.value && active.value === suggestions.value.length)) {
    add(chosen)
  } else if (isNewTag.value) {
    // New tags are lower-cased: the vocabulary is case-insensitive, and
    // "Infra"/"infra" as two tags is the classic way to rot a taxonomy.
    add(draft.value.trim().toLowerCase())
  }
}

function onBackspace() {
  if (draft.value !== '' || props.modelValue.length === 0) return
  emit('update:modelValue', props.modelValue.slice(0, -1))
}

function move(delta: number) {
  const count = suggestions.value.length + (isNewTag.value ? 1 : 0)
  if (count === 0) return
  active.value = (active.value + delta + count) % count
}

function onBlur() {
  // Suggestion mousedown is prevented, so a click never blurs; a real blur
  // (clicking elsewhere) dismisses.
  window.setTimeout(() => (open.value = false), 120)
}
</script>

<template>
  <div class="mm-taginput" :class="{ 'mm-taginput-disabled': disabled }" @click="input?.focus()">
    <div class="mm-taginput-field">
      <!-- Solid indigo for `skill` / `live-state`, with the reason on hover:
           adding one of those to a note changes what a connected assistant is
           served, and the moment to notice is while you are typing it. -->
      <span v-for="tag in modelValue" :key="tag" class="mm-tag-pill"
            :class="{ 'mm-tag-system': isSystemTag(tag) }"
            :title="systemReason(tag) ?? undefined">
        {{ tag }}
        <button type="button"
                class="mm-tag-pill-x"
                :disabled="disabled"
                :aria-label="$t('editor.tag_input.remove', { tag })"
                @click.stop="remove(tag)">×</button>
      </span>
      <input ref="input"
             type="text"
             class="mm-taginput-input"
             v-model="draft"
             :disabled="disabled"
             :placeholder="modelValue.length ? '' : placeholderText"
             role="combobox"
             aria-autocomplete="list"
             :aria-expanded="open"
             @focus="open = true"
             @input="open = true; active = 0"
             @blur="onBlur"
             @keydown.enter.prevent="onEnter"
             @keydown.down.prevent="move(1)"
             @keydown.up.prevent="move(-1)"
             @keydown.esc.prevent="open = false"
             @keydown.delete="onBackspace">
    </div>

    <div class="mm-taginput-menu" v-if="open && (suggestions.length || isNewTag)" role="listbox">
      <div v-for="(name, i) in suggestions"
           :key="name"
           class="mm-taginput-item"
           :class="{ active: i === active }"
           role="option"
           :aria-selected="i === active"
           @mousedown.prevent="add(name)"
           @mousemove="active = i">
        {{ name }}
      </div>
      <div v-if="isNewTag"
           class="mm-taginput-item mm-taginput-new"
           :class="{ active: active === suggestions.length }"
           role="option"
           :aria-selected="active === suggestions.length"
           @mousedown.prevent="add(draft.trim().toLowerCase())"
           @mousemove="active = suggestions.length">
        <i class="fa-solid fa-plus me-2"></i>{{ $t('editor.tag_input.create', { name: draft.trim().toLowerCase() }) }}
      </div>
    </div>
  </div>
</template>
