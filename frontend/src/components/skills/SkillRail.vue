<script setup lang="ts">
import { computed, ref } from 'vue'
import type { SkillConnection, SkillRow } from '@/api/client'
import { copyText } from '@/lib/clipboard'
import SkillConnections from './SkillConnections.vue'
import SkillUseExample from './SkillUseExample.vue'
import { skillOffered } from './skillGroups'
import { skillDraft, useSkillEditing } from './skillEditing'

// What a skill adds to a note, in one box in the rail beside it: who can use
// it, its alias to copy and how to ask for it; then what is wrong with it. How
// often it was loaded is among the details, and the alias is changed in the
// editor. memex's own skills and the templates show the same, with nothing to
// change.
const props = defineProps<{ skill: SkillRow; connections: SkillConnection[]; busy?: boolean }>()
const emit = defineEmits<{ updated: [row: SkillRow] }>()

const editable = computed(() => props.skill.kind === 'note')
const draft = skillDraft(props.skill)
const { error, saving: editing, patch } = useSkillEditing(() => props.skill, (row) => emit('updated', row))
const locked = computed(() => props.busy || editing.value || props.skill.status === 'pending')
const copied = ref(false)
async function copySlug() {
  copied.value = await copyText(props.skill.slug)
}
</script>

<template>
  <p v-if="error" class="alert alert-warning py-1 mm-context-skill" role="alert">{{ error }}</p>

  <section class="app-panel mm-context-panel mm-context-skill mm-skill-box">
    <div v-if="skill.kind !== 'catalogue'" class="mm-skill-box-part">
      <SkillConnections v-if="editable" :blocked="skill.blocked" :connections="connections" :draft="draft"
                        :disabled="locked" @save="patch({ blocked: $event })" />
      <template v-else>
        <h2 class="mm-context-title">{{ $t('skills.panel.connections') }}</h2>
        <p class="mm-skill-aside">{{ $t('skills.panel.origin_shipped') }}</p>
      </template>
    </div>

    <div class="mm-skill-box-part">
      <h2 class="mm-context-title">{{ $t('skills.panel.slug') }}</h2>
      <p class="mm-skill-aside">{{ $t('skills.panel.slug_help') }}</p>
      <div class="mm-skill-alias">
        <code>{{ skill.slug }}</code>
        <button type="button" class="btn btn-sm btn-link mm-skill-alias-copy" :title="copied ? $t('skills.example.copied') : $t('skills.panel.copy_alias')"
                :aria-label="copied ? $t('skills.example.copied') : $t('skills.panel.copy_alias')" @click="copySlug" @mouseleave="copied = false">
          <i :class="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <div class="mm-skill-box-part">
      <h2 class="mm-context-title">{{ $t('skills.panel.how_to_use') }}</h2>
      <SkillUseExample :skill="skill" compact :can-copy="skillOffered(skill)" />
    </div>
  </section>

  <section v-if="skill.lint.length" class="app-panel mm-context-panel mm-context-skill">
    <h2 class="mm-context-title">{{ $t('skills.panel.findings') }}</h2>
    <ul class="mm-skill-usage"><li v-for="f in skill.lint" :key="f.code">{{ f.message }}</li></ul>
  </section>
</template>
