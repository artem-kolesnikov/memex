<!--
  Adding one provider key.

  ## Why a dialog, and why a name

  It was a select and a password box sitting open at the bottom of the pane,
  which is fine for the one key a team was allowed to hold. A team can hold as
  many as it likes now, so this is an action you take rather than a form that
  is always there — and every key needs a name, because two OpenAI keys are
  two accounts and two bills, and a menu that says "OpenAI" twice is not
  offering a choice.

  ## Save and verify are one button

  memex calls the provider with the key before storing it, so a key on the
  screen is a key that has answered at least once. A rejected key is not
  saved and the dialog stays open with the provider's own words in it — "no
  credit on this account" and "that key is wrong" need different fixes, and
  only the provider knows which happened.
-->
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type AiProvider } from '@/api/client'
import { toastSuccess } from '@/components/toastService'
import PasswordField from '@/components/PasswordField.vue'

const props = defineProps<{
  providers: AiProvider[]
  /** The section this dialog was opened from. It is what adopts the key —
   *  keying off the provider instead switched descriptions on for a key added
   *  under Semantic search. */
  section?: 'embed' | 'fetch' | 'text'
}>()
const emit = defineEmits<{ added: [settings: Awaited<ReturnType<typeof api.addAiKey>>]; close: [] }>()

const { t } = useI18n()

const name = ref('')
// A section that sells one vendor's keys does not ask which vendor: the dialog
// is opened from inside Fetching or Embeddings, where the answer is already
// known, and a select with one option is a question with one answer.
const provider = ref(props.providers.length === 1 ? (props.providers[0]?.id ?? '') : '')
const secret = ref('')
const error = ref<string | null>(null)
const busy = ref(false)
const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

const chosen = computed(() => props.providers.find((p) => p.id === provider.value) ?? null)
const ready = computed(() => name.value.trim() !== '' && provider.value !== '' && secret.value.trim() !== '')

onMounted(() => {
  if (dialog.value === null) return
  modal = new window.bootstrap.Modal(dialog.value)
  dialog.value.addEventListener('hidden.bs.modal', () => emit('close'))
  modal.show()
})
// `hide()` alone is not a teardown. Bootstrap returns from it immediately
// while the dialog is still transitioning, so unmounting mid-fade leaves its
// deferred show callback to put the element back on <body> — a dialog with no
// component behind it, over a backdrop and a scroll lock nothing will clear.
// `dispose()` drops the instance, its backdrop and its handlers either way.
onBeforeUnmount(() => {
  modal?.hide()
  modal?.dispose()
  modal = null
})

function cancel() {
  modal?.hide()
}

async function save() {
  if (!ready.value) return
  busy.value = true
  error.value = null
  try {
    const settings = await api.addAiKey(provider.value, name.value.trim(), secret.value.trim(), props.section)
    toastSuccess(
      t('automation.provider_dialog.key_verified'),
      t('automation.provider_dialog.key_ready', { name: name.value.trim() }),
    )
    emit('added', settings)
    modal?.hide()
  } catch (e) {
    // The provider's own words, kept in the dialog rather than sent to a
    // toast: the fix is in this form, so the message belongs beside it.
    error.value = e instanceof Error ? e.message : t('common.unknown_error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="add-provider-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title h6 mb-0" id="add-provider-title">{{ $t('automation.add_provider') }}</h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="cancel"></button>
          </div>

          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label" for="key-name">{{ $t('automation.provider_dialog.displayed_name') }}</label>
              <input type="text" id="key-name" class="form-control" maxlength="60"
                     :placeholder="$t('automation.provider_dialog.name_placeholder')" v-model="name" :disabled="busy">
            </div>

            <div class="mb-3">
              <label class="form-label" for="key-provider" v-if="providers.length > 1">{{ $t('automation.provider') }}</label>
              <select class="form-select" id="key-provider" v-model="provider" :disabled="busy"
                      v-if="providers.length > 1">
                <option value="">{{ $t('automation.provider_dialog.choose_provider') }}</option>
                <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.label }}</option>
              </select>
              <!-- Where to get one, from the provider that issues it. Shown
                   only for the provider being added: a page of "how to get an
                   Anthropic key" under a team adding an OpenAI one is noise. -->
              <p class="mm-note mb-0" v-if="chosen">
                <a :href="chosen.key_url" target="_blank" rel="noopener">{{ chosen.key_url }}</a><br>
                {{ chosen.key_steps }}
              </p>
            </div>

            <div class="mb-2">
              <label class="form-label" for="key-secret">{{ $t('automation.provider_dialog.api_key') }}</label>
              <PasswordField id="key-secret" autocomplete="off"
                             :placeholder="chosen ? chosen.key_prefix + '...' : $t('automation.choose_provider_first')"
                             v-model="secret" :disabled="busy || !provider" @keyup.enter="save" />
            </div>
            <div v-if="error" class="small text-danger">{{ error }}</div>
            <p class="mm-note mb-0">
              {{ $t('automation.provider_dialog.stored_note', { provider: chosen?.label ?? $t('automation.provider_dialog.the_provider') }) }}
            </p>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="busy" @click="cancel">
              {{ $t('common.cancel') }}
            </button>
            <button type="button" class="btn btn-primary btn-sm" :disabled="busy || !ready" @click="save">
              {{ busy ? $t('automation.provider_dialog.checking') : $t('automation.provider_dialog.save_and_verify') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
