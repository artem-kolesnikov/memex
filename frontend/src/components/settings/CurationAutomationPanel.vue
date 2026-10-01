<script setup lang="ts">
// Which assistants curate, what they are told, and the prompt that starts a
// pass. Shown below connections in Settings > Assistants.
//
// It loads its own instructions rather than taking them as a prop, so that the
// enrichment pane above is not responsible for a payload it has nothing to do
// with: a failure here must not read as an enrichment failure, or the reverse.
//
// The prompt preview is composed by the SERVER from the settings on screen,
// including unsaved ones. A second implementation in TypeScript would drift
// from the document agents are actually served, which is the one thing this
// panel exists to show.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  api,
  type CurationBriefFields,
  type CurationConnection,
  type CurationInstructions,
  type CurationPreset,
  type TokenInfo,
} from '@/api/client'
import { formatDate } from '@/lib/datetime'
import { reasonCountWords } from '@/lib/curationReasons'
import AgentMark from '@/components/AgentMark.vue'
import { toastError, toastSuccess } from '@/components/toastService'

// Where each vendor documents running an agent on a schedule. Named here
// rather than in the copy so a translation cannot move a link somewhere else.
// Both checked 2026-09-10.
const CHATGPT_TASKS = 'https://help.openai.com/en/articles/10291617-scheduled-tasks-in-chatgpt'
const CLAUDE_TASKS = 'https://support.claude.com/en/articles/13854387-schedule-recurring-tasks-in-claude-cowork'

const { t } = useI18n()
const emit = defineEmits<{ changed: [] }>()
const props = defineProps<{ connectionsRevision?: number }>()

const instructions = ref<CurationInstructions | null>(null)
const instructionsFailed = ref(false)

const connections = ref<CurationConnection[]>([])
const otherCount = ref(0)
const wiringFailed = ref(false)
const wiringLoading = ref(true)

const selectedId = ref<number | null>(null)
const draft = ref<CurationBriefFields | null>(null)
const saving = ref(false)
const newTag = ref('')

// Promoting a connection to Curator lives here rather than in the Connections
// table (operator, 2026-09-01): the authority is only meaningful next to what
// it authorises. The server still decides — App\Service\NoteWriter is what
// makes a curator's safe write apply — and a token of any role still cannot
// grant itself one.
const candidates = ref<TokenInfo[]>([])
const addingCurator = ref(false)
const candidatesFailed = ref(false)
const promoting = ref<number | null>(null)
const promotingBusy = ref(false)
const demoting = ref<number | null>(null)

// Custom curation profiles are held back from the interface (operator,
// 2026-09-01). The presets, their API and the per-connection assignment all
// still exist and every curator runs on the default; profiling is revisited
// before it is shown again.
const showProfiles = ref(false)

const addOpen = ref(false)
const addName = ref('')
const addSaving = ref(false)

const promptForm = ref<'short' | 'full'>('short')
const prompts = ref<{ short: string; full: string }>({ short: '', full: '' })
const promptLoading = ref(false)
const promptFailed = ref(false)
const copied = ref('')

const presets = computed(() => instructions.value?.presets ?? [])
const selected = computed<CurationPreset | null>(
  () => presets.value.find((p) => p.id === selectedId.value) ?? presets.value[0] ?? null,
)
const locked = computed(() => selected.value?.is_standard !== false)

watch(
  instructions,
  () => {
    if (selectedId.value === null || !presets.value.some((p) => p.id === selectedId.value)) {
      selectedId.value = presets.value[0]?.id ?? null
    }
    reset()
  },
  { immediate: true },
)
watch(selectedId, reset)

function reset() {
  const fields = selected.value?.fields
  draft.value = fields ? JSON.parse(JSON.stringify(fields)) : null
}

const dirty = computed(
  () => !!draft.value && JSON.stringify(draft.value) !== JSON.stringify(selected.value?.fields),
)

