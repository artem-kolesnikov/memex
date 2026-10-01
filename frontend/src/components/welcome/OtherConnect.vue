<script setup lang="ts">
// Other: an agent that speaks Authorization headers and nothing else. The
// token is minted at agent role and shown once, in the same dialog Settings
// uses; the server keeps only a hash. The minting and the result live in
// the store, so switching provider while the request is out cannot lose
// the only copy of the secret.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { toastError, toastSuccess } from '@/components/toastService'
import { SETUPS, mcpAddress } from '@/components/settings/connectGuides'
import { editions } from '@/editions'
import { useWelcomeStore } from '@/stores/welcome'
import { copyText } from '@/lib/clipboard'
import WizardIcon from './WizardIcon.vue'

const { t } = useI18n()
const welcome = useWelcomeStore()
const address = mcpAddress()
const setups = [...SETUPS, ...editions.flatMap((edition) => edition.assistantSetups ?? [])]

const name = ref('')
const copied = ref(false)
const setupId = ref<string | null>(null)
const setup = computed(() => setups.find((s) => s.id === setupId.value) ?? null)
const setupCopied = ref(false)

async function generateToken() {
  const label = name.value.trim()
  if (label === '' || welcome.minting) return
  try {
    await welcome.mint(label)
    name.value = ''
  } catch (e) {
    toastError(t('connections.manual.create_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

async function copyAddress() {
  if (await copyText(address)) {
    copied.value = true
    toastSuccess(t('welcome.connect.address_copied'))
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('connections.manual.copy_address_failed'), t('connections.copy_by_hand'))
  }
}

async function copySetup() {
  if (setup.value === null) return
  if (await copyText(setup.value.text(address))) {
    setupCopied.value = true
    window.setTimeout(() => (setupCopied.value = false), 2000)
  } else {
    toastError(t('welcome.connect.setup_copy_failed'), t('connections.copy_by_hand'))
  }
}
</script>

<template>
  <div class="mm-wiz-custom">
    <div>
      <h2>{{ $t('welcome.connect.other_create') }}</h2>
      <p>{{ $t('welcome.connect.other_create_body') }}</p>
      <div class="mm-wiz-field">
        <label for="wiz-agent-name">{{ $t('welcome.connect.other_name') }}</label>
        <input id="wiz-agent-name" type="text" maxlength="120" v-model="name" :disabled="welcome.minting"
               :placeholder="$t('welcome.connect.other_name_placeholder')" @keyup.enter="generateToken">
      </div>
      <button type="button" class="mm-wiz-button is-secondary" :disabled="welcome.minting || name.trim() === ''"
              @click="generateToken">
        {{ welcome.minting ? $t('connections.manual.creating') : $t('connections.manual.create') }}
      </button>
    </div>
    <div>
      <h2>{{ $t('welcome.connect.other_add') }}</h2>
      <p>{{ $t('welcome.connect.other_add_body') }}</p>
      <div class="mm-wiz-address">
        <code>{{ address }}</code>
        <button type="button" class="mm-wiz-copy-button"
                :aria-label="copied ? $t('common.copied') : $t('connections.manual.copy_address')" @click="copyAddress">
          <WizardIcon :name="copied ? 'check' : 'copy'" /><span>{{ copied ? $t('common.copied') : $t('common.copy') }}</span>
        </button>
      </div>
      <i18n-t keypath="connections.manual.header_sentence" tag="p" scope="global">
        <template #header><br><code>{{ $t('connections.manual.header_value') }}</code></template>
      </i18n-t>

      <p class="mm-wiz-setups-intro">{{ $t('welcome.connect.setups') }}</p>
      <div class="mm-wiz-setups" role="group" :aria-label="$t('welcome.connect.setups')">
        <button v-for="s in setups" :key="s.id" type="button" class="mm-wiz-setup"
                :aria-pressed="setupId === s.id" @click="setupId = setupId === s.id ? null : s.id">
          {{ s.label }}
        </button>
      </div>
      <template v-if="setup">
        <i18n-t v-if="setup.file" keypath="welcome.connect.setup_file" tag="p" class="mm-wiz-setup-where" scope="global">
          <template #file><code>{{ setup.file }}</code></template>
        </i18n-t>
        <p v-else class="mm-wiz-setup-where">{{ $t('welcome.connect.setup_terminal') }}</p>
        <div class="mm-wiz-snippet">
          <pre><code>{{ setup.text(address) }}</code></pre>
          <button type="button" class="mm-wiz-copy-button"
                  :aria-label="setupCopied ? $t('common.copied') : $t('common.copy')" @click="copySetup">
            <WizardIcon :name="setupCopied ? 'check' : 'copy'" /><span>{{ setupCopied ? $t('common.copied') : $t('common.copy') }}</span>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
