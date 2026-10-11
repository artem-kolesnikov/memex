<script setup lang="ts">
import { computed, ref } from 'vue'
import type { SkillRow } from '@/api/client'
import { formatDate } from '@/lib/datetime'
import { useLayoutStore } from '@/stores/layout'
import { skillKey, skillOffered } from './skillGroups'

// The skills list in every layout: the owner's skills or memex's, never mixed,
// as one list of the notes list's rows, the last used first, each marked
// enabled or disabled at its name. What a skill is and everything that changes
// it, its switch included, opens from its row. In a window the rows sit on a
// tray under a head, the last use in a column beside them, as notes do.
const props = defineProps<{ rows: SkillRow[]; openKey: string | null }>()
const emit = defineEmits<{ open: [row: SkillRow] }>()
const layout = useLayoutStore()

const kind = ref<'user' | 'system'>('user')
const listed = computed(() => props.rows
  .filter((r) => (kind.value === 'system') === (r.kind === 'shipped'))
  .sort((a, b) => (b.usage.last_at ?? '').localeCompare(a.usage.last_at ?? '') || a.title.localeCompare(b.title)))
const on = computed(() => listed.value.filter(skillOffered))
const size = computed(() => on.value.reduce((sum, row) => sum + row.size_tokens, 0))
const compactNumber = (value: number) => new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value)
const number = (value: number) => new Intl.NumberFormat().format(value)
</script>

<template>
  <div class="mm-skills-list" :class="{ 'is-windowed': !layout.framed }">
    <div class="mm-skills-summary">
      <select class="form-select" v-model="kind" :aria-label="$t('skills.list.kind')">
        <option value="user">{{ $t('skills.list.user') }}</option>
        <option value="system">{{ $t('skills.list.system') }}</option>
      </select>
      <p class="mm-skills-summary-line">
        <strong data-testid="skills-active-count">{{ number(on.length) }}</strong> {{ $t('skills.dashboard.title') }}
        <span aria-hidden="true">·</span>
        <span :title="$t('skills.cards.tokens', { count: number(size) })">{{ $t('skills.list.size_tk', { size: compactNumber(size) }) }}</span>
      </p>
    </div>

    <div class="app-note-scroll">
      <p v-if="!listed.length" class="app-state" role="status">{{ $t('frames.skills_empty') }}</p>
      <div v-else class="app-note-list" :class="{ 'app-tray': !layout.framed }" :data-skill-kind="kind">
        <div v-if="!layout.framed" class="app-note-list-head">
          <span class="app-note-list-title">{{ $t('skills.col.skill') }}</span>
          <span class="app-note-list-modified">{{ $t('skills.list.last_used_column') }}</span>
        </div>
        <article v-for="row in listed" :key="skillKey(row)" class="app-note-row is-plain mm-skill-row"
                 :class="{ 'is-open': openKey === skillKey(row), 'is-off': !skillOffered(row) }" :data-skill="row.slug">
          <div class="mm-skill-row-head">
            <button type="button" class="app-note-main" :title="row.title" :aria-pressed="openKey === skillKey(row)" @click="emit('open', row)">
              <span class="app-note-title">{{ row.title }}</span>
            </button>
            <span class="app-status mm-skill-state" :class="skillOffered(row) ? 'app-status-verified' : 'app-status-danger'">{{ skillOffered(row) ? $t('skills.list.enabled') : $t('skills.list.disabled') }}</span>
          </div>
          <span class="app-note-summary" v-if="row.short || row.description">{{ row.short || row.description }}</span>
          <div class="app-note-meta">
            <span class="app-note-date" v-if="!row.usage.last_at">{{ $t('skills.list.never_used') }}</span>
            <span class="app-note-date" v-else-if="layout.framed">{{ $t('skills.list.last_used', { when: formatDate(row.usage.last_at) }) }}</span>
            <span class="app-note-date" v-else>{{ formatDate(row.usage.last_at) }}</span>
            <span>{{ $t('skills.cards.tokens', { count: number(row.size_tokens) }) }}</span>
          </div>
        </article>
      </div>
    </div>
  </div>
</template>
