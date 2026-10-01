<!-- "(?)" help affordance with a STICKY hover panel: it stays open while the
     pointer travels from the icon into the panel, so the text inside can be
     read, selected, and can hold links. Native title="" tooltips can do none of
     that and vanish the moment you move. Also opens on keyboard focus.

     A click only ever opens it. By the time a click arrives, mouseenter or
     focus has already opened the panel, so a toggle shut it on every press and
     left a phone, which has no hover, with no way to read a hint at all.

     Where it opens is MEASURED when it opens, not chosen by whoever wrote the
     call site — see lib/popoverPlacement.ts for the bug that came from doing it
     the other way.

     The panel is TELEPORTED to <body> and positioned `fixed`. Landing inside
     the viewport turned out not to be enough: inside `.table-responsive`
     (`overflow-x: auto`) an absolutely-positioned panel is clipped by the
     wrapper AND counted as its scrollable width, so on the connections table it
     lost 143px off its right edge and put a horizontal scrollbar under a table
     that fitted perfectly well. Escaping the clipping ancestor is the only fix
     that does not depend on knowing which ancestors a call site has. -->
<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { popoverLeftOffset, popoverTop } from '@/lib/popoverPlacement'

const props = defineProps<{ label?: string }>()
const { t } = useI18n()
const ariaLabel = computed(() => props.label ?? t('note.helptip.label'))

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
/** Viewport coordinates, written once the panel has been measured. */
const pos = ref({ left: 0, top: 0 })
/** False for the first frame, when the panel has a size but not yet a place. */
const placed = ref(false)
// Grace period covers the gap between icon and panel — without it the panel
// closes mid-travel and the whole point is lost.
let closeTimer: number | undefined

async function place() {
  await nextTick()
  measure()
  // A second pass on the next frame, and it earns itself. `nextTick` only
  // promises that Vue has patched the DOM — not that the browser has finished
  // whatever else the same gesture started. Focusing the button can scroll the
  // page, and a `fixed` panel placed against the old position does not follow
  // it: measured at the bottom of a 844px viewport, the first pass put the
  // panel 23px below the fold and the second put it back on screen.
  requestAnimationFrame(measure)
}

function measure() {
  if (root.value === null || panel.value === null) return
  const host = root.value.getBoundingClientRect()
  const viewportWidth = document.documentElement.clientWidth
  const viewportHeight = document.documentElement.clientHeight
  pos.value = {
    // The horizontal rule still answers relative to the tip; adding the tip's
    // own x is what turns it into the viewport coordinate `fixed` wants.
    left:
      host.left +
      popoverLeftOffset({
        hostLeft: host.left,
        panelWidth: panel.value.offsetWidth,
        viewportWidth,
      }),
    top: popoverTop({
      hostTop: host.top,
      hostBottom: host.bottom,
      panelHeight: panel.value.offsetHeight,
      viewportHeight,
    }),
  }
  placed.value = true
}

function show() {
  window.clearTimeout(closeTimer)
  const wasOpen = open.value
  open.value = true
  if (!wasOpen) {
    placed.value = false
    void place()
    // A rotated phone or a resized window moves the tip out from under a panel
    // that was placed for the old width. Only listened to while open.
    window.addEventListener('resize', place)
    // And a `fixed` panel does not travel with its tip, so any scroll — the
    // page, or the scroll container the tip sits in — has to move it. Capture,
    // because scrolls on inner elements do not bubble.
    window.addEventListener('scroll', place, true)
    // A phone has no pointer to move away and Safari never focuses a tapped
    // button, so neither mouseleave nor blur is sure to close it there.
    document.addEventListener('pointerdown', closeOnPressElsewhere, true)
  }
}

function hide() {
  open.value = false
  placed.value = false
  window.removeEventListener('resize', place)
  window.removeEventListener('scroll', place, true)
  document.removeEventListener('pointerdown', closeOnPressElsewhere, true)
}

function closeOnPressElsewhere(event: PointerEvent) {
  const target = event.target as Node | null
  if (root.value?.contains(target) || panel.value?.contains(target)) return
  window.clearTimeout(closeTimer)
  hide()
}

function scheduleHide() {
  window.clearTimeout(closeTimer)
  closeTimer = window.setTimeout(hide, 180)
}

onBeforeUnmount(() => {
  window.clearTimeout(closeTimer)
  window.removeEventListener('resize', place)
  window.removeEventListener('scroll', place, true)
  document.removeEventListener('pointerdown', closeOnPressElsewhere, true)
})
</script>

<template>
  <span class="mm-helptip" ref="root" @mouseenter="show" @mouseleave="scheduleHide">
    <button type="button"
            class="mm-helptip-icon"
            :aria-label="ariaLabel"
            :aria-expanded="open"
            @focus="show"
            @blur="scheduleHide"
            @click.prevent="show">
      <i class="fa-regular fa-circle-question"></i>
    </button>
    <Teleport to="body">
      <span class="mm-helptip-panel"
            ref="panel"
            :style="{
              left: pos.left + 'px',
              top: pos.top + 'px',
              visibility: placed ? 'visible' : 'hidden',
            }"
            v-if="open"
            role="tooltip"
            @mouseenter="show"
            @mouseleave="scheduleHide">
        <slot />
      </span>
    </Teleport>
  </span>
</template>
