<!--
  One line of tag chips that fills the width it has been given, and collapses
  whatever is left into "+N".

  ## What this replaces

  `tags.slice(0, 3)` — a fixed COUNT, in a fixed-width column. A count is blind
  to how wide a tag is, so the same cell showed three long tags overflowing
  their 260px and three short ones leaving half the column empty next to a "+1"
  that would plainly have fitted. Down a page of fifty rows that reads as an
  uneven column and wasted screen (operator, 2026-08-23).

  ## How it decides

  Chips are laid out at their natural width, measured, and admitted one at a
  time while there is still room for them AND for the "+N" that would follow.
  The "+N" is measured too, at its widest possible text, so admitting a chip
  can never be undone by the badge that has to sit after it.

  At least one chip is always shown. A cell reading only "+7" tells you the
  number of tags and nothing about the note, which is the one outcome this is
  not for.

  Measuring happens with the row hidden rather than visible, so nobody sees the
  full set flash before it is trimmed, and it re-runs on resize because the
  column is a fraction of the page.

  The probe wears the same classes as the visible row, `mm-tag-system`
  included: a system chip is semibold and therefore wider than the same word in
  a plain one, and measuring the light version would admit a chip that does not
  fit.
-->
<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { TagRef } from '@/api/client'
import { useSystemTags } from '@/lib/systemTags'

const { isSystemTag } = useSystemTags()

const props = defineProps<{ tags: TagRef[] }>()

const row = ref<HTMLElement | null>(null)
const probe = ref<HTMLElement | null>(null)
const visible = ref(props.tags.length)
const measured = ref(false)

/** Matches `gap: 0.25rem` on .mm-tag-row. Read from the DOM, not assumed. */
function gapOf(el: HTMLElement): number {
  const g = Number.parseFloat(getComputedStyle(el).columnGap)
  return Number.isFinite(g) ? g : 4
}

function measure() {
  const el = row.value
  const p = probe.value
  if (el === null || p === null) return

  // Fractional widths throughout: offsetWidth rounds, and four chips each
  // rounded down admitted a row a few pixels wider than the box it is clipped
  // to, which cut the badge rather than dropping a chip.
  const available = el.getBoundingClientRect().width
  const gap = gapOf(p)
  const chips = [...p.querySelectorAll<HTMLElement>('.mm-tag')]
  // The badge at its WIDEST — "+12" is wider than "+9", and the count shrinks
  // as chips are admitted, so measuring the widest is the safe direction.
  const badge = p.querySelector<HTMLElement>('.mm-tag-more')?.getBoundingClientRect().width ?? 0

  let used = 0
  let count = 0
  for (const chip of chips) {
    const width = used + (count > 0 ? gap : 0) + chip.getBoundingClientRect().width
    const hidden = props.tags.length - (count + 1)
    const withBadge = width + (hidden > 0 ? gap + badge : 0)
    if (withBadge > available && count > 0) break
    used = width
    count++
  }

  visible.value = Math.max(1, count)
  measured.value = true
}

async function remeasure() {
  measured.value = false
  await nextTick()
  measure()
}

let observer: ResizeObserver | undefined
onMounted(() => {
  void remeasure()
  if (row.value !== null && typeof ResizeObserver !== 'undefined') {
    // The column is a fraction of the page, so a window resize changes it
    // without changing anything this component is passed.
    observer = new ResizeObserver(() => measure())
    observer.observe(row.value)
  }
})
onBeforeUnmount(() => observer?.disconnect())
watch(() => props.tags, remeasure)
</script>

<template>
  <div class="mm-tag-row" ref="row" :style="{ visibility: measured ? 'visible' : 'hidden' }">
    <span v-for="tag in tags.slice(0, visible)" :key="tag.id" class="mm-tag"
          :class="{ 'mm-tag-system': isSystemTag(tag.name) }" :title="tag.name">
      {{ tag.name }}
    </span>
    <span
      v-if="tags.length > visible"
      class="mm-tag-more"
      :title="tags.slice(visible).map((t) => t.name).join(', ')"
      >+{{ tags.length - visible }}</span
    >

    <!-- The measuring copy: every chip at its natural width plus the badge at
         its widest text, laid out but never painted and never read by a screen
         reader. Kept out of the flow so it cannot affect the row it measures. -->
    <div class="mm-tag-probe" ref="probe" aria-hidden="true">
      <span v-for="tag in tags" :key="tag.id" class="mm-tag"
            :class="{ 'mm-tag-system': isSystemTag(tag.name) }">{{ tag.name }}</span>
      <span class="mm-tag-more">+{{ tags.length }}</span>
    </div>
  </div>
</template>
