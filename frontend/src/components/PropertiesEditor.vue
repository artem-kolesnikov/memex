<script setup lang="ts">
// The editor's properties: a row per name and value. A value is typed as one
// line and read on save the way Obsidian's source view reads it (3, true,
// [a, b]); a row nobody touched keeps the value it came with.
import { computed } from 'vue'
import { propertyNameProblem, type PropertyRow } from '@/lib/properties'

const rows = defineModel<PropertyRow[]>({ required: true })
defineProps<{ disabled?: boolean }>()

const problems = computed(() => rows.value.map((row, index) =>
  row.name === '' && row.text.trim() === '' ? null
    : propertyNameProblem(row.name, rows.value.slice(0, index).map((other) => other.name))))

function add() {
  rows.value = [...rows.value, { name: '', text: '' }]
}

function remove(index: number) {
  rows.value = rows.value.filter((_, at) => at !== index)
}

</script>

<template>
  <div class="mm-property-rows">
    <div v-for="(row, index) in rows" :key="index" class="mm-property-row">
      <input
        type="text"
        class="form-control form-control-sm mm-property-name"
        :class="{ 'is-invalid': problems[index] }"
        v-model="row.name"
        :disabled="disabled"
        :placeholder="$t('properties.name_placeholder')"
        :aria-label="$t('properties.name_label')"
        maxlength="64"
      />
      <input
        type="text"
        class="form-control form-control-sm mm-property-value"
        v-model="row.text"
        :disabled="disabled"
        :placeholder="$t('properties.value_placeholder')"
        :aria-label="$t('properties.value_label', { name: row.name })"
      />
      <button type="button" class="btn btn-sm btn-link mm-property-remove" :disabled="disabled"
              :aria-label="$t('properties.remove', { name: row.name })" :title="$t('properties.remove', { name: row.name })"
              @click="remove(index)">
        <i class="fa-solid fa-xmark"></i>
      </button>
      <p v-if="problems[index]" class="mm-field-error mm-property-problem">{{ $t(`properties.problem.${problems[index]}`) }}</p>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary mm-property-add" :disabled="disabled" @click="add">
      <i class="fa-solid fa-plus"></i>{{ $t('properties.add') }}
    </button>
  </div>
</template>
