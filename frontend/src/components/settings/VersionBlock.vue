<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/client'

const version = ref('')

onMounted(async () => {
  try {
    version.value = (await api.health()).version
  } catch {
    /* the block stays hidden */
  }
})
</script>

<template>
  <div v-if="version !== ''" class="mm-block">
    <h3 class="mm-block-title">{{ $t('general.version.title') }}</h3>
    <p class="mb-0">{{ version }}</p>
  </div>
</template>
