import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { api, type NoteDetail, type WelcomeFacts } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { sessionEpoch } from '@/lib/operationLifetime'
import { PROFILE_SUMMARY, PROFILE_TAG, PROFILE_TITLE, applyChoice, buildProfile, profileGroups } from '@/components/welcome/profileLines'

/**
 * The profile screens' state: which of the two is showing, and the account's
 * profile notes as the server last listed them. The list is re-read on every
 * open and after every save, never inferred from what this window did.
 */
export type ProfileSlide = 'choose' | 'ask'

export const useWelcomeStore = defineStore('welcome', () => {
  const facts = ref<WelcomeFacts | null>(null)
  /** The opening read could not be made; the view offers a retry. */
  const openFailed = ref(false)
  const profileSlide = ref<ProfileSlide>('choose')

  // Which refresh is allowed to write. Two reads in flight can land out of order.
  let latest = 0
  // Whose profiles these are. Signing in as somebody else in the same tab
  // must not show them the first person's.
  let factsFor: string | null = null

  const profiles = computed(() => facts.value?.profiles ?? [])
  const groups = computed(() => profileGroups())

  /**
   * Re-read the profile list. 'failed' when the server could not be asked,
   * 'stale' when a newer request has landed since. What was known before is
   * kept either way.
   */
  async function refresh(): Promise<'ok' | 'failed' | 'stale'> {
    const auth = useAuthStore()
    if (auth.user === null) {
      forget()
      return 'failed'
    }
    const mine = ++latest
    const forUser = auth.user.team.handle
    const epoch = sessionEpoch.value
    try {
      const state = await api.welcome()
      if (mine !== latest || epoch !== sessionEpoch.value || useAuthStore().user?.team.handle !== forUser) return 'stale'
      facts.value = state.facts
      factsFor = forUser
      return 'ok'
    } catch {
      if (mine !== latest || epoch !== sessionEpoch.value || useAuthStore().user?.team.handle !== forUser) return 'stale'
      return 'failed'
    }
  }

  async function openProfile(): Promise<void> {
    const handle = useAuthStore().user?.team.handle ?? null
    if (handle === null || (factsFor !== null && factsFor !== handle)) forget()
    if (handle === null) return
    profileSlide.value = 'choose'
    openFailed.value = false
    const result = await refresh()
    if (result === 'failed') openFailed.value = facts.value === null
  }

  // Signing out, or in as somebody else, forgets the first account at once.
  watch(sessionEpoch, forget, { flush: 'sync' })

  function profileAsk(): void {
    profileSlide.value = 'ask'
  }

  /** Back to the choices, with the inventory re-read: an assistant may have filed one meanwhile. */
  function profileChoose(): void {
    profileSlide.value = 'choose'
    void refresh()
  }

  /**
   * Write the chosen lines: a new note tagged `user-profile` when the account
   * has none, otherwise those lines added to or removed from the profile AS
   * THE PERSON SAW IT — the body and version the screen loaded are what the
   * change is computed on and what is sent back, so a note somebody else
   * changed meanwhile answers 409 rather than having their edit undone.
   * Returns the note, or the reason it did not land. Nothing is written with
   * no choice made — an empty profile is not a profile — and nothing is
   * created while what the account holds is unknown.
   */
  async function saveProfile(
    chosen: Set<string>,
    target: { id: number; body: string; version: number } | null,
  ): Promise<{ note: NoteDetail } | { error: string }> {
    const auth = useAuthStore()
    const forUser = auth.user?.team.handle ?? null
    if (forUser === null) return { error: 'signed out' }
    if (facts.value === null) return { error: 'unknown' }
    const epoch = sessionEpoch.value
    try {
      let note: NoteDetail
      if (target === null) {
        if (profiles.value.length > 0) return { error: 'exists' }
        const body = buildProfile(groups.value, chosen)
        if (body.trim() === '') return { error: 'nothing chosen' }
        note = (await api.createNote({ title: PROFILE_TITLE, body_md: body, tags: [PROFILE_TAG], summary: PROFILE_SUMMARY })).note
      } else {
        const body = applyChoice(target.body, groups.value, chosen)
        if (body === target.body) return { error: 'nothing changed' }
        note = (await api.updateNote(target.id, { body_md: body, expected_version: target.version })).note
      }
      if (epoch !== sessionEpoch.value || useAuthStore().user?.team.handle !== forUser) return { error: 'signed out' }
      void refresh()
      return { note }
    } catch (e) {
      if (epoch !== sessionEpoch.value || useAuthStore().user?.team.handle !== forUser) return { error: 'signed out' }
      return { error: e instanceof Error ? e.message : 'unknown' }
    }
  }

  function forget(): void {
    latest++
    factsFor = null
    facts.value = null
    openFailed.value = false
    profileSlide.value = 'choose'
  }

  return {
    facts,
    openFailed,
    profileSlide,
    profiles,
    groups,
    openProfile,
    refresh,
    profileAsk,
    profileChoose,
    saveProfile,
    forget,
  }
})
