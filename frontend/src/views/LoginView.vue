<script setup lang="ts">
// The sign-in screen: the provider buttons. An edition with its own way in
// replaces it (src/editions.ts).
//
// The `error` codes come back on the URL from the OAuth callback. The wording
// lives here rather than on the server on purpose: a sentence carried in a
// query string is a sentence anyone can choose, and this page would render it
// with our styling around it. The ORDER of the buttons is the server's, not a
// list written here: see SocialProviders::CATALOGUE.
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, type SignInProvider } from '@/api/client'
import PublicPage from '@/components/PublicPage.vue'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()

const error = ref('')
const providers = ref<SignInProvider[]>([])

/**
 * Where to go after signing in: only the OAuth consent page. An unchecked
 * `next` on a sign-in screen is an open redirect, and the server applies the
 * same rule again on its own side.
 */
const next = computed(() => {
  const raw = typeof route.query.next === 'string' ? route.query.next : ''
  return raw === '/oauth/authorize' || raw.startsWith('/oauth/authorize?') ? raw : ''
})
/** The server did not answer what it offers, so this page knows of no door. */
const unreachable = ref(false)

const SIGN_IN_ERRORS = ['denied', 'state', 'provider', 'unconfigured', 'no_email', 'email_in_use', 'identity_taken', 'link_session', 'suspended']

async function loadOffered() {
  unreachable.value = false
  try {
    providers.value = (await api.signInProviders()).providers
  } catch {
    providers.value = []
    unreachable.value = true
  }
}

onMounted(async () => {
  const code = typeof route.query.error === 'string' ? route.query.error : ''
  if (code) {
    error.value = t(`auth.login.errors.${SIGN_IN_ERRORS.includes(code) ? code : 'unfinished'}`)
    // Cleared from the URL so a refresh does not re-accuse.
    router.replace({ name: 'login', query: { ...route.query, error: undefined } })
  }
  await loadOffered()
})

function signInWith(provider: string) {
  window.location.href = api.signInUrl(provider, { next: next.value || undefined })
}
</script>

<template>
  <PublicPage class="mm-login">
    <h1 class="mm-login-welcome">{{ $t('auth.login.welcome_back') }}</h1>

    <div class="alert alert-danger text-start" v-if="error">{{ error }}</div>

    <div class="mm-login-box">
      <div v-if="providers.length" class="d-grid gap-2">
        <button v-for="p in providers" :key="p.id" type="button"
                class="btn btn-outline-secondary mm-login-provider"
                @click="signInWith(p.id)">
          <i :class="p.icon" class="me-2"></i>{{ $t('auth.continue_with', { provider: p.label }) }}
        </button>
      </div>

      <div v-if="unreachable" class="text-start">
        <p class="mb-3">{{ $t('auth.login.unreachable') }}</p>
        <button type="button" class="btn btn-primary w-100" @click="loadOffered">{{ $t('auth.login.try_again') }}</button>
      </div>
    </div>
  </PublicPage>
</template>
