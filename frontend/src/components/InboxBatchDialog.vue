<script setup lang="ts">
import { ref } from 'vue'
import { useBootstrapModal } from '@/lib/bootstrapModal'

const props = defineProps<{
  action: 'approve' | 'reject'
  count: number
  warning: string
  destructive: boolean
  busy?: boolean
}>()
const emit = defineEmits<{ close: []; confirm: [] }>()

const dialog = ref<HTMLElement | null>(null)
const { hide } = useBootstrapModal(dialog, { onHidden: () => emit('close') })
</script>

<template>
  <Teleport to="body">
    <div
      ref="dialog"
      class="modal fade app-dialog mm-batch-dialog"
      tabindex="-1"
      aria-labelledby="inbox-batch-title"
      aria-hidden="true"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 id="inbox-batch-title" class="modal-title h5">
              {{ props.action === 'approve' ? $t('inbox.batch.approve_title', props.count) : $t('inbox.batch.reject_title', props.count) }}
            </h3>
            <button type="button" class="btn-close" @click="hide" :aria-label="$t('common.cancel')"></button>
          </div>
          <div class="modal-body">
            <p class="mm-batch-consequence">{{ props.warning }}</p>
            <p class="mm-batch-unread">
              {{ $t('inbox.batch.unread') }}
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="hide">{{ $t('common.cancel') }}</button>
            <button
              type="button"
              class="btn nowrap"
              :class="props.destructive ? 'btn-danger' : 'btn-primary'"
              :disabled="props.busy"
              @click="emit('confirm')"
            >
              <span v-if="props.busy" class="spinner-border spinner-border-sm" role="status"></span>
              {{ props.action === 'approve' ? $t('inbox.batch.approve_n', { n: props.count }) : $t('inbox.batch.reject_n', { n: props.count }) }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
