<script setup lang="ts">
// One automation role: whether it runs, on whose key, and with which model.
//
// Enrichment is the only role, and this stayed a component rather than folding
// into the pane because the four decisions it renders are the shape of a role
// rather than of enrichment. It carried a cadence control and an "available"
// state until 2026-08-27, both for curation, which was presented here as a
// second role that nothing performed; the operator ruled on 2026-08-25 that no
// runner is coming, so those halves went with the role.
//
// The switch sits directly under the description and the rest of the block is
// greyed out when it is off (operator, 2026-08-23, placement revised
// 2026-08-29). That is not decoration: the question "is memex doing this for
// me" is the first one anybody has, and it used to be answered by a checkbox
// three paragraphs down that read "Let memex do this on its own".
//
// Changes are still a DRAFT until Save, because choosing a model is choosing a
// price and a control that saved on every keystroke of a dropdown made that
// invisible. The switch is part of the draft too — turning something on is the
// most consequential control here, and it should not be the only one that
// commits itself.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type AiKey, type AiRole, type AiRolePatch } from '@/api/client'

const props = defineProps<{
  role: AiRole
  keys: AiKey[]
  name: string
  guidance: string
  saving: boolean
}>()

const emit = defineEmits<{ save: [patch: AiRolePatch] }>()

const { t } = useI18n()

const draft = ref({
  enabled: props.role.enabled,
  credentialId: props.role.credential_id ?? 0,
  model: props.role.model ?? '',
})

// A save returns the stored state, and that is what the draft becomes. Without
// this, a rejected save would leave the form showing a state the server refused.
watch(
  () => props.role,
  (role) => {
    draft.value = {
      enabled: role.enabled,
      credentialId: role.credential_id ?? 0,
      model: role.model ?? '',
    }
  },
  { deep: true },
)

// Verified as well as readable: a key is verified against the provider when it
// is added, and the switch below appears only once one of them can actually run
// this (operator, 2026-09-01).
const usable = computed(() => props.keys.filter((k) => k.readable !== false && k.verified_at))
const chosen = computed(() => props.keys.find((k) => k.id === draft.value.credentialId) ?? null)

const models = ref<{ id: string; label: string; }[]>([])
const defaultModel = ref('')
const modelsLoading = ref(false)
const modelsError = ref<string | null>(null)

async function loadModels(credentialId: number) {
  models.value = []
  modelsError.value = null
  if (!credentialId) return
  modelsLoading.value = true
  try {
    const answer = await api.aiModels(credentialId)
    models.value = answer.models
    defaultModel.value = answer.default_model
  } catch (e) {
    modelsError.value = e instanceof Error ? e.message : t('common.unknown_error')
  } finally {
    modelsLoading.value = false
  }
}
watch(() => draft.value.credentialId, loadModels, { immediate: true })

function onKeyChange(event: Event) {
  const id = Number((event.target as HTMLSelectElement).value)
  // A model id belongs to the PROVIDER that listed it, so it survives a switch
  // between two keys of the same provider — which is the case named keys were
  // added for — and cannot survive a switch to a different provider.
  if (props.keys.find((k) => k.id === id)?.provider !== chosen.value?.provider) {
    draft.value.model = ''
  }
  draft.value.credentialId = id
}

const dirty = computed(
  () =>
    draft.value.enabled !== props.role.enabled ||
    draft.value.credentialId !== (props.role.credential_id ?? 0) ||
    draft.value.model !== (props.role.model ?? ''),
)

// The server refuses this combination, and saying so before the press is
// kinder than a red toast afterwards.
const blocked = computed(() => draft.value.enabled && draft.value.credentialId === 0)

function submit() {
  emit('save', {
    enabled: draft.value.enabled,
    credential_id: draft.value.credentialId,
    model: draft.value.model,
  })
}
</script>

<template>
  <div class="mm-role" :class="{ 'is-disabled': !draft.enabled }">
    <div class="form-check form-switch mm-role-switch" v-if="usable.length">
      <input class="form-check-input" type="checkbox" role="switch" :id="name + '-switch'"
             v-model="draft.enabled" :disabled="saving">
      <label class="form-check-label" :for="name + '-switch'">{{ $t('automation.role.enable') }}</label>
    </div>

    <p class="mm-step-wait" v-if="!usable.length">
      {{ $t('automation.role.add_provider_first') }}
    </p>

    <!-- Everything below is inert while the role is off. Kept on the page
         rather than hidden: what a role WOULD run on is the thing you want to
         see before turning it on. -->
    <fieldset v-else :disabled="!draft.enabled || saving">
      <div class="mm-settings-grid mb-2">
        <div class="mm-settings-field">
          <label class="form-label" :for="name + '-key'">{{ $t('automation.role.key') }}</label>
          <select class="form-select" :id="name + '-key'" :value="draft.credentialId"
                  @change="onKeyChange">
            <option :value="0">{{ $t('automation.role.not_chosen') }}</option>
            <option v-for="k in usable" :key="k.id" :value="k.id">
              {{ k.name }} · {{ k.provider_label }}
            </option>
          </select>
        </div>
        <div class="mm-settings-field">
          <label class="form-label" :for="name + '-model'">{{ $t('automation.role.model') }}</label>
          <select class="form-select" :id="name + '-model'" v-model="draft.model"
                  :disabled="modelsLoading || !draft.credentialId">
            <!-- Three states, not two. A key IS chosen and its model list did
                 not load — a key the provider rejects, most often — used to
                 read "Choose a provider first", which is advice to do the
                 thing you have already done. -->
            <option value="">
              <template v-if="!draft.credentialId">{{ $t('automation.choose_provider_first') }}</template>
              <template v-else-if="defaultModel">{{ $t('automation.role.default_named', { model: defaultModel }) }}</template>
              <template v-else>{{ $t('automation.role.default') }}</template>
            </option>
            <option v-for="m in models" :key="m.id" :value="m.id">{{ m.label }}</option>
          </select>
        </div>
      </div>

      <p class="mm-note">
        <span v-if="modelsLoading">{{ $t('automation.role.asking_models', { provider: chosen?.provider_label }) }}</span>
        <span v-else-if="modelsError" class="text-danger">{{ modelsError }}</span>
        <span v-else>{{ guidance }}</span>
      </p>
    </fieldset>

    <!-- Shown only when there is something to save, so a permanently disabled
         button does not sit under the form suggesting the toggle above it did
         not take. Blocked is the one exception: the press is refused and the
         reason is beside it, which is more useful than no button at all. -->
    <div class="d-flex align-items-center gap-2" v-if="dirty">
      <button class="btn btn-sm btn-primary" :disabled="saving || blocked" @click="submit">
        {{ saving ? $t('automation.role.saving') : $t('common.save') }}
      </button>
      <span class="small text-muted" v-if="blocked">{{ $t('automation.role.choose_key_first') }}</span>
      <span class="small text-muted" v-else>{{ $t('automation.role.not_saved_yet') }}</span>
    </div>

    <slot name="footer"></slot>
  </div>
</template>
