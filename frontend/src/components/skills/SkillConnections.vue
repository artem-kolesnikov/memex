<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { SkillConnection } from '@/api/client'

const props = defineProps<{ grants: number[]; connections: SkillConnection[]; disabled: boolean }>()
const emit = defineEmits<{ save: [grants: number[]] }>()
const all = ref(true)
const selected = ref<number[]>([])
watch(() => props.grants, (grants) => {
  all.value = grants.length === 0
  selected.value = [...grants]
}, { immediate: true })
const changed = computed(() => all.value !== (props.grants.length === 0) || (!all.value && [...selected.value].sort().join() !== [...props.grants].sort().join()))
function choose(id: number, checked: boolean) {
  if (all.value) selected.value = props.connections.map(c => c.id)
  all.value = false
  selected.value = checked ? [...new Set([...selected.value, id])] : selected.value.filter(value => value !== id)
}
</script>

<template>
  <fieldset class="skill-connections" :disabled="disabled">
    <legend class="connection-heading">{{ $t('skills.panel.connections') }}</legend>
    <label class="connection-choice connection-all">
      <input type="checkbox" class="form-check-input" v-model="all" :aria-label="$t('skills.panel.all_connections')" />
      <span class="connection-all-text"><span>{{ $t('skills.panel.all_connections') }}</span><span class="text-muted">{{ $t('skills.panel.future_connections') }}</span></span>
    </label>
    <div class="connection-options">
    <label v-for="c in connections" :key="c.id" class="connection-choice connection-option" :class="{ 'is-selected': all || selected.includes(c.id) }">
      <input type="checkbox" class="form-check-input" :checked="all || selected.includes(c.id)"
             @change="choose(c.id, ($event.target as HTMLInputElement).checked)" />
      <span>{{ c.display_name || c.label }}</span>
    </label>
    </div>
    <p v-if="!all && !selected.length" class="small text-muted my-2">{{ $t('skills.panel.choose_connection') }}</p>
    <div class="connection-save-row">
    <span class="connection-feedback" role="status">{{ changed ? $t('skills.panel.unsaved') : '' }}</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="disabled || !changed || (!all && !selected.length)"
            @click="emit('save', all ? [] : [...selected])">{{ $t('skills.panel.save_connections') }}</button>
    </div>
  </fieldset>
</template>

<style scoped>
.skill-connections { min-width: 0; }
.connection-heading { font-size: .875rem; font-weight: 600; margin-bottom: .65rem; }
.connection-choice { display: flex; align-items: start; gap: .6rem; font-size: .8rem; overflow-wrap: anywhere; cursor: pointer; }
.connection-choice .form-check-input { flex-shrink: 0; }
.connection-all { padding: .1rem 0 .65rem; }
.connection-all-text { display: flex; align-items: baseline; flex-wrap: wrap; gap: .25rem .5rem; }
.connection-all-text .text-muted { font-size: .75rem; }
.connection-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
.connection-option { border: 1px solid var(--mm-line); border-radius: .5rem; padding: .65rem .75rem; background: var(--mm-surface); }
.connection-option.is-selected { border-color: color-mix(in srgb, var(--mm-primary) 35%, var(--mm-line)); background: color-mix(in srgb, var(--mm-primary) 4%, var(--mm-surface)); }
.connection-save-row { display: flex; justify-content: space-between; align-items: center; gap: .5rem; min-height: 1.9rem; margin-top: .75rem; }
.connection-feedback { font-size: .75rem; color: var(--mm-muted); }
.connection-save-row .btn { flex-shrink: 0; }
</style>
