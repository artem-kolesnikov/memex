<script setup lang="ts">
// A journal row's contents: the description, the notes it touched, its diff
// and the operator's reasoning.
//
// One component because the journal renders twice — a table above `md` and
// cards below it, which is what the operator asked for and what two different
// shapes of the same row require. Only the WRAPPER differs; duplicating the
// inside is how the phone copy comes to lag the desktop one.
import type { CuratorLogRow } from '@/api/client'
import ClampedMarkdown from '@/components/ClampedMarkdown.vue'

defineProps<{ row: CuratorLogRow; expanded: boolean }>()
defineEmits<{ (e: 'toggle'): void }>()

const gone = (row: CuratorLogRow) => Math.max(0, row.affected_total - row.affected.length)
</script>

<template>
  <div>
    <ClampedMarkdown :text="row.description" />

    <!-- Gated on the TOTAL, not on how many resolved: a row whose notes have
         all since been deleted still touched them, and gating on the resolved
         list made the "no longer exist" line unreachable in exactly the case
         it was written for (Codex, 2026-08-27). -->
    <div v-if="row.affected_total" class="small mt-1">
      <span class="text-muted me-1">{{ $t('activity.entry.notes', row.affected_total) }}</span>
      <template v-for="(note, i) in row.affected" :key="note.id">
        <router-link :to="{ name: 'note', params: { id: note.id } }">{{ note.title }}</router-link>
        <span v-if="i < row.affected.length - 1">, </span>
      </template>
      <span v-if="gone(row)" class="text-muted">
        <template v-if="row.affected.length">· </template>{{ $t('activity.entry.gone', gone(row)) }}
      </span>
    </div>

    <a v-if="row.diff" href="javascript:void(0)" class="small d-inline-block mt-1" @click="$emit('toggle')">
      {{ expanded ? $t('activity.entry.hide_diff') : $t('activity.entry.show_diff') }}
    </a>

    <!-- The operator's reasoning is the part the curator reads back and acts
         on, so it is shown, not hidden behind an expander. -->
    <div v-if="row.operator_comment" class="small mt-1 ps-2 border-start border-2">
      <i class="fa-regular fa-comment-dots me-1 text-muted"></i>{{ row.operator_comment }}
      <span v-if="row.is_precedent" class="badge text-bg-primary ms-2"
            :title="$t('activity.entry.precedent_title')">{{ $t('activity.entry.precedent') }}</span>
    </div>
  </div>
</template>
