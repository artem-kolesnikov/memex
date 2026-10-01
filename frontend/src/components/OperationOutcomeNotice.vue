<script setup lang="ts">
function reload() { window.location.reload() }
</script>

<template>
  <div class="alert alert-warning mm-operation-unknown" role="alert">
    <p>{{ $t('common.operation_outcome_unknown') }}</p>
    <button type="button" class="btn btn-sm btn-secondary" @click="reload">{{ $t('common.reload_page') }}</button>
  </div>
</template>
