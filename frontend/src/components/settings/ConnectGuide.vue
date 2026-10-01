<script setup lang="ts">
// Settings › Connections: the same tabs and one-instruction-at-a-time
// switcher as the first-run wizard, ending on the prompt to paste. The
// cursor is this pane's own; the wizard keeps its own in the welcome store.
import { computed, ref } from 'vue'
import { CHATGPT, CLIENTS, OTHER } from '@/components/settings/connectGuides'
import { useAuthStore } from '@/stores/auth'
import ProviderTabs from '@/components/settings/ProviderTabs.vue'
import GuideInstruction from '@/components/settings/GuideInstruction.vue'
import PromptBox from '@/components/settings/PromptBox.vue'
import OtherConnect from '@/components/welcome/OtherConnect.vue'
import WizardIcon from '@/components/welcome/WizardIcon.vue'

defineProps<{ curator: boolean }>()
/** ChatGPT, Claude and Gemini only where they can reach this memex; the server says. */
const web = computed(() => useAuthStore().user?.web_assistants !== false)
const provider = ref<string>(web.value ? CHATGPT.id : OTHER)
const at = ref<Record<string, number>>(Object.fromEntries(CLIENTS.map((c) => [c.id, 0])))

const client = computed(() => CLIENTS.find((c) => c.id === provider.value) ?? null)
const position = computed(() => at.value[provider.value] ?? 0)
// The prompt is the last position, after the vendor's own instructions.
const last = computed(() => client.value?.instructions.length ?? 0)
const onPrompt = computed(() => client.value !== null && position.value >= last.value)

function show(i: number) {
  at.value = { ...at.value, [provider.value]: Math.max(0, Math.min(last.value, i)) }
}
</script>

<template>
  <div class="mm-wiz-scope mm-connect-guide">
    <ProviderTabs v-if="web" v-model="provider" />

    <OtherConnect v-if="provider === OTHER" />

    <template v-else-if="client">
      <template v-if="onPrompt">
        <div class="mm-wiz-guide-titlebar">
          <span class="mm-wiz-guide-kicker">
            {{ $t('welcome.connect.in_provider', { provider: client.label }) }}
            <span aria-hidden="true">/</span>
            {{ $t('connections.guides.paste_prompt') }}
          </span>
        </div>
        <PromptBox :client="client" />
        <p class="mm-wiz-prompt-note" v-if="client.persist.note">
          {{ $t(client.persist.note.beforeKey) }}
          <a class="mm-wiz-inline-link" :href="client.persist.note.link.url" target="_blank" rel="noopener noreferrer">{{ $t(client.persist.note.link.labelKey) }}<WizardIcon name="external" /></a>
          {{ $t(client.persist.note.afterKey) }}
        </p>
      </template>
      <GuideInstruction v-else :client="client" :at="position" :curator="curator" @show="show" />

      <div class="mm-wiz-guide-nav">
        <button type="button" class="mm-wiz-text-button is-back" v-if="position > 0" @click="show(position - 1)">
          <WizardIcon name="back" />{{ $t('common.back') }}
        </button>
        <button type="button" class="mm-wiz-button" v-if="!onPrompt" @click="show(position + 1)">
          {{ $t(position === last - 1 ? 'connections.guides.to_prompt' : client.instructions[position]?.nextKey ?? 'common.next') }}<WizardIcon name="arrow" />
        </button>
      </div>
    </template>
  </div>
</template>
