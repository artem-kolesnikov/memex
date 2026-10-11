<script setup lang="ts">
import { computed, toRef, watch } from 'vue'
import type { SkillConnection } from '@/api/client'
import type { SkillDraft } from './skillEditing'

// Every connection may use a skill, one added later included, until it is
// unticked here.
const props = defineProps<{ blocked: number[]; connections: SkillConnection[]; disabled: boolean; draft: SkillDraft }>()
const emit = defineEmits<{ save: [blocked: number[]] }>()
const unticked = toRef(props.draft, 'blocked')
// What was saved replaces the draft; the draft itself outlives this component.
watch(() => props.blocked, (blocked) => {
  unticked.value = [...blocked]
})
const changed = computed(() => [...unticked.value].sort().join() !== [...props.blocked].sort().join())
function choose(id: number, checked: boolean) {
  unticked.value = checked ? unticked.value.filter(value => value !== id) : [...new Set([...unticked.value, id])]
}
</script>

<template>
  <fieldset class="skill-connections" :disabled="disabled">
    <legend class="connection-heading">{{ $t('skills.panel.connections') }}</legend>
    <div class="connection-options">
    <label v-for="c in connections" :key="c.id" class="connection-choice connection-option" :class="{ 'is-selected': !unticked.includes(c.id) }">
      <input type="checkbox" class="form-check-input" :checked="!unticked.includes(c.id)"
             @change="choose(c.id, ($event.target as HTMLInputElement).checked)" />
      <span>{{ c.display_name || c.label }}</span>
    </label>
    </div>
    <div class="connection-save-row">
    <span class="connection-feedback" role="status">{{ changed ? $t('skills.panel.unsaved') : '' }}</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="disabled || !changed"
            @click="emit('save', [...unticked])">{{ $t('skills.panel.save_connections') }}</button>
    </div>
  </fieldset>
</template>

<style scoped>
.skill-connections { min-width: 0; }
.connection-heading { font-size: .875rem; font-weight: 600; margin-bottom: .65rem; }
.connection-choice { display: flex; align-items: start; gap: .6rem; font-size: .8rem; overflow-wrap: anywhere; cursor: pointer; }
.connection-choice .form-check-input { flex-shrink: 0; }
.connection-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
.connection-option { border: 1px solid var(--mm-line); border-radius: .5rem; padding: .65rem .75rem; background: var(--mm-surface); }
.connection-option.is-selected { border-color: color-mix(in srgb, var(--mm-primary) 35%, var(--mm-line)); background: color-mix(in srgb, var(--mm-primary) 4%, var(--mm-surface)); }
.connection-save-row { display: flex; justify-content: space-between; align-items: center; gap: .5rem; min-height: 1.9rem; margin-top: .75rem; }
.connection-feedback { font-size: .75rem; color: var(--mm-muted); }
.connection-save-row .btn { flex-shrink: 0; }
</style>
