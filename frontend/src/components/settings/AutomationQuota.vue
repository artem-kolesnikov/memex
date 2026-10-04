<script setup lang="ts">
// What is left of today on one section.
//
// A daily figure alone (operator, 2026-09-10). The hourly window is still
// enforced and is what actually stops a loop inside the hour, but it is not a
// number anybody can act on — and two counters where one is meaningful reads
// as a budget rather than as a loop-stopper.
import { computed } from 'vue'

const props = defineProps<{
  /** Null when no ceiling applies: an own key, or an administrative account. */
  left: number | null
  daily: number
  own: boolean
  /** The model memex runs this section on, named beside what is left of today. */
  model?: string | null
}>()

const used = computed(() => Math.max(0, props.daily - (props.left ?? props.daily)))
const pct = computed(() => (props.daily > 0 ? Math.min(100, (used.value / props.daily) * 100) : 0))
</script>

<template>
  <p class="mm-note mb-2" v-if="own">{{ $t('automation.quota.own_key') }}</p>
  <p class="mm-note mb-2" v-else-if="left === null">{{ model ? $t('automation.quota.no_ceiling_model', { model }) : $t('automation.quota.no_ceiling') }}</p>
  <div class="mm-quota mb-2" v-else>
    <div class="mm-quota-bar" role="img"
         :aria-label="$t('automation.quota.left', { left, daily })">
      <span :style="{ width: pct + '%' }"></span>
    </div>
    <p class="mm-note mb-0">{{ model ? $t('automation.quota.left_model', { left, daily, model }) : $t('automation.quota.left', { left, daily }) }}</p>
  </div>
</template>
