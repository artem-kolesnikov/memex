import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { api, type NoteDetail, type WelcomeFacts } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { sessionEpoch } from '@/lib/operationLifetime'
import { CHATGPT, CLIENTS, OTHER } from '@/components/settings/connectGuides'
import { PROFILE_SUMMARY, PROFILE_TAG, PROFILE_TITLE, applyChoice, buildProfile, profileGroups } from '@/components/welcome/profileLines'

/**
 * The first-run wizard's state.
 *
 * Two kinds, kept apart on purpose. FACTS come from the server and say what
 * the account has achieved: a connection that has called in, an assistant
 * that opened the guide. They are re-read on every check and never inferred
 * from anything the person did in this window. The CURSOR — which step,
 * which assistant's guide, which instruction — is UI state, remembered per
 * account in this browser so that leaving to another window, or reloading,
 * comes back to the same place. Position is never evidence: the steps that
 * need a fact wait for it, and a cursor past a fact the account no longer
 * has is pulled back to the step that checks it.
 */
export type WelcomeStep = 'you' | 'connect' | 'test' | 'ask'
export const STEPS: WelcomeStep[] = ['you', 'connect', 'test', 'ask']

export type CheckResult = 'idle' | 'waiting' | 'success' | 'error'
/**
 * 'profile' is the two optional screens between Finish setup and the finished
 * screen — and, opened from Settings, the whole of what is shown.
 */
export type WelcomeMode = 'setup' | 'paused' | 'profile' | 'finished'
export type ProfileSlide = 'choose' | 'ask'

interface Cursor {
  step: WelcomeStep
  provider: string
  instruction: Record<string, number>
  promptCopied: boolean
}

const KEY = (userId: string) => `memex.welcome.${userId}`

function readCursor(userId: string): Cursor | null {
  try {
    const raw = localStorage.getItem(KEY(userId))
    if (raw === null) return null
    const c = JSON.parse(raw) as Partial<Cursor>
    const step = STEPS.includes(c.step as WelcomeStep) ? (c.step as WelcomeStep) : 'you'
    const provider =
      typeof c.provider === 'string' && (c.provider === OTHER || CLIENTS.some((x) => x.id === c.provider))
        ? c.provider
        : CHATGPT.id
    const instruction: Record<string, number> = {}
    for (const client of CLIENTS) {
      const at = Number(c.instruction?.[client.id] ?? 0)
      instruction[client.id] = Number.isInteger(at) && at >= 0 && at < client.instructions.length ? at : 0
    }
    return { step, provider, instruction, promptCopied: c.promptCopied === true }
  } catch {
    return null
  }
}

function writeCursor(userId: string, cursor: Cursor | null): void {
  try {
    if (cursor === null) localStorage.removeItem(KEY(userId))
    else localStorage.setItem(KEY(userId), JSON.stringify(cursor))
  } catch {
    /* a browser without storage forgets the position, nothing else */
  }
}

function freshInstruction(): Record<string, number> {
  return Object.fromEntries(CLIENTS.map((c) => [c.id, 0]))
}

