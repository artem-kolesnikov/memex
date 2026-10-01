<script setup lang="ts">
import { useWelcomeStore } from '@/stores/welcome'
const welcome = useWelcomeStore()
</script>

<template>
  <span class="mm-wiz-profile-position">
    {{ $t('welcome.connect.position', { at: welcome.profileSlide === 'choose' ? 1 : 2, total: 2 }) }}
  </span>
</template>
