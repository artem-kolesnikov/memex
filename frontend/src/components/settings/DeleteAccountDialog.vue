<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { toastError } from '@/components/toastService'

const props = defineProps<{ notes: number }>()
const emit = defineEmits<{ close: [] }>()

const auth = useAuthStore()
const { t } = useI18n()
const typed = ref('')
const busy = ref(false)
const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

// The address has to be typed, and this is the whole check: a control that
// destroys a knowledge base should not be reachable by a stray click.
const confirmed = computed(
  () => typed.value.trim().toLowerCase() === (auth.user?.email ?? '').toLowerCase(),
)

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

function close() {
  modal?.hide()
}

async function confirm() {
  if (!confirmed.value) return
  busy.value = true
  try {
    await auth.deleteAccount(typed.value.trim())
    // The session is gone server-side; a full load rather than a route push,
    // so nothing in the SPA is left holding an account that no longer exists.
    window.location.href = '/login'
  } catch (e) {
    toastError(t('account.delete.not_deleted'), e instanceof Error ? e.message : t('common.unknown_error'))
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="delete-account-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title h6 mb-0" id="delete-account-title">
              {{ $t('account.delete.question') }}
            </h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="close"></button>
          </div>

          <form @submit.prevent="confirm">
            <div class="modal-body">
              <p>{{ $t('account.delete.warning') }}</p>
              <label for="delete-confirm" class="form-label">{{ $t('account.delete.type_email') }}</label>
              <input type="text" id="delete-confirm" class="form-control" autocomplete="off"
                     :placeholder="auth.user?.email" v-model="typed" :disabled="busy">
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="busy"
                      @click="close">{{ $t('common.cancel') }}</button>
              <button type="submit" class="btn btn-danger btn-sm" :disabled="busy || !confirmed">
                {{ $t('account.delete.confirm_button', props.notes) }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </Teleport>
</template>
