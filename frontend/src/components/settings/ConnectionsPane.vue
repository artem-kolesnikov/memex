<script setup lang="ts">
// "Connections": which assistants can reach this knowledge base and what each
// of them is allowed to do without asking.
//
// The guides are four tabs, each assistant's steps one numbered list with the
// prompt last. Other mints a token for an agent that cannot complete an OAuth
// flow in a browser.
//
// Changes to the table are a DRAFT until Save. Promoting a token to curator is
// a decision about unattended writes to someone's knowledge base, and a
// checkbox that took effect on the click made it the easiest thing on the
// screen to do by accident.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, type IconChoice, type LogoChoice, type TokenInfo } from '@/api/client'
import { toastError, toastSuccess } from '@/components/toastService'
import AgentIdentityEditor from '@/components/settings/AgentIdentityEditor.vue'
import AgentMark from '@/components/AgentMark.vue'
import CurationAutomationPanel from '@/components/settings/CurationAutomationPanel.vue'
import ConnectGuide from '@/components/settings/ConnectGuide.vue'
import NewTokenDialog from '@/components/settings/NewTokenDialog.vue'
import { formatDate as writeDate } from '@/lib/datetime'
import { copyFetched } from '@/lib/clipboard'
import { useTokenMintStore } from '@/stores/tokenMint'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const mint = useTokenMintStore()
const auth = useAuthStore()

const route = useRoute()
const showConnect = ref(route.hash === '#connect')
watch(() => route.hash, (hash) => { if (hash === '#connect') showConnect.value = true })
const loaded = ref(false)
const connectionsRevision = ref(0)
const loadFailed = ref(false)
const tokens = ref<TokenInfo[]>([])
/** The shipped marks, as the server sent them beside the list. */
const icons = ref<IconChoice[]>([])
const logos = ref<LogoChoice[]>([])
/** Which connection's identity dialog is open, if any. */
const editing = ref<number | null>(null)
const saving = ref(false)

// The draft. Absent means "unchanged", so a row the user never touched is
// never sent, and a reload that brings new data cannot fight a stale edit.
const revokeDraft = ref<Set<number>>(new Set())

// Revoked connections are kept — they record which assistant wrote a note or
// filed a proposal, and a byline whose subject vanished is worse than a
// revoked one. They are no longer LISTED (operator, 2026-08-23): a connection
// that cannot reach anything is not a thing the owner has to manage, and a
// "show 3 revoked connections" link made it look like one.
const liveTokens = computed(() => tokens.value.filter((t) => !t.revoked))
/** Resolved rather than passed down a row, because the dialog is mounted once
 *  outside the table — a Bootstrap modal inside a <tr> is a modal inside a
 *  stacking context it does not own. */
const editingToken = computed(() => tokens.value.find((t) => t.id === editing.value) ?? null)

const isRevoking = computed(() => (token: TokenInfo) => revokeDraft.value.has(token.id))
// The guides' review sentences depend on it, and the table is the fact.
const hasCurator = computed(() => liveTokens.value.some((t) => t.role === 'curator'))

const dirty = computed(() => revokeDraft.value.size > 0)

let tokenRequest = 0

