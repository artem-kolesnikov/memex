<script setup lang="ts">
import { computed } from 'vue'
import type { SkillConnection, SkillRow } from '@/api/client'
import AgentMark from '@/components/AgentMark.vue'
import { formatDateTime } from '@/lib/datetime'
import SkillConnections from './SkillConnections.vue'
import SkillUseExample from './SkillUseExample.vue'
import { skillOffered } from './skillGroups'
import { skillDraft, useSkillEditing } from './skillEditing'

// What a skill adds to a note, in the rail beside it: who can use it, its
// alias, how to ask for it, how often it was loaded and what is wrong with it.
// memex's own skills and the templates show the same, with nothing to change.
const props = defineProps<{ skill: SkillRow; connections: SkillConnection[]; busy?: boolean }>()
const emit = defineEmits<{ updated: [row: SkillRow] }>()

const editable = computed(() => props.skill.kind === 'note')
const draft = skillDraft(props.skill)
const { slugDraft, error, saving: editing, patch, rename } = useSkillEditing(() => props.skill, draft, (row) => emit('updated', row))
const locked = computed(() => props.busy || editing.value || props.skill.status === 'pending')
const byId = computed(() => new Map(props.connections.map((c) => [c.id, c])))

function connectionName(id: number): string {
  const c = byId.value.get(id)
  return c ? c.display_name || c.label : String(id)
}
</script>

<template>
  <p v-if="error" class="alert alert-warning py-1 mm-context-skill" role="alert">{{ error }}</p>

  <section v-if="skill.kind !== 'catalogue'" class="app-panel mm-context-panel mm-context-skill mm-skill-access">
    <SkillConnections v-if="editable" :blocked="skill.blocked" :connections="connections" :draft="draft"
                      :disabled="locked" @save="patch({ blocked: $event })" />
    <template v-else>
      <h2 class="mm-context-title">{{ $t('skills.panel.connections') }}</h2>
      <p class="mm-skill-aside">{{ $t('skills.panel.origin_shipped') }}</p>
    </template>
  </section>

  <section class="app-panel mm-context-panel mm-context-skill">
    <label class="mm-context-title d-block" :for="editable ? 'skill-rail-slug' : undefined">{{ $t('skills.panel.slug') }}</label>
    <p id="skill-rail-slug-help" class="mm-skill-aside">{{ $t('skills.panel.slug_help') }}</p>
    <div v-if="editable" class="mm-skill-alias">
      <input id="skill-rail-slug" class="form-control form-control-sm font-monospace" v-model="slugDraft"
             :disabled="locked" aria-describedby="skill-rail-slug-help" />
      <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="locked || slugDraft === skill.slug"
              @click="rename">{{ $t('common.save') }}</button>
    </div>
    <code v-else>{{ skill.slug }}</code>
  </section>

  <section class="app-panel mm-context-panel mm-context-skill">
    <h2 class="mm-context-title">{{ $t('skills.panel.how_to_use') }}</h2>
    <SkillUseExample :skill="skill" compact :can-copy="skillOffered(skill)" />
  </section>

  <section class="app-panel mm-context-panel mm-context-skill">
    <h2 class="mm-context-title">{{ $t('skills.panel.usage') }}</h2>
    <ul v-if="skill.usage.total_30d > 0" class="mm-skill-usage">
      <li v-for="u in skill.usage.by_token" :key="u.token_id">
        <AgentMark v-if="byId.get(u.token_id)" :icon="byId.get(u.token_id)!.icon" :icon-url="byId.get(u.token_id)!.icon_url" :icon-url-dark="byId.get(u.token_id)!.icon_url_dark" />
        <span>{{ $t('skills.panel.usage_line', { name: connectionName(u.token_id), count: u.count, when: formatDateTime(u.last_at) }, u.count) }}</span>
      </li>
    </ul>
    <p v-else class="mm-skill-aside">{{ $t('skills.panel.usage_none') }}</p>
  </section>

  <section v-if="skill.lint.length" class="app-panel mm-context-panel mm-context-skill">
    <h2 class="mm-context-title">{{ $t('skills.panel.findings') }}</h2>
    <ul class="mm-skill-usage"><li v-for="f in skill.lint" :key="f.code">{{ f.message }}</li></ul>
  </section>
</template>
