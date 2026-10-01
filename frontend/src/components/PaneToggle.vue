<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLayoutStore, type RailPage } from '@/stores/layout'

const props = defineProps<{ pane: 'sidebar' | RailPage }>()

const layout = useLayoutStore()
const { t } = useI18n()

const hidden = computed(() =>
  props.pane === 'sidebar' ? layout.sidebarCollapsed : layout.railHidden[props.pane],
)

const label = computed(() => {
  if (props.pane === 'sidebar') {
    return hidden.value ? t('app.shell.expand_sidebar') : t('app.shell.collapse_sidebar')
  }
  return hidden.value ? t('app.shell.show_rail') : t('app.shell.hide_rail')
})

function toggle(): void {
  if (props.pane === 'sidebar') layout.toggleSidebar()
  else layout.toggleRail(props.pane)
}
</script>

<template>
  <button type="button" class="app-pane-toggle" :aria-pressed="pane === 'sidebar' ? !hidden : hidden"
          :title="label" :aria-label="label" @click="toggle">
    <svg viewBox="0 0 16 16" aria-hidden="true">
      <rect class="app-pane-frame" x="1.5" y="2.5" width="13" height="11" rx="2" />
      <path v-if="pane === 'sidebar'" class="app-pane-fill"
            d="M3.5 3.5h2.5v9H3.5a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z" />
      <path v-else class="app-pane-fill" d="M10 3.5h2.5a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H10v-9Z" />
    </svg>
  </button>
</template>
