<script setup lang="ts">
// The prompt a person pastes into a new chat, with its copy control beside it.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { initPrompt } from '@/components/settings/initPrompt'
import type { ConnectGuide } from '@/components/settings/connectGuides'
import WizardIcon from '@/components/welcome/WizardIcon.vue'
import { copyText } from '@/lib/clipboard'
import { toastError, toastSuccess } from '@/components/toastService'

const props = defineProps<{ client: ConnectGuide }>()
const { t } = useI18n()

const prompt = computed(() => initPrompt(props.client))
const copied = ref(false)

async function copy() {
  if (await copyText(prompt.value)) {
    copied.value = true
    toastSuccess(t('connections.prompt.copied'), t('connections.prompt.copied_body'))
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('common.copy_failed'), t('connections.copy_by_hand'))
  }
}
</script>

<template>
  <div class="mm-wiz-snippet mm-connect-prompt">
    <pre>{{ prompt }}</pre>
    <button type="button" class="mm-wiz-copy-button"
            :aria-label="copied ? $t('common.copied') : $t('connections.prompt.copy')"
            :title="copied ? $t('common.copied') : $t('connections.prompt.copy')" @click="copy">
      <WizardIcon :name="copied ? 'check' : 'copy'" />
    </button>
  </div>
</template>
