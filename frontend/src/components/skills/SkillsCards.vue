<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SkillRow } from '@/api/client'
import { formatDateTime } from '@/lib/datetime'
import SkillUseExample from './SkillUseExample.vue'

const props = defineProps<{ rows: SkillRow[]; savingId: number | null; saveError: { id: number; message: string } | null }>()
const emit = defineEmits<{ open: [row: SkillRow]; add: [row: SkillRow]; toggle: [row: SkillRow] }>()
const { t } = useI18n()
function offered(row: SkillRow): boolean {
  return (row.kind === 'shipped' && row.status !== 'switched_off') || (row.status === 'served' && row.enabled && (row.auto || row.command))
}
const builtIn = computed(() => props.rows.filter(r => r.kind === 'shipped' && r.status !== 'switched_off'))
const personal = computed(() => props.rows.filter(r => r.kind === 'note' && offered(r)))
const available = computed(() => [...builtIn.value, ...personal.value])
const personalColors = computed(() => new Map(props.rows.filter(r => r.kind === 'note').sort((a, b) => (a.note_id ?? 0) - (b.note_id ?? 0)).map((row, index) => [row.slug, `var(--mm-skill-${index % 6})`])))
function skillColor(row: SkillRow): string {
  return row.kind === 'shipped' ? `var(--mm-skill-built-in-${props.rows.filter(r => r.kind === 'shipped').findIndex(r => r.slug === row.slug) % 5})` : personalColors.value.get(row.slug) ?? 'var(--mm-primary)'
}
const contextSize = computed(() => available.value.reduce((sum, row) => sum + row.size_tokens, 0))
const hoveredAlias = ref<string | null>(null)
const focusedAlias = ref<string | null>(null)
const selectedAlias = ref<string | null>(null)
const segments = computed(() => available.value.map(row => ({
  ...row,
  width: contextSize.value > 0 ? row.size_tokens / contextSize.value * 100 : 0,
  color: skillColor(row),
})))
const inspected = computed(() => segments.value.find(row => row.slug === (hoveredAlias.value ?? focusedAlias.value ?? selectedAlias.value)))
const builtInSize = computed(() => builtIn.value.reduce((sum, row) => sum + row.size_tokens, 0))
function clearInspection() {
  hoveredAlias.value = null
  focusedAlias.value = null
  selectedAlias.value = null
}
const compactNumber = (value: number) => new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value)
const share = (value: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(contextSize.value > 0 ? value / contextSize.value * 100 : 0)
function stateLabel(row: SkillRow): string {
  if (row.kind === 'shipped') return t('skills.cards.always_on')
  if (row.status === 'pending') return t('skills.state.pending')
  if (row.kind === 'catalogue') return t('skills.state.offered')
  if (!row.enabled) return t('skills.state.paused')
  return offered(row) ? t('skills.cards.on') : t('skills.state.paused')
}
const groups = computed(() => [
  { kind: 'active', title: t('skills.groups.active'), help: t('skills.groups.active_help'), rows: props.rows.filter(r => r.kind === 'note' && offered(r)) },
  { kind: 'available', title: t('skills.groups.available'), help: t('skills.groups.available_help'), rows: props.rows.filter(r => r.kind === 'catalogue' || (r.kind === 'note' && !offered(r))) },
  { kind: 'system', title: t('skills.groups.system'), help: t('skills.groups.system_help'), rows: props.rows.filter(r => r.kind === 'shipped') },
])
const number = (value: number) => new Intl.NumberFormat().format(value)
</script>

<template>
  <section class="skills-dashboard app-panel" :aria-label="$t('skills.dashboard.title')" @keydown.esc="clearInspection">
    <div class="dashboard-heading">
      <div class="dashboard-overview">
        <h2><span class="active-count" data-testid="skills-active-count">{{ number(available.length) }}</span> {{ $t('skills.dashboard.title') }}</h2>
        <p>{{ $t('skills.dashboard.ready') }}</p>
      </div>
      <div class="dashboard-size">
        <span class="dashboard-size-label">{{ $t('skills.dashboard.total_size') }}</span>
        <span><strong data-testid="skills-context-size">~{{ compactNumber(contextSize) }}</strong> {{ $t('skills.dashboard.tokens') }}</span>
      </div>
    </div>

    <div class="size-chart" :aria-label="$t('skills.dashboard.breakdown')" role="group">
      <div class="size-track" data-testid="skills-size-bar">
        <button v-for="row in segments" :key="row.slug" type="button" class="size-segment"
                :class="{ 'size-segment--boundary': row.slug === personal[0]?.slug && builtIn.length, 'size-segment--inspected': inspected?.slug === row.slug }"
                :style="{ width: row.width + '%', backgroundColor: row.color }" :data-alias="row.slug" :data-kind="row.kind"
                :aria-label="row.title + ': ' + $t('skills.cards.tokens', { count: number(row.size_tokens) }) + ', ' + $t('skills.dashboard.share', { percent: share(row.size_tokens) })"
                :aria-describedby="inspected?.slug === row.slug ? 'skill-size-detail' : undefined"
                @mouseenter="hoveredAlias = row.slug" @mouseleave="hoveredAlias = null"
                @focus="focusedAlias = row.slug" @blur="focusedAlias = null" @click="selectedAlias = selectedAlias === row.slug ? null : row.slug">
        </button>
      </div>
    </div>

    <div class="size-legend">
      <div class="size-legend-group">
        <span class="legend-swatch legend-swatch--built-in" aria-hidden="true"></span>
        <span><strong>{{ $t('skills.dashboard.built_in_count', { count: builtIn.length }) }}</strong><span class="legend-description"><i class="pi pi-lock" aria-hidden="true"></i> {{ $t('skills.dashboard.built_in_help') }}</span></span>
        <span class="legend-size">~{{ compactNumber(builtInSize) }}</span>
      </div>
      <div class="size-legend-group">
        <span class="legend-swatch legend-swatch--personal" aria-hidden="true"></span>
        <span><strong>{{ $t('skills.dashboard.personal_count', { count: personal.length }) }}</strong><span class="legend-description">{{ $t('skills.dashboard.personal_help') }}</span></span>
        <span class="legend-size">~{{ compactNumber(contextSize - builtInSize) }}</span>
      </div>
    </div>

    <div class="size-caption">
      <div v-if="inspected" id="skill-size-detail" class="size-detail" role="status">
        <span class="detail-swatch" :style="{ backgroundColor: inspected.color }" aria-hidden="true"></span>
        <strong>{{ inspected.title }}</strong>
        <span>~{{ number(inspected.size_tokens) }} {{ $t('skills.dashboard.tokens') }} · {{ $t('skills.dashboard.share', { percent: share(inspected.size_tokens) }) }}</span>
      </div>
      <p v-else>{{ $t('skills.dashboard.estimate') }}</p>
    </div>
  </section>

  <template v-for="group in groups" :key="group.kind">
    <section v-if="group.rows.length" class="skill-group" :data-skill-group="group.kind">
      <h2 class="group-title">{{ group.title }} <span class="text-muted">· {{ group.rows.length }}</span></h2>
      <p class="small text-muted mt-2 mb-3">{{ group.help }}</p>
      <div v-if="group.kind === 'system'" class="system-skills-grid">
        <article v-for="row in group.rows" :key="row.slug" class="system-skill-card" :class="{ 'skill-card--dimmed': row.status === 'switched_off' }"
                 :data-system-skill="row.slug" :style="{ '--skill-color': skillColor(row) }">
          <h3>{{ row.title }}</h3>
          <p>{{ row.short || row.description }}</p>
          <p v-if="row.status === 'switched_off'" class="system-skill-off"><router-link :to="{ name: 'settings', params: { pane: 'personalization' } }">{{ $t('skills.cards.switched_off') }}</router-link></p>
          <span class="system-skill-size">~{{ number(row.size_tokens) }} {{ $t('skills.dashboard.tokens') }}</span>
        </article>
      </div>
      <div v-else class="skills-grid">
        <article v-for="row in group.rows" :key="row.note_id ?? row.slug" class="skill-card"
                 :class="{ 'skill-card--dimmed': !offered(row) }" :style="{ '--skill-color': skillColor(row) }"
                 :data-note-id="row.note_id" :data-enabled="String(row.enabled)">
          <div class="skill-card-controls">
            <span class="skill-state" :class="{ 'skill-state--on': offered(row) }"><span class="state-dot" aria-hidden="true"></span>{{ stateLabel(row) }}</span>
            <div class="form-check form-switch m-0" v-if="row.kind === 'note'">
              <input :key="saveError?.id === row.note_id ? 'failed' : 'ready'" type="checkbox" class="form-check-input" :checked="offered(row)"
                     :aria-label="$t('skills.cards.toggle', { title: row.title })" :disabled="savingId !== null || row.status === 'pending'" @change="emit('toggle', row)" />
            </div>
          </div>
          <div class="skill-card-copy">
            <h3 class="h6 mb-2"><button v-if="row.kind !== 'catalogue'" type="button" class="skill-title" @click="emit('open', row)">{{ row.title }}</button><span v-else>{{ row.title }}</span></h3>
            <p class="skill-description">{{ row.short || row.description || $t('skills.cards.no_description') }}</p>
            <p v-if="!offered(row)" class="small text-muted mb-0">{{ row.status === 'pending' ? $t('skills.cards.review_help') : row.kind === 'catalogue' ? $t('skills.cards.add_help') : $t('skills.cards.paused_help') }}</p>
          </div>
          <p v-if="saveError?.id === row.note_id" class="alert alert-warning py-1 small mb-0" role="alert">{{ saveError.message }}</p>
          <div class="card-example">
            <h4 class="small fw-semibold mb-2">{{ $t('skills.example.title') }}</h4>
            <SkillUseExample :skill="row" compact :can-copy="offered(row)" />
          </div>
          <div class="skill-card-footer">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <span class="small text-muted" :title="row.usage.last_at ? formatDateTime(row.usage.last_at) : undefined">{{ $t('skills.cards.loads', { count: row.usage.total_30d }, row.usage.total_30d) }}</span>
              <button type="button" class="btn btn-sm btn-outline-secondary" v-if="row.kind === 'catalogue'" @click="emit('add', row)">{{ $t('skills.catalogue.add') }}</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" v-else @click="emit('open', row)">{{ $t('skills.cards.details') }}</button>
            </div>
          </div>
        </article>
      </div>
    </section>
  </template>
</template>

<style scoped>
.skills-dashboard { padding: 1.4rem 1.5rem 0; }
.dashboard-heading { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.25rem; }
.dashboard-overview h2 { display: flex; align-items: baseline; gap: .5rem; margin: 0; font-size: 1rem; font-weight: 500; }
.active-count { font-size: 1.85rem; font-weight: 650; letter-spacing: -.06em; line-height: 1.1; font-variant-numeric: tabular-nums; }
.dashboard-overview p { margin: .45rem 0 0; font-size: .78rem; color: var(--mm-ink-soft); }
.dashboard-size { display: flex; flex-direction: column; gap: .25rem; text-align: right; white-space: nowrap; color: var(--mm-ink-soft); font-size: .75rem; }
.dashboard-size strong { color: var(--mm-ink); font-size: 1.15rem; font-weight: 550; font-variant-numeric: tabular-nums; }
.dashboard-size-label { font-size: .7rem; }
.size-track { display: flex; height: 1.85rem; border-radius: .45rem; overflow: hidden; background: var(--mm-bg); }
.size-segment { display: block; flex: 0 0 auto; min-width: 0; border: 0; border-radius: 0; padding: 0; box-shadow: inset -1px 0 var(--mm-surface); cursor: pointer; transition: filter .12s; }
.size-segment--boundary { box-shadow: inset 4px 0 var(--mm-surface), inset -1px 0 var(--mm-surface); }
.size-segment:hover, .size-segment:focus-visible, .size-segment--inspected { filter: brightness(1.15); outline: 2px solid var(--mm-ink); outline-offset: -2px; }
.size-legend { display: flex; gap: 2.5rem; padding: 1rem 0 1.1rem; }
.size-legend-group { display: flex; gap: .6rem; align-items: flex-start; min-width: 0; font-size: .77rem; }
.size-legend-group strong { display: block; font-weight: 550; }
.legend-swatch { flex-shrink: 0; width: .7rem; height: .7rem; border-radius: .2rem; margin-top: .2rem; }
.legend-swatch--built-in { background: var(--mm-skill-built-in-2); }
.legend-swatch--personal { background: var(--mm-primary); }
.legend-description { display: block; color: var(--mm-ink-soft); font-size: .7rem; margin-top: .25rem; }
.legend-description i { font-size: .6rem; margin-right: .2rem; }
.legend-size { color: var(--mm-ink-soft); margin-left: .45rem; font-size: .72rem; font-variant-numeric: tabular-nums; }
.size-caption { border-top: 1px solid var(--mm-line); padding: .7rem 0; min-height: 2.6rem; font-size: .7rem; color: var(--mm-ink-soft); }
.size-caption p { margin: 0; }
.size-detail { display: flex; align-items: center; flex-wrap: wrap; gap: .35rem .6rem; overflow-wrap: anywhere; }
.size-detail strong { min-width: 0; color: var(--mm-ink); font-weight: 550; }
.detail-swatch { width: .55rem; height: .55rem; border-radius: 50%; }
.skill-group { margin-top: 1.5rem; margin-bottom: 1.5rem; }
.group-title { font-size: 1rem; font-weight: 600; }
.skills-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 19rem), 1fr)); gap: 1rem; }
.skill-card { display: flex; flex-direction: column; gap: 1rem; min-width: 0; border: 1px solid var(--mm-line); border-top: 3px solid var(--skill-color, var(--mm-primary)); border-radius: var(--mm-radius); background: var(--mm-surface); padding: 1.1rem; }
.skill-card--dimmed { border-top-color: var(--mm-line); background: var(--mm-bg); }
.skill-card--dimmed .skill-card-copy { opacity: .6; }
.system-skills-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr)); gap: .75rem; }
.system-skill-card { display: flex; flex-direction: column; min-width: 0; border: 1px solid var(--mm-line); border-radius: .65rem; padding: .9rem 1rem; background: var(--mm-surface); }
.system-skill-card { border-top: 3px solid var(--skill-color); }
.system-skill-card h3 { margin: 0 0 .35rem; font-size: .85rem; font-weight: 600; overflow-wrap: anywhere; }
.system-skill-card p { margin: 0 0 .65rem; font-size: .75rem; line-height: 1.5; color: var(--mm-muted); overflow-wrap: anywhere; }
.system-skill-size { margin-top: auto; color: var(--mm-muted); font-size: .7rem; font-variant-numeric: tabular-nums; }
.system-skill-card.skill-card--dimmed { border-top-color: var(--mm-line); background: var(--mm-bg); }
.system-skill-card.skill-card--dimmed > :not(.system-skill-off) { opacity: .6; }
.system-skill-card p.system-skill-off { color: var(--mm-ink); }
.skill-card-controls { display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
.skill-state { display: inline-flex; align-items: center; gap: .4rem; font-size: .75rem; color: var(--mm-muted); }
.skill-state--on { color: var(--mm-primary); }
.state-dot { width: .4rem; height: .4rem; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.skill-title { padding: 0; border: 0; background: none; color: inherit; font: inherit; text-align: left; overflow-wrap: anywhere; }
.skill-title:hover { color: var(--mm-primary); }
.skill-card-copy { min-width: 0; }
.skill-card-copy h3 { overflow-wrap: anywhere; }
.skill-description { font-size: .85rem; line-height: 1.6; color: var(--mm-muted); overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 0; }
.skill-card-footer { margin-top: auto; padding-top: .85rem; border-top: 1px solid var(--mm-line); }
@media (max-width: 575.98px) {
  .skills-dashboard { padding: 1rem 1rem 0; }
  .dashboard-heading { align-items: flex-start; }
  .dashboard-overview p { max-width: 14rem; }
  .size-legend { gap: 1rem; flex-wrap: wrap; }
  .legend-size { display: none; }
  .dashboard-size strong { font-size: 1rem; }
}
</style>
