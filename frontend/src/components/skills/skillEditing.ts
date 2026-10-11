import { reactive, ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type SkillPatch, type SkillRow } from '@/api/client'

const SLUG_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

/** What is typed into an open skill and not yet saved: its alias and the
 *  connections kept from it. */
export interface SkillDraft { slug: string; blocked: number[] }

export function skillDraft(skill: SkillRow): SkillDraft {
  return reactive({ slug: skill.slug, blocked: [...skill.blocked] })
}

// Changing one skill: switching it, its connections and its alias.
export function useSkillEditing(skill: () => SkillRow, draft: SkillDraft, updated: (row: SkillRow) => void) {
  const { t } = useI18n()
  const slugDraft = toRef(draft, 'slug')
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
      slugDraft.value = r.skill.slug
    } catch (e) {
      error.value = e instanceof Error ? e.message : t('common.unknown_error')
    } finally {
      saving.value = false
    }
  }

  function rename() {
    if (slugDraft.value.length > 64 || !SLUG_RE.test(slugDraft.value)) {
      error.value = t('skills.panel.slug_invalid')
      return
    }
    void patch({ slug: slugDraft.value })
  }

  return { slugDraft, error, saving, patch, rename }
}
