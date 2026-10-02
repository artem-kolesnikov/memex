import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { sessionEpoch } from '@/lib/operationLifetime'

/**
 * A token minted on the Other tab of Settings › Assistants, until its dialog
 * is closed. Held here rather than in the tab so that switching to another
 * assistant while the request is out cannot unmount the only copy of the
 * secret.
 */
export const useTokenMintStore = defineStore('tokenMint', () => {
  const minted = ref<{ name: string; token: string } | null>(null)
  const minting = ref(false)
  let mintedFor: string | null = null

  async function mint(name: string): Promise<void> {
    if (minting.value) return
    const forUser = useAuthStore().user?.team.handle ?? null
    if (forUser === null) return
    const epoch = sessionEpoch.value
    minting.value = true
    try {
      const answer = await api.createToken(name)
      if (epoch !== sessionEpoch.value || useAuthStore().user?.team.handle !== forUser) return
      mintedFor = forUser
      minted.value = { name: answer.name, token: answer.token }
    } finally {
      if (epoch === sessionEpoch.value) minting.value = false
    }
  }

  // Signing out, or in as somebody else, drops the first account's secret.
  watch(sessionEpoch, () => {
    minted.value = null
    mintedFor = null
    minting.value = false
  }, { flush: 'sync' })

  watch(() => useAuthStore().user?.team.handle ?? null, (id) => {
    if (minted.value !== null && mintedFor !== id) minted.value = null
  })

  return { minted, minting, mint }
})
