<script setup lang="ts">
// Rendered markdown cut to a few lines, with a control to see the rest.
//
// The control appears only when there IS a rest. That has to be measured
// rather than guessed from the text: three lines of a wide table cell and
// three lines of the same cell on a phone are different amounts of prose, and
// a "more" chevron under a two-line description is a promise of nothing.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { renderLogEntry } from '@/lib/markdown'

const props = withDefaults(defineProps<{ text: string; lines?: number }>(), { lines: 3 })

const html = computed(() => renderLogEntry(props.text))
const body = ref<HTMLElement | null>(null)
const expanded = ref(false)
const overflows = ref(false)

function measure() {
  const el = body.value
  if (!el) return
  // Measured while CLAMPED: the expanded element is its own full height, so
  // asking an open one whether it overflows always answers no, and the control
  // that closes it would disappear the moment it was used.
  if (expanded.value) return
  overflows.value = el.scrollHeight - el.clientHeight > 1
}

let observer: ResizeObserver | undefined
onMounted(() => {
  measure()
  observer = new ResizeObserver(() => measure())
  if (body.value) observer.observe(body.value)
})
onBeforeUnmount(() => observer?.disconnect())
watch(
  () => props.text,
  () => {
    expanded.value = false
    nextTick(measure)
  },
)
</script>

<template>
  <div>
    <div
      ref="body"
      class="mm-log-md mm-clamp"
      :class="{ 'mm-clamp-open': expanded }"
      :style="{ '--mm-clamp-lines': String(lines) }"
      v-html="html"
    ></div>
    <button
      v-if="overflows || expanded"
      type="button"
      class="btn btn-link btn-sm p-0 mm-clamp-toggle"
      :aria-expanded="expanded"
      @click.stop="expanded = !expanded"
    >
      <i class="fa-solid" :class="expanded ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
      {{ expanded ? $t('note.clamp.less') : $t('note.clamp.more') }}
    </button>
  </div>
</template>

<style scoped>
.mm-clamp {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: var(--mm-clamp-lines, 3);
  line-clamp: var(--mm-clamp-lines, 3);
  overflow: hidden;
}
.mm-clamp-open {
  display: block;
  overflow: visible;
}
.mm-clamp-toggle {
  text-decoration: none;
  font-size: 0.85em;
}
</style>
