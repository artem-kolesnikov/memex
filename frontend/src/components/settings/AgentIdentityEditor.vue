<!--
  Naming a connected assistant, describing it, and giving it a face; for a
  connection made in Settings, its token too, masked, with a copy button.

  ## What it is for

  A token's name is whatever the client called itself when it registered, and
  clients do not coordinate: the operator's own box carries two live rows both
  called `oauth: Google`, and several Claudes. Once the notes list shows which
  assistant last touched a note, four things called Claude stop being
  information (operator, 2026-08-23).

  ## Why the registered name stays on screen

  The dialog keeps showing what the client called itself, quietly, under the
  heading. A rename that hid the original would make it impossible to answer
  "which of these two is the one I connected from the laptop" — which is the
  question the rename is meant to settle, not create.

  ## A modal, and a draft

  It opened as a row underneath the connection and saved each field the moment
  it changed. The operator asked for a modal with Save and Cancel
  (2026-08-23), and those two buttons change what the form IS: nothing is
  written until Save, so Cancel means what it says.

  The one thing that cannot be deferred is an upload — the bytes have to reach
  the server to be re-encoded, and that endpoint sets the icon as a side
  effect. So abandoning the dialog after an upload puts the previous icon back
  explicitly. The uploaded bytes stay on the connection, which is invisible and
  harmless; what the operator can see is restored.

  ## Two tabs in the picker

  The catalogue doubled on 2026-08-23 when the operator supplied real provider
  marks, and one flat grid of forty-six tiles is the shape this picker was
  rebuilt to escape. So the dropdown has a Logos tab and an Icons tab, and it
  opens on the one already in use — somebody who has chosen Gemini and reopens
  this is switching provider, not looking for a wand. "None" sits in both,
  because having to remember which tab clears an icon would be a puzzle.

  ## Every exit means the same thing

  That revert used to live in the Cancel handler, so the three ways out of a
  Bootstrap modal disagreed: Cancel undid an upload, Escape and a click on the
  backdrop kept it. Which is why "click outside and the image sticks" was a
  workaround for Save being broken (2026-08-23) rather than a second bug
  anybody had noticed. It hangs off `hidden.bs.modal` now — the one event all
  three exits fire — so leaving without saving means the same thing however you
  leave. A successful Save hides the dialog through the same event, hence the
  flag that tells the two apart.
-->
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type IconChoice, type LogoChoice, type TokenInfo } from '@/api/client'
import { toastError, toastSuccess } from '@/components/toastService'
import AgentMark from '@/components/AgentMark.vue'
import { copyFetched } from '@/lib/clipboard'

const props = defineProps<{ token: TokenInfo; icons: IconChoice[]; logos: LogoChoice[] }>()
const emit = defineEmits<{ saved: []; close: [] }>()
const { t } = useI18n()

const name = ref(props.token.display_name ?? '')
const description = ref(props.token.description ?? '')
const iconKey = ref<string | null>(props.token.icon_key)
/** Set the moment an upload succeeds, so the preview updates without a reload. */
const uploadedAt = ref(0)
const saving = ref(false)
const uploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void; dispose(): void } | null = null

// What the icon was when the dialog opened. Cancel needs it because an upload
// has already changed the connection by the time this dialog closes.
const iconOnOpen = props.token.icon_key

/** The glyph catalogue as a flat grid: no group labels (operator, 2026-08-23). */
const choices = computed(() => props.icons)

/**
 * Which tab the picker opens on, and it opens on the one you are already using.
 * Somebody who has chosen Gemini and reopens the dialog is far more likely to
 * be switching to another provider than to a wand.
 */
const tab = ref<'logos' | 'icons'>(props.token.icon_key?.startsWith('builtin:') ? 'icons' : 'logos')

const hasUpload = computed(() => iconKey.value === 'upload')
/** Cache-busted, because the URL does not change when the bytes behind it do. */
const uploadUrl = computed(
  () => `/api/tokens/${props.token.id}/icon${uploadedAt.value ? `?v=${uploadedAt.value}` : ''}`,
)
const chosenGlyph = computed(() => choices.value.find((i) => i.key === iconKey.value) ?? null)
const chosenLogo = computed(() => props.logos.find((l) => l.key === iconKey.value) ?? null)

/** Set by a successful Save, so the close it triggers is not read as abandoning. */
const savedOk = ref(false)

const tokenKept = ref(props.token.token_kept)
/** The token in the clear, only after the clipboard refused it. */
const tokenShown = ref<string | null>(null)
const tokenCopied = ref(false)
const reissuing = ref(false)

async function copy(fetchToken: () => Promise<string>): Promise<boolean> {
  let token = ''
  const copied = await copyFetched(() => fetchToken().then((value) => (token = value)))
  if (copied) {
    tokenCopied.value = true
    window.setTimeout(() => (tokenCopied.value = false), 2000)
  } else {
    tokenShown.value = token
    toastError(t('connections.new_token.copy_failed'), t('connections.copy_by_hand'))
  }
  return copied
}

