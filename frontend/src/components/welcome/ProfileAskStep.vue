<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { copyText } from '@/lib/clipboard'
import { toastError, toastSuccess } from '@/components/toastService'
import { PROFILE_PROMPT } from './profilePrompt'
import ProfileTitlebar from './ProfileTitlebar.vue'
import WizardIcon from './WizardIcon.vue'

const { t } = useI18n()
const copied = ref(false)

async function copy() {
  const ok = await copyText(PROFILE_PROMPT)
  if (ok) {
    copied.value = true
    toastSuccess(t('welcome.profile_ask.copied'), t('welcome.profile_ask.copied_body'))
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('welcome.copy_failed'), t('connections.copy_by_hand'))
  }
}
</script>

<template>
  <section class="mm-wiz-profile mm-wiz-profile-ask">
    <div class="mm-wiz-heading">
      <div class="mm-wiz-profile-title-row">
        <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.profile_ask.title') }}</h1>
        <ProfileTitlebar />
      </div>
      <p class="mm-wiz-intro">{{ $t('welcome.profile_ask.intro') }}</p>
    </div>

    <div class="mm-wiz-prompt">
      <div class="mm-wiz-prompt-heading">
        <span><WizardIcon name="chat" />{{ $t('welcome.profile_ask.prompt_label') }}</span>
        <span>
          <button type="button" class="mm-wiz-copy-button"
                  :aria-label="copied ? $t('common.copied') : $t('welcome.profile_ask.copy')" @click="copy">
            <WizardIcon :name="copied ? 'check' : 'copy'" /><span>{{ copied ? $t('common.copied') : $t('welcome.profile_ask.copy') }}</span>
          </button>
        </span>
      </div>
      <pre class="mm-wiz-prompt-text">{{ PROFILE_PROMPT }}</pre>
    </div>

    <p class="mm-wiz-prompt-note">{{ $t('welcome.profile_ask.explain') }}</p>
    <div class="mm-wiz-profile-later">
      <WizardIcon name="clock" />
      <p><strong>{{ $t('welcome.profile_ask.later') }}</strong><span>{{ $t('welcome.profile_ask.where') }}</span></p>
    </div>
  </section>
</template>
