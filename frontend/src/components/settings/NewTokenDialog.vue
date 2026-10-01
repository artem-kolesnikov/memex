<script setup lang="ts">
// The token, shown once.
//
// A modal rather than a panel below the form (operator, 2026-08-28): the server
// keeps only a hash, so this is the single moment the secret exists anywhere a
// person can read it, and a box that scrolls away with the rest of the page is
// the wrong shape for that.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { copyText } from '@/lib/clipboard'
import { toastError } from '@/components/toastService'

const { t } = useI18n()
const props = defineProps<{ name: string; token: string }>()
const emit = defineEmits<{ close: [] }>()

const copied = ref(false)
const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

onMounted(() => {
  if (dialog.value === null) return
  modal = new window.bootstrap.Modal(dialog.value)
  dialog.value.addEventListener('hidden.bs.modal', () => emit('close'))
  modal.show()
})
// `hide()` alone is not a teardown, and `dispose()` straight after it is
// too early: Bootstrap returns from `hide()` while the dialog is still
// fading and finishes on a timer, against an instance `dispose()` has
// already emptied. Unmounted while open — the wizard navigating away with
// the token on screen — it waits for `hidden` before disposing.
onBeforeUnmount(() => {
  const instance = modal
  modal = null
  if (instance === null) return
  if (dialog.value?.classList.contains('show') === true) {
    dialog.value.addEventListener('hidden.bs.modal', () => instance.dispose(), { once: true })
    instance.hide()
    return
  }
  instance.dispose()
})

function close() {
  modal?.hide()
}

async function copy() {
  if (await copyText(props.token)) {
    copied.value = true
    window.setTimeout(() => (copied.value = false), 2000)
  } else {
    toastError(t('connections.new_token.copy_failed'), t('connections.copy_by_hand'))
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="new-token-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title h6 mb-0" id="new-token-title">{{ props.name }}</h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="close"></button>
          </div>

          <div class="modal-body">
            <p class="mm-note">{{ $t('connections.new_token.copy_now') }}</p>
            <div class="mm-address">
              <code>{{ props.token }}</code>
              <button type="button" class="mm-address-copy"
                      :aria-label="copied ? $t('common.copied') : $t('connections.new_token.copy_token')" @click="copy">
                <i class="fa-regular" :class="copied ? 'fa-circle-check' : 'fa-copy'"></i>
              </button>
              <span class="small text-muted" v-if="copied">{{ $t('common.copied') }}</span>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-primary btn-sm" @click="close">{{ $t('connections.new_token.add') }}</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
