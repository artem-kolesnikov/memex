<script setup lang="ts">
// The profile screens: two optional steps in a dialog over the workspace,
// opened from Settings › Personalization, which they return to.
//
// The backdrop does not close it and neither does Escape: the person may be
// midway through choosing lines, and a stray click on the page behind must
// not throw the choice away. Cancel and × go back to Settings.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useWelcomeStore } from '@/stores/welcome'
import { useTheme } from '@/lib/theme'
import ProfileStep from '@/components/welcome/ProfileStep.vue'
import ProfileAskStep from '@/components/welcome/ProfileAskStep.vue'
import WizardIcon from '@/components/welcome/WizardIcon.vue'

const router = useRouter()
const welcome = useWelcomeStore()
const { theme, set: setTheme } = useTheme()

const dialog = ref<HTMLElement | null>(null)
const profile = ref<InstanceType<typeof ProfileStep> | null>(null)
const busy = ref(false)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

onMounted(async () => {
  if (dialog.value !== null) {
    modal = new window.bootstrap.Modal(dialog.value, { backdrop: 'static', keyboard: false })
    modal.show()
  }
  window.addEventListener('focus', refreshOnReturn)
  await welcome.openProfile()
})

// Coming back from an assistant's window re-reads the profile list: it may
// have filed one meanwhile.
function refreshOnReturn() {
  if (!welcome.openFailed) void welcome.refresh()
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
watch(() => welcome.profileSlide, async () => {
  await nextTick()
  dialog.value?.querySelector<HTMLElement>('#welcome-title')?.focus({ preventScroll: true })
})

async function retryOpen() {
  if (busy.value) return
  busy.value = true
  try {
    await welcome.openProfile()
  } finally {
    busy.value = false
  }
}

/** Save writes the chosen lines and moves on; Continue moves on without writing. */
async function saveProfile() {
  if (busy.value || !profile.value) return
  busy.value = true
  try {
    if (!profile.value.hasChanges || await profile.value.save()) welcome.profileAsk()
  } finally {
    busy.value = false
  }
}

function close() {
  void router.push({ name: 'settings', params: { pane: 'personalization' } })
}
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
              <button type="button" class="mm-wiz-icon-button" :aria-label="$t('common.close')" @click="close()">
                <WizardIcon name="close" />
              </button>
            </div>
          </header>

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
            <ProfileStep v-if="welcome.profileSlide === 'choose'" ref="profile" />
            <ProfileAskStep v-else />
          </div>

          <footer class="mm-wiz-footer">
            <div class="mm-wiz-footer-left">
              <button type="button" class="mm-wiz-text-button is-back" v-if="welcome.profileSlide === 'ask'" @click="welcome.profileChoose()">
                <WizardIcon name="back" />{{ $t('common.back') }}
              </button>
              <button type="button" class="mm-wiz-text-button" v-else :disabled="busy" @click="close()">
                {{ $t('common.cancel') }}
              </button>
            </div>
            <button type="button" class="mm-wiz-button" v-if="welcome.profileSlide === 'choose'"
                    :disabled="busy || (!!profile?.hasChanges && !profile.canSave)" @click="saveProfile()">
              {{ $t(profile?.hasChanges ? 'welcome.profile.save' : 'welcome.profile.continue') }}<WizardIcon name="arrow" />
            </button>
            <button type="button" class="mm-wiz-button" v-else :disabled="busy" @click="close()">
              {{ $t('common.done') }}<WizardIcon name="check" />
            </button>
          </footer>
        </div>
      </div>
    </div>
  </Teleport>
</template>
