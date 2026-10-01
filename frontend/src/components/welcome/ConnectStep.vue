<script setup lang="ts">
// Connect: pick an assistant, then follow one instruction at a time. The
// instructions come from the same data Settings › Connections shows the same
// way; the cursor per assistant lives in the store so switching tabs, or
// leaving to the assistant's window and coming back, keeps the place.
import { OTHER } from '@/components/settings/connectGuides'
import ProviderTabs from '@/components/settings/ProviderTabs.vue'
import GuideInstruction from '@/components/settings/GuideInstruction.vue'
import OtherConnect from './OtherConnect.vue'
import { useWelcomeStore } from '@/stores/welcome'

const welcome = useWelcomeStore()
</script>

<template>
  <section>
    <div class="mm-wiz-heading">
      <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.connect.title') }}</h1>
      <p class="mm-wiz-intro">{{ $t('welcome.connect.intro') }}</p>
    </div>

    <ProviderTabs v-if="welcome.webAssistants" :model-value="welcome.provider" @update:model-value="welcome.choose($event)" />

    <OtherConnect v-if="welcome.provider === OTHER" />

    <GuideInstruction v-else-if="welcome.client" :client="welcome.client" :at="welcome.instructionAt"
                      :curator="welcome.curator" @show="welcome.showInstruction($event)" />
  </section>
</template>