// Every change re-composes the prompt, so flipping a control shows what an
// assistant would actually be told rather than what the label promised.
let previewTimer: ReturnType<typeof setTimeout> | undefined
watch(
  [draft, selected],
  () => {
    if (!draft.value || !selected.value) return
    clearTimeout(previewTimer)
    const name = selected.value.name
    const fields = JSON.parse(JSON.stringify(draft.value))
    promptLoading.value = true
    previewTimer = setTimeout(async () => {
      try {
        prompts.value = await api.curationPreview(name, fields)
        promptFailed.value = false
      } catch {
        prompts.value = { short: '', full: '' }
        promptFailed.value = true
      } finally {
        promptLoading.value = false
      }
    }, 200)
  },
  { deep: true, immediate: true },
)

// Each is a sentence the pass says to you when it finishes, because memex
// never will. The three on by default are the three that answer "did anything
// happen"; the two off are detail you ask for while tuning.
const REPORT_OPTIONS: { key: string; labelKey: string; whyKey: string }[] = [
  { key: 'examined', labelKey: 'automation.curation.report.examined', whyKey: 'automation.curation.report.examined_why' },
  { key: 'changed', labelKey: 'automation.curation.report.changed', whyKey: 'automation.curation.report.changed_why' },
  { key: 'filed', labelKey: 'automation.curation.report.filed', whyKey: 'automation.curation.report.filed_why' },
  { key: 'skipped', labelKey: 'automation.curation.report.skipped', whyKey: 'automation.curation.report.skipped_why' },
  { key: 'observations', labelKey: 'automation.curation.report.observations', whyKey: 'automation.curation.report.observations_why' },
]

const BOLDNESS_OPTIONS: { key: string; titleKey: string; bodyKey: string }[] = [
  { key: 'conservative', titleKey: 'automation.curation.boldness.conservative', bodyKey: 'automation.curation.boldness.conservative_body' },
  { key: 'balanced', titleKey: 'automation.curation.boldness.balanced', bodyKey: 'automation.curation.boldness.balanced_body' },
  { key: 'bold', titleKey: 'automation.curation.boldness.bold', bodyKey: 'automation.curation.boldness.bold_body' },
]

