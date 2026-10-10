<script setup lang="ts">
// What the search box understands beyond plain words, opened from its (?).
// The docs page on finding notes says the same; this links there.
import { ref } from 'vue'
import { useBootstrapModal } from '@/lib/bootstrapModal'
import { docsPage } from '@/lib/docs'

const emit = defineEmits<{ close: [] }>()

const dialog = ref<HTMLElement | null>(null)
const { hide } = useBootstrapModal(dialog, { onHidden: () => emit('close') })

// The operators are the parser's own words, the same in every language.
const OPERATORS = [
  { example: 'memex memory', key: 'search.help.and' },
  { example: 'memex OR memory', key: 'search.help.or' },
  { example: 'NOT ai', key: 'search.help.not' },
  { example: '(memex OR memory) NOT ai', key: 'search.help.group' },
  { example: '"review gate"', key: 'search.help.phrase' },
] as const
</script>

<template>
  <Teleport to="body">
    <div class="modal fade app-dialog" tabindex="-1" ref="dialog" aria-labelledby="search-help-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title h6 mb-0" id="search-help-title">{{ $t('search.help.title') }}</h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="hide"></button>
          </div>
          <div class="modal-body">
            <p class="mm-search-help-lede">{{ $t('search.help.lede') }}</p>
            <dl class="mm-search-help-list">
              <template v-for="row in OPERATORS" :key="row.example">
                <dt><code>{{ row.example }}</code></dt>
                <dd>{{ $t(row.key) }}</dd>
              </template>
            </dl>
            <ul class="mm-search-help-rules">
              <li>{{ $t('search.help.uppercase') }}</li>
              <li>{{ $t('search.help.binding') }}</li>
              <li>{{ $t('search.help.exact') }}</li>
            </ul>
          </div>
          <div class="modal-footer">
            <a class="btn btn-link btn-sm me-auto px-0" :href="docsPage('find-notes')" target="_blank" rel="noopener">
              <i class="fa-solid fa-book-open me-1"></i>{{ $t('search.help.more') }}
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="hide">{{ $t('common.close') }}</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
