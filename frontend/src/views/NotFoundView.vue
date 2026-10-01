<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue'
</script>

<template>
  <div class="mm-not-found">
    <div class="mm-not-found-inner">
      <BrandMark class="mm-not-found-mark" aria-hidden="true" />
      <p class="mm-not-found-code">{{ $t('not_found.code') }}</p>
      <h1 class="mm-not-found-title">{{ $t('not_found.title') }}</h1>
      <p class="mm-not-found-body">{{ $t('not_found.body') }}</p>
      <router-link class="btn btn-primary" :to="{ name: 'search' }">{{ $t('not_found.back') }}</router-link>
    </div>
  </div>
</template>
