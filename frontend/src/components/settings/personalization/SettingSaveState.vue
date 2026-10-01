<script setup lang="ts">
import { computed } from 'vue'
import type { PersonalizationState } from './usePersonalization'

const props = defineProps<{ settings: PersonalizationState; setting: string }>()
const state = computed(() => props.settings.feedback.value[props.setting] ?? 'idle')
</script>

<template>
  <span class="mm-pers-save-state" role="status" :data-save-feedback="setting" :data-state="state">
    <i v-if="state !== 'idle'" class="fa-solid" :class="{ 'fa-spinner fa-spin': state === 'saving', 'fa-check': state === 'saved', 'fa-circle-exclamation': state === 'failed' }" aria-hidden="true"></i>
    <template v-if="state !== 'idle'">{{ $t(`personalization.persistence.${state}`) }}</template>
  </span>
</template>
