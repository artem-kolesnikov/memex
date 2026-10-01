<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type AiKey, type AiRolePatch, type AiSettings } from '@/api/client'
import { toastError, toastSuccess } from '@/components/toastService'
import AutomationKeys from '@/components/settings/AutomationKeys.vue'
import AutomationQuota from '@/components/settings/AutomationQuota.vue'
import AutomationRole from '@/components/settings/AutomationRole.vue'


const { t } = useI18n()

// Where each vendor sells a key. Named here rather than in the copy so a
// translation cannot move a link somewhere else.
const OPENAI_KEYS = 'https://platform.openai.com/api-keys'
const LOCAL_MODEL = 'nomic-embed-text-v1.5'

const loadFailed = ref(false)
const ai = ref<AiSettings | null>(null)
const savingEnrichment = ref(false)
const adoptingSearchKey = ref(false)
const switchingModel = ref(false)

const keys = computed(() => ai.value?.keys ?? [])
const limits = computed(() => ai.value?.limits ?? null)
const search = computed(() => ai.value?.search ?? null)
// One model offered is memex.tools: nothing to choose, and the section reads as it always has.
const choosable = computed(() => (search.value?.models.length ?? 0) > 1)
const searchPaid = computed(() => search.value?.model !== LOCAL_MODEL)
const selectedModel = ref('')
watch(() => search.value?.model, (model) => { selectedModel.value = model ?? '' }, { immediate: true })

// What each section can be run on. Semantic search is OpenAI alone rather than
// "any text vendor": every stored vector is one OpenAI model for every
// knowledge base, and Anthropic sells no embeddings at all.
const textProviders = computed(() => ai.value?.providers ?? [])
const embedProviders = computed(() => textProviders.value.filter((p) => p.id === 'openai'))

const textKeys = computed(() => keys.value.filter((k) => textProviders.value.some((p) => p.id === k.provider)))
const embedKeys = computed(() => keys.value.filter((k) => k.provider === 'openai'))
const searchKeyUnused = computed(() => ai.value?.embed_credential_id === null && embedKeys.value.length > 0)

// Enrichment's fields are the top-level ones for historical reasons: it was
// the only role when they were added.
const enrichment = computed(() => ({
  enabled: ai.value?.enabled ?? false,
  credential_id: ai.value?.credential_id ?? null,
  provider: ai.value?.provider ?? null,
  model: ai.value?.model ?? null,
}))

const includedText = computed(() => ai.value?.included === true && ai.value?.credential_id === null)
// With nothing capped the server sends no limits, and whose key pays is read off the sections.
const ownEmbedKey = computed(() => searchPaid.value && (limits.value ? limits.value.own_embed_key : ai.value?.embed_credential_id != null))
const ownTextKey = computed(() => limits.value ? limits.value.own_text_key : ai.value?.credential_id != null)
const textActive = computed(() => includedText.value || (
  ai.value?.enabled === true && keys.value.some((key) => key.id === ai.value?.credential_id && key.readable !== false)
))

