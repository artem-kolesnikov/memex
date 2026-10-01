<script setup lang="ts">
import { ref } from 'vue'
import { useBootstrapModal } from '@/lib/bootstrapModal'

const props = defineProps<{ title: string; busy?: boolean; drafts?: string; draftCount?: number }>()
const emit = defineEmits<{ close: []; confirm: [] }>()

const dialog = ref<HTMLElement | null>(null)
const { hide } = useBootstrapModal(dialog, { onHidden: () => emit('close') })
</script>

<template>
  <Teleport to="body">
    <div
      ref="dialog"
      class="modal fade app-dialog mm-delete-dialog"
      tabindex="-1"
      aria-labelledby="note-delete-title"
      aria-hidden="true"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 id="note-delete-title" class="modal-title h5">{{ $t('note.delete.title') }}</h3>
            <button type="button" class="btn-close" @click="hide" :aria-label="$t('common.cancel')"></button>
          </div>
          <div class="modal-body">
            <p class="mm-delete-name">{{ props.title || $t('note.delete.untitled') }}</p>
            <p class="mm-delete-copy">{{ $t('note.delete.copy') }}</p>
            <p v-if="props.drafts" class="mm-delete-copy mm-delete-drafts">
              <i18n-t keypath="note.delete.drafts" tag="span" :plural="props.draftCount ?? 1" scope="global">
                <template #drafts>{{ props.drafts }}</template>
              </i18n-t>
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="hide">{{ $t('common.cancel') }}</button>
            <button type="button" class="btn btn-danger" :disabled="props.busy" @click="emit('confirm')">
              <span v-if="props.busy" class="spinner-border spinner-border-sm me-1" role="status"></span>
              {{ props.drafts ? $t('note.delete.confirm_drafts') : $t('note.delete.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
