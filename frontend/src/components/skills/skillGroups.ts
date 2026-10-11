import type { SkillRow } from '@/api/client'

/** One key per row whatever its kind: a note's number, or a slug for the rest. */
export function skillKey(row: SkillRow): string {
  return row.note_id !== null ? `note:${row.note_id}` : `${row.kind}:${row.slug}`
}

/** Whether an assistant can load the skill now. */
export function skillOffered(row: SkillRow): boolean {
  return (row.kind === 'shipped' && row.status !== 'switched_off') || (row.status === 'served' && row.enabled && (row.auto || row.command))
}
