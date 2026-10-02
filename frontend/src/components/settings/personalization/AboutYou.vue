<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, type ProfileRef } from '@/api/client'
import { PROFILE_PROMPT } from '@/components/welcome/profilePrompt'
import { copyText } from '@/lib/clipboard'
import { useOperationLifetime } from '@/lib/operationLifetime'
import { toastError, toastSuccess } from '@/components/toastService'

defineProps<{ paragraph: string | null }>()

const lifetime = useOperationLifetime()
const router = useRouter()
const { t } = useI18n()

// The profile note, read from the notes every time this pane opens rather
// than remembered: it may have been deleted, retagged, or written by an
// assistant since. 'unknown' is a failed read, which is not "none".
const profiles = ref<ProfileRef[] | 'unknown' | null>(null)
const showPrompt = ref(false)
const promptCopied = ref(false)

async function loadProfiles() {
  const mine = lifetime.capture()
  try {
    const state = await api.welcome()
    if (!lifetime.current(mine)) return
    profiles.value = state.facts.profiles ?? []
  } catch {
    if (!lifetime.current(mine)) return
    profiles.value = 'unknown'
  }
}

async function copyProfilePrompt() {
  if (await copyText(PROFILE_PROMPT)) {
    promptCopied.value = true
    toastSuccess(t('welcome.profile_ask.copied'), t('welcome.profile_ask.copied_body'))
    window.setTimeout(() => (promptCopied.value = false), 2000)
  } else {
    toastError(t('common.copy_failed'), t('connections.copy_by_hand'))
  }
}

onMounted(() => {
  void loadProfiles()
})
</script>

<template>
  <div class="mm-block" data-profile-note>
    <h3 class="mm-block-title">{{ $t('personalization.about.title') }}</h3>
    <div class="mm-profile-settings">
      <p class="mm-note">{{ $t('personalization.profile_note.intro') }}</p>

      <p v-if="profiles === null" class="mm-note" role="status">{{ $t('common.loading') }}</p>
      <div v-else-if="profiles === 'unknown'" class="mm-profile-load-error" role="alert">
        <span>{{ $t('personalization.profile_note.load_failed') }}</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="loadProfiles">{{ $t('personalization.profile_note.retry') }}</button>
      </div>
      <template v-else>
        <template v-if="profiles.length === 0">
          <p class="mm-profile-empty">{{ $t('personalization.profile_note.none') }}</p>
          <button type="button" class="btn btn-outline-primary btn-sm"
                  @click="router.push({ name: 'welcome' })">{{ $t('personalization.profile_note.create') }}</button>
        </template>
        <div v-else-if="profiles.length === 1" class="mm-profile-note-row mm-settings-item">
          <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
          <div class="mm-profile-note-name mm-settings-item-details">
            <strong>{{ profiles[0]!.title }}</strong>
            <span class="mm-profile-note-status" v-if="profiles[0]!.status === 'pending'">{{ $t('personalization.profile_note.pending') }}</span>
          </div>
          <router-link class="btn btn-outline-primary btn-sm" :to="{ name: 'note', params: { id: profiles[0]!.id } }">{{ $t('personalization.profile_note.open') }}</router-link>
        </div>
        <template v-else>
          <p class="mm-profile-empty">{{ $t('personalization.profile_note.several') }}</p>
          <ul class="mm-profile-list">
            <li v-for="p in profiles" :key="p.id" class="mm-profile-note-row mm-settings-item">
              <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
              <div class="mm-profile-note-name mm-settings-item-details">
                <router-link :to="{ name: 'note', params: { id: p.id } }">{{ p.title }}</router-link>
                <span class="mm-profile-note-status" v-if="p.status === 'pending'">{{ $t('personalization.profile_note.pending') }}</span>
              </div>
            </li>
          </ul>
        </template>

        <details class="mm-pers-disclosure" v-if="paragraph" data-profile-told>
          <summary>{{ $t('personalization.about.told') }}</summary>
          <pre class="mm-pers-skill">{{ paragraph }}</pre>
        </details>

        <div class="mm-profile-assistant-action">
          <button type="button" class="btn btn-link p-0" :aria-expanded="showPrompt" aria-controls="personalization-profile-prompt"
                  @click="showPrompt = !showPrompt">{{ $t('personalization.profile_note.ask') }}<i class="fa-solid fa-chevron-down ms-2" :class="{ 'is-open': showPrompt }" aria-hidden="true"></i></button>
        </div>
        <div class="mm-profile-prompt" id="personalization-profile-prompt" v-if="showPrompt">
          <p class="mm-note">{{ $t('personalization.profile_note.prompt_hint') }}</p>
          <div class="mm-profile-message">
            <div class="mm-profile-message-heading">
              <span>{{ $t('welcome.profile_ask.prompt_label') }}</span>
              <button type="button" class="btn btn-link btn-sm p-0" @click="copyProfilePrompt">
                <i class="fa-regular fa-copy me-1" aria-hidden="true"></i>{{ promptCopied ? $t('common.copied') : $t('welcome.profile_ask.copy') }}
              </button>
            </div>
            <p class="mm-profile-prompt-text">{{ PROFILE_PROMPT }}</p>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