async function useForSearch(key: AiKey) {
  adoptingSearchKey.value = true
  try {
    ai.value = await api.saveAiSections({ embed_credential_id: key.id })
    toastSuccess(t('automation.embeddings.key_adopted'))
  } catch (e) {
    toastError(t('automation.embeddings.adopt_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    adoptingSearchKey.value = false
  }
}

async function chooseSearchModel(model: string) {
  if (!search.value || model === search.value.model) return
  if (!window.confirm(t('automation.embeddings.switch_confirm', { model }))) {
    selectedModel.value = search.value.model
    return
  }
  switchingModel.value = true
  try {
    ai.value = await api.saveSearchModel(model)
    toastSuccess(t('automation.embeddings.switched', { model }))
  } catch (e) {
    selectedModel.value = search.value.model
    toastError(t('automation.embeddings.switch_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    switchingModel.value = false
  }
}

async function load() {
  try {
    ai.value = await api.aiSettings()
    loadFailed.value = false
  } catch (e) {
    loadFailed.value = true
    toastError(t('automation.pane.load_failed_title'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}
onMounted(load)

async function saveEnrichment(patch: AiRolePatch) {
  savingEnrichment.value = true
  try {
    ai.value = await api.saveAiSettings(patch)
    toastSuccess(patch.enabled ? t('automation.pane.enrichment_on') : t('automation.pane.enrichment_off'))
  } catch (e) {
    toastError(t('automation.pane.not_saved'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    savingEnrichment.value = false
  }
}
</script>

<template>
  <section class="mm-pane">
    <div class="alert alert-warning" v-if="loadFailed">
      {{ $t('automation.pane.load_failed') }}
    </div>

    <p v-else-if="!ai" class="mm-note">{{ $t('common.loading') }}</p>
    <template v-if="ai">
    <div class="mm-block" id="embeddings">
      <h3 class="mm-block-title">{{ $t('automation.embeddings.title') }}</h3>
      <p class="mm-note">{{ $t('automation.embeddings.intro') }}</p>
      <template v-if="search && choosable">
        <h4 class="mm-ai-label">{{ $t('automation.embeddings.model_label') }}</h4>
        <div class="form-check mb-2" v-for="model in search.models" :key="model">
          <input class="form-check-input" type="radio" name="search-model" :id="`search-model-${model}`" :value="model"
                 v-model="selectedModel" @change="chooseSearchModel(model)"
                 :disabled="switchingModel || (model !== LOCAL_MODEL && ai.embed_credential_id === null)">
          <label class="form-check-label" :for="`search-model-${model}`">
            {{ $t(model === LOCAL_MODEL ? 'automation.embeddings.model_local' : 'automation.embeddings.model_openai') }}
            <span class="mm-note d-block mb-0">{{ model }} · {{ $t(model === LOCAL_MODEL
              ? 'automation.embeddings.model_local_note'
              : (ai.embed_credential_id === null ? 'automation.embeddings.model_needs_key' : 'automation.embeddings.model_openai_note')) }}</span>
          </label>
        </div>
        <div class="alert alert-warning" v-if="!search.ready">{{ $t('automation.embeddings.not_ready') }}</div>
        <p class="mm-note" v-else-if="search.embedded < search.notes">{{ $t('automation.embeddings.progress', { embedded: search.embedded, notes: search.notes }) }}</p>
      </template>
      <div class="mm-settings-grid mm-ai-usage" v-if="limits">
        <div>
          <h4 class="mm-ai-label">{{ $t('automation.embeddings.notes_limit') }}</h4>
          <AutomationQuota :left="limits.left_today.embed" :daily="limits.embed_daily" :own="limits.own_embed_key" />
        </div>
        <div>
          <h4 class="mm-ai-label">{{ $t('automation.embeddings.search_limit') }}</h4>
          <AutomationQuota :left="limits.left_today.search" :daily="limits.search_daily" :own="limits.own_embed_key" />
        </div>
      </div>
      <h4 class="mm-ai-label">{{ $t('automation.personal_key') }}</h4>
      <p class="mm-note" v-if="ownEmbedKey">{{ $t('automation.key_in_use') }}</p>
      <p class="mm-note" v-else-if="limits">{{ $t(searchKeyUnused ? 'automation.embeddings.key_unused' : 'automation.embeddings.allowance') }}</p>

      <AutomationKeys
        section="embed"
        :keys="embedKeys"
        :providers="embedProviders"
        :keys-url="OPENAI_KEYS"
        :keys-url-label="$t('automation.embeddings.where')"
        :use-label="searchKeyUnused ? $t('automation.embeddings.use_key') : undefined"
        :using="adoptingSearchKey"
        @use="useForSearch"
        @changed="(settings) => (ai = settings)"
      />
    </div>

    <div class="mm-block" id="enrichment">
      <div class="mm-block-head">
        <h3 class="mm-block-title mb-0">{{ $t('automation.pane.enrichment_title') }}</h3>
        <span class="badge" :class="textActive ? 'text-bg-success' : 'text-bg-secondary'">{{ $t(textActive ? 'automation.pane.active' : 'automation.pane.inactive') }}</span>
      </div>
      <p class="mm-note">{{ $t('automation.pane.enrichment_why') }}</p>

      <div class="mm-ai-usage" v-if="limits && textActive">
        <h4 class="mm-ai-label">{{ $t('automation.pane.details_limit') }}</h4>
        <AutomationQuota :left="limits.left_today.text" :daily="limits.text_daily" :own="limits.own_text_key"
                         :model="includedText ? ai?.included_model : null" />
      </div>
      <h4 class="mm-ai-label">{{ $t('automation.personal_key') }}</h4>
      <p class="mm-note" v-if="ownTextKey">{{ $t('automation.key_in_use') }}</p>
      <p class="mm-note" v-else>{{ $t(includedText ? 'automation.pane.enrichment_allowance' : 'automation.pane.enrichment_key') }}</p>

      <AutomationKeys
        section="text"
        :keys="textKeys"
        :providers="textProviders"
        @changed="(settings) => (ai = settings)"
      />

      <AutomationRole v-if="textKeys.length"
        name="Enrichment"
        :role="enrichment"
        :keys="textKeys"
        :saving="savingEnrichment"
        :guidance="$t('automation.pane.enrichment_guidance')"
        @save="saveEnrichment"
      />
    </div>
    </template>
  </section>
</template>
