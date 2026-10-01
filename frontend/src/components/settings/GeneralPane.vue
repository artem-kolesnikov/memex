<script setup lang="ts">
import PreferencesPane from '@/components/settings/PreferencesPane.vue'
import MapBlock from '@/components/settings/MapBlock.vue'
import VersionBlock from '@/components/settings/VersionBlock.vue'
</script>

<template>
  <section class="mm-pane">
    <PreferencesPane />
    <MapBlock />
    <VersionBlock />
  </section>
</template>
