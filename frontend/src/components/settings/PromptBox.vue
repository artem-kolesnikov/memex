<script setup lang="ts">
// The prompt a person pastes into a new chat, with one copy control in its
// heading. Copying is a convenience and proves nothing; the caller decides
// what a copy means.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { initPrompt } from '@/components/settings/initPrompt'
import type { ConnectGuide } from '@/components/settings/connectGuides'
import WizardIcon from '@/components/welcome/WizardIcon.vue'
import { copyText } from '@/lib/clipboard'
import { toastError, toastSuccess } from '@/components/toastService'

const props = defineProps<{ client: ConnectGuide | null }>()
const emit = defineEmits<{ copied: [ok: boolean] }>()
const { t } = useI18n()

const prompt = computed(() => initPrompt(props.client))
const providerName = computed(() => props.client?.label ?? t('welcome.your_assistant'))
const copied = ref(false)

async function copy() {
  const ok = await copyText(prompt.value)
  if (ok) {
    copied.value = true
    toastSuccess(t('welcome.ask.copied'), t('welcome.ask.copied_body'))
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('welcome.copy_failed'), t('connections.copy_by_hand'))
  }
  emit('copied', ok)
}
</script>

<template>
  <div class="mm-wiz-prompt">
    <div class="mm-wiz-prompt-heading">
      <span><WizardIcon name="chat" />{{ $t('welcome.ask.prompt_label') }}</span>
      <span>
        {{ $t('welcome.ask.for_provider', { provider: providerName }) }}
        <button type="button" class="mm-wiz-copy-button"
                :aria-label="copied ? $t('common.copied') : $t('welcome.ask.copy')" @click="copy">
          <WizardIcon :name="copied ? 'check' : 'copy'" /><span>{{ copied ? $t('common.copied') : $t('common.copy') }}</span>
        </button>
      </span>
    </div>
    <pre class="mm-wiz-prompt-text">{{ prompt }}</pre>
  </div>
</template>
