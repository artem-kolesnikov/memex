<script setup lang="ts">
// Memex Standalone's sign-in screen, in place of the core's: the setup form
// while the server has no account, and the password form after. `next` returns
// only to the OAuth consent page, as on the core's screen, and the server checks
// it again.
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import PublicPage from '@/components/PublicPage.vue'
import { owner } from './owner'

const { t } = useI18n()
const route = useRoute()

const SETUP_CODE = 'docker exec memex php bin/console app:setup-code'
const RESET_PASSWORD = 'docker exec -it memex php bin/console app:reset-password'

const hasOwner = ref<boolean | null>(null)
const codeNeeded = ref(false)
const error = ref('')
const busy = ref(false)

const code = ref('')
const email = ref('')
const password = ref('')

const next = computed(() => {
  const raw = typeof route.query.next === 'string' ? route.query.next : ''
  return raw === '/oauth/authorize' || raw.startsWith('/oauth/authorize?') ? raw : ''
})

const KNOWN = ['credentials', 'code', 'code_needed', 'email', 'password_empty', 'password_long', 'owner_exists', 'one_owner', 'throttled']

function say(code: string | undefined) {
  if (code !== undefined && KNOWN.includes(code)) error.value = t(`standalone.errors.${code}`)
  else error.value = t('auth.login.errors.unfinished')
}


async function load() {
  error.value = ''
  try {
    const answer = await owner('GET', '/api/auth/owner')
    if (!answer.ok) throw new Error()
    hasOwner.value = answer.data.owner === true
    codeNeeded.value = answer.data.code === true
  } catch {
    error.value = t('standalone.errors.unreachable')
  }
}

onMounted(() => {
  const bounced = typeof route.query.error === 'string' ? route.query.error : ''
  load().then(() => { if (bounced) say(bounced) })
})

async function submit() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const answer = hasOwner.value
      ? await owner('POST', '/api/auth/password', { email: email.value, password: password.value, next: next.value || undefined })
      : await owner('POST', '/api/auth/owner', { code: code.value, email: email.value, password: password.value })
    if (answer.ok && answer.data.redirect) {
      window.location.assign(answer.data.redirect)
      return
    }
    if (answer.data.error === 'owner_exists') hasOwner.value = true
    if (answer.data.error === 'code_needed') codeNeeded.value = true
    say(answer.data.error)
  } catch {
    error.value = t('standalone.errors.unreachable')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <PublicPage class="mm-login">
    <h1 class="mm-login-welcome">{{ $t(hasOwner === false ? 'standalone.setup.title' : 'standalone.sign_in.title') }}</h1>

    <div class="alert alert-danger text-start" v-if="error">{{ error }}</div>

    <div class="mm-login-box text-start" v-if="hasOwner !== null">
      <i18n-t v-if="!hasOwner && codeNeeded" keypath="standalone.setup.intro" tag="p" class="mb-3" scope="global">
        <template #command><code>{{ SETUP_CODE }}</code></template>
      </i18n-t>
      <p v-else-if="!hasOwner" class="mb-3">{{ $t('standalone.setup.intro_open') }}</p>

      <form @submit.prevent="submit">
        <div class="mb-3" v-if="!hasOwner && codeNeeded">
          <label for="ms-code" class="form-label">{{ $t('standalone.setup.code') }}</label>
          <input id="ms-code" v-model="code" class="form-control" autocomplete="one-time-code" required>
        </div>
        <div class="mb-3">
          <label for="ms-email" class="form-label">{{ $t('standalone.sign_in.email') }}</label>
          <input id="ms-email" v-model="email" type="email" class="form-control" autocomplete="username" required>
        </div>
        <div class="mb-3">
          <label for="ms-password" class="form-label">{{ $t('standalone.sign_in.password') }}</label>
          <input id="ms-password" v-model="password" type="password" class="form-control"
                 :autocomplete="hasOwner ? 'current-password' : 'new-password'" required>
        </div>
        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
          <template v-if="hasOwner">{{ $t(busy ? 'standalone.sign_in.signing_in' : 'standalone.sign_in.submit') }}</template>
          <template v-else>{{ $t(busy ? 'standalone.setup.creating' : 'standalone.setup.submit') }}</template>
        </button>
      </form>
    </div>

    <div class="mm-login-box" v-else-if="error">
      <button type="button" class="btn btn-primary w-100" @click="load">{{ $t('auth.login.try_again') }}</button>
    </div>

    <i18n-t v-if="hasOwner" keypath="standalone.sign_in.forgotten" tag="p" class="mm-login-fineprint mt-3 mb-0" scope="global">
      <template #command><code>{{ RESET_PASSWORD }}</code></template>
    </i18n-t>
  </PublicPage>
</template>
