<script setup lang="ts">
import { ref } from 'vue'
import { useBootstrapModal } from '@/lib/bootstrapModal'

withDefaults(
  defineProps<{
    title: string
    confirmLabel?: string
    cancelLabel?: string
    danger?: boolean
    busy?: boolean
  }>(),
  { confirmLabel: undefined, cancelLabel: undefined, danger: false, busy: false },
)

const emit = defineEmits<{ confirm: []; close: [] }>()

const dialog = ref<HTMLElement | null>(null)
const { hide } = useBootstrapModal(dialog, { onHidden: () => emit('close') })
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="confirm-dialog-title">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title h6 mb-0" id="confirm-dialog-title">{{ title }}</h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="hide"></button>
          </div>
          <div class="modal-body">
            <p class="mm-note mb-0"><slot /></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="hide">
              {{ cancelLabel ?? $t('common.cancel') }}
            </button>
            <button type="button" class="btn btn-sm"
                    :class="danger ? 'btn-danger' : 'btn-primary'"
                    :disabled="busy" @click="emit('confirm')">
              {{ confirmLabel ?? $t('app.confirm_dialog.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
