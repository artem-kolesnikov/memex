<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type ProfileRef } from '@/api/client'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import { toastError, toastSuccess } from '@/components/toastService'
import { useWelcomeStore } from '@/stores/welcome'
import { diffChoice, linesIn } from './profileLines'
import ProfileTitlebar from './ProfileTitlebar.vue'
import WizardIcon from './WizardIcon.vue'

const welcome = useWelcomeStore()
const lifetime = useOperationLifetime()
const { t } = useI18n()

const chosen = ref<Set<string>>(new Set())
const targetId = ref<number | null>(null)
const targetBody = ref<string | null>(null)
const targetVersion = ref<number | null>(null)
const loadFailed = ref(false)
const saving = ref(false)
const saveError = ref<string | null>(null)
/** The note a save just wrote, so the screen can say so and link it. */
const saved = ref<ProfileRef | null>(null)

const groups = computed(() => welcome.groups)
const profiles = computed(() => welcome.profiles)
const target = computed(() => profiles.value.find((p) => p.id === targetId.value) ?? null)

// Which profile the lines go into: the only one when there is one, the
// person's pick when there are several, none when there are none.
watch(profiles, (list) => {
  if (list.length === 0) targetId.value = null
  else if (list.length === 1) targetId.value = list[0]!.id
  else if (!list.some((p) => p.id === targetId.value)) targetId.value = null
}, { immediate: true })

// The target's current text, read fresh, so the preselection and the diff
// describe the note as it is rather than as it was when the wizard opened.
async function loadTarget(id: number | null): Promise<void> {
  targetBody.value = null
  targetVersion.value = null
  loadFailed.value = false
  if (id === null) {
    chosen.value = new Set()
    return
  }
  const mine = lifetime.capture()
  try {
    const note = await api.getNote(id)
    if (!lifetime.current(mine) || targetId.value !== id) return
    targetBody.value = note.body_md ?? ''
    targetVersion.value = note.version ?? null
    chosen.value = linesIn(note.body_md ?? '', groups.value)
  } catch {
    if (!lifetime.current(mine) || targetId.value !== id) return
    loadFailed.value = true
  }
}
watch(targetId, loadTarget, { immediate: true })

onMounted(() => { saved.value = null })

function isChosen(line: string): boolean {
  return chosen.value.has(line)
}

function pick(groupId: string, line: string, on: boolean): void {
  const group = groups.value.find((g) => g.id === groupId)
  if (!group) return
  const next = new Set(chosen.value)
  if (group.kind === 'radio') for (const o of group.options) next.delete(o.line)
  if (on) next.add(line)
  else next.delete(line)
  chosen.value = next
  saved.value = null
}

const change = computed(() => {
  if (targetId.value === null) return { adds: [...chosen.value], removes: [] as string[] }
  if (targetBody.value === null) return { adds: [] as string[], removes: [] as string[] }
  return diffChoice(targetBody.value, groups.value, chosen.value)
})

/**
 * Whether Save has anything to write: a choice for a new note, a change for
 * an existing one — and, either way, only once the server has said what the
 * account holds. Unknown is not none: creating on a failed read is how a
 * second profile appears beside the one the read would have shown.
 */
const canSave = computed(() => {
  if (saving.value || welcome.facts === null) return false
  if (targetId.value === null) return profiles.value.length === 0 && chosen.value.size > 0
  if (targetBody.value === null || targetVersion.value === null) return false
  return change.value.adds.length > 0 || change.value.removes.length > 0
})

async function save(): Promise<boolean> {
  if (!canSave.value) return false
  const mine = lifetime.capture()
  const epoch = sessionEpoch.value
  saving.value = true
  saveError.value = null
  try {
    const target = targetId.value === null || targetBody.value === null || targetVersion.value === null
      ? null
      : { id: targetId.value, body: targetBody.value, version: targetVersion.value }
    const result = await welcome.saveProfile(chosen.value, target)
    if (!lifetime.current(mine) || epoch !== sessionEpoch.value) return false
    if ('error' in result) {
      saveError.value = result.error
      toastError(t('welcome.profile.not_saved'), result.error)
      // The note may have moved under the choice (a 409), or the inventory
      // may be stale: read again so the next attempt starts from what is there.
      void welcome.refresh()
      if (targetId.value !== null) void loadTarget(targetId.value)
      return false
    }
    saved.value = { id: result.note.id, title: result.note.title, status: result.note.status as ProfileRef['status'] }
    targetBody.value = result.note.body_md
    targetVersion.value = result.note.version ?? null
    targetId.value = result.note.id
    toastSuccess(t('welcome.profile.saved'))
    return true
  } finally {
    if (lifetime.current(mine)) saving.value = false
  }
}

