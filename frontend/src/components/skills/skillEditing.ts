import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type SkillPatch, type SkillRow } from '@/api/client'

export const SLUG_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

/** What is ticked in an open skill and not yet saved: the connections kept
 *  from it. */
export interface SkillDraft { blocked: number[] }

export function skillDraft(skill: SkillRow): SkillDraft {
  return reactive({ blocked: [...skill.blocked] })
}

// Changing one skill from its page: which connections may use it.
export function useSkillEditing(skill: () => SkillRow, updated: (row: SkillRow) => void) {
  const { t } = useI18n()
  const error = ref<string | null>(null)
  const saving = ref(false)

  async function patch(p: SkillPatch) {
    const noteId = skill().note_id
    if (noteId === null) return
    saving.value = true
    error.value = null
    try {
      const r = await api.updateSkill(noteId, p)
      updated(r.skill)
    } catch (e) {
      error.value = e instanceof Error ? e.message : t('common.unknown_error')
    } finally {
      saving.value = false
    }
  }

  return { error, saving, patch }
}
