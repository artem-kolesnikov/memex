<script setup lang="ts">
// Test it: send a message that makes the assistant reach memex, then ask the
// server whether one has called in. The only fact memex can see is a request arriving on an
// unrevoked token, and it is asked for on the footer's button, never watched
// on a timer: whether and when a request arrives is the assistant's business.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { OTHER, logoFor } from '@/components/settings/connectGuides'
import { useWelcomeStore } from '@/stores/welcome'
import { useTheme } from '@/lib/theme'
import { copyText } from '@/lib/clipboard'
import { toastError, toastSuccess } from '@/components/toastService'
import WizardIcon from './WizardIcon.vue'

const welcome = useWelcomeStore()
const { t } = useI18n()
const { theme } = useTheme()

// A greeting alone is answered from the assistant's own head — several took
// "memex" for their name. A question about memex has to be asked of memex.
const HELLO = 'Can you reach my memex? Tell me in a sentence what memex is.'
const copied = ref(false)

async function copyHello() {
  if (await copyText(HELLO)) {
    copied.value = true
    toastSuccess(t('welcome.test.copied'))
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('welcome.copy_failed'), t('connections.copy_by_hand'))
  }
}

const intro = computed(() =>
  welcome.provider === OTHER
    ? t('welcome.test.intro_other')
    : t('welcome.test.intro', { provider: welcome.client?.label ?? '' }),
)

const status = computed(() => {
  switch (welcome.connectionCheck) {
    case 'success':
      return { icon: 'check', kind: 'is-success', title: t('welcome.test.found', { name: welcome.connectionName ?? '' }), body: t('welcome.test.found_body') }
    case 'waiting':
      return { icon: 'clock', kind: '', title: t('welcome.test.not_yet'), body: t('welcome.test.not_yet_body') }
    case 'error':
      return { icon: 'alert', kind: 'is-error', title: t('welcome.test.failed'), body: t('welcome.test.failed_body') }
    default:
      return { icon: 'clock', kind: '', title: t('welcome.test.ready'), body: t('welcome.test.ready_body') }
  }
})
</script>

<template>
  <section>
    <div class="mm-wiz-heading">
      <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.test.title') }}</h1>
      <p class="mm-wiz-intro"><template v-if="welcome.provider === 'gemini'">{{ $t('welcome.test.intro_gemini_before') }} <strong>{{ $t('welcome.test.switch_to_spark') }}</strong> {{ $t('welcome.test.intro_gemini_after') }}</template><template v-else>{{ intro }}</template></p>
    </div>

    <div class="mm-wiz-test-card">
      <div class="mm-wiz-test-top">
        <span class="mm-wiz-app-mark">
          <img v-if="welcome.client" :src="logoFor(welcome.client, theme)" alt="">
          <WizardIcon v-else name="code" />
        </span>
        <div>
          <strong>{{ $t('welcome.test.card_title') }}</strong>
          <small>{{ $t('welcome.test.card_hint') }}</small>
        </div>
      </div>
      <div class="mm-wiz-message">
        <span>{{ HELLO }}</span>
        <button type="button" class="mm-wiz-copy-button" :aria-label="copied ? $t('common.copied') : $t('welcome.test.copy_message')"
                @click="copyHello">
          <WizardIcon :name="copied ? 'check' : 'copy'" /><span>{{ copied ? $t('common.copied') : $t('common.copy') }}</span>
        </button>
      </div>
      <div class="mm-wiz-test-links">
        <a v-if="welcome.client" class="mm-wiz-text-button" :href="welcome.client.home" target="_blank" rel="noopener noreferrer">
          {{ $t('welcome.open_provider', { provider: welcome.client.label }) }}<WizardIcon name="external" />
          <span class="visually-hidden"> {{ $t('welcome.opens_new_tab') }}</span>
        </a>
        <span class="mm-wiz-prompt-note" v-else>{{ $t('welcome.test.send_in_agent') }}</span>
        <button type="button" class="mm-wiz-text-button" @click="welcome.go('connect')">
          {{ $t('welcome.test.review_steps') }}<WizardIcon name="back" />
        </button>
      </div>
    </div>

    <div class="mm-wiz-status" :class="status.kind" role="status" aria-live="polite">
      <WizardIcon :name="status.icon" />
      <div>
        <strong>{{ status.title }}</strong>
        <p>{{ status.body }}</p>
      </div>
    </div>
  </section>
</template>
