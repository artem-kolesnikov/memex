<script setup lang="ts">
import type { SkillRow } from '@/api/client'
import { skillOffered } from './skillGroups'

// A skill's one state, at its name: on, green, and an assistant can load it;
// off, red, and none can. memex's own skills are on for good, and a skill
// waiting for review cannot be switched on until it is approved.
const props = defineProps<{ skill: SkillRow; saving: boolean }>()
const emit = defineEmits<{ toggle: [] }>()

// The switch shows what the server holds: a press asks for the change, and the
// switch moves when the answer comes back, or stays put if it fails.
function press(event: Event) {
  const input = event.target as HTMLInputElement
  input.checked = skillOffered(props.skill)
  emit('toggle')
}
</script>

<template>
  <div class="form-check form-switch mm-skill-switch" :class="{ 'is-on': skillOffered(skill) }">
    <input type="checkbox" class="form-check-input" role="switch" :checked="skillOffered(skill)"
           :aria-label="$t('skills.cards.toggle', { title: skill.title })"
           :disabled="saving || skill.kind === 'shipped' || skill.status === 'pending'" @change="press" />
  </div>
</template>
