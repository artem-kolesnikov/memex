<script setup lang="ts">
// The screenshots beside an instruction: thumbnails that open the enlarged
// view, or the assistant-to-memex diagram when an instruction has none.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GuideImage } from './connectGuides'
import ScreenshotDialog from './ScreenshotDialog.vue'

const props = defineProps<{ images: GuideImage[]; logo: string | null; providerName: string }>()
const { t } = useI18n()

const open = ref<GuideImage | null>(null)
let opener: HTMLElement | null = null

// Focus goes back to the thumbnail that was pressed. The browser would do
// this for a native dialog on its own, but the wizard's modal keeps a focus
// trap that can intercept the hand-back and land on its own title instead.
function show(shot: GuideImage, event: MouseEvent) {
  opener = event.currentTarget as HTMLElement
  open.value = shot
}

function closed() {
  open.value = null
  opener?.focus({ preventScroll: true })
}
</script>

<template>
  <div v-if="props.images.length"
       :class="props.images.length > 2 ? 'mm-wiz-gallery mm-wiz-gallery-three' : props.images.length > 1 ? 'mm-wiz-gallery' : ''">
    <div v-for="shot in props.images" :key="shot.src">
      <button type="button" class="mm-wiz-thumbnail" :aria-label="t('connections.guides.enlarge', { what: t(shot.altKey) })"
              @click="show(shot, $event)">
        <img :src="shot.src" :alt="$t(shot.altKey)">
        <span class="mm-wiz-zoom" aria-hidden="true">
          <svg class="mm-wiz-icon" viewBox="0 0 24 24"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5M7 10h6m-3-3v6"/></svg>
        </span>
      </button>
      <span class="mm-wiz-thumb-caption">{{ $t(shot.captionKey) }}</span>
    </div>
  </div>
  <div v-else class="mm-wiz-diagram" :aria-label="$t('connections.guides.diagram_label', { provider: props.providerName })">
    <div class="mm-wiz-diagram-pair">
      <span class="mm-wiz-app-mark">
        <img v-if="props.logo" :src="props.logo" alt="">
        <svg v-else class="mm-wiz-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 6-6 6 6 6m8-12 6 6-6 6M14 3l-4 18"/></svg>
      </span>
      <svg class="mm-wiz-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m10 13 4-4m-7 6-1 1a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 4 1-1a4 4 0 0 1 6 6l-5 5a4 4 0 0 1-6 0" transform="translate(2 0)"/></svg>
      <span class="mm-wiz-app-mark"><img src="/favicon.svg" alt=""></span>
    </div>
    <small>{{ $t('connections.guides.diagram_caption') }}</small>
  </div>

  <ScreenshotDialog v-if="open" :src="open.src" :alt="$t(open.altKey)" :caption="$t(open.altKey)" @close="closed" />
</template>
