<script setup lang="ts">
// First chat: the prompt to paste, then the check that the guide was read.
// Copying is a convenience and proves nothing; the guide read is a fact the
// server records when a connection opens the guide skill.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PromptBox from '@/components/settings/PromptBox.vue'
import { useWelcomeStore } from '@/stores/welcome'
import WizardIcon from './WizardIcon.vue'

const welcome = useWelcomeStore()
const { t } = useI18n()

// Either way the next thing to do is check: a browser that refuses the
// clipboard leaves the text selectable, and the person copies it by hand.
function copied() {
  welcome.promptCopied = true
}

const status = computed(() => {
  switch (welcome.readCheck) {
    case 'success':
      return { icon: 'check', kind: 'is-success', title: t('welcome.ask.read'), body: t('welcome.ask.read_body') }
    case 'waiting':
      return { icon: 'clock', kind: '', title: t('welcome.ask.not_yet'), body: t('welcome.ask.not_yet_body') }
    case 'error':
      return { icon: 'alert', kind: 'is-error', title: t('welcome.ask.failed'), body: t('welcome.ask.failed_body') }
    default:
      return null
  }
})
</script>

<template>
  <section class="mm-wiz-first-chat">
    <div class="mm-wiz-heading">
      <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.ask.title') }}</h1>
      <p class="mm-wiz-intro">
        <template v-if="welcome.client">
          {{ $t('welcome.ask.intro_before') }}
          <a class="mm-wiz-inline-link" :href="welcome.client.home" target="_blank" rel="noopener noreferrer">{{ welcome.client.label }}<WizardIcon name="external" /><span class="visually-hidden"> {{ $t('welcome.opens_new_tab') }}</span></a>{{ $t('welcome.ask.intro_after') }}
        </template>
        <template v-else>{{ $t('welcome.ask.intro_other') }}</template>
      </p>
    </div>

    <PromptBox :client="welcome.client" @copied="copied" />

    <p class="mm-wiz-prompt-note" v-if="welcome.client?.persist.note">
      {{ $t(welcome.client.persist.note.beforeKey) }}
      <a class="mm-wiz-inline-link" :href="welcome.client.persist.note.link.url" target="_blank" rel="noopener noreferrer">{{ $t(welcome.client.persist.note.link.labelKey) }}<WizardIcon name="external" /></a>
      {{ $t(welcome.client.persist.note.afterKey) }}
    </p>

    <div class="mm-wiz-status" v-if="status" :class="status.kind" role="status" aria-live="polite">
      <WizardIcon :name="status.icon" />
      <div>
        <strong>{{ status.title }}</strong>
        <p>{{ status.body }}</p>
      </div>
    </div>
    <p class="mm-wiz-guide-wait" v-else aria-live="polite">
      <WizardIcon name="clock" />{{ welcome.promptCopied ? $t('welcome.ask.wait') : $t('welcome.ask.paste_first') }}
    </p>

    <p class="mm-wiz-review-reminder"><WizardIcon name="shield" />{{ $t(welcome.curator ? 'welcome.review_reminder_curator' : 'welcome.review_reminder') }}</p>
  </section>
</template>