export const useWelcomeStore = defineStore('welcome', () => {
  const facts = ref<WelcomeFacts | null>(null)
  const completed = ref(false)
  const loaded = ref(false)
  /** The opening read could not be made; the view offers a retry. */
  const openFailed = ref(false)

  const step = ref<WelcomeStep>('you')
  const mode = ref<WelcomeMode>('setup')
  const provider = ref<string>(CHATGPT.id)
  /** ChatGPT, Claude and Gemini only where they can reach this memex; the server says. */
  const webAssistants = computed(() => useAuthStore().user?.web_assistants !== false)
  const firstProvider = () => (webAssistants.value ? CHATGPT.id : OTHER)
  const instruction = ref<Record<string, number>>(freshInstruction())
  const promptCopied = ref(false)
  /** What the last press of each check button answered; 'idle' before any. */
  const connectionCheck = ref<CheckResult>('idle')
  const readCheck = ref<CheckResult>('idle')
  const finishFailed = ref(false)
  const profileSlide = ref<ProfileSlide>('choose')
  /** Opened from Settings for the profile alone: no rail, and finishing returns there. */
  const standalone = ref(false)
  /** A token minted on the Other tab, until its dialog is closed. Held here
   *  rather than in the tab so that switching provider mid-request cannot
   *  unmount the only copy of the secret. */
  const minted = ref<{ name: string; token: string } | null>(null)
  const minting = ref(false)
  // Whose token is on screen. Settings mints without ever opening the wizard,
  // so this is kept apart from the cursor's owner.
  let mintedFor: string | null = null

  // Which refresh is allowed to write. Two checks in flight can land out of
  // order, which is how a step that has just gone green goes back to waiting.
  let latest = 0
  // Whose facts these are. A sign-out and sign-in as somebody else in the
  // same tab must not show them the first person's account.
  let factsFor: string | null = null
  let userId: string | null = null
  // Bumped by every navigation the person makes, so a slow opening read that
  // lands afterwards knows not to put them back where they started.
  let moves = 0
  // Which finish is allowed to write back. A rejection that arrives after a
  // sign-out and sign-in must not revert the next account's completion.
  let finishing = 0
  let finishRequest: Promise<boolean> | null = null

  const connected = computed(() => facts.value?.connected === true)
  const connectionName = computed(() => facts.value?.connection ?? null)
  const lastSeen = computed(() => facts.value?.last_seen ?? null)
  const guideRead = computed(() => facts.value?.guide_read === true)
  const curator = computed(() => facts.value?.curator === true)
  const profiles = computed(() => facts.value?.profiles ?? [])
  const groups = computed(() => profileGroups())

  const client = computed(() => CLIENTS.find((c) => c.id === provider.value) ?? null)
  const instructionAt = computed(() => instruction.value[provider.value] ?? 0)
  const currentInstruction = computed(() => client.value?.instructions[instructionAt.value] ?? null)

  /**
   * Bring what is on screen into line with the facts just read. A success
   * that the facts no longer support goes back to waiting, and a step that
   * needs a fact the account has lost goes back to the step that checks it.
   */
  function reconcile(): void {
    if (connectionCheck.value === 'success' && !connected.value) connectionCheck.value = 'waiting'
    if (readCheck.value === 'success' && !guideRead.value) readCheck.value = 'waiting'
    if (connected.value && connectionCheck.value !== 'error') connectionCheck.value = 'success'
    if (guideRead.value && readCheck.value !== 'error') readCheck.value = 'success'
    if (step.value === 'ask' && !connected.value) step.value = 'test'
    if (mode.value === 'finished' && !guideRead.value) mode.value = 'setup'
    if (mode.value === 'profile' && !standalone.value && !guideRead.value) mode.value = 'setup'
  }

  /**
   * Re-read the facts. 'failed' when the server could not be asked, so a
   * check button can say "could not check" rather than "nothing arrived";
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
      completed.value = state.completed
      // Another tab may have finished it. The guard reads the auth copy, so
      // the two must agree here as well as in finish().
      if (state.completed && auth.user !== null) auth.user.welcome_completed = true
      loaded.value = true
      reconcile()
      return 'ok'
    } catch {
      if (mine !== latest || epoch !== sessionEpoch.value || useAuthStore().user?.team.handle !== forUser) return 'stale'
      return 'failed'
    }
  }

  /**
   * Which steps a click on the rail may reach. Earlier steps always; the
   * first three always, because they are where the facts get made; the
   * last only once an assistant has actually reached memex.
   */
  function reachable(target: WelcomeStep): boolean {
    if (STEPS.indexOf(target) <= STEPS.indexOf(step.value)) return true
    return target !== 'ask' || connected.value
  }

  /**
   * Whether the rail may tick a step. The first two mean "visited and left";
   * the test's tick is the connection itself; First chat's is the guide read,
   * and only once the person has gone past it to the profile screens or the
   * finish. On the profile screens the step they left is behind them too.
   */
  function done(target: WelcomeStep): boolean {
    if (mode.value === 'finished') return true
    const at = STEPS.indexOf(step.value)
    const behind = STEPS.indexOf(target) < at || (mode.value === 'profile' && STEPS.indexOf(target) <= at)
    if (target === 'test') return behind && connected.value
    if (target === 'ask') return behind && guideRead.value
    return behind
  }

  /**
   * Open the wizard: place the person where they left off, read the facts,
   * then move forward only to the step that reports what the account has
   * since achieved. Reopened after completion, it starts at the top.
   */
  async function open(): Promise<void> {
    const auth = useAuthStore()
    if (auth.user === null) {
      forget()
      return
    }
    if (userId !== null && userId !== auth.user.team.handle) forget()
    userId = auth.user.team.handle
    mode.value = 'setup'
    standalone.value = false
    profileSlide.value = 'choose'
    finishFailed.value = false
    openFailed.value = false
    const stored = auth.user.welcome_completed !== true ? readCursor(userId) : null
    if (stored !== null) {
      provider.value = webAssistants.value ? stored.provider : OTHER
      instruction.value = stored.instruction
      promptCopied.value = stored.promptCopied
      step.value = stored.step
    } else {
      provider.value = firstProvider()
      instruction.value = freshInstruction()
      promptCopied.value = false
      step.value = 'you'
    }
    const before = moves
    const result = await refresh()
    if (result === 'failed') {
      openFailed.value = facts.value === null
      return
    }
    // A newer answer landed while this one was out, or the person has
    // already moved on: either way this read has nothing to settle.
    if (result === 'stale' || moves !== before) return
    if (completed.value) {
      writeCursor(userId, null)
      step.value = 'you'
      provider.value = firstProvider()
      instruction.value = freshInstruction()
      promptCopied.value = false
    } else if (guideRead.value) {
      step.value = 'ask'
    } else if (connected.value && STEPS.indexOf(step.value) < STEPS.indexOf('test')) {
      step.value = 'test'
    }
  }

  /**
   * The profile screens on their own, from Settings: the facts are read so the
   * screen knows which profile notes exist, and nothing about the setup's own
   * steps is touched or claimed.
   */
  async function openProfile(): Promise<void> {
    const auth = useAuthStore()
    if (auth.user === null) {
      forget()
      return
    }
    if (userId !== null && userId !== auth.user.team.handle) forget()
    userId = auth.user.team.handle
    moves++
    mode.value = 'profile'
    standalone.value = true
    profileSlide.value = 'choose'
    finishFailed.value = false
    openFailed.value = false
    const result = await refresh()
    if (result === 'failed') openFailed.value = facts.value === null
  }

  // Signing out, or in as somebody else, forgets the first account at once —
  // before the next wizard renders, not when it opens.
  watch(sessionEpoch, forget, { flush: 'sync' })

  watch(() => useAuthStore().user?.team.handle ?? null, (id) => {
    if (userId !== null && id !== userId) forget()
    else if (minted.value !== null && mintedFor !== id) minted.value = null
  })

  watch([step, provider, instruction, promptCopied], () => {
    if (userId === null || mode.value === 'finished') return
    writeCursor(userId, {
      step: step.value,
      provider: provider.value,
      instruction: { ...instruction.value },
      promptCopied: promptCopied.value,
    })
  }, { deep: true })

  function go(target: WelcomeStep): void {
    moves++
    step.value = target
    mode.value = 'setup'
  }

  function choose(id: string): void {
    moves++
    provider.value = webAssistants.value ? id : OTHER
  }

  /** Forward from the connect step: the next instruction, or the test. */
  function advance(): void {
    const c = client.value
    if (c !== null && instructionAt.value < c.instructions.length - 1) {
      moves++
      instruction.value = { ...instruction.value, [c.id]: instructionAt.value + 1 }
      return
    }
    go('test')
  }

  function back(): void {
    if (step.value === 'connect' && client.value !== null && instructionAt.value > 0) {
      moves++
      instruction.value = { ...instruction.value, [client.value.id]: instructionAt.value - 1 }
      return
    }
    const at = STEPS.indexOf(step.value)
    if (at > 0) go(STEPS[at - 1]!)
  }

  function showInstruction(at: number): void {
    const c = client.value
    if (c === null || at < 0 || at >= c.instructions.length) return
    moves++
    instruction.value = { ...instruction.value, [c.id]: at }
  }

  let checking = false

  /** The test step's button: ask the server whether an assistant has called in. */
  async function checkConnection(): Promise<void> {
    if (checking) return
    const epoch = sessionEpoch.value
    checking = true
    try {
      const result = await refresh()
      if (result === 'stale') return
      connectionCheck.value = result === 'failed' ? 'error' : connected.value ? 'success' : 'waiting'
    } finally {
      if (epoch === sessionEpoch.value) checking = false
    }
  }

  /** The first-chat button: ask whether an assistant has opened the guide. */
  async function checkRead(): Promise<void> {
    if (checking) return
    const epoch = sessionEpoch.value
    checking = true
    try {
      const result = await refresh()
      if (result === 'stale') return
      readCheck.value = result === 'failed' ? 'error' : guideRead.value ? 'success' : 'waiting'
    } finally {
      if (epoch === sessionEpoch.value) checking = false
    }
  }

  /** Mint a token for the Other tab. The result outlives the tab. */
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
      if (userId !== null) void refresh()
    } finally {
      if (epoch === sessionEpoch.value) minting.value = false
    }
  }

  function pause(): void {
    moves++
    mode.value = 'paused'
  }

  function resume(): void {
    moves++
    mode.value = 'setup'
  }

  /**
   * Only once the server has recorded a guide read: what follows claims it.
   * Finish setup leads to the two optional profile screens, not straight to
   * the finished screen; the stamp is still written by leaving, as before.
   */
  function complete(): void {
    if (!guideRead.value) return
    moves++
    mode.value = 'profile'
    profileSlide.value = 'choose'
  }

  /**
   * The rail's fifth item. Optional and configuring nothing, so it needs no
   * fact: a person may look at it before an assistant has read the guide,
   * and leave it by Skip. Finish setup on First chat lands here too.
   */
  function goProfile(): void {
    moves++
    mode.value = 'profile'
    profileSlide.value = 'choose'
  }

  /** Past the profile screens without touching them, or after them. */
  function endProfile(): void {
    moves++
    mode.value = 'finished'
  }

  function profileAsk(): void {
    moves++
    profileSlide.value = 'ask'
  }

  /** Back to the choices, with the inventory re-read: an assistant may have filed one meanwhile. */
  function profileChoose(): void {
    moves++
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

  /**
   * Stop sending this person here on sign-in, whether finished or paused.
   * The same write either way; Settings › Account opens this again. Returns
   * false when the write did not land, so the button can say so and offer
   * another try rather than navigating into the guard's redirect.
   */
  function finish(): Promise<boolean> {
    if (finishRequest !== null) return finishRequest
    const auth = useAuthStore()
    if (auth.user === null) return Promise.resolve(false)
    if (completed.value && factsFor === auth.user.team.handle) {
      auth.user.welcome_completed = true
      if (userId !== null) writeCursor(userId, null)
      return Promise.resolve(true)
    }
    const forUser = auth.user.team.handle
    const epoch = sessionEpoch.value
    const mine = ++finishing
    const current = () => mine === finishing && epoch === sessionEpoch.value && auth.user?.team.handle === forUser
    finishFailed.value = false
    const request = (async () => {
      try {
        await api.finishWelcome()
        if (!current()) return false
        latest++
        factsFor = forUser
        completed.value = true
        auth.user!.welcome_completed = true
        writeCursor(forUser, null)
        return true
      } catch {
        if (!current()) return false
        finishFailed.value = true
        return false
      } finally {
        if (current()) finishRequest = null
      }
    })()
    finishRequest = request
    return request
  }

  function forget(): void {
    latest++
    finishing++
    finishRequest = null
    checking = false
    userId = null
    factsFor = null
    facts.value = null
    completed.value = false
    loaded.value = false
    openFailed.value = false
    step.value = 'you'
    mode.value = 'setup'
    profileSlide.value = 'choose'
    standalone.value = false
    provider.value = CHATGPT.id
    instruction.value = freshInstruction()
    promptCopied.value = false
    connectionCheck.value = 'idle'
    readCheck.value = 'idle'
    finishFailed.value = false
    minted.value = null
    mintedFor = null
    minting.value = false
  }

  return {
    facts,
    completed,
    loaded,
    openFailed,
    step,
    mode,
    provider,
    webAssistants,
    client,
    instruction,
    instructionAt,
    currentInstruction,
    promptCopied,
    connectionCheck,
    readCheck,
    finishFailed,
    profileSlide,
    standalone,
    profiles,
    groups,
    minted,
    minting,
    connected,
    connectionName,
    lastSeen,
    guideRead,
    curator,
    reachable,
    done,
    open,
    openProfile,
    refresh,
    go,
    choose,
    advance,
    back,
    showInstruction,
    checkConnection,
    checkRead,
    mint,
    pause,
    resume,
    complete,
    goProfile,
    endProfile,
    profileAsk,
    profileChoose,
    saveProfile,
    finish,
    forget,
  }
})
