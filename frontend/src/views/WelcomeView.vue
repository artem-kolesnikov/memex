<script setup lang="ts">
// The first-run wizard: four goals in a dialog over the workspace.
//
// Still a ROUTE, `/<handle>/welcome`, because its connect step sends somebody
// into another application and they come back minutes later — new tab,
// reload, sometimes a different machine. Where it opens is derived from the
// account's facts and the remembered cursor, never from a step counter.
//
// The backdrop does not close it and neither does Escape: the person is
// midway through pasting an address into another window, and a stray click
// on the page behind must not put the instructions away. The × pauses it.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useOperationLifetime } from '@/lib/operationLifetime'
import { STEPS, useWelcomeStore, type WelcomeStep } from '@/stores/welcome'
import { OTHER } from '@/components/settings/connectGuides'
import { initPrompt } from '@/components/settings/initPrompt'
import { useTheme } from '@/lib/theme'
import { copyText } from '@/lib/clipboard'
import { toastError, toastSuccess } from '@/components/toastService'
import YouStep from '@/components/welcome/YouStep.vue'
import ConnectStep from '@/components/welcome/ConnectStep.vue'
import TestStep from '@/components/welcome/TestStep.vue'
import AskStep from '@/components/welcome/AskStep.vue'
import ProfileStep from '@/components/welcome/ProfileStep.vue'
import ProfileAskStep from '@/components/welcome/ProfileAskStep.vue'
import WizardIcon from '@/components/welcome/WizardIcon.vue'
import NewTokenDialog from '@/components/settings/NewTokenDialog.vue'

const router = useRouter()
const route = useRoute()
const { t } = useI18n()
const welcome = useWelcomeStore()
const lifetime = useOperationLifetime()
const { theme, set: setTheme } = useTheme()

const dialog = ref<HTMLElement | null>(null)
const you = ref<InstanceType<typeof YouStep> | null>(null)
const profile = ref<InstanceType<typeof ProfileStep> | null>(null)
const busy = ref(false)
const leaving = ref(false)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

onMounted(async () => {
  if (dialog.value !== null) {
    modal = new window.bootstrap.Modal(dialog.value, { backdrop: 'static', keyboard: false })
    modal.show()
  }
  window.addEventListener('focus', refreshOnReturn)
  // `?section=profile` is Settings › Personalization asking for the two profile
  // screens alone; anything else is the setup from the top.
  if (route.query.section === 'profile') await welcome.openProfile()
  else await welcome.open()
})

// Coming back from the assistant's window re-reads the facts, so a status
// box is current before the button is pressed. It moves nothing: which step
// is on screen is the person's, and only their own press changes the answer
// a check button shows.
function refreshOnReturn() {
  if ((welcome.mode === 'setup' || welcome.mode === 'profile') && !welcome.openFailed) void welcome.refresh()
}

// `hide()` alone is not a teardown: Bootstrap returns from it while the
// dialog is still fading, and unmounting mid-transition leaves its deferred
// callback to put the element back on <body> with no component behind it.
onBeforeUnmount(() => {
  window.removeEventListener('focus', refreshOnReturn)
  const instance = modal
  modal = null
  if (instance === null) return
  if (dialog.value?.classList.contains('show') === true) {
    dialog.value.addEventListener('hidden.bs.modal', () => instance.dispose(), { once: true })
    instance.hide()
    return
  }
  instance.dispose()
})

// Moving the reader's attention with the content: the new screen's title
// takes focus, which is also what a screen reader announces.
watch([() => welcome.step, () => welcome.mode, () => welcome.instructionAt, () => welcome.profileSlide], async () => {
  await nextTick()
  dialog.value?.querySelector<HTMLElement>('#welcome-title')?.focus({ preventScroll: true })
})

const isSetup = computed(() => welcome.mode === 'setup')
const isProfile = computed(() => welcome.mode === 'profile')
const instruction = computed(() => (welcome.provider === OTHER ? null : welcome.currentInstruction))

