<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { toastError, toastSuccess } from '@/components/toastService'

const { t } = useI18n()
const exporting = ref(false)

async function exportAll() {
  exporting.value = true
  try {
    const { blob, filename } = await api.exportAll()
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = filename
    anchor.click()
    URL.revokeObjectURL(url)
    toastSuccess(t('account.export.downloaded'), filename)
  } catch (e) {
    toastError(t('account.export.failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    exporting.value = false
  }
}
</script>

<template>
    <div class="mm-block" id="export">
      <h3 class="mm-block-title">{{ $t('account.export.title') }}</h3>
      <p class="mm-note">{{ $t('account.export.intro') }}</p>
      <button class="btn btn-outline-secondary btn-sm" :disabled="exporting" @click="exportAll">
        <i class="fa-solid fa-file-zipper me-1"></i>
        {{ exporting ? $t('account.export.building') : $t('account.export.download') }}
      </button>
    </div>

</template>