async function load() {
  const request = ++tokenRequest
  try {
    const answer = await api.tokens()
    if (request !== tokenRequest) return
    loaded.value = true
    if (!answer.tokens.some((token) => !token.revoked)) showConnect.value = true
    tokens.value = answer.tokens
    connectionsRevision.value++
    icons.value = answer.icons
    logos.value = answer.logos
    loadFailed.value = false
  } catch (e) {
    if (request !== tokenRequest) return
    loadFailed.value = true
    toastError(t('connections.load_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}
onMounted(load)
// A token minted on the Other tab is a new row in the table below.
watch(() => mint.minted, (minted) => {
  if (minted !== null) {
    void load()
  }
})

/** A token the clipboard refused, on screen to be copied by hand. */
const shownToken = ref<{ name: string; token: string } | null>(null)

async function copyToken(token: TokenInfo) {
  const name = token.display_name || token.label
  try {
    if (await copyFetched(() => api.tokenSecret(token.id))) {
      toastSuccess(t('connections.table.token_copied'), name)
    } else {
      shownToken.value = { name, token: await api.tokenSecret(token.id) }
    }
  } catch (e) {
    toastError(t('connections.new_token.copy_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

function markRevoked(token: TokenInfo) {
  revokeDraft.value = new Set(revokeDraft.value).add(token.id)
}

function undoRevoke(token: TokenInfo) {
  const next = new Set(revokeDraft.value)
  next.delete(token.id)
  revokeDraft.value = next
}

function discard() {
  revokeDraft.value = new Set()
}

async function save() {
  // One confirmation for the whole batch, naming the two consequences that
  // cannot be undone by unticking a box afterwards.
  const warnings: string[] = []
  if (revokeDraft.value.size) {
    warnings.push(t('connections.table.revoke_warning', revokeDraft.value.size))
  }
  if (warnings.length && !window.confirm(warnings.join('\n\n') + '\n\n' + t('connections.table.confirm_save'))) return

  saving.value = true
  const failures: string[] = []
  try {
    for (const id of revokeDraft.value) {
      try {
        await api.revokeToken(id)
      } catch (e) {
        failures.push(e instanceof Error ? e.message : t('connections.table.revoke_failed'))
      }
    }
    discard()
    await load()
    if (failures.length) {
      toastError(t('connections.table.partial_save'), failures[0])
    } else {
      toastSuccess(t('connections.table.saved'))
    }
  } finally {
    saving.value = false
  }
}


</script>

<template>
  <section class="mm-pane">

    <div class="alert alert-warning" v-if="loadFailed">
      {{ $t('connections.load_failed_alert') }}
    </div>

    <div class="mm-block" id="connect">
      <h3 class="mm-block-title">{{ $t('connections.quick.title') }}</h3>
      <p class="mm-note" v-if="auth.user?.web_assistants !== false">{{ $t('connections.quick.intro') }}</p>
      <button v-if="!showConnect && (!loaded || liveTokens.length)" type="button" class="btn btn-outline-primary"
              @click="showConnect = true">{{ $t('connections.quick.title') }}</button>
      <ConnectGuide v-if="showConnect" :curator="hasCurator" />
    </div>

    <div class="mm-block">
      <div class="mm-block-head">
        <h3 class="mm-block-title mb-0">{{ $t('connections.table.title') }}</h3>
        <span class="small text-muted" v-if="liveTokens.length">{{ $t('connections.table.connected_count', liveTokens.length) }}</span>
      </div>

      <!-- Stacked below `md`, like the review inbox and the invites table: the
           Actions dropdown lives in the last column, and a per-route sweep on
           2026-08-28 found it and eight other controls past the right edge here
           at every width, reachable only by scrolling the table sideways. Not
           on the operator's list — found by the sweep his list prompted. -->
      <div class="table-responsive" v-if="liveTokens.length">
        <table class="table align-middle mm-stack-sm app-table mm-settings-table">
          <thead>
            <tr>
              <th>{{ $t('connections.table.name') }}</th>
              <th>{{ $t('connections.table.auth') }}</th>
              <th>{{ $t('connections.table.created') }}</th>
              <th>{{ $t('connections.table.last_used') }}</th>
              <th class="text-end">{{ $t('connections.table.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="token in liveTokens" :key="token.id">
            <tr :class="{ 'mm-row-revoking': isRevoking(token) }">
              <td>
                <!-- Icon, chosen name, and — quietly under it — what the client
                     called itself. Both, because a rename that hid the
                     registered name would make "which of these two Googles is
                     it" unanswerable, which is the question renaming exists to
                     settle rather than to create. -->
                <div class="mm-settings-identity">
                  <!-- The mark is resolved server-side for every kind, so this
                       row and a byline elsewhere cannot disagree about it. -->
                  <AgentMark
                    :icon="token.icon"
                    :icon-url="token.icon_url"
                    :icon-url-dark="token.icon_url_dark"
                  />
                  <span>
                    {{ token.display_name || token.label }}
                    <span class="d-block small text-muted" v-if="token.display_name">{{ token.label }}</span>
                    <span class="d-block small text-muted" v-else-if="token.description">{{ token.description }}</span>
                  </span>
                </div>
              </td>
              <td :data-label="$t('connections.table.auth')"><span class="badge text-bg-light border">{{ token.auth === 'oauth' ? $t('connections.auth.oauth') : $t('connections.auth.manual') }}</span></td>
              <td class="text-nowrap" :data-label="$t('connections.table.created')">{{ writeDate(token.created_at) || '—' }}</td>
              <td class="text-nowrap" :data-label="$t('connections.table.last_used')">{{ writeDate(token.last_used_at) || '—' }}</td>
              <td class="text-end mm-stack-actions">
                <template v-if="isRevoking(token)">
                  <span class="badge text-bg-danger me-2">{{ $t('connections.table.will_be_revoked') }}</span>
                  <a href="javascript:void(0)" class="small" @click="undoRevoke(token)">{{ $t('connections.table.undo') }}</a>
                </template>
                <div class="dropdown" v-else>
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                          data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'
                          aria-expanded="false" :disabled="saving">
                    {{ $t('connections.table.actions') }}
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end app-menu">
                    <li>
                      <a class="dropdown-item" href="javascript:void(0)"
                         @click="editing = editing === token.id ? null : token.id">
                        <i class="fa-solid fa-pen fa-fw me-2 text-muted"></i>{{ $t('connections.table.edit') }}
                      </a>
                    </li>
                    <li v-if="token.token_kept">
                      <a class="dropdown-item" href="javascript:void(0)" @click="copyToken(token)">
                        <i class="fa-regular fa-copy fa-fw me-2 text-muted"></i>{{ $t('connections.table.copy_token') }}
                      </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <a class="dropdown-item text-danger" href="javascript:void(0)" @click="markRevoked(token)">
                        <i class="fa-solid fa-ban fa-fw me-2"></i>{{ $t('connections.table.revoke') }}
                      </a>
                    </li>
                  </ul>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>
      <p class="small text-muted" v-else-if="!loadFailed">
        {{ $t('connections.table.empty') }}
      </p>

      <AgentIdentityEditor
        v-if="editingToken"
        :key="editingToken.id"
        :token="editingToken"
        :icons="icons"
        :logos="logos"
        @saved="load"
        @close="editing = null"
      />

      <NewTokenDialog v-if="mint.minted" :name="mint.minted.name" :token="mint.minted.token"
                      @close="mint.minted = null" />
      <NewTokenDialog v-if="shownToken" :name="shownToken.name" :token="shownToken.token"
                      @close="shownToken = null" />

      <div class="mm-savebar" v-if="dirty">
        <span class="small">{{ $t('connections.table.not_saved') }}</span>
        <div>
          <button class="btn btn-sm btn-outline-secondary me-2" :disabled="saving" @click="discard">{{ $t('connections.table.discard') }}</button>
          <button class="btn btn-sm btn-primary" :disabled="saving" @click="save">
            {{ saving ? $t('connections.table.saving') : $t('connections.table.save_changes') }}
          </button>
        </div>
      </div>

    </div>


    <div class="mm-block" id="curation">
      <h3 class="mm-block-title">{{ $t('automation.pane.curation_title') }}</h3>
      <p class="mm-note mm-note-wide">{{ $t('automation.pane.curation_intro') }}</p>

      <CurationAutomationPanel :connections-revision="connectionsRevision" @changed="load" />
    </div>
  </section>
</template>
