<script setup lang="ts">
// The wizard's line icons, drawn inline so they take the current colour.
const PATHS: Record<string, string> = {
  arrow: 'M5 12h14m-5-5 5 5-5 5',
  back: 'M19 12H5m5-5-5 5 5 5',
  check: 'm5 12 4 4L19 6',
  close: 'm6 6 12 12M6 18 18 6',
  copy: 'M16 5V4a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1M8 8h12v12H8z',
  sun: 'M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1 1m12 12 1 1M5 19l1-1M18 6l1-1M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  moon: 'M20.8 13.2A9 9 0 0 1 10.8 3.1a9 9 0 1 0 10 10.1Z',
  external: 'M14 3h7v7m0-7L11 13M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5',
  info: 'M12 11v5m0-9v.1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  link: 'M12 13l4-4m-7 6-1 1a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 4 1-1a4 4 0 0 1 6 6l-5 5a4 4 0 0 1-6 0',
  chat: 'M21 11a8 8 0 0 1-8 8H7l-5 3 1-6a8 8 0 0 1-1-5 9 9 0 0 1 19 0ZM7 10h10m-10 4h6',
  clock: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  shield: 'm12 3 8 3v6c0 4-4 7-8 9-4-2-8-5-8-9V6Zm-4 9 3 3 5-6',
  alert: 'm12 3 10 18H2ZM12 9v5m0 3v.1',
  code: 'm8 6-6 6 6 6m8-12 6 6-6 6M14 3l-4 18',
}
defineProps<{ name: keyof typeof PATHS | string }>()
</script>

<template>
  <svg class="mm-wiz-icon" viewBox="0 0 24 24" aria-hidden="true"><path :d="PATHS[name] ?? PATHS.info" /></svg>
</template>