async function next() {
  const mine = lifetime.capture()
  if (busy.value) return
  busy.value = true
  try {
    if (welcome.step === 'you') {
      if (!(await you.value?.flush()) || !lifetime.current(mine)) return
      welcome.go('connect')
    } else if (welcome.step === 'connect') {
      if (welcome.provider === OTHER) welcome.go('test')
      else welcome.advance()
    } else if (welcome.step === 'test') {
      if (welcome.connectionCheck === 'success') welcome.go('ask')
      else await welcome.checkConnection()
    } else if (welcome.readCheck === 'success') {
      welcome.complete()
    } else if (!welcome.promptCopied) {
      await copyPrompt()
    } else {
      await welcome.checkRead()
    }
  } finally {
    busy.value = false
  }
}

async function copyPrompt() {
  const mine = lifetime.capture()
  const ok = await copyText(initPrompt(welcome.client))
  if (!lifetime.current(mine)) return
  // Either way the next thing to do is check: a browser that refuses the
  // clipboard leaves the text selectable, and the person copies it by hand.
  welcome.promptCopied = true
  if (ok) toastSuccess(t('welcome.ask.copied'), t('welcome.ask.copied_body'))
  else toastError(t('welcome.copy_failed'), t('connections.copy_by_hand'))
}

async function jump(target: WelcomeStep) {
  const mine = lifetime.capture()
  if (busy.value || !welcome.reachable(target) || target === welcome.step) return
  busy.value = true
  try {
    // Leaving the first step by the rail is leaving it: the same saves have
    // to land as when Next is pressed, and a refused one keeps the person here.
    if (welcome.step === 'you' && (!(await you.value?.flush()) || !lifetime.current(mine))) return
    welcome.go(target)
  } finally {
    busy.value = false
  }
}

/**
 * Leave, finished or paused, and stop being sent here on sign-in. If the
 * write does not land the person stays here with a way to try again, rather
 * than being navigated into the guard's redirect.
 */
async function leave(to: RouteLocationRaw = intended()) {
  const mine = lifetime.capture()
  if (leaving.value) return
  leaving.value = true
  try {
    if (await welcome.finish() && lifetime.current(mine)) void router.push(to)
  } finally {
    leaving.value = false
  }
}

/**
 * Where leaving lands: the page the guard redirected here from, when it
 * named one inside this knowledge base, otherwise the list. A path only —
 * never a name the query could make up — and only one under the handle the
 * guard already vouched for.
 */
function intended(): RouteLocationRaw {
  const next = route.query.next
  const handle = useAuthStore().user?.team.handle
  if (typeof next === 'string' && handle && next.startsWith(`/${handle}/`) && !next.startsWith('//') && !next.includes('/welcome')) {
    return next
  }
  return { name: 'search' }
}

async function retryOpen() {
  if (busy.value) return
  busy.value = true
  try {
    if (welcome.standalone) await welcome.openProfile()
    else await welcome.open()
  } finally {
    busy.value = false
  }
}

/**
 * The profile screens' own footer. Save writes the chosen lines and moves on;
 * Continue moves on without writing; Skip goes to the end. Opened from
 * Settings, "the end" is Settings again, and the stamp is not touched.
 */
async function saveProfile() {
  if (busy.value || !profile.value) return
  busy.value = true
  try {
    if (!profile.value.hasChanges || await profile.value.save()) welcome.profileAsk()
  } finally {
    busy.value = false
  }
}

function endProfile() {
  if (welcome.standalone) {
    void router.push({ name: 'settings', params: { pane: 'personalization' } })
    return
  }
  welcome.endProfile()
}

const primary = computed<{ labelKey: string; icon: string }>(() => {
  if (welcome.mode === 'profile') {
    if (welcome.profileSlide === 'choose') {
      return { labelKey: profile.value?.hasChanges ? 'welcome.profile.save' : 'welcome.profile.continue', icon: 'arrow' }
    }
    return { labelKey: welcome.standalone ? 'common.done' : 'welcome.ask.finish', icon: 'check' }
  }
  if (welcome.mode !== 'setup') return { labelKey: 'welcome.go_to_memex', icon: 'arrow' }
  switch (welcome.step) {
    case 'you':
      return { labelKey: 'welcome.you.next', icon: 'arrow' }
    case 'connect':
      if (welcome.provider === OTHER) return { labelKey: 'welcome.connect.test_connection', icon: 'arrow' }
      return { labelKey: instruction.value?.nextKey ?? 'common.next', icon: 'arrow' }
    case 'test':
      if (welcome.connectionCheck === 'success') return { labelKey: 'welcome.test.give_instructions', icon: 'arrow' }
      return { labelKey: welcome.connectionCheck === 'idle' ? 'welcome.test.check' : 'welcome.check_again', icon: 'link' }
    default:
      if (welcome.readCheck === 'success') return { labelKey: 'welcome.ask.finish', icon: 'check' }
      if (welcome.readCheck === 'idle' && !welcome.promptCopied) return { labelKey: 'welcome.ask.copy', icon: 'copy' }
      return { labelKey: welcome.readCheck === 'idle' ? 'welcome.ask.check' : 'welcome.check_again', icon: 'check' }
  }
})

