<script setup lang="ts">
// Limbo, beside the tag vocabulary it belongs with: both are things this
// knowledge base removed and can take back. A count and a way in rather than
// the list itself, which lives in its own dialog.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type DeletedNote } from '@/api/client'
import { toastError } from '@/components/toastService'
import DeletedNotesDialog from '@/components/settings/DeletedNotesDialog.vue'

const { t } = useI18n()

const loading = ref(true)
const failed = ref(false)
const notes = ref<DeletedNote[]>([])
const limboDays = ref(30)
const open = ref(false)

const restorable = computed(() => notes.value.filter((n) => n.restorable))
// What the countdown is about to take, which is the only reason to come here
// before something has gone missing.
const soon = computed(() => restorable.value.filter((n) => n.days_left <= 7).length)

async function load() {
  loading.value = true
  try {
    const result = await api.deletedNotes(false)
    notes.value = result.notes
    limboDays.value = result.limbo_days
    failed.value = false
  } catch (e) {
    failed.value = true
    toastError(t('content.deleted.load_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <div class="mm-block" id="deleted">
    <div class="mm-block-head">
      <h3 class="mm-block-title mb-0">{{ $t('content.deleted.title') }}</h3>
      <span class="small text-muted" v-if="!loading && !failed">
        {{ $t('content.deleted.restorable', { n: restorable.length }) }}
      </span>
    </div>

    <p class="mm-note">{{ $t('content.deleted.intro', { days: limboDays }) }}</p>

    <p class="mm-note mb-0" v-if="failed">
      {{ $t('content.deleted.count_failed') }}
    </p>
    <i18n-t v-else-if="soon > 0" keypath="content.deleted.soon" :plural="soon"
            tag="p" class="mm-note mb-0" scope="global">
      <template #n><strong>{{ soon }}</strong></template>
    </i18n-t>

    <div class="mt-3">
      <button type="button" class="btn btn-outline-secondary btn-sm" @click="open = true">
        <i class="fa-solid fa-trash-can-arrow-up me-1"></i>{{ $t('content.deleted.open') }}
      </button>
    </div>

    <DeletedNotesDialog v-if="open" @changed="load" @close="open = false" />
  </div>
</template>
