import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SkillRow } from '@/api/client'

/** One key per row whatever its kind: a note's number, or a slug for the rest. */
export function skillKey(row: SkillRow): string {
  return row.note_id !== null ? `note:${row.note_id}` : `${row.kind}:${row.slug}`
}

/** Whether an assistant can load the skill now. */
export function skillOffered(row: SkillRow): boolean {
  return (row.kind === 'shipped' && row.status !== 'switched_off') || (row.status === 'served' && row.enabled && (row.auto || row.command))
}

/** The words for where a skill stands, as an i18n key. */
export function skillStateKey(row: SkillRow): string {
  if (row.kind === 'shipped') return row.status === 'switched_off' ? 'skills.state.paused' : 'skills.cards.always_on'
  if (row.status === 'pending') return 'skills.state.pending'
  if (row.kind === 'catalogue') return 'skills.state.offered'
  return skillOffered(row) ? 'skills.cards.on' : 'skills.state.paused'
}

// What the skills page shows of its rows, as cards or as the list in frames:
// the three groups, each skill's colour in the size bar, and its state.
export function useSkillGroups(rows: () => SkillRow[]) {
  const { t } = useI18n()
  const builtIn = computed(() => rows().filter(r => r.kind === 'shipped' && r.status !== 'switched_off'))
  const personal = computed(() => rows().filter(r => r.kind === 'note' && skillOffered(r)))
  const available = computed(() => [...builtIn.value, ...personal.value])
  const personalColors = computed(() => new Map(rows().filter(r => r.kind === 'note').sort((a, b) => (a.note_id ?? 0) - (b.note_id ?? 0)).map((row, index) => [row.slug, `var(--mm-skill-${index % 6})`])))
  function skillColor(row: SkillRow): string {
    return row.kind === 'shipped' ? `var(--mm-skill-built-in-${rows().filter(r => r.kind === 'shipped').findIndex(r => r.slug === row.slug) % 5})` : personalColors.value.get(row.slug) ?? 'var(--mm-primary)'
  }
  const contextSize = computed(() => available.value.reduce((sum, row) => sum + row.size_tokens, 0))
  const builtInSize = computed(() => builtIn.value.reduce((sum, row) => sum + row.size_tokens, 0))
  const stateLabel = (row: SkillRow) => t(skillStateKey(row))
  const groups = computed(() => [
    { kind: 'active', title: t('skills.groups.active'), help: t('skills.groups.active_help'), rows: rows().filter(r => r.kind === 'note' && skillOffered(r)) },
    { kind: 'available', title: t('skills.groups.available'), help: t('skills.groups.available_help'), rows: rows().filter(r => r.kind === 'catalogue' || (r.kind === 'note' && !skillOffered(r))) },
    { kind: 'system', title: t('skills.groups.system'), help: t('skills.groups.system_help'), rows: rows().filter(r => r.kind === 'shipped') },
  ])

  return { builtIn, personal, available, skillColor, contextSize, builtInSize, stateLabel, groups }
}
