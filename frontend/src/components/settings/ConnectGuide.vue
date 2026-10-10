<script setup lang="ts">
// Settings › Assistants: four tabs, and under each assistant its steps as one
// numbered list, the prompt to paste as the last of them. Nothing to press
// between steps: the list is read and done, and the table below shows the
// connection once it arrives.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CHATGPT, CLIENTS, OTHER, mcpAddress } from '@/components/settings/connectGuides'
import { useAuthStore } from '@/stores/auth'
import { copyText } from '@/lib/clipboard'
import { toastError, toastSuccess } from '@/components/toastService'
import ProviderTabs from '@/components/settings/ProviderTabs.vue'
import GuideRich from '@/components/settings/GuideRich.vue'
import PromptBox from '@/components/settings/PromptBox.vue'
import OtherConnect from '@/components/settings/OtherConnect.vue'
import WizardIcon from '@/components/welcome/WizardIcon.vue'

const props = defineProps<{ curator: boolean }>()
const { t } = useI18n()
/** ChatGPT, Claude and Gemini only where they can reach this memex; the server says. */
const web = computed(() => useAuthStore().user?.web_assistants !== false)
const provider = ref<string>(web.value ? CHATGPT.id : OTHER)
const client = computed(() => CLIENTS.find((c) => c.id === provider.value) ?? null)
const address = mcpAddress()
const copied = ref(false)

async function copyAddress() {
  if (await copyText(address)) {
    copied.value = true
    toastSuccess(t('connections.connect.address_copied'))
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('connections.manual.copy_address_failed'), t('connections.copy_by_hand'))
  }
}

// The review sentences assume the agent role. A knowledge base with a live
// curator connection gets the sentence that is true of it instead.
const CURATOR_TIPS: Record<string, string> = {
  'connections.guides.review_tip': 'connections.guides.review_tip_curator',
}
const tip = (key: string) => (props.curator ? (CURATOR_TIPS[key] ?? key) : key)
</script>

<template>
  <div class="mm-wiz-scope mm-connect-guide">
    <ProviderTabs v-if="web" v-model="provider" />

    <OtherConnect v-if="provider === OTHER" />

    <template v-else-if="client">
      <ol class="mm-connect-steps">
        <li v-for="step in client.steps" :key="step.titleKey">
          <div class="mm-connect-step-head">
            <h4>{{ $t(step.titleKey) }}</h4>
            <a v-if="step.link" class="mm-wiz-text-button" :href="step.link.url" target="_blank" rel="noopener noreferrer">
              {{ $t(step.link.labelKey) }}<WizardIcon name="external" />
              <span class="visually-hidden"> {{ $t('common.opens_new_tab') }}</span>
            </a>
          </div>
          <a v-if="step.action" class="mm-wiz-button mm-connect-action" :href="step.action.href(address)" target="_blank" rel="noopener noreferrer">
            {{ $t(step.action.labelKey) }}<WizardIcon name="external" />
            <span class="visually-hidden"> {{ $t('common.opens_new_tab') }}</span>
          </a>
          <p><GuideRich :text="$t(step.bodyKey)" /></p>
          <p class="mm-connect-tip" v-if="step.tipKey">{{ $t(tip(step.tipKey)) }}</p>
          <p class="mm-connect-warn" v-if="step.warnKey"><WizardIcon name="alert" />{{ $t(step.warnKey) }}</p>
          <details class="mm-connect-manual" v-if="step.manual">
            <summary>{{ $t(step.manual.summaryKey) }}</summary>
            <div class="mm-connect-manual-body">
              <a v-if="step.manual.link" class="mm-wiz-text-button" :href="step.manual.link.url" target="_blank" rel="noopener noreferrer">
                {{ $t(step.manual.link.labelKey) }}<WizardIcon name="external" />
                <span class="visually-hidden"> {{ $t('common.opens_new_tab') }}</span>
              </a>
              <ol>
                <li v-for="key in step.manual.lineKeys" :key="key"><GuideRich :text="$t(key)" /></li>
              </ol>
              <div class="mm-wiz-address" v-if="step.manual.address">
              <code>{{ address }}</code>
              <button type="button" class="mm-wiz-copy-button"
                      :aria-label="copied ? $t('common.copied') : $t('connections.manual.copy_address')"
                      :title="copied ? $t('common.copied') : $t('connections.manual.copy_address')"
                      @click="copyAddress">
                <WizardIcon :name="copied ? 'check' : 'copy'" />
              </button>
              </div>
            </div>
          </details>
          <div class="mm-wiz-address mm-connect-copy" v-if="step.address">
            <code>{{ address }}</code>
            <button type="button" class="mm-wiz-copy-button"
                    :aria-label="copied ? $t('common.copied') : $t('connections.manual.copy_address')"
                    :title="copied ? $t('common.copied') : $t('connections.manual.copy_address')"
                    @click="copyAddress">
              <WizardIcon :name="copied ? 'check' : 'copy'" />
            </button>
          </div>
        </li>
        <li>
          <div class="mm-connect-step-head"><h4>{{ $t('connections.guides.paste_prompt') }}</h4></div>
          <PromptBox class="mm-connect-copy" :client="client" />
          <p class="mm-connect-tip mm-connect-copy" v-if="client.persist.note">
            {{ $t(client.persist.note.beforeKey) }}
            <a class="mm-wiz-inline-link" :href="client.persist.note.link.url" target="_blank" rel="noopener noreferrer">{{ $t(client.persist.note.link.labelKey) }}<WizardIcon name="external" /></a>
            {{ $t(client.persist.note.afterKey) }}
          </p>
        </li>
      </ol>
      <p class="mm-connect-optional" v-if="client.permissionsKey">
        <strong>{{ $t('connections.guides.optional') }}</strong> <GuideRich :text="$t(client.permissionsKey)" />
        {{ $t(tip('connections.guides.permissions_tip')) }}
      </p>
    </template>
  </div>
</template>
