<script setup lang="ts">
// A screenshot, enlarged. A native <dialog> rather than a Bootstrap modal:
// it sits in the top layer above the wizard's own modal, contains focus by
// itself, closes on Escape without reaching the wizard (whose keyboard
// dismissal is off), and hands focus back to the thumbnail when it closes.
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps<{ src: string; alt: string; caption: string }>()
const emit = defineEmits<{ close: [] }>()

const dialog = ref<HTMLDialogElement | null>(null)

function onClick(event: MouseEvent) {
  const box = dialog.value
  if (box === null || event.target !== box) return
  const r = box.getBoundingClientRect()
  if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) {
    box.close()
  }
}

onMounted(() => {
  const box = dialog.value
  if (box === null) return
  box.addEventListener('close', () => emit('close'))
  box.showModal()
})

onBeforeUnmount(() => {
  if (dialog.value?.open) dialog.value.close()
})
</script>

<template>
  <!-- Escape is handled here and stopped: the wizard's modal listens for it
       on the element this dialog sits inside, and would answer with its
       static-backdrop shake for a key meant for the picture. -->
  <dialog ref="dialog" class="mm-wiz-image-dialog" :aria-label="props.caption" @click="onClick"
          @keydown.esc.stop.prevent="dialog?.close()" @cancel.prevent="dialog?.close()">
    <header>
      <span>{{ props.caption }}</span>
      <button type="button" class="mm-wiz-icon-button" :aria-label="$t('connections.guides.close_screenshot')"
              @click="dialog?.close()">
        <svg class="mm-wiz-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg>
      </button>
    </header>
    <img :src="props.src" :alt="props.alt">
  </dialog>
</template>
