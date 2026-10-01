<script setup lang="ts">
// Your space: the two names and the theme. Names save on blur through the
// existing account endpoints, one request after another; the theme applies
// to the whole app at once through the same mechanism Settings uses.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import { toastError } from '@/components/toastService'
import { enqueue, saved, sent, settled } from './nameSaves'
import ThemeChoice from '@/components/ThemeChoice.vue'

const auth = useAuthStore()
const lifetime = useOperationLifetime()
const { t } = useI18n()

// A save still out from before a pause is the newest value, not the store's.
const nameDraft = ref(sent.name ?? auth.user?.name ?? '')
const memexDraft = ref(sent.memex ?? auth.user?.team.name ?? '')
const nameError = ref(false)
const memexError = ref(false)
const saving = ref(0)

// One save at a time, in order. Blurring one field and then the other fires
// two requests, and the second must not be lost behind the first or land
// before it: each waits for the one before. While a request is out its value
// is the one to compare against, not the store's: typing the old name back
// over an unanswered rename must send the old name again, and blurring twice
// — or Next, which flushes both — must not send it twice.

async function saveName(): Promise<boolean> {
  const epoch = sessionEpoch.value
  const mine = lifetime.capture()
  const name = nameDraft.value.trim()
  if (name === '') {
    nameDraft.value = auth.user?.name ?? ''
    return true
  }
  if (name === (sent.name ?? saved.name ?? auth.user?.name)) return true
  sent.name = name
  saving.value++
  let ok = true
  try {
    await enqueue(() => auth.rename(name))
    if (epoch !== sessionEpoch.value) return false
    saved.name = name
    nameError.value = false
  } catch (e) {
    if (!lifetime.current(mine)) return false
    ok = false
    nameError.value = true
    toastError(t('account.name.not_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (epoch === sessionEpoch.value && sent.name === name) sent.name = null
    saving.value--
  }
  return ok
}

async function saveMemexName(): Promise<boolean> {
  const epoch = sessionEpoch.value
  const mine = lifetime.capture()
  const name = memexDraft.value.trim()
  if (name === '') {
    memexDraft.value = auth.user?.team.name ?? ''
    return true
  }
  if (name === (sent.memex ?? saved.memex ?? auth.user?.team.name)) return true
  sent.memex = name
  saving.value++
  let ok = true
  try {
    await enqueue(() => auth.renameMemex(name))
    if (epoch !== sessionEpoch.value) return false
    saved.memex = name
    memexError.value = false
  } catch (e) {
    if (!lifetime.current(mine)) return false
    ok = false
    memexError.value = true
    toastError(t('account.name.not_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (epoch === sessionEpoch.value && sent.memex === name) sent.memex = null
    saving.value--
  }
  return ok
}

function unsaved(): boolean {
  const name = nameDraft.value.trim()
  const memex = memexDraft.value.trim()
  return (name !== '' && name !== (saved.name ?? auth.user?.name)) || (memex !== '' && memex !== (saved.memex ?? auth.user?.team.name))
}

/**
 * Save whatever is still unsaved and wait for what is in flight; the shell
 * awaits this before moving on. Typing continues while a save is out, so
 * the drafts are read again once the queue is empty and saved again if
 * they moved.
 */
async function flush(): Promise<boolean> {
  const mine = lifetime.capture()
  for (let round = 0; round < 5; round++) {
    const results = await Promise.all([saveName(), saveMemexName()])
    await settled()
    if (!lifetime.current(mine)) return false
    if (!results.every(Boolean) || nameError.value || memexError.value) return false
    if (!unsaved()) return true
  }
  return false
}

defineExpose({ flush })

const busy = computed(() => saving.value > 0)
</script>

<template>
  <section>
    <div class="mm-wiz-heading">
      <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.you.title') }}</h1>
      <p class="mm-wiz-intro">{{ $t('welcome.you.intro') }}</p>
    </div>

    <form @submit.prevent>
      <div class="mm-wiz-fields">
        <div class="mm-wiz-field">
          <label for="wiz-name">{{ $t('welcome.you.name') }}</label>
          <input id="wiz-name" type="text" maxlength="120" autocomplete="name"
                 :placeholder="$t('welcome.you.name_placeholder')" v-model="nameDraft"
                 :aria-invalid="nameError || undefined" @blur="saveName">
          <small :class="{ 'is-error': nameError }">
            {{ nameError ? $t('welcome.you.not_saved') : $t('welcome.you.name_hint') }}
          </small>
        </div>
        <div class="mm-wiz-field">
          <label for="wiz-memex">{{ $t('welcome.you.memex_name') }}</label>
          <input id="wiz-memex" type="text" maxlength="120" autocomplete="off"
                 v-model="memexDraft" :aria-invalid="memexError || undefined" @blur="saveMemexName">
          <small :class="{ 'is-error': memexError }">
            {{ memexError ? $t('welcome.you.not_saved') : $t('welcome.you.memex_hint') }}
          </small>
        </div>
      </div>

      <fieldset class="mm-wiz-appearance">
        <legend>{{ $t('welcome.you.look') }}</legend>
        <ThemeChoice />
      </fieldset>
    </form>
    <p class="visually-hidden" aria-live="polite" v-if="busy">{{ $t('common.saving') }}</p>
  </section>
</template>
