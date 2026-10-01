import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, ApiError, type PersonalizationSettings, type PersonalizationView } from '@/api/client'
import { useOperationLifetime } from '@/lib/operationLifetime'
import { toastError } from '@/components/toastService'

export type PersonalizationPatch = { [K in keyof PersonalizationSettings]?: PersonalizationSettings[K] | null }

/**
 * The presets, saved as each control changes. Every write names the revision
 * it was made against: a tab looking at older settings is refused, shown the
 * current ones, and asked to make its change again. Only the newest request's
 * answer is applied, so a slow read cannot put back what a later save changed.
 */
export function usePersonalization() {
  const lifetime = useOperationLifetime()
  const { t } = useI18n()
  const view = ref<PersonalizationView | null>(null)
  const failed = ref(false)
  const saving = ref(false)
  const feedback = ref<Record<string, 'saving' | 'saved' | 'failed'>>({})
  let latest = 0

  async function load(): Promise<void> {
    const mine = lifetime.capture()
    const seq = ++latest
    failed.value = false
    try {
      const v = await api.personalization()
      if (lifetime.current(mine) && seq === latest) view.value = v
    } catch {
      if (lifetime.current(mine) && seq === latest) failed.value = true
    }
  }

  /** @param row which control's save state to report */
  async function update(row: string, patch: PersonalizationPatch): Promise<boolean> {
    if (view.value === null || saving.value) return false
    const mine = lifetime.capture()
    const seq = ++latest
    saving.value = true
    feedback.value = { ...feedback.value, [row]: 'saving' }
    try {
      const next = await api.updatePersonalization({ ...patch, expected_revision: view.value.revision })
      if (!lifetime.current(mine)) return false
      if (seq === latest) view.value = next
      feedback.value = { ...feedback.value, [row]: 'saved' }
      return true
    } catch (e) {
      if (!lifetime.current(mine)) return false
      feedback.value = { ...feedback.value, [row]: 'failed' }
      if (e instanceof ApiError && e.status === 409 && e.data.current) {
        if (seq === latest) view.value = e.data.current as PersonalizationView
        toastError(t('personalization.settings.stale'), e.message)
      } else {
        toastError(t('personalization.settings.not_saved'), e instanceof Error ? e.message : '')
      }
      return false
    } finally {
      if (lifetime.current(mine)) saving.value = false
    }
  }

  return { view, failed, saving, feedback, load, update }
}

export type PersonalizationState = ReturnType<typeof usePersonalization>