async function loadInstructions() {
  try {
    instructions.value = await api.curationInstructions()
    instructionsFailed.value = false
  } catch (e) {
    instructionsFailed.value = true
    toastError(t('automation.curation.load_instructions_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

let wiringRequest = 0
let candidatesRequest = 0

async function loadWiring() {
  const request = ++wiringRequest
  wiringLoading.value = true
  try {
    const result = await api.curationWiring()
    if (request !== wiringRequest) return
    wiringFailed.value = false
    connections.value = result.connections
    otherCount.value = result.other_count
  } catch {
    if (request !== wiringRequest) return
    wiringFailed.value = true
  } finally {
    if (request === wiringRequest) wiringLoading.value = false
  }
}

async function loadCandidates() {
  const request = ++candidatesRequest
  try {
    const answer = await api.tokens()
    if (request !== candidatesRequest) return
    candidatesFailed.value = false
    candidates.value = answer.tokens.filter((tok) => !tok.revoked && tok.role !== 'curator')
  } catch {
    if (request !== candidatesRequest) return
    candidatesFailed.value = true
    candidates.value = []
  }
}

watch(() => props.connectionsRevision, () => {
  void loadWiring()
  void loadCandidates()
})

onMounted(() => {
  loadInstructions()
  loadWiring()
  loadCandidates()
})

async function addCurator() {
  const id = promoting.value
  if (id === null) return
  promotingBusy.value = true
  try {
    await api.setTokenRole(id, 'curator')
    promoting.value = null
    addingCurator.value = false
    await Promise.all([loadWiring(), loadCandidates()])
    emit('changed')
    toastSuccess(t('automation.curation.curator_added'), t('automation.curation.curator_added_detail'))
  } catch (e) {
    toastError(t('automation.curation.not_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    promotingBusy.value = false
  }
}

async function removeCurator(connection: CurationConnection) {
  demoting.value = connection.id
  try {
    await api.setTokenRole(connection.id, 'agent')
    await Promise.all([loadWiring(), loadCandidates()])
    emit('changed')
  } catch (e) {
    toastError(t('automation.curation.not_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    demoting.value = null
  }
}

async function setPreset(connection: CurationConnection, presetId: number) {
  const previous = connection.preset_id
  connection.preset_id = presetId
  try {
    await api.curationSetConnectionPreset(connection.id, presetId)
    connection.preset_name = presets.value.find((p) => p.id === presetId)?.name ?? connection.preset_name
  } catch (e) {
    connection.preset_id = previous
    toastError(t('automation.curation.change_profile_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

function toggleReport(key: string) {
  if (!draft.value || locked.value) return
  const at = draft.value.report_back.indexOf(key)
  if (at === -1) draft.value.report_back.push(key)
  else draft.value.report_back.splice(at, 1)
}

function addTag() {
  const tag = newTag.value.trim()
  if (!draft.value || locked.value || tag === '' || draft.value.never_touch_tags.includes(tag)) return
  draft.value.never_touch_tags.push(tag)
  newTag.value = ''
}

async function save() {
  if (!selected.value || !draft.value) return
  saving.value = true
  try {
    await api.curationSavePreset(selected.value.id, { fields: draft.value })
    toastSuccess(t('automation.curation.profile_saved'), t('automation.curation.profile_saved_detail'))
    loadInstructions()
  } catch (e) {
    toastError(t('automation.curation.save_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    saving.value = false
  }
}

function openAdd() {
  addName.value = ''
  addOpen.value = true
}

async function createProfile() {
  const name = addName.value.trim()
  if (!name) return
  addSaving.value = true
  try {
    // Seeded from what is on screen, so adding a profile from Standard starts
    // where you were rather than from nothing.
    const created = await api.curationCreatePreset({
      name,
      fields: draft.value ?? presets.value[0]?.fields ?? ({} as CurationBriefFields),
    })
    addOpen.value = false
    await loadInstructions()
    selectedId.value = created.id
  } catch (e) {
    toastError(t('automation.curation.add_profile_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    addSaving.value = false
  }
}

async function remove() {
  if (!selected.value || selected.value.is_standard) return
  if (!window.confirm(t('automation.curation.delete_profile_confirm', { name: selected.value.name }))) return
  try {
    await api.curationDeletePreset(selected.value.id)
    selectedId.value = null
    await loadInstructions()
    loadWiring()
  } catch (e) {
    toastError(t('automation.curation.delete_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  }
}

async function copy(text: string, key: string) {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = key
    setTimeout(() => (copied.value = ''), 2000)
  } catch {
    toastError(t('automation.curation.copy_failed'), t('automation.curation.copy_failed_detail'))
  }
}
</script>

<template>
  <div>
    <div class="alert alert-warning" v-if="instructionsFailed">
      {{ $t('automation.curation.profiles_load_failed') }}
    </div>

    <div class="mm-maintenance-step">
      <h4 class="mm-maintenance-title"><span aria-hidden="true">1</span>{{ $t('automation.curation.assign_title') }}</h4>
      <p class="mm-note mm-note-wide">{{ $t('automation.curation.assign_trust') }}</p>

      <p v-if="wiringFailed" class="mm-step-wait">{{ $t('automation.curation.connections_failed') }}</p>

      <div class="table-responsive" v-else-if="connections.length">
        <table class="table app-table align-middle mm-settings-table mm-stack-sm">
          <thead><tr>
            <th>{{ $t('automation.name') }}</th>
            <th>{{ $t('automation.curation.last_run') }}</th>
            <th class="text-end">{{ $t('connections.table.actions') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="c in connections" :key="c.id">
              <td>
                <div class="mm-settings-identity">
                  <AgentMark :icon="c.icon" :icon-url="c.icon_url" :icon-url-dark="c.icon_url_dark" />
                  <span>{{ c.name }}<span v-if="c.name !== c.label" class="d-block small text-muted">{{ c.label }}</span></span>
                </div>
              </td>
              <td :data-label="$t('automation.curation.last_run')">{{ c.last_run_at ? formatDate(c.last_run_at) : $t('automation.never') }}</td>
              <td class="text-end mm-stack-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        :disabled="demoting !== null" @click="removeCurator(c)">{{ $t('automation.curation.remove_role') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else-if="!wiringLoading" class="mm-step-wait">
        {{ $t('automation.curation.no_curator') }}
        <template v-if="!candidates.length && !candidatesFailed">
          {{ $t('automation.curation.connect_first') }}
        </template>
      </p>

      <p v-if="candidatesFailed" class="mm-note" role="alert">{{ $t('automation.curation.connections_failed') }}</p>

      <template v-if="candidates.length && !wiringFailed">
        <button type="button" class="btn btn-outline-secondary btn-sm mt-3" v-if="connections.length && !addingCurator"
                @click="addingCurator = true">
          <i class="fa-solid fa-plus me-1"></i> {{ $t('automation.curation.add_curator') }}
        </button>

        <div class="mm-curator-add" v-else>
          <select class="form-select form-select-sm" v-model.number="promoting" :disabled="promotingBusy"
                  :aria-label="$t('automation.curation.connection_to_promote')">
            <option :value="null">{{ $t('automation.curation.choose_connection') }}</option>
            <option v-for="t in candidates" :key="t.id" :value="t.id">
              {{ t.display_name || t.label }}
            </option>
          </select>
          <button type="button" class="btn btn-primary btn-sm"
                  :disabled="promoting === null || promotingBusy" @click="addCurator">
            {{ promotingBusy ? $t('common.saving') : $t('automation.curation.allow') }}
          </button>
        </div>
      </template>

      <!-- A pass that never asked for the instructions looks exactly like one
           that read them. It is the only wiring failure memex can see. -->
      <p
        v-if="connections.length && connections.every((c) => !c.charter_last_loaded_at)"
        class="mm-step-wait"
      >
        {{ $t('automation.curation.never_loaded') }}
      </p>
    </div>

    <div class="mm-block" v-if="showProfiles && draft && selected">
      <h4 class="mm-block-title">{{ $t('automation.curation.profiles_title') }}</h4>
      <div class="mm-profile-pick">
        <select class="form-select form-select-sm w-auto" v-model.number="selectedId" :aria-label="$t('automation.curation.profile')">
          <option v-for="p in presets" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="button" @click="openAdd">
          <i class="fa-solid fa-plus me-1"></i>{{ $t('automation.curation.add_profile') }}
        </button>
      </div>

      <p v-if="locked" class="mm-note mm-profile-locked">
        <i class="fa-solid fa-lock me-1"></i>
        {{ $t('automation.curation.locked_note') }}
      </p>
      <p v-else class="mm-note">
        {{ $t('automation.curation.unlocked_note') }}
      </p>

      <fieldset :disabled="locked">
        <div class="mm-field-grid">
          <div class="mm-brief-field">
            <label class="form-label" for="notes-per-run">{{ $t('automation.curation.notes_per_pass') }}</label>
            <input
              id="notes-per-run"
              class="form-control form-control-sm mm-num"
              type="number"
              v-model.number="draft.notes_per_run"
              :min="instructions?.field_options.notes_per_run.min"
              :max="instructions?.field_options.notes_per_run.max"
            />
            <p class="mm-curation-why">{{ $t('automation.curation.notes_per_pass_why') }}</p>
          </div>

          <div class="mm-brief-field">
            <label class="form-label" for="cooldown">{{ $t('automation.curation.recheck_interval') }}</label>
            <div class="d-flex align-items-center gap-2">
              <input
                id="cooldown"
                class="form-control form-control-sm mm-num"
                type="number"
                v-model.number="draft.cooldown_days"
                :min="instructions?.field_options.cooldown_days.min"
                :max="instructions?.field_options.cooldown_days.max"
              />
              <span class="small text-muted">{{ $t('automation.curation.days') }}</span>
            </div>
            <p class="mm-curation-why">{{ $t('automation.curation.recheck_interval_why') }}</p>
          </div>

          <div class="mm-brief-field">
            <label class="form-label" for="work-first">{{ $t('automation.curation.priority') }}</label>
            <select id="work-first" class="form-select form-select-sm" v-model="draft.work_first">
              <option value="">{{ $t('automation.curation.queue_default') }}</option>
              <option v-for="r in instructions?.field_options.work_first ?? []" :key="r" :value="r">
                {{ reasonCountWords(r) }}
              </option>
            </select>
            <p class="mm-curation-why">{{ $t('automation.curation.priority_why') }}</p>
          </div>

          <div class="mm-brief-field">
            <label class="form-label" for="never-touch">{{ $t('automation.curation.protected_tags') }}</label>
            <div class="d-flex flex-wrap gap-1 mb-2" v-if="draft.never_touch_tags.length">
              <span v-for="tag in draft.never_touch_tags" :key="tag" class="badge text-bg-light">
                {{ tag }}
                <button
                  v-if="!locked"
                  class="btn-close btn-close-sm ms-1"
                  type="button"
                  :aria-label="$t('automation.curation.remove_tag', { tag })"
                  @click="draft.never_touch_tags.splice(draft.never_touch_tags.indexOf(tag), 1)"
                ></button>
              </span>
            </div>
            <div class="d-flex gap-2">
              <input
                id="never-touch"
                class="form-control form-control-sm"
                v-model="newTag"
                :placeholder="$t('automation.curation.tag_placeholder')"
                @keydown.enter.prevent="addTag"
              />
              <button class="btn btn-sm btn-outline-secondary" type="button" @click="addTag">
                {{ $t('automation.curation.add') }}
              </button>
            </div>
            <p class="mm-curation-why">{{ $t('automation.curation.protected_tags_why') }}</p>
          </div>
        </div>

        <div class="mm-brief-field mm-brief-wide">
          <span class="form-label d-block">{{ $t('automation.curation.threshold') }}</span>
          <p class="mm-curation-why mb-2">
            {{ $t('automation.curation.threshold_why') }}
          </p>
          <div class="mm-choice-row">
            <button
              v-for="opt in BOLDNESS_OPTIONS"
              :key="opt.key"
              type="button"
              class="mm-choice"
              :class="{ selected: draft.boldness === opt.key }"
              :aria-pressed="draft.boldness === opt.key"
              @click="draft.boldness = opt.key"
            >
              <span class="mm-choice-title">{{ $t(opt.titleKey) }}</span>
              <span class="mm-choice-body">{{ $t(opt.bodyKey) }}</span>
            </button>
          </div>
        </div>

        <div class="mm-brief-field mm-brief-wide">
          <span class="form-label d-block">{{ $t('automation.curation.run_report') }}</span>
          <p class="mm-curation-why mb-2">
            {{ $t('automation.curation.run_report_why') }}
          </p>
          <div class="mm-report-list">
            <label
              v-for="opt in REPORT_OPTIONS"
              :key="opt.key"
              class="mm-report-item"
              :class="{ selected: draft.report_back.includes(opt.key) }"
            >
              <input
                class="form-check-input mt-1"
                type="checkbox"
                :checked="draft.report_back.includes(opt.key)"
                @change="toggleReport(opt.key)"
              />
              <span>
                <span class="mm-report-label">{{ $t(opt.labelKey) }}</span>
                <span class="mm-curation-why">{{ $t(opt.whyKey) }}</span>
              </span>
            </label>
          </div>
        </div>
      </fieldset>

      <div class="d-flex gap-2 flex-wrap mt-3" v-if="!locked">
        <button
          class="btn btn-sm btn-primary"
          type="button"
          :disabled="!dirty || saving"
          @click="save"
        >
          {{ $t('common.save') }}
        </button>
        <button
          class="btn btn-sm btn-outline-secondary"
          type="button"
          :disabled="!dirty"
          @click="reset"
        >
          {{ $t('automation.curation.discard') }}
        </button>
        <button class="btn btn-sm btn-outline-danger ms-auto" type="button" @click="remove">
          {{ $t('automation.curation.delete_profile') }}
        </button>
      </div>
    </div>

    <div class="mm-maintenance-step" v-if="connections.length">
      <h4 class="mm-maintenance-title"><span aria-hidden="true">2</span>{{ $t('automation.curation.prompt_title') }}</h4>
      <p class="mm-note">
        {{ $t('automation.curation.prompt_intro') }}
      </p>

      <p v-if="promptFailed" class="mm-note" role="alert">{{ $t('automation.curation.prompt_failed') }}</p>
      <div class="mm-init-prompt" v-else>
        <div class="mm-init-prompt-bar">
          <button type="button" class="mm-init-prompt-copy"
                  :disabled="promptLoading || !prompts.short"
                  :aria-label="copied === 'short' ? $t('common.copied') : $t('automation.curation.copy_prompt')"
                  @click="copy(prompts.short, 'short')">
            <i class="fa-regular" :class="copied === 'short' ? 'fa-circle-check' : 'fa-copy'"></i>
            {{ copied === 'short' ? $t('common.copied') : $t('automation.curation.copy_prompt') }}
          </button>
        </div>
        <pre>{{ promptLoading ? '…' : prompts.short }}</pre>
      </div>
      <p class="mm-note mt-3 mb-0">
        <i18n-t keypath="automation.curation.assign_schedule" scope="global">
          <template #chatgpt><a :href="CHATGPT_TASKS" target="_blank" rel="noopener">{{ $t('automation.curation.assign_schedule_chatgpt') }}</a></template>
          <template #claude><a :href="CLAUDE_TASKS" target="_blank" rel="noopener">{{ $t('automation.curation.assign_schedule_claude') }}</a></template>
        </i18n-t>
      </p>
    </div>

    <!-- To <body>, not into the pane. Every route's <main> is a stacking
         context now so the fixed selection bar can escape the footer, and a
         dialog left inside one cannot rise above the sidebar however high its
         z-index goes: the sidebar painted over its backdrop and stayed
         clickable, so you could navigate away from an open aria-modal. -->
    <Teleport to="body">
    <div v-if="addOpen" class="mm-modal-backdrop" @click.self="addOpen = false">
      <div class="mm-modal" role="dialog" aria-modal="true" :aria-label="$t('automation.curation.add_profile_dialog')">
        <h4 class="mm-block-title">{{ $t('automation.curation.add_profile_dialog') }}</h4>
        <p class="mm-note">
          {{ $t('automation.curation.add_profile_intro') }}
        </p>
        <label class="form-label" for="profile-name">{{ $t('automation.name') }}</label>
        <input
          id="profile-name"
          class="form-control form-control-sm"
          v-model="addName"
          :placeholder="$t('automation.curation.profile_name_placeholder')"
          @keydown.enter.prevent="createProfile"
        />
        <div class="d-flex gap-2 justify-content-end mt-3">
          <button class="btn btn-sm btn-outline-secondary" type="button" @click="addOpen = false">
            {{ $t('common.cancel') }}
          </button>
          <button
            class="btn btn-sm btn-primary"
            type="button"
            :disabled="!addName.trim() || addSaving"
            @click="createProfile"
          >
            {{ $t('common.save') }}
          </button>
        </div>
      </div>
    </div>
    </Teleport>
  </div>
</template>
