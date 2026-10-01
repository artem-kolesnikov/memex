<script setup lang="ts">
// A guide sentence with `**bold**` runs and `[label](url)` links, built as
// elements. The markup is ours, from the locale file, and this is what keeps
// it out of `v-html`.
import { computed, h, type VNode } from 'vue'

const props = defineProps<{ text: string }>()

const TOKEN = /\*\*(.+?)\*\*|\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g

const parts = computed<VNode[] | string[]>(() => {
  const out: (VNode | string)[] = []
  let last = 0
  for (const m of props.text.matchAll(TOKEN)) {
    const at = m.index ?? 0
    if (at > last) out.push(props.text.slice(last, at))
    if (m[1] !== undefined) {
      out.push(h('strong', m[1]))
    } else {
      out.push(
        h('a', { href: m[3], target: '_blank', rel: 'noopener noreferrer', class: 'mm-wiz-inline-link' }, m[2]),
      )
    }
    last = at + m[0].length
  }
  if (last < props.text.length) out.push(props.text.slice(last))
  return out as VNode[]
})

const Rich = () => h('span', parts.value)
</script>

<template>
  <Rich />
</template>
