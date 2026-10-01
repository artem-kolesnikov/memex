/**
 * "Is this tag one memex itself reads?" — asked by every component that draws
 * a tag chip.
 *
 * The set is `skill`, `live-state` and `user-profile`, and it lives on the server
 * (App\Service\SystemTags), which is the only place that can enforce it. It
 * reaches the client on `me`, so this module reads the auth store rather than
 * holding a copy: a hardcoded list here would be a second source of truth for
 * a rule the server owns, and the day a third tag is added it would be the
 * half nobody remembers to change.
 *
 * A chip may render before `me` has landed (a hard refresh straight onto a
 * note). That is why an empty registry answers `false` rather than throwing:
 * the tag shows as ordinary for the moment before the store fills, which is
 * the right failure — a chip that is briefly the wrong colour, never a screen
 * that does not render.
 */
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

export function useSystemTags() {
  const auth = useAuthStore()
  const reasons = computed(() => {
    const map = new Map<string, string>()
    for (const t of auth.user?.system_tags ?? []) map.set(t.name.toLowerCase(), t.reason)
    return map
  })

  return {
    /** Tag names are stored lowercased server-side; match on that, not on display case. */
    isSystemTag: (name: string) => reasons.value.has(name.toLowerCase()),
    /** Why memex holds this word, or null for an ordinary tag. */
    systemReason: (name: string) => reasons.value.get(name.toLowerCase()) ?? null,
  }
}