async function copyToken() {
  try {
    await copy(() => api.tokenSecret(props.token.id))
  } catch (e) {
    toastError(t('connections.new_token.copy_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

async function reissue() {
  if (!window.confirm(t('connections.identity.new_token_confirm'))) return
  reissuing.value = true
  try {
    const copied = await copy(() => api.reissueToken(props.token.id))
    tokenKept.value = true
    emit('saved')
    if (copied) toastSuccess(t('connections.identity.new_token_copied'), name.value.trim() || props.token.label)
  } catch (e) {
    toastError(t('connections.identity.new_token_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    reissuing.value = false
  }
}

onMounted(() => {
  if (dialog.value === null) return
  modal = new window.bootstrap.Modal(dialog.value)
  // The one exit: Bootstrap fires this for the close button, Escape and a
  // click on the backdrop alike, so the parent is told once however it ended —
  // and so the upload revert happens once, however it ended.
  dialog.value.addEventListener('hidden.bs.modal', () => {
    void revertUploadIfAbandoned().then(() => emit('close'))
  })
  modal.show()
})
// `hide()` alone is not a teardown. Bootstrap returns from it immediately
// while the dialog is still transitioning, so unmounting mid-fade leaves its
// deferred show callback to put the element back on <body> — a dialog with no
// component behind it, over a backdrop and a scroll lock nothing will clear.
// `dispose()` drops the instance, its backdrop and its handlers either way.
onBeforeUnmount(() => {
  modal?.hide()
  modal?.dispose()
  modal = null
})

async function save() {
  saving.value = true
  try {
    await api.updateToken(props.token.id, {
      // Empty means "go back to what the client called it", which is why this
      // sends null rather than an empty string.
      display_name: name.value.trim() === '' ? null : name.value,
      description: description.value.trim() === '' ? null : description.value,
      icon_key: iconKey.value,
    })
    savedOk.value = true
    emit('saved')
    toastSuccess(t('connections.identity.saved'), name.value.trim() === '' ? props.token.label : name.value)
    modal?.hide()
  } catch (e) {
    toastError(t('connections.identity.save_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    saving.value = false
  }
}

/**
 * See the note at the top: an upload has already landed, so leaving without
 * saving means putting the icon that was showing back. Runs on the way out
 * whichever way that was — button, Escape or backdrop.
 */
async function revertUploadIfAbandoned() {
  if (savedOk.value) return
  if (uploadedAt.value === 0 || iconOnOpen === 'upload') return
  try {
    await api.updateToken(props.token.id, { icon_key: iconOnOpen })
    emit('saved')
  } catch {
    /* the dialog is closing either way — a failed revert is not worth a toast */
  }
}

function cancel() {
  modal?.hide()
}

async function onFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  uploading.value = true
  try {
    const result = await api.uploadTokenIcon(props.token.id, file)
    iconKey.value = result.icon_key
    uploadedAt.value = Date.now()
    emit('saved')
  } catch (e) {
    toastError(t('connections.identity.icon_rejected'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    uploading.value = false
    // Cleared so choosing the SAME file again still fires a change event.
    if (fileInput.value) fileInput.value.value = ''
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade" tabindex="-1" ref="dialog" aria-labelledby="rename-connection-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title h6 mb-0" id="rename-connection-title">{{ $t('connections.identity.title') }}</h5>
              <!-- The registered name, always. See the note at the top. -->
              <span class="small text-muted">
                {{ $t('connections.identity.connected_as', { label: token.label, auth: token.auth === 'oauth' ? $t('connections.auth.oauth') : $t('connections.identity.auth_manual') }) }}
              </span>
            </div>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="cancel"></button>
          </div>

          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label" :for="'name-' + token.id">{{ $t('connections.identity.display_name') }}</label>
              <input
                :id="'name-' + token.id"
                class="form-control"
                v-model="name"
                :maxlength="80"
                :placeholder="token.label"
                :disabled="saving"
              />
            </div>

            <div class="mb-3">
              <label class="form-label" :for="'desc-' + token.id">{{ $t('connections.identity.description') }}</label>
              <textarea
                :id="'desc-' + token.id"
                class="form-control"
                rows="2"
                v-model="description"
                :maxlength="500"
                :placeholder="$t('connections.identity.description_placeholder')"
                :disabled="saving"
              ></textarea>
            </div>

            <div class="mb-3" v-if="token.auth === 'manual'">
              <label class="form-label d-block">{{ $t('connections.identity.token') }}</label>
              <div class="mm-address" v-if="tokenKept">
                <code>{{ tokenShown ?? 'mxt_••••••••••••••••••••' }}</code>
                <button type="button" class="mm-address-copy" :disabled="saving"
                        :aria-label="tokenCopied ? $t('common.copied') : $t('connections.new_token.copy_token')" @click="copyToken">
                  <i class="fa-regular" :class="tokenCopied ? 'fa-circle-check' : 'fa-copy'"></i>
                </button>
                <span class="small text-muted" v-if="tokenCopied">{{ $t('common.copied') }}</span>
              </div>
              <template v-else>
                <p class="small text-muted mb-2">{{ $t('connections.identity.token_not_kept') }}</p>
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="reissuing || saving" @click="reissue">
                  {{ reissuing ? $t('connections.identity.new_token_making') : $t('connections.identity.new_token') }}
                </button>
              </template>
            </div>

            <label class="form-label d-block">{{ $t('connections.identity.icon') }}</label>
            <div class="d-flex flex-wrap align-items-center gap-2">
              <!-- A box showing the current mark, opening a flat grid of the
                   rest (operator, 2026-08-23). It was a wall of forty tiles
                   under three group headings, which is most of the height this
                   dialog had. -->
              <!-- `auto-close="outside"`: Bootstrap closes a dropdown on ANY
                   click inside it by default, which made the tab strip
                   unusable — choosing Logos shut the menu instead of showing
                   them. It suits the picker on its own terms too: nothing is
                   written until Save, so browsing twenty-two marks in one
                   opening is the point. -->
              <div class="dropdown">
                <button
                  class="btn btn-outline-secondary dropdown-toggle mm-icon-box"
                  type="button"
                  data-bs-toggle="dropdown"
                  data-bs-auto-close="outside"
                  aria-expanded="false"
                  :disabled="saving"
                  :aria-label="$t('connections.identity.choose_icon')"
                >
                  <AgentMark
                    v-if="hasUpload"
                    :icon-url="uploadUrl"
                  />
                  <AgentMark
                    v-else-if="chosenLogo"
                    :icon-url="chosenLogo.light_url"
                    :icon-url-dark="chosenLogo.dark_url"
                  />
                  <AgentMark v-else-if="chosenGlyph" :icon="chosenGlyph.icon" />
                  <span class="mm-agent-mark" v-else>
                    <i class="fa-regular fa-circle text-muted"></i>
                  </span>
                </button>
                <div class="dropdown-menu p-2 mm-icon-menu">
                  <!-- Two tabs rather than one longer grid (operator,
                       2026-08-23): the provider marks doubled the catalogue,
                       and forty tiles in one wall is the shape this picker was
                       rebuilt to escape. The split matches the real choice —
                       your provider's mark, or a neutral one. -->
                  <ul class="nav nav-tabs nav-fill mb-2 small">
                    <li class="nav-item">
                      <button
                        type="button"
                        class="nav-link"
                        :class="{ active: tab === 'logos' }"
                        @click="tab = 'logos'"
                      >
                        {{ $t('connections.identity.logos') }}
                      </button>
                    </li>
                    <li class="nav-item">
                      <button
                        type="button"
                        class="nav-link"
                        :class="{ active: tab === 'icons' }"
                        @click="tab = 'icons'"
                      >
                        {{ $t('connections.identity.icons') }}
                      </button>
                    </li>
                  </ul>
                  <div class="mm-icon-picker">
                    <!-- "None" first and in BOTH tabs: an assistant with no
                         icon is a valid choice, and having to remember which
                         tab clears it would be a puzzle. -->
                    <button
                      type="button"
                      class="mm-icon-choice"
                      :class="{ 'is-chosen': iconKey === null }"
                      :title="$t('connections.identity.no_icon')"
                      @click="iconKey = null"
                    >
                      <i class="fa-regular fa-circle text-muted"></i>
                    </button>
                    <template v-if="tab === 'logos'">
                      <button
                        v-for="logo in logos"
                        :key="logo.key"
                        type="button"
                        class="mm-icon-choice"
                        :class="{ 'is-chosen': iconKey === logo.key }"
                        :title="logo.label"
                        :aria-label="logo.label"
                        @click="iconKey = logo.key"
                      >
                        <AgentMark :icon-url="logo.light_url" :icon-url-dark="logo.dark_url" />
                      </button>
                    </template>
                    <template v-else>
                      <button
                        v-for="icon in choices"
                        :key="icon.key"
                        type="button"
                        class="mm-icon-choice"
                        :class="{ 'is-chosen': iconKey === icon.key }"
                        :title="icon.label"
                        :aria-label="icon.label"
                        @click="iconKey = icon.key"
                      >
                        <i :class="icon.icon"></i>
                      </button>
                    </template>
                  </div>
                </div>
              </div>

              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="uploading || saving"
                @click="fileInput?.click()"
              >
                <i class="fa-solid fa-arrow-up-from-bracket me-1"></i>
                {{ uploading ? $t('connections.identity.uploading') : $t('connections.identity.upload') }}
              </button>
              <input
                ref="fileInput"
                type="file"
                class="d-none"
                accept="image/png,image/jpeg"
                @change="onFile"
              />
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="cancel">
              {{ $t('common.cancel') }}
            </button>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="save">
              {{ saving ? $t('common.saving') : $t('common.save') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
