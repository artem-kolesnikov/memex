<script setup lang="ts">
// The document a proposal would leave behind — one shape for every kind of
// proposal, because the question is always the same: is this what the note
// should say. A new note reads as all addition, a deletion as all removal, an
// edit and a merge as the difference between what is there and what is
// proposed. The kind decides what goes into the three fields, never how they
// are drawn.
import { computed } from 'vue'
import { lineDiffHtml } from '@/lib/diffs'
import { useSystemTags } from '@/lib/systemTags'
import type { ProposedDoc } from '@/lib/proposedDoc'

const { isSystemTag, systemReason } = useSystemTags()

const props = defineProps<{
  doc: ProposedDoc
  /** Whether the surrounding card already names this document. It does in the
   *  review inbox, and does not in the Activity journal, where a row heads
   *  itself. A title the proposal CHANGES is always shown either way — that is
   *  the one case where the header is out of date. */
  headed?: boolean
}>()

const showTitle = computed(() => props.doc.titleBefore !== null || props.headed === false)

/** Added, removed and kept, in one pass, so the row reads as one list rather
 *  than three. Order follows the resulting document, with removals last. */
const tagPills = computed(() => {
  const before = props.doc.tags.before
  const after = props.doc.tags.after
  return [
    ...after.map((name) => ({ name, state: before.includes(name) ? 'same' : 'added' })),
    ...before.filter((name) => !after.includes(name)).map((name) => ({ name, state: 'removed' })),
  ]
})

/** `lineDiffHtml` collapses an unchanged run into "⋯ N unchanged lines", which
 *  is right inside a diff and wrong as a whole field: the operator is being
 *  shown the resulting document, and a field nothing touched is still part of
 *  it. So an unchanged field is rendered as itself. */
const bodyChanged = computed(() => props.doc.body.before !== props.doc.body.after)
const summaryChanged = computed(() => props.doc.summary.before !== props.doc.summary.after)
</script>

<template>
  <div class="mm-doc">
    <h4 class="mm-doc-heading">
      <span class="mm-doc-what">{{ doc.heading }}</span>
      <template v-if="showTitle">
        <span class="mm-doc-title">{{ doc.title }}</span>
        <!-- A title is one line with nowhere to draw a diff, and word-diffing
             two unrelated names interleaves them into something unreadable.
             The name it replaces goes beside it, struck through, whole. -->
        <span class="mm-doc-was" v-if="doc.titleBefore !== null">
          <del>{{ doc.titleBefore }}</del>
        </span>
      </template>
    </h4>

    <!-- An edit the server will refuse. Said here, beside the text it is about,
         rather than left to the disabled button to imply. -->
    <div class="app-notice app-notice-warning mm-doc-refusal" v-if="doc.refusal">
      <span class="app-notice-mark">!</span>
      <div>
        <strong>{{ $t(`inbox.panel.${doc.refusal}_title`) }}</strong>
        <p>{{ $t(`inbox.panel.${doc.refusal}_body`) }}</p>
      </div>
    </div>

    <div class="app-panel mm-doc-field">
      <span class="mm-doc-label">{{ $t('inbox.doc.body') }}</span>
      <!-- eslint-disable-next-line vue/no-v-html — lineDiffHtml escapes both sides -->
      <div class="mm-diff mm-diff-body" v-if="bodyChanged" v-html="lineDiffHtml(doc.body.before, doc.body.after)"></div>
      <div class="mm-diff mm-diff-body mm-diff-plain" v-else>{{ doc.body.after }}</div>
      <!-- The absorbed note's text belongs to the body it is being folded
           into, as the removal it is. Unlabelled on purpose: the row above the
           card already names the note, and saying it again here was the third
           time on one card (operator, 2026-09-07). -->
      <!-- eslint-disable-next-line vue/no-v-html — lineDiffHtml escapes both sides -->
      <div class="mm-diff mm-diff-body mm-doc-destroyed" v-if="doc.destroyed" v-html="lineDiffHtml(doc.destroyed.body, '')"></div>
    </div>

    <div class="app-panel mm-doc-field">
      <span class="mm-doc-label">{{ $t('inbox.doc.tags') }}</span>
      <div class="mm-doc-tags">
        <span
          v-for="pill in tagPills"
          :key="pill.state + pill.name"
          class="mm-tag mm-doc-tag"
          :class="[`is-${pill.state}`, { 'mm-tag-system': isSystemTag(pill.name) }]"
          :title="systemReason(pill.name) ?? undefined"
        >{{ pill.name }}</span>
        <span class="mm-doc-none" v-if="!tagPills.length">{{ $t('inbox.doc.no_tags') }}</span>
      </div>
    </div>

    <div class="app-panel mm-doc-field">
      <span class="mm-doc-label">{{ $t('inbox.doc.summary') }}</span>
      <!-- eslint-disable-next-line vue/no-v-html — lineDiffHtml escapes both sides -->
      <div class="mm-diff mm-diff-body mm-diff-summary" v-if="summaryChanged" v-html="lineDiffHtml(doc.summary.before, doc.summary.after)"></div>
      <p class="mm-doc-summary" v-else-if="doc.summary.after">{{ doc.summary.after }}</p>
      <span class="mm-doc-none" v-else>{{ $t('inbox.doc.no_summary') }}</span>
    </div>
  </div>
</template>