const hasChanges = computed(() => change.value.adds.length > 0 || change.value.removes.length > 0)

defineExpose({ save, canSave, hasChanges })
</script>

<template>
  <section class="mm-wiz-profile">
    <div class="mm-wiz-heading">
      <div class="mm-wiz-profile-title-row">
        <h1 class="mm-wiz-title" id="welcome-title" tabindex="-1">{{ $t('welcome.profile.title') }}</h1>
        <ProfileTitlebar />
      </div>
      <p class="mm-wiz-intro">{{ $t('welcome.profile.intro') }}</p>
    </div>

    <div class="mm-wiz-status is-error" role="alert" v-if="loadFailed">
      <WizardIcon name="alert" />
      <div><strong>{{ $t('welcome.profile.load_failed') }}</strong></div>
    </div>

    <div class="mm-wiz-profile-target" v-if="profiles.length > 1">
      <label for="profile-target">{{ $t('welcome.profile.several') }}</label>
      <select id="profile-target" class="form-select form-select-sm" v-model="targetId">
        <option :value="null" disabled>{{ $t('welcome.profile.choose_target') }}</option>
        <option v-for="p in profiles" :key="p.id" :value="p.id">
          {{ p.title }}{{ p.status === 'pending' ? ' — ' + $t('welcome.profile.pending') : '' }}
        </option>
      </select>
    </div>
    <p class="mm-wiz-profile-existing" v-else-if="target">
      <WizardIcon name="check" />
      <span>{{ $t('welcome.profile.existing') }} <strong>{{ target.title }}</strong><template v-if="target.status === 'pending'"> — {{ $t('welcome.profile.pending') }}</template></span>
    </p>

    <div class="mm-wiz-profile-preferences">
      <fieldset class="mm-wiz-profile-group" v-for="group in groups" :key="group.id"
                :aria-labelledby="`profile-${group.id}-label`">
        <div class="mm-wiz-profile-row">
          <div>
            <span class="mm-wiz-profile-label" :id="`profile-${group.id}-label`">{{ $t(`welcome.profile.groups.${group.id}`) }}</span>
            <p class="mm-wiz-profile-hint" v-if="group.id === 'personal'">{{ $t('welcome.profile.sensitive_hint') }}</p>
          </div>
          <div class="mm-wiz-profile-options" :class="{ 'is-checkbox': group.kind === 'checkbox' }">
            <label class="mm-wiz-profile-option" v-for="option in group.options" :key="option.id">
              <input
                :type="group.kind"
                :name="`profile-${group.id}`"
                :value="option.line"
                :checked="isChosen(option.line)"
                :disabled="saving || (profiles.length > 1 && targetId === null)"
                @click="group.kind === 'radio' && isChosen(option.line) ? pick(group.id, option.line, false) : undefined"
                @change="pick(group.id, option.line, ($event.target as HTMLInputElement).checked)"
              >
              <span>{{ $t(`welcome.profile.options.${group.id}.${option.id}`) }}</span>
            </label>
          </div>
        </div>
      </fieldset>
    </div>

    <details class="mm-wiz-profile-change" v-if="hasChanges">
      <summary>{{ $t('welcome.profile.review_changes') }}<span v-if="target"> · {{ $t('welcome.profile.adds', change.adds.length) }} · {{ $t('welcome.profile.removes', change.removes.length) }}</span></summary>
      <div v-if="change.adds.length"><strong>{{ $t('welcome.profile.adding') }}</strong><ul><li v-for="line in change.adds" :key="line">{{ line }}</li></ul></div>
      <div v-if="change.removes.length"><strong>{{ $t('welcome.profile.removing') }}</strong><ul><li v-for="line in change.removes" :key="line">{{ line }}</li></ul></div>
    </details>
    <p class="mm-wiz-profile-save-note" v-else>{{ $t('welcome.profile.choose') }}</p>

    <div class="mm-wiz-status is-success" v-if="saved" role="status" aria-live="polite">
      <WizardIcon name="check" />
      <div>
        <strong>{{ $t('welcome.profile.saved') }}</strong>
        <p>
          <RouterLink class="mm-wiz-inline-link" :to="{ name: 'note', params: { id: saved.id } }" target="_blank">{{ saved.title }}<WizardIcon name="external" /></RouterLink>
        </p>
      </div>
    </div>
    <div class="mm-wiz-status is-error" v-else-if="saveError" role="alert">
      <WizardIcon name="alert" />
      <div>
        <strong>{{ $t('welcome.profile.not_saved') }}</strong>
        <p>{{ saveError }}</p>
      </div>
    </div>
  </section>
</template>
