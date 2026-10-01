import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { sessionEpoch } from '@/lib/operationLifetime'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

/**
 * How many things are waiting to be reviewed — the number on the header badge.
 *
 * It lived as a `ref` inside App.vue, refreshed by a route watch and a slow
 * poll. Approving in the inbox changes neither: the inbox splices the decided
 * row out of its own list and never navigates, so the badge kept counting
 * things the operator had already dealt with until he reloaded the page or two
 * minutes went by (his report, 2026-08-23).
 *
 * A store rather than an event or a prop, because the two components have no
 * relationship to hang either on — the badge is in the app shell and the
 * decisions happen in a routed view. This is the seam where "who owns this
 * number" gets an answer: the server does, and `refresh()` is how anything
 * that changed it says so.
 *
 * The poll in App.vue stays as a safety net for the counts nothing here can
 * see — an assistant filing a proposal while the tab sits open.
 */
export const useInboxStore = defineStore('inbox', () => {
  const count = ref(0)
  let latest = 0
  watch(sessionEpoch, () => { latest++; count.value = 0 }, { flush: 'sync' })

  async function refresh(): Promise<void> {
    const epoch = sessionEpoch.value
    const mine = ++latest
    if (useAuthStore().user === null) {
      count.value = 0
      return
    }
    try {
      const result = await api.inboxCount()
      if (epoch === sessionEpoch.value && mine === latest) count.value = result.total
    } catch {
      /* transient — keep the last known count rather than flashing zero */
    }
  }

  return { count, refresh }
})
