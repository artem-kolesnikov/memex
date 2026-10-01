<script setup lang="ts">
// The editor assistant's one sentence on who writes Analyze's summaries and
// titles: nobody until a key is added, the person's own key, or memex's key
// under the tier's allowance, named by model.
import type { AnalyzeAllowance } from '@/api/client'

defineProps<{ allowance: AnalyzeAllowance | null }>()
</script>

<template>
  <template v-if="allowance">
    <i18n-t v-if="allowance.suggestions === 'none'" keypath="editor.assistant.allowance.none" tag="p"
            class="mm-assistant-lede" scope="global">
      <template #link>
        <router-link :to="{ name: 'settings' }">{{ $t('editor.assistant.allowance.link') }}</router-link>
      </template>
    </i18n-t>
    <p v-else-if="allowance.suggestions === 'own'" class="mm-assistant-lede">{{ $t('editor.assistant.allowance.own') }}</p>
    <p v-else class="mm-assistant-lede">
      {{ allowance.model
        ? $t('editor.assistant.allowance.included', { model: allowance.model, left: allowance.left ?? 0 }, allowance.left ?? 0)
        : $t('editor.assistant.allowance.included_default', { left: allowance.left ?? 0 }, allowance.left ?? 0) }}
    </p>
  </template>
</template>
