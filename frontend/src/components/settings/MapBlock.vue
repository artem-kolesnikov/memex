<script setup lang="ts">
// How the map is drawn. On the account rather than the device, so the shape you
// settled on is the shape you meet on the laptop and the phone — unlike the
// text size beside it in Preferences, which is about the screen in your hand.
//
// Every control writes on change and reports its own failure. There is no Save:
// a preference that needs confirming is a preference you have to think about.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { MAP_DEFAULTS, readMapPrefs, type MapPrefs } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import OperationOutcomeNotice from '@/components/OperationOutcomeNotice.vue'
import { toastError } from '@/components/toastService'

type Key = keyof MapPrefs

const auth = useAuthStore()
const lifetime = useOperationLifetime()
const { t } = useI18n()
// A set, not one key: two rows written quickly used to share a single flag, and
// whichever request finished first unlocked the other's button.
const saving = ref<Set<Key>>(new Set())
const unknown = ref(false)
watch(sessionEpoch, () => { if (saving.value.size > 0) unknown.value = true })

// Writes are queued rather than raced. Each PATCH reads the stored blob and
// writes it back whole, so two in flight at once can drop one another's key.
let queue: Promise<unknown> = Promise.resolve()

const current = computed<Required<MapPrefs>>(() => readMapPrefs(auth.user?.map))

const CHOICES: { key: Key; labelKey: string; options: { value: string; labelKey: string }[] }[] = [
  {
    key: 'view',
    labelKey: 'content.map.view',
    options: [
      { value: '2d', labelKey: 'content.map.view_2d' },
      { value: '3d', labelKey: 'content.map.view_3d' },
    ],
  },
  {
    key: 'nodeShape',
    labelKey: 'content.map.shape',
    options: [
      { value: 'dot', labelKey: 'content.map.shape_dot' },
      { value: 'square', labelKey: 'content.map.shape_square' },
    ],
  },
  {
    key: 'links',
    labelKey: 'content.map.links',
    options: [
      { value: 'curve', labelKey: 'content.map.links_curve' },
      { value: 'line', labelKey: 'content.map.links_line' },
      { value: 'arrow', labelKey: 'content.map.links_arrow' },
    ],
  },
  {
    key: 'labels',
    labelKey: 'content.map.labels',
    options: [
      { value: 'title', labelKey: 'content.map.labels_title' },
      { value: 'id', labelKey: 'content.map.labels_id' },
      { value: 'none', labelKey: 'content.map.labels_none' },
    ],
  },
]

async function choose(key: Key, value: string) {
  const mine = lifetime.capture()
  if (current.value[key] === value || saving.value.has(key)) return
  saving.value = new Set(saving.value).add(key)

  queue = queue
    .then(() =>
      !lifetime.current(mine) ? undefined :
      // The default is sent as null, so an account that chose its way back to
      // the defaults keeps an empty row rather than a blob restating them.
      auth.chooseMapSetting({ [key]: value === MAP_DEFAULTS[key] ? null : value }),
    )
    .catch((e: unknown) => {
      if (!lifetime.current(mine)) return
      toastError(t('content.map.not_saved'), e instanceof Error ? e.message : t('common.unknown_error'))
    })
    .finally(() => {
      if (!lifetime.current(mine)) return
      const next = new Set(saving.value)
      next.delete(key)
      saving.value = next
    })

  await queue
}
</script>

<template>
  <div class="mm-block">
    <h3 class="mm-block-title">{{ $t('content.map.title') }}</h3>
    <p class="mm-note">{{ $t('content.map.intro') }}</p>

    <OperationOutcomeNotice v-if="unknown" />
    <div v-else class="mm-map-settings">
      <div v-for="row in CHOICES" :key="row.key" class="mm-map-setting">
        <span class="mm-map-setting-label" :id="`map-setting-${row.key}`">
          {{ $t(row.labelKey) }}
        </span>
        <div class="mm-map-setting-choices" role="group" :aria-labelledby="`map-setting-${row.key}`">
          <button
            v-for="option in row.options"
            :key="option.value"
            type="button"
            class="mm-map-setting-choice"
            :class="{ 'is-on': current[row.key] === option.value }"
            :aria-pressed="current[row.key] === option.value"
            :disabled="saving.has(row.key)"
            @click="choose(row.key, option.value)"
          >
            {{ $t(option.labelKey) }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
