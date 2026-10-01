<script setup lang="ts">
// The key ONE section runs on, listed and added inside that section.
//
// A section holds at most one key and uses it (operator, 2026-09-10). The
// "Paid by" selector that used to sit under a list of them is gone: a key
// added and not used was a screen saying somebody had brought their own
// account while the bill still came here.
//
// The STORE is still one — a key belongs to the knowledge base, not to a
// section — so a key added here is listed by every section its provider can
// serve, and deleting it in any of them deletes it everywhere. That is what
// the confirmation says, because it is the one thing this layout could
// otherwise hide.
//
// Only the section it was added FROM starts USING it, which is why `section`
// is a prop rather than something the server infers: adopting by provider
// switched descriptions on for a key somebody added under Semantic search.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type AiKey, type AiProvider, type AiSettings } from '@/api/client'
import { toastError, toastSuccess } from '@/components/toastService'
import AddProviderDialog from '@/components/settings/AddProviderDialog.vue'
import { formatDateTime } from '@/lib/datetime'

const props = defineProps<{
  keys: AiKey[]
  providers: AiProvider[]
  /** Which section this list belongs to. Only that section adopts the key. */
  section: 'embed' | 'text'
  /** Where the provider sells one. Shown beside the button, never instead. */
  keysUrl?: string
  keysUrlLabel?: string
  /** Offered on a readable key this section lists but does not run on. */
  useLabel?: string
  using?: boolean
}>()

const emit = defineEmits<{ changed: [settings: AiSettings]; use: [key: AiKey] }>()

const { t } = useI18n()

const adding = ref(false)
const unreadable = computed(() => props.keys.filter((k) => k.readable === false))
const full = computed(() => props.keys.length > 0)

async function deleteKey(key: AiKey) {
  if (!window.confirm(t('automation.pane.delete_key_confirm', { name: key.name }))) return
  try {
    emit('changed', await api.deleteAiKey(key.id))
    toastSuccess(t('automation.pane.key_deleted'))
  } catch (e) {
    toastError(t('automation.pane.delete_key_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

function formatWhen(iso: string | null) {
  return iso ? formatDateTime(iso) : t('automation.never')
}
</script>

<template>
  <div class="table-responsive mb-2" v-if="keys.length">
    <table class="table app-table align-middle mb-0 mm-settings-table mm-stack-sm">
      <thead>
        <tr>
          <th>{{ $t('automation.name') }}</th>
          <th>{{ $t('automation.provider') }}</th>
          <th>{{ $t('automation.pane.key') }}</th>
          <th>{{ $t('automation.pane.last_used') }}</th>
          <th class="text-end">{{ $t('connections.table.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="key in keys" :key="key.id">
          <td>
            {{ key.name }}
            <span v-if="!key.verified_at" class="badge text-bg-secondary ms-1">{{ $t('automation.pane.unverified') }}</span>
            <span v-if="key.readable === false" class="badge text-bg-danger ms-1">{{ $t('automation.pane.unreadable') }}</span>
          </td>
          <td :data-label="$t('automation.provider')">{{ key.provider_label }}</td>
          <td :data-label="$t('automation.pane.key')"><code>{{ key.provider === 'google' ? 'AIza' : 'sk-' }}*********{{ key.hint }}</code></td>
          <td :data-label="$t('automation.pane.last_used')">{{ formatWhen(key.last_used_at) }}</td>
          <td class="text-end mm-stack-actions">
            <button v-if="useLabel && key.readable !== false" type="button" class="btn btn-outline-primary btn-sm me-2"
                    :disabled="using" @click="emit('use', key)">{{ useLabel }}</button>
            <button type="button" class="btn btn-outline-danger btn-sm" @click="deleteKey(key)">{{ $t('common.delete') }}</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <p v-if="unreadable.length" class="small danger">
    {{ $t('automation.pane.unreadable_notice', { names: unreadable.map((k) => k.name).join($t('automation.pane.names_joiner')) }, unreadable.length) }}
  </p>

  <div class="d-flex align-items-center flex-wrap gap-2 mb-3" v-if="!full">
    <button type="button" class="btn btn-outline-secondary btn-sm" @click="adding = true">
      <i class="fa-solid fa-plus me-1"></i>
      {{ $t('automation.add_key') }}
    </button>
    <a v-if="keysUrl" class="small" :href="keysUrl" target="_blank" rel="noopener">
      {{ keysUrlLabel }}
    </a>
  </div>

  <!-- Mounted outside the table: a Bootstrap modal inside a <tr> is a modal
       inside a stacking context it does not own. -->
  <AddProviderDialog
    v-if="adding"
    :section="section"
    :providers="providers"
    @added="(settings) => emit('changed', settings)"
    @close="adding = false"
  />
</template>
