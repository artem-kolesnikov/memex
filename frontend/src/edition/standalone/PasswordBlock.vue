<script setup lang="ts">
// Settings › Account › Password. A wrong current password, a short new one and
// too many tries come back as codes and are worded here.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { toastError, toastSuccess } from '@/components/toastService'
import { useAuthStore } from '@/stores/auth'
import { owner } from './owner'

const { t } = useI18n()
const auth = useAuthStore()
const current = ref('')
const next = ref('')
const saving = ref(false)

const KNOWN = ['current', 'password_empty', 'password_long', 'throttled']

async function change() {
  if (saving.value) return
  saving.value = true
  try {
    const answer = await owner('PUT', '/api/me/password', { current: current.value, password: next.value })
    if (answer.ok) {
      current.value = ''
      next.value = ''
      toastSuccess(t('standalone.password.changed'))
      return
    }
    const code = answer.data.error ?? ''
    toastError(t('standalone.password.title'), t(KNOWN.includes(code) ? `standalone.errors.${code}` : 'standalone.errors.unreachable'))
  } catch {
    toastError(t('standalone.password.title'), t('standalone.errors.unreachable'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mm-block">
    <h3 class="mm-block-title">{{ $t('standalone.password.title') }}</h3>
    <p class="mm-note">{{ $t('standalone.password.intro') }}</p>
    <form class="mm-password-form" @submit.prevent="change">
      <input type="text" class="d-none" autocomplete="username" aria-hidden="true" tabindex="-1" :value="auth.user?.email ?? ''" readonly>
      <div class="mb-2">
        <label for="ms-current" class="form-label">{{ $t('standalone.password.current') }}</label>
        <input id="ms-current" v-model="current" type="password" class="form-control" autocomplete="current-password" required>
      </div>
      <div class="mb-3">
        <label for="ms-new" class="form-label">{{ $t('standalone.password.new') }}</label>
        <input id="ms-new" v-model="next" type="password" class="form-control" autocomplete="new-password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" :disabled="saving || current === '' || next === ''">
        {{ $t(saving ? 'standalone.password.saving' : 'standalone.password.submit') }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.mm-password-form { max-width: 24rem; }
</style>
