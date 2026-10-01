<script setup lang="ts">
// One numbered instruction of a vendor's guide, with the dots that reach the
// others. The wizard and Settings › Connections both show instructions this
// way; only where the cursor lives differs.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { logoFor, mcpAddress, type ConnectGuide } from '@/components/settings/connectGuides'
import GuideRich from '@/components/settings/GuideRich.vue'
import GuideThumbnails from '@/components/settings/GuideThumbnails.vue'
import WizardIcon from '@/components/welcome/WizardIcon.vue'
import { useTheme } from '@/lib/theme'
import { copyText } from '@/lib/clipboard'
import { toastError, toastSuccess } from '@/components/toastService'

const props = defineProps<{ client: ConnectGuide; at: number; curator: boolean }>()
const emit = defineEmits<{ show: [at: number] }>()
const { t } = useI18n()
const { theme } = useTheme()
const address = mcpAddress()

const instruction = computed(() => props.client.instructions[props.at] ?? null)
const copied = ref(false)

async function copyAddress() {
  if (await copyText(address)) {
    copied.value = true
    toastSuccess(t('welcome.connect.address_copied'))
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('connections.manual.copy_address_failed'), t('connections.copy_by_hand'))
  }
}

// The review sentences assume the agent role. A knowledge base with a live
// curator connection gets the sentence that is true of it instead.
const CURATOR_TIPS: Record<string, string> = {
  'connections.guides.review_tip': 'connections.guides.review_tip_curator',
  'connections.guides.permissions_tip': 'connections.guides.permissions_tip_curator',
}
const tipKey = computed(() => {
  const key = instruction.value?.tipKey
  if (key === undefined) return undefined
  return props.curator ? (CURATOR_TIPS[key] ?? key) : key
})
</script>

<template>
  <template v-if="instruction">
    <div class="mm-wiz-guide-titlebar">
      <span class="mm-wiz-guide-kicker">
        {{ $t('welcome.connect.in_provider', { provider: client.label }) }}
        <span aria-hidden="true">/</span>
        {{ $t('welcome.connect.position', { at: at + 1, total: client.instructions.length }) }}
        <span class="mm-wiz-optional-badge" v-if="instruction.optional">{{ $t('connections.guides.optional') }}</span>
      </span>
      <div class="mm-wiz-guide-dots" role="group" :aria-label="$t('welcome.connect.instructions')">
        <button v-for="(ins, i) in client.instructions" :key="i" type="button" class="mm-wiz-guide-dot"
                :aria-current="i === at ? 'step' : undefined"
                :aria-label="$t('welcome.connect.instruction_n', { n: i + 1, title: $t(ins.titleKey) })"
                @click="emit('show', i)">{{ i + 1 }}</button>
      </div>
    </div>

    <div class="mm-wiz-guide-content" :class="{ 'is-optional': instruction.optional }">
      <div>
        <h2>{{ $t(instruction.titleKey) }}</h2>
        <p class="mm-wiz-guide-copy"><GuideRich :text="$t(instruction.bodyKey)" /></p>
        <p class="mm-wiz-guide-copy" v-if="instruction.instructionKey">
          <GuideRich :text="$t(instruction.instructionKey)" />
        </p>
        <div class="mm-wiz-address" v-if="instruction.address">
          <code>{{ address }}</code>
          <button type="button" class="mm-wiz-copy-button"
                  :aria-label="copied ? $t('common.copied') : $t('connections.manual.copy_address')"
                  @click="copyAddress">
            <WizardIcon :name="copied ? 'check' : 'copy'" /><span>{{ copied ? $t('common.copied') : $t('common.copy') }}</span>
          </button>
        </div>
        <p class="mm-wiz-guide-copy" v-if="instruction.afterAddressKey">
          <GuideRich :text="$t(instruction.afterAddressKey)" />
        </p>
        <p class="mm-wiz-guide-tip" v-if="tipKey">
          <WizardIcon name="info" /><span>{{ $t(tipKey) }}</span>
        </p>
        <a v-if="instruction.link" class="mm-wiz-text-button" :href="instruction.link.url"
           target="_blank" rel="noopener noreferrer">
          {{ $t(instruction.link.labelKey) }}<WizardIcon name="external" />
          <span class="visually-hidden"> {{ $t('welcome.opens_new_tab') }}</span>
        </a>
      </div>
      <div>
        <GuideThumbnails :images="instruction.images" :logo="logoFor(client, theme)" :provider-name="client.label" />
      </div>
    </div>
  </template>
</template>
