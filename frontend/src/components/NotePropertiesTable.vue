<script setup lang="ts">
// A note's properties as a name/value table, read-only: on the note page under
// the description, and in a review card as tracked changes.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyChanges, type Properties, type PropertyValue } from '@/lib/properties'

const props = defineProps<{
  properties: Properties
  /** What they were: the rows then show what was added, removed and changed. */
  before?: Properties
}>()

const { t } = useI18n()

const rows = computed(() => propertyChanges(props.before ?? props.properties, props.properties))

function shown(value: PropertyValue | undefined): string {
  if (value === null || value === undefined) return t('properties.empty')
  if (value === true) return t('properties.yes')
  if (value === false) return t('properties.no')
  if (Array.isArray(value) && value.every((item) => item === null || typeof item !== 'object')) {
    return value.map((item) => shown(item)).join(', ')
  }
  return typeof value === 'object' ? JSON.stringify(value) : String(value)
}
</script>

<template>
  <dl class="mm-properties">
    <template v-for="row in rows" :key="row.name">
      <dt :class="`is-${row.state}`">{{ row.name }}</dt>
      <dd :class="`is-${row.state}`">
        <del v-if="row.state === 'changed' || row.state === 'removed'">{{ shown(row.before) }}</del>
        <ins v-if="row.state === 'changed' || row.state === 'added'">{{ shown(row.after) }}</ins>
        <span v-if="row.state === 'same'" :class="{ 'mm-properties-empty': row.after === null }">{{ shown(row.after) }}</span>
      </dd>
    </template>
  </dl>
</template>