const note = computed(() => {
  if (welcome.finishFailed) return t('welcome.finish_failed')
  if (welcome.mode === 'profile') {
    return ''
  }
  if (welcome.mode === 'finished') return t('welcome.finished.note')
  if (welcome.mode === 'paused') return t('welcome.paused.note')
  switch (welcome.step) {
    case 'you':
      return t('welcome.you.note')
    case 'connect':
      if (welcome.provider === OTHER) return t('welcome.connect.note_token')
      return instruction.value?.optional ? t('welcome.connect.note_optional') : t('welcome.connect.note')
    case 'test':
      return welcome.connectionCheck === 'success' ? t('welcome.test.note_done') : t('welcome.test.note')
    default:
      if (welcome.readCheck === 'success') return t('welcome.ask.note_done')
      return welcome.promptCopied ? t('welcome.ask.note_wait') : t('welcome.ask.note')
  }
})

const showBack = computed(() => isSetup.value && welcome.step !== 'you')
const showSkip = computed(() => isSetup.value && welcome.step === 'connect' && instruction.value?.optional === true)
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="welcome-title">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mm-wiz-dialog">
        <div class="modal-content mm-wiz">
          <header class="mm-wiz-header">
            <span class="mm-wiz-brand"><img src="/favicon.svg" alt="">{{ $t('welcome.brand') }}</span>
            <div class="mm-wiz-header-meta">
              <span class="mm-wiz-setup-label">{{ $t('welcome.header') }}</span>
              <button type="button" class="mm-wiz-icon-button"
                      :aria-label="$t(theme === 'light' ? 'welcome.switch_dark' : 'welcome.switch_light')"
                      @click="setTheme(theme === 'light' ? 'dark' : 'light')">
                <WizardIcon :name="theme === 'light' ? 'moon' : 'sun'" />
              </button>
              <button type="button" class="mm-wiz-icon-button" :aria-label="$t('welcome.leave')" :disabled="!isSetup"
                      @click="welcome.pause()">
                <WizardIcon name="close" />
              </button>
            </div>
          </header>

          <nav :aria-label="$t('welcome.progress_label')" v-if="!welcome.standalone">
            <ol class="mm-wiz-progress">
              <li v-for="(name, i) in STEPS" :key="name">
                <button type="button" :class="{ 'is-past': welcome.done(name) }"
                        :aria-current="isSetup && welcome.step === name ? 'step' : undefined"
                        :aria-disabled="!welcome.reachable(name) || undefined"
                        :title="welcome.reachable(name) ? undefined : $t('welcome.rail_locked')"
                        @click="jump(name)">
                  <span class="mm-wiz-step-number">
                    <WizardIcon v-if="welcome.done(name)" name="check" />
                    <template v-else>{{ i + 1 }}</template>
                  </span>
                  <span class="mm-wiz-step-label">{{ $t(`welcome.rail.${name}`) }}</span>
                </button>
              </li>
              <!-- Optional, so always reachable: it configures nothing and waits
                   on no fact. Ticked only by finishing. -->
              <li>
                <button type="button" :class="{ 'is-past': welcome.mode === 'finished' }"
                        :aria-current="isProfile ? 'step' : undefined"
                        @click="busy || isProfile ? undefined : welcome.goProfile()">
                  <span class="mm-wiz-step-number">
                    <WizardIcon v-if="welcome.mode === 'finished'" name="check" />
                    <template v-else>{{ STEPS.length + 1 }}</template>
                  </span>
                  <span class="mm-wiz-step-label">{{ $t('welcome.rail.profile') }}</span>
                </button>
              </li>
            </ol>
          </nav>

          <div class="modal-body mm-wiz-body">
            <div class="mm-wiz-status is-error mm-wiz-open-failed" role="alert" v-if="welcome.openFailed">
              <WizardIcon name="alert" />
              <div>
                <strong>{{ $t('welcome.open_failed') }}</strong>
                <button type="button" class="mm-wiz-text-button" :disabled="busy" @click="retryOpen()">
                  {{ $t('welcome.open_retry') }}
                </button>
              </div>
            </div>
            <div class="mm-wiz-completion" v-if="welcome.mode === 'finished'">
              <div class="mm-wiz-completion-mark"><WizardIcon name="check" /></div>
              <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.finished.title') }}</h1>
              <p>{{ $t('welcome.finished.body') }}</p>
              <div class="mm-wiz-completion-checks">
                <span><WizardIcon name="check" />{{ $t('welcome.finished.account') }}</span>
                <span><WizardIcon name="check" />{{ $t('welcome.finished.connected') }}</span>
                <span><WizardIcon name="check" />{{ $t('welcome.finished.guide') }}</span>
              </div>
            </div>
            <div class="mm-wiz-completion" v-else-if="welcome.mode === 'paused'">
              <div class="mm-wiz-completion-mark is-paused"><WizardIcon name="clock" /></div>
              <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.paused.title') }}</h1>
              <p>{{ $t('welcome.paused.body') }}</p>
              <button type="button" class="mm-wiz-button is-secondary" @click="welcome.resume()">
                {{ $t('welcome.paused.resume') }}<WizardIcon name="arrow" />
              </button>
            </div>
            <ProfileStep v-else-if="isProfile && welcome.profileSlide === 'choose'" ref="profile" />
            <ProfileAskStep v-else-if="isProfile" />
            <YouStep v-else-if="welcome.step === 'you'" ref="you" />
            <ConnectStep v-else-if="welcome.step === 'connect'" />
            <TestStep v-else-if="welcome.step === 'test'" />
            <AskStep v-else />
          </div>

          <footer class="mm-wiz-footer">
            <div class="mm-wiz-footer-left">
              <button type="button" class="mm-wiz-text-button is-back" v-if="showBack" @click="welcome.back()">
                <WizardIcon name="back" />{{ $t('common.back') }}
              </button>
              <button type="button" class="mm-wiz-text-button is-back" v-else-if="isProfile && welcome.profileSlide === 'ask'" @click="welcome.profileChoose()">
                <WizardIcon name="back" />{{ $t('common.back') }}
              </button>
              <button type="button" class="mm-wiz-text-button" v-else-if="isProfile" :disabled="busy" @click="endProfile()">
                {{ $t(welcome.standalone ? 'common.cancel' : 'welcome.profile.skip') }}
              </button>
              <span v-if="note" class="mm-wiz-footer-note" :class="{ 'is-error': welcome.finishFailed }">{{ note }}</span>
            </div>
            <button type="button" class="mm-wiz-button" v-if="isProfile && welcome.profileSlide === 'choose'"
                    :disabled="busy || (!!profile?.hasChanges && !profile.canSave)" @click="saveProfile()">
              {{ $t(primary.labelKey) }}<WizardIcon :name="primary.icon" />
            </button>
            <button type="button" class="mm-wiz-button" v-else-if="isProfile" :disabled="busy" @click="endProfile()">
              {{ $t(primary.labelKey) }}<WizardIcon :name="primary.icon" />
            </button>
            <div class="mm-wiz-optional-actions" v-else-if="showSkip">
              <button type="button" class="mm-wiz-text-button" @click="welcome.go('test')">{{ $t('welcome.connect.skip') }}</button>
              <button type="button" class="mm-wiz-button" :disabled="busy" @click="next()">
                {{ $t(primary.labelKey) }}<WizardIcon :name="primary.icon" />
              </button>
            </div>
            <button type="button" class="mm-wiz-button" v-else-if="isSetup" :disabled="busy" @click="next()">
              {{ $t(primary.labelKey) }}<WizardIcon :name="primary.icon" />
            </button>
            <button type="button" class="mm-wiz-button" v-else :disabled="leaving" @click="leave()">
              {{ $t(welcome.finishFailed ? 'common.retry' : 'welcome.go_to_memex') }}<WizardIcon :name="primary.icon" />
            </button>
          </footer>
        </div>
      </div>
    </div>
    <NewTokenDialog v-if="welcome.minted" :name="welcome.minted.name" :token="welcome.minted.token"
                    @close="welcome.minted = null" />
  </Teleport>
</template>
