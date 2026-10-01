<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, type BrowserSession, type SignInProvider, type VaultStats } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import OperationOutcomeNotice from '@/components/OperationOutcomeNotice.vue'
import { toastError, toastSuccess } from '@/components/toastService'
import DeleteAccountDialog from '@/components/settings/DeleteAccountDialog.vue'
import { formatDate, formatDateTime } from '@/lib/datetime'
import { editions } from '@/editions'

const accountBlocks = editions.flatMap((edition) => edition.accountBlocks ?? [])

const auth = useAuthStore()
const lifetime = useOperationLifetime()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

// Your name is the only part of the account you can rewrite. Linking a provider
// deliberately does NOT touch it, so this is the only way.
const nameDraft = ref('')
const nameSaving = ref(false)
const profileUnknown = ref(false)
watch(sessionEpoch, () => { if (nameSaving.value || pictureSaving.value) profileUnknown.value = true })
const nameChanged = computed(
  () => nameDraft.value.trim() !== '' && nameDraft.value.trim() !== (auth.user?.name ?? ''),
)

async function saveName() {
  const mine = lifetime.capture()
  const name = nameDraft.value.trim()
  if (name === '' || !nameChanged.value) return
  nameSaving.value = true
  try {
    await auth.rename(name)
    if (!lifetime.current(mine)) return
    nameDraft.value = auth.user?.name ?? name
    toastSuccess(t('account.name.changed'))
  } catch (e) {
    if (!lifetime.current(mine)) return
    // Put the stored name back, so the field never shows something that is not
    // what the account is actually called.
    nameDraft.value = auth.user?.name ?? ''
    toastError(t('account.name.not_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (lifetime.current(mine)) nameSaving.value = false
  }
}

// Your picture. Refused here for kind and size before it is sent — the server
// checks the bytes anyway, but a 20 MB photograph should not be uploaded to be
// told no. It is re-encoded to 500px square on arrival and shown as a circle.
const PICTURE_TYPES = ['image/png', 'image/jpeg']
const PICTURE_MAX_BYTES = 8 * 1024 * 1024

const pictureSaving = ref(false)
const hasPicture = computed(() => auth.user?.icon_key === 'upload')
const pictureUrl = computed(() => auth.user?.icon_url ?? '/api/me/icon')
const initial = computed(() => auth.user?.initial ?? '?')

async function choosePicture(event: Event) {
  const mine = lifetime.capture()
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (file === null) return

  if (!PICTURE_TYPES.includes(file.type)) {
    toastError(t('account.picture.wrong_kind'), t('account.picture.wrong_kind_detail'))
    return
  }
  if (file.size > PICTURE_MAX_BYTES) {
    toastError(t('account.picture.too_large'), t('account.picture.too_large_detail'))
    return
  }

  pictureSaving.value = true
  try {
    await auth.uploadPicture(file)
    if (!lifetime.current(mine)) return
  } catch (e) {
    if (!lifetime.current(mine)) return
    toastError(t('account.picture.not_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (lifetime.current(mine)) pictureSaving.value = false
  }
}

async function removePicture() {
  const mine = lifetime.capture()
  pictureSaving.value = true
  try {
    await auth.clearPicture()
    if (!lifetime.current(mine)) return
  } catch (e) {
    if (!lifetime.current(mine)) return
    toastError(t('account.picture.not_removed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (lifetime.current(mine)) pictureSaving.value = false
  }
}

interface Identity {
  id: string
  provider: string
  label: string
  icon: string
  email: string | null
  linked_at: string
  last_used_at: string | null
}

const identities = ref<Identity[]>([])
const available = ref<SignInProvider[]>([])
/** The edition lets this account in by a way of its own, a password say. */
const ownWayIn = ref(false)
const methodsLoaded = ref(false)
const removing = ref<string | null>(null)

/** Providers that are offered here but not yet attached to this account. */
const unlinked = computed(() =>
  available.value.filter((p) => !identities.value.some((i) => i.provider === p.id)),
)

/** Whether removing this would leave nothing that opens the account. */
function isOnlyWayIn(): boolean {
  return identities.value.length <= 1 && !ownWayIn.value
}

/**
 * A Microsoft account is the one an employer can close without notice, and if
 * it is the last thing standing the knowledge base goes with it. Said only when
 * that is the actual position, so it is not a warning people learn to skip.
 */
const microsoftIsTheLastWayIn = computed(
  () =>
    identities.value.length === 1 &&
    !ownWayIn.value &&
    identities.value[0]?.provider === 'microsoft',
)

async function loadMethods() {
  try {
    const data = await api.signInMethods()
    identities.value = data.identities
    available.value = data.available
    ownWayIn.value = data.own_way_in
  } catch {
    // An installation with no providers configured answers this fine; a real
    // failure just leaves the block empty rather than breaking the pane.
    identities.value = []
    available.value = []
  } finally {
    methodsLoaded.value = true
  }
}

const LINK_ERRORS: Record<string, string> = {
  denied: 'account.link_errors.denied',
  state: 'account.link_errors.state',
  provider: 'account.link_errors.provider',
  unconfigured: 'account.link_errors.unconfigured',
  identity_taken: 'account.link_errors.identity_taken',
  link_session: 'account.link_errors.link_session',
}

function link(provider: string) {
  window.location.href = api.signInUrl(provider, { link: true })
}

async function remove(identity: Identity) {
  removing.value = identity.id
  try {
    await api.removeSignInMethod(identity.id)
    await loadMethods()
    toastSuccess(t('account.linked.removed', { label: identity.label }))
  } catch (e) {
    toastError(t('account.linked.not_removed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    removing.value = null
  }
}

// Where you are signed in. Browsers only: connected assistants hold bearer
// tokens and are switched off on the Connections pane, and a control here that
// silently disconnected them would be a second, worse version of one that
// already exists.
const sessions = ref<BrowserSession[]>([])
const endingSession = ref<string | null>(null)
const endingOthers = ref(false)
const others = computed(() => sessions.value.filter((s) => !s.current).length)

async function loadSessions() {
  try {
    sessions.value = (await api.sessions()).sessions
  } catch {
    sessions.value = []
  }
}

async function endSession(session: BrowserSession) {
  endingSession.value = session.id
  try {
    await api.endSession(session.id)
    await loadSessions()
    toastSuccess(t('account.devices.signed_out'), t('account.devices.signed_out_detail', { browser: session.browser }))
  } catch (e) {
    toastError(t('account.devices.not_signed_out'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    endingSession.value = null
  }
}

async function endOtherSessions() {
  endingOthers.value = true
  try {
    const { ended } = await api.endOtherSessions()
    await loadSessions()
    toastSuccess(t('account.devices.others_signed_out', ended), t('account.devices.this_one_stays'))
  } catch (e) {
    toastError(t('account.devices.not_signed_out'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    endingOthers.value = false
  }
}

function when(iso: string | null): string {
  return iso === null ? t('account.not_yet') : formatDate(iso)
}

/** Last-used wants the time of day; a date alone cannot tell you it was an hour ago. */
function whenExact(iso: string | null): string {
  return iso === null ? t('account.not_yet') : formatDateTime(iso)
}

const stats = ref<VaultStats | null>(null)
const deleting = ref(false)


onMounted(async () => {
  nameDraft.value = auth.user?.name ?? ''
  // The catalogue is static and small; a failure here leaves the box showing
  // the current face with nothing to change it to, which is the right failure.
  try {
    stats.value = await api.stats()
  } catch {
    // The count is in the confirmation only; failing to read it must not put
    // the export out of reach.
    stats.value = null
  }
  await loadMethods()
  await loadSessions()

  // The OAuth callback comes back here with ?linked= or ?error=, because it is
  // a full page load and cannot resolve a toast on the other side of it.
  const linked = typeof route.query.linked === 'string' ? route.query.linked : ''
  const failed = typeof route.query.error === 'string' ? route.query.error : ''
  if (linked) {
    const label = available.value.find((p) => p.id === linked)?.label ?? linked
    toastSuccess(t('account.linked.added', { label }), t('account.linked.added_detail', { label }))
  }
  if (failed) {
    toastError(t('account.linked.not_added'), t(LINK_ERRORS[failed] ?? 'account.link_errors.unknown'))
  }
  if (linked || failed) {
    router.replace({ name: 'settings', params: { pane: 'account' } })
  }
})
</script>

<template>
  <section class="mm-pane">

    <div class="mm-block">
      <h3 class="mm-block-title">{{ $t('account.profile.title') }}</h3>
      <OperationOutcomeNotice v-if="profileUnknown" />
      <form v-else @submit.prevent="saveName" class="mm-account-details">
        <div class="d-flex align-items-center gap-3">
          <!-- The circle IS the control: pressing it opens the file browser.
               A separate "upload" button beside a picture of you is one thing
               too many for what is obviously the thing you would click. -->
          <span class="mm-avatar-well">
            <span class="mm-avatar mm-avatar-button" aria-hidden="true">
              <img v-if="hasPicture" :src="pictureUrl" alt="">
              <span v-else class="mm-avatar-initial">{{ initial }}</span>
            </span>
            <input type="file" class="mm-file-input" accept="image/png,image/jpeg"
                   :disabled="pictureSaving"
                   :aria-label="hasPicture ? $t('account.picture.change') : $t('account.picture.upload')"
                   :title="hasPicture ? $t('account.picture.change') : $t('account.picture.upload')"
                   @change="choosePicture">
            <button type="button" class="mm-avatar-clear" v-if="hasPicture"
                    :disabled="pictureSaving" :aria-label="$t('account.picture.remove')"
                    @click="removePicture">×</button>
          </span>

          <div class="mm-account-name">
            <label for="display_name" class="form-label">{{ $t('account.profile.preferred_name') }}</label>
            <div class="input-group mb-0">
              <input type="text" id="display_name" class="form-control" maxlength="120"
                     autocomplete="name" v-model="nameDraft" :disabled="nameSaving">
              <button type="submit" class="btn btn-primary"
                      :disabled="nameSaving || !nameChanged">{{ $t('common.save') }}</button>
            </div>
          </div>
        </div>
      </form>
    </div>

    <component :is="block" v-for="(block, i) in accountBlocks" :key="i" />

    <div class="mm-block">
      <h3 class="mm-block-title">{{ $t('account.linked.title') }}</h3>

      <div class="table-responsive mb-3" v-if="identities.length">
        <table class="table app-table align-middle mm-settings-table mm-stack-sm">
          <thead><tr>
            <th>{{ $t('account.linked.account') }}</th>
            <th>{{ $t('account.added') }}</th>
            <th>{{ $t('account.linked.last_used') }}</th>
            <th class="text-end">{{ $t('connections.table.actions') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="i in identities" :key="i.id">
              <td>
                <div class="mm-settings-identity">
                  <i :class="i.icon" aria-hidden="true"></i>
                  <span>{{ i.label }}<span v-if="i.email" class="d-block small text-muted">{{ i.email }}</span></span>
                </div>
              </td>
              <td :data-label="$t('account.added')">{{ when(i.linked_at) }}</td>
              <td :data-label="$t('account.linked.last_used')">{{ when(i.last_used_at) }}</td>
              <td class="text-end mm-stack-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        :disabled="removing !== null || isOnlyWayIn()"
                        @click="remove(i)">{{ $t('common.remove') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="mm-note" v-if="isOnlyWayIn()">
        {{ $t('account.linked.at_least_one') }}
      </p>

      <p class="mm-note" v-if="microsoftIsTheLastWayIn">
        {{ $t('account.linked.microsoft_last') }}
      </p>

      <div v-if="unlinked.length">
        <button v-for="p in unlinked" :key="p.id" type="button"
                class="btn btn-outline-secondary btn-sm me-2 mb-2"
                @click="link(p.id)">
          <i :class="p.icon" class="me-2"></i>{{ $t('account.linked.add', { label: p.label }) }}
        </button>
      </div>

      <p class="mm-note mb-0" v-if="methodsLoaded && available.length === 0">
        <span class="mm-soon">{{ $t('account.linked.not_set_up') }}</span>
      </p>
    </div>

    <div class="mm-block">
      <h3 class="mm-block-title">{{ $t('account.devices.title') }}</h3>
      <p class="mm-note">{{ $t('account.devices.intro') }}</p>

      <div class="table-responsive mb-3" v-if="sessions.length">
        <table class="table app-table align-middle mm-settings-table mm-stack-sm">
          <thead><tr>
            <th>{{ $t('account.devices.browser') }}</th>
            <th>{{ $t('account.devices.ip') }}</th>
            <th>{{ $t('account.devices.last_signed_in') }}</th>
            <th class="text-end">{{ $t('connections.table.actions') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="s in sessions" :key="s.id">
              <td>
                <div class="mm-settings-identity">
                  <i class="fa-solid fa-display" aria-hidden="true"></i>
                  <span>{{ s.browser }}<span v-if="s.current" class="d-block small text-muted">{{ $t('account.devices.this_browser') }}</span></span>
                </div>
              </td>
              <td :data-label="$t('account.devices.ip')">{{ s.ip || '—' }}</td>
              <td :data-label="$t('account.devices.last_signed_in')">{{ whenExact(s.last_seen_at) }}</td>
              <td class="text-end mm-stack-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm" v-if="!s.current"
                        :disabled="endingSession === s.id || endingOthers"
                        @click="endSession(s)">{{ $t('account.devices.sign_out') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <button type="button" class="btn btn-outline-secondary btn-sm"
              v-if="others > 0" :disabled="endingOthers" @click="endOtherSessions">
        {{ $t('account.devices.sign_out_others') }}
      </button>
    </div>

    <div class="mm-block">
      <h3 class="mm-block-title">{{ $t('account.delete.title') }}</h3>
      <p class="mm-note mm-note-wide">{{ $t('account.delete.intro') }}</p>
      <p class="mm-note">
        <router-link :to="{ name: 'settings', params: { pane: 'content' }, hash: '#export' }">{{ $t('account.delete.export_first') }}</router-link>
      </p>
      <button type="button" class="btn btn-outline-danger btn-sm" @click="deleting = true">
        {{ $t('account.delete.title') }}
      </button>

      <DeleteAccountDialog v-if="deleting" :notes="stats?.notes.total ?? 0"
                           @close="deleting = false" />
    </div>
  </section>
</template>
