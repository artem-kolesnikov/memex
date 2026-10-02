<script setup lang="ts">
// Other: an agent that speaks Authorization headers and nothing else. The
// token is minted at agent role and shown once; the server keeps only a hash.
// The minting and the result live in a store, so switching to another
// assistant while the request is out cannot lose the only copy of the secret.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { toastError, toastSuccess } from '@/components/toastService'
import { SETUPS, mcpAddress } from '@/components/settings/connectGuides'
import { editions } from '@/editions'
import { useTokenMintStore } from '@/stores/tokenMint'
import { copyText } from '@/lib/clipboard'
import WizardIcon from '@/components/welcome/WizardIcon.vue'

const { t } = useI18n()
const mint = useTokenMintStore()
const address = mcpAddress()
const setups = [...SETUPS, ...editions.flatMap((edition) => edition.assistantSetups ?? [])]

const name = ref('')
const copied = ref(false)
const setupId = ref<string | null>(null)
const setup = computed(() => setups.find((s) => s.id === setupId.value) ?? null)
const setupCopied = ref(false)

async function generateToken() {
  const label = name.value.trim()
  if (label === '' || mint.minting) return
  try {
    await mint.mint(label)
    name.value = ''
  } catch (e) {
    toastError(t('connections.manual.create_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

async function copyAddress() {
  if (await copyText(address)) {
    copied.value = true
    toastSuccess(t('connections.connect.address_copied'))
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
    toastError(t('connections.connect.setup_copy_failed'), t('connections.copy_by_hand'))
  }
}
</script>

<template>
  <ol class="mm-connect-steps">
    <li>
      <div class="mm-connect-step-head"><h4>{{ $t('connections.connect.other_create') }}</h4></div>
      <p>{{ $t('connections.connect.other_create_body') }}</p>
      <div class="mm-connect-token">
        <label class="visually-hidden" for="connect-agent-name">{{ $t('connections.connect.other_name') }}</label>
        <input id="connect-agent-name" class="form-control form-control-sm" type="text" maxlength="120" v-model="name"
               :disabled="mint.minting" :placeholder="$t('connections.connect.other_name_placeholder')" @keyup.enter="generateToken">
        <button type="button" class="btn btn-sm btn-outline-primary" :disabled="mint.minting || name.trim() === ''"
                @click="generateToken">
          {{ mint.minting ? $t('connections.manual.creating') : $t('connections.manual.create') }}
        </button>
      </div>
    </li>
    <li>
      <div class="mm-connect-step-head"><h4>{{ $t('connections.connect.other_add') }}</h4></div>
      <p>{{ $t('connections.connect.other_add_body') }}</p>
      <div class="mm-wiz-address mm-connect-copy">
        <code>{{ address }}</code>
        <button type="button" class="mm-wiz-copy-button"
                :aria-label="copied ? $t('common.copied') : $t('connections.manual.copy_address')"
                :title="copied ? $t('common.copied') : $t('connections.manual.copy_address')" @click="copyAddress">
          <WizardIcon :name="copied ? 'check' : 'copy'" />
        </button>
      </div>
      <i18n-t keypath="connections.manual.header_sentence" tag="p" scope="global">
        <template #header><code>{{ $t('connections.manual.header_value') }}</code></template>
      </i18n-t>
      <p class="mm-connect-setups-intro">{{ $t('connections.connect.setups') }}</p>
      <div class="mm-wiz-setups" role="group" :aria-label="$t('connections.connect.setups')">
        <button v-for="s in setups" :key="s.id" type="button" class="mm-wiz-setup"
                :aria-pressed="setupId === s.id" @click="setupId = setupId === s.id ? null : s.id">
          {{ s.label }}
        </button>
      </div>
      <template v-if="setup">
        <i18n-t v-if="setup.file" keypath="connections.connect.setup_file" tag="p" scope="global">
          <template #file><code>{{ setup.file }}</code></template>
        </i18n-t>
        <p v-else>{{ $t('connections.connect.setup_terminal') }}</p>
        <div class="mm-wiz-snippet mm-connect-copy">
          <pre><code>{{ setup.text(address) }}</code></pre>
          <button type="button" class="mm-wiz-copy-button"
                  :aria-label="setupCopied ? $t('common.copied') : $t('common.copy')"
                  :title="setupCopied ? $t('common.copied') : $t('common.copy')" @click="copySetup">
            <WizardIcon :name="setupCopied ? 'check' : 'copy'" />
          </button>
        </div>
      </template>
    </li>
  </ol>
</template>
