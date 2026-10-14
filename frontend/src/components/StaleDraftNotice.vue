<script setup lang="ts">
// A held draft written before somebody else changed what it changes
// (operator, 2026-10-13). The card says which of three things that means and
// shows what changed since, field by field: a patch that still fits applies on
// top of it, an edit that replaces a field undoes it, and a patch whose text is
// gone cannot be applied at all.
import { trackedChangesHtml } from '@/lib/diffs'

export interface StaleField {
  field: 'title' | 'body' | 'tags' | 'summary' | 'absorbed_body'
  before: string
  after: string
  tagsBefore: string[]
  tagsAfter: string[]
}

export interface StaleView {
  variant: 'changed' | 'undoes' | 'refused'
  fields: StaleField[]
}

defineProps<{ view: StaleView }>()

const LABELS: Record<StaleField['field'], string> = {
  title: 'inbox.stale.field.title',
  body: 'inbox.doc.body',
  tags: 'inbox.doc.tags',
  summary: 'inbox.doc.summary',
  absorbed_body: 'inbox.stale.field.absorbed_body',
}

function tagPills(item: StaleField) {
  return [
    ...item.tagsAfter.map((name) => ({ name, state: item.tagsBefore.includes(name) ? 'same' : 'added' })),
    ...item.tagsBefore.filter((name) => !item.tagsAfter.includes(name)).map((name) => ({ name, state: 'removed' })),
  ]
}
</script>

<template>
  <div class="app-notice app-notice-warning mm-stale">
    <span class="app-notice-mark">!</span>
    <div class="mm-stale-copy">
      <strong>{{ $t(`inbox.stale.${view.variant}_title`) }}</strong>
      <p>{{ $t(`inbox.stale.${view.variant}_body`) }}</p>
      <div class="mm-stale-field" v-for="item in view.fields" :key="item.field">
        <span class="mm-doc-label">{{ $t(LABELS[item.field]) }}</span>
        <div class="mm-doc-tags" v-if="item.field === 'tags'">
          <span
            v-for="pill in tagPills(item)"
            :key="pill.state + pill.name"
            class="mm-tag mm-doc-tag"
            :class="`is-${pill.state}`"
          >{{ pill.name }}</span>
        </div>
        <div class="mm-was" v-else-if="item.field === 'title'">
          <del>{{ item.before }}</del> <span>{{ item.after }}</span>
        </div>
        <!-- eslint-disable-next-line vue/no-v-html — trackedChangesHtml escapes both sides -->
        <div class="mm-diff mm-diff-body" v-else v-html="trackedChangesHtml(item.before, item.after)"></div>
      </div>
    </div>
  </div>
</template>

