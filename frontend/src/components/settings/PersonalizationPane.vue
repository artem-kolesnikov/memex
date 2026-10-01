<script setup lang="ts">
// Personalization: the presets for memex-writing, memex's built-in skill for
// writing notes, and the owner's profile note.
import { onMounted } from 'vue'
import AboutYou from './personalization/AboutYou.vue'
import WritingPresets from './personalization/WritingPresets.vue'
import { usePersonalization } from './personalization/usePersonalization'

const settings = usePersonalization()

onMounted(() => {
  void settings.load()
})
</script>

<template>
  <section class="mm-pane mm-personalization">
    <div class="mm-block" v-if="settings.failed.value">
      <div class="mm-profile-load-error" role="alert">
        <span>{{ $t('personalization.settings.load_failed') }}</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="settings.load()">{{ $t('personalization.profile_note.retry') }}</button>
      </div>
    </div>
    <p class="mm-note" v-else-if="settings.view.value === null" role="status">{{ $t('common.loading') }}</p>
    <WritingPresets v-else :settings="settings" />

    <AboutYou :paragraph="settings.view.value?.profile_paragraph ?? null" />
  </section>
</template>
