<script setup lang="ts">
import { computed, ref } from 'vue'
import type { SkillRow } from '@/api/client'
import { formatDate } from '@/lib/datetime'
import { skillKey, skillOffered } from './skillGroups'

// The skills in frames: the owner's skills or memex's, never mixed, as one
// list of the notes list's rows, the last used first, each marked enabled or
// disabled at its name. What a skill is and everything that changes it, its
// switch included, open beside the list.
const props = defineProps<{ rows: SkillRow[]; openKey: string | null }>()
const emit = defineEmits<{ open: [row: SkillRow] }>()

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
  <div class="mm-skills-list">
    <section class="app-panel mm-skills-summary">
      <select class="form-select form-select-sm" v-model="kind" :aria-label="$t('skills.list.kind')">
        <option value="user">{{ $t('skills.list.user') }}</option>
        <option value="system">{{ $t('skills.list.system') }}</option>
      </select>
      <p class="mm-skills-summary-line">
        <strong data-testid="skills-active-count">{{ number(on.length) }}</strong> {{ $t('skills.dashboard.title') }}
        <span aria-hidden="true">·</span>
        <span>~{{ compactNumber(size) }} {{ $t('skills.dashboard.tokens') }}</span>
      </p>
    </section>

    <div class="app-note-scroll">
      <p v-if="!listed.length" class="app-state" role="status">{{ $t('frames.skills_empty') }}</p>
      <div class="app-note-list" :data-skill-kind="kind">
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
            <span class="app-note-date">{{ row.usage.last_at ? $t('skills.list.last_used', { when: formatDate(row.usage.last_at) }) : $t('skills.list.never_used') }}</span>
            <span>{{ $t('skills.cards.tokens', { count: number(row.size_tokens) }) }}</span>
          </div>
        </article>
      </div>
    </div>
  </div>
</template>
