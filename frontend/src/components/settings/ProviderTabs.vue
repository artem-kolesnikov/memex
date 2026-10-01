<script setup lang="ts">
// The four ways in, as one row of tabs: the three guided assistants and Other.
import { CLIENTS, OTHER, logoFor } from '@/components/settings/connectGuides'
import { useTheme } from '@/lib/theme'
import WizardIcon from '@/components/welcome/WizardIcon.vue'

defineProps<{ modelValue: string }>()
const emit = defineEmits<{ 'update:modelValue': [id: string] }>()
const { theme } = useTheme()
</script>

<template>
  <div class="mm-wiz-providers" role="group" :aria-label="$t('welcome.connect.choose')">
    <button v-for="c in CLIENTS" :key="c.id" type="button" class="mm-wiz-provider"
            :aria-pressed="modelValue === c.id" @click="emit('update:modelValue', c.id)">
      <img :src="logoFor(c, theme)" alt="">{{ c.label }}
    </button>
    <button type="button" class="mm-wiz-provider" :aria-pressed="modelValue === OTHER"
            @click="emit('update:modelValue', OTHER)">
      <WizardIcon name="code" />{{ $t('welcome.connect.other') }}
    </button>
  </div>
</template>
