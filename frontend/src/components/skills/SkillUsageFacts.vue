<script setup lang="ts">
import { computed } from 'vue'
import type { SkillConnection, SkillRow } from '@/api/client'
import AgentMark from '@/components/AgentMark.vue'
import { formatDateTime } from '@/lib/datetime'

// How a skill has been used, as lines of its details: how often in the last 30
// days, when last, and by which connections.
const props = defineProps<{ skill: SkillRow; connections: SkillConnection[] }>()
const byId = computed(() => new Map(props.connections.map((c) => [c.id, c])))
const connectionName = (id: number) => {
  const c = byId.value.get(id)
  return c ? c.display_name || c.label : String(id)
}
</script>

<template>
  <dt>{{ $t('skills.details.loaded') }}</dt>
  <dd>{{ $t('skills.cards.loads', { count: skill.usage.total_30d }, skill.usage.total_30d) }}</dd>
  <dt>{{ $t('skills.details.last_used') }}</dt>
  <dd>{{ skill.usage.last_at ? formatDateTime(skill.usage.last_at) : $t('skills.details.never') }}</dd>
  <template v-if="skill.usage.by_token.length">
    <dt>{{ $t('skills.details.used_by') }}</dt>
    <dd>
      <ul class="mm-skill-usage">
        <li v-for="u in skill.usage.by_token" :key="u.token_id" :title="formatDateTime(u.last_at)">
          <AgentMark v-if="byId.get(u.token_id)" :icon="byId.get(u.token_id)!.icon" :icon-url="byId.get(u.token_id)!.icon_url" :icon-url-dark="byId.get(u.token_id)!.icon_url_dark" />
          <span>{{ $t('skills.details.used_by_line', { name: connectionName(u.token_id), count: u.count }) }}</span>
        </li>
      </ul>
    </dd>
  </template>
</template>
