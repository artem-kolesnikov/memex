<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type SkillConnection, type SkillPatch, type SkillRow } from '@/api/client'
import AgentMark from '@/components/AgentMark.vue'
import { formatDateTime } from '@/lib/datetime'
import SkillConnections from './SkillConnections.vue'
import SkillUseExample from './SkillUseExample.vue'

const props = defineProps<{ skill: SkillRow; connections: SkillConnection[] }>()
const emit = defineEmits<{ close: []; updated: [row: SkillRow] }>()
const { t } = useI18n()

const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void; dispose(): void } | null = null
onMounted(() => {
  if (dialog.value !== null) {
    modal = new window.bootstrap.Modal(dialog.value)
    dialog.value.addEventListener('hidden.bs.modal', () => emit('close'))
    modal.show()
  }
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
function close() {
  modal?.hide()
}

const SLUG_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

const editable = computed(() => props.skill.kind === 'note')
const available = computed(() => props.skill.status === 'built_in' || (props.skill.status === 'served' && props.skill.enabled && (props.skill.auto || props.skill.command)))
const slugDraft = ref(props.skill.slug)
const error = ref<string | null>(null)
const saving = ref(false)
const byId = computed(() => new Map(props.connections.map((c) => [c.id, c])))

async function patch(p: SkillPatch) {
  if (props.skill.note_id === null) return
  saving.value = true
  error.value = null
  try {
    const r = await api.updateSkill(props.skill.note_id, p)
    emit('updated', r.skill)
    slugDraft.value = r.skill.slug
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('common.unknown_error')
  } finally {
    saving.value = false
  }
}
function rename() {
  if (slugDraft.value.length > 64 || !SLUG_RE.test(slugDraft.value)) {
    error.value = t('skills.panel.slug_invalid')
    return
  }
  void patch({ slug: slugDraft.value })
}
function connectionName(id: number): string {
  const c = byId.value.get(id)
  return c ? c.display_name || c.label : String(id)
}
</script>

<template>
  <Teleport to="body">
    <div class="modal fade mm-skill-panel" tabindex="-1" ref="dialog" aria-labelledby="skill-panel-title">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <div class="skill-heading">
              <h2 class="modal-title" id="skill-panel-title" :title="skill.title">{{ skill.title }}</h2>
            </div>
            <div class="skill-header-actions">
              <div v-if="editable" class="skill-header-toggle">
                <label for="skill-enabled" class="skill-state-label" :class="{ 'is-enabled': available }">{{ skill.status === 'pending' ? $t('skills.state.pending') : available ? $t('skills.cards.on') : $t('skills.state.paused') }}</label>
                <div class="form-check form-switch m-0">
                  <input class="form-check-input" type="checkbox" id="skill-enabled" :aria-label="$t('skills.panel.served')" :key="'enabled:' + (error ?? '')" :checked="available" :disabled="saving || skill.status === 'pending'" @change="patch(($event.target as HTMLInputElement).checked ? { enabled: true, auto: true, command: true } : { enabled: false })" />
                </div>
              </div>
            </div>
          </div>

          <div class="modal-body">
            <p class="skill-summary">{{ skill.description || $t('skills.cards.no_description') }}</p>
            <p class="alert alert-warning py-1" v-if="error">{{ error }}</p>

            <p class="small text-muted" v-if="skill.status === 'pending'">{{ $t('skills.panel.pending_note') }}</p>
            <p class="skill-system-note" v-if="skill.kind === 'shipped'"><i class="fa-solid fa-lock" aria-hidden="true"></i>{{ $t('skills.panel.origin_shipped') }}</p>

            <SkillConnections v-if="editable" class="skill-panel-section" :grants="skill.grants" :connections="connections" :disabled="saving || skill.status === 'pending'" @save="patch({ grants: $event })" />

            <div class="skill-setup-columns">
              <section class="skill-setup-column skill-alias-section">
                <label class="skill-field-label" :for="editable ? 'skill-slug' : undefined">{{ $t('skills.panel.slug') }}</label>
                <p id="skill-alias-help" class="skill-alias-help">{{ $t('skills.panel.slug_help') }}</p>
                <div v-if="editable" class="skill-alias-field">
                  <input id="skill-slug" class="form-control form-control-sm font-monospace" v-model="slugDraft" :disabled="saving || skill.status === 'pending'" aria-describedby="skill-alias-help" />
                  <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="saving || skill.status === 'pending' || slugDraft === skill.slug" @click="rename">{{ $t('common.save') }}</button>
                </div>
                <p v-else class="small mb-0"><code>{{ skill.slug }}</code></p>
              </section>
              <section class="skill-setup-column skill-howto-section">
                <h3 class="skill-field-label">{{ $t('skills.panel.how_to_use') }}</h3>
                <SkillUseExample :skill="skill" compact :can-copy="available" />
              </section>
            </div>

            <section class="skill-usage" :aria-label="$t('skills.panel.usage')">
              <div class="skill-usage-summary">
                <span><i class="fa-solid fa-chart-simple" aria-hidden="true"></i>{{ $t('skills.cards.loads', { count: skill.usage.total_30d }, skill.usage.total_30d) }}</span>
                <span v-if="skill.usage.last_at">{{ $t('skills.panel.last_loaded', { when: formatDateTime(skill.usage.last_at) }) }}</span>
              </div>
              <ul v-if="skill.usage.total_30d > 0" class="list-unstyled mt-2 mb-0">
                <li v-for="u in skill.usage.by_token" :key="u.token_id" class="d-flex align-items-center gap-2">
                  <AgentMark v-if="byId.get(u.token_id)" :icon="byId.get(u.token_id)!.icon" :icon-url="byId.get(u.token_id)!.icon_url" :icon-url-dark="byId.get(u.token_id)!.icon_url_dark" />
                  {{ $t('skills.panel.usage_line', { name: connectionName(u.token_id), count: u.count, when: formatDateTime(u.last_at) }, u.count) }}
                </li>
              </ul>
            </section>

            <template v-if="skill.lint.length">
              <h3 class="skill-field-label">{{ $t('skills.panel.findings') }}</h3>
              <ul class="small"><li v-for="f in skill.lint" :key="f.code">{{ f.message }}</li></ul>
            </template>

          </div>

          <div class="modal-footer">
            <div class="skill-note-actions">
              <router-link v-if="skill.note_id" class="btn btn-sm btn-primary" :to="{ name: 'note-edit', params: { id: skill.note_id } }">{{ $t('skills.panel.edit_note') }}</router-link>
              <a class="btn btn-sm btn-outline-secondary" :href="api.exportServedSkillUrl(skill.slug)">
                <i class="fa-regular fa-circle-down me-1" aria-hidden="true"></i>{{ $t('skills.panel.download') }}
              </a>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary skill-cancel" @click="close">{{ $t('common.cancel') }}</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.mm-skill-panel .modal-dialog { max-width: 46rem; }
.mm-skill-panel .modal-content { border-radius: 1rem; overflow: hidden; }
.mm-skill-panel .modal-header { align-items: flex-start; gap: 1rem; padding: 1.4rem 1.5rem .6rem; border-bottom: 0; }
.skill-heading { min-width: 0; }
.mm-skill-panel .modal-title { font-size: 1.2rem; line-height: 1.35; font-weight: 600; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.skill-summary { margin: 0 0 1.1rem; font-size: .875rem; line-height: 1.6; color: var(--mm-ink); overflow-wrap: anywhere; }
.mm-skill-panel .modal-body { padding: .4rem 1.5rem 1.15rem; overflow-wrap: anywhere; }
.mm-skill-panel .modal-body code { white-space: normal; overflow-wrap: anywhere; }
.skill-header-actions { display: flex; align-items: center; gap: 1rem; margin-left: auto; flex-shrink: 0; }
.skill-header-toggle { display: flex; align-items: center; gap: .5rem; }
.skill-header-toggle label { cursor: pointer; }
.skill-header-toggle .form-check-input { margin-top: 0; }
.skill-state-label { font-size: .7rem; font-weight: 500; color: var(--mm-muted); }
.skill-state-label.is-enabled { color: var(--mm-primary); }
.skill-panel-section { margin-top: 1.3rem; }
.skill-setup-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: stretch; gap: 1rem; margin-top: 1.3rem; }
.skill-setup-column { min-width: 0; padding: 1rem; border: 1px solid var(--mm-line); border-radius: .65rem; background: var(--mm-surface); }
.skill-field-label { display: block; margin: 0 0 .65rem; font-size: .875rem; font-weight: 600; }
.skill-alias-help { margin: 0 0 .85rem; color: var(--mm-muted); font-size: .75rem; }
.skill-alias-field { display: flex; align-items: center; gap: .5rem; }
.skill-alias-field .form-control { min-width: 0; border-radius: .5rem; font-size: .8rem; }
.skill-alias-field .btn { flex-shrink: 0; }
.skill-howto-section :deep(blockquote) { margin-bottom: 0 !important; }
.skill-usage { margin-top: 1.15rem; padding-top: .9rem; border-top: 1px solid var(--mm-line); color: var(--mm-muted); font-size: .75rem; }
.skill-usage-summary { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .4rem 1rem; }
.skill-usage-summary i { margin-right: .4rem; }
.skill-system-note { display: flex; gap: .6rem; color: var(--mm-muted); font-size: .8rem; margin-bottom: 0; }
.skill-system-note i { margin-top: .2rem; }
.mm-skill-panel .modal-footer { padding: .9rem 1.5rem; justify-content: space-between; gap: .5rem; }
.skill-note-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin: 0; }
.skill-cancel { margin-left: auto; }
@media (max-width: 575.98px) {
  .mm-skill-panel .modal-header { padding: 1.1rem 1rem .5rem; }
  .mm-skill-panel .modal-body { padding: .4rem 1rem 1rem; }
  .mm-skill-panel .modal-footer { padding: .8rem 1rem; }
  .skill-setup-columns { gap: .6rem; }
  .skill-setup-column { padding: .7rem; }
  .skill-alias-field { flex-wrap: wrap; justify-content: flex-end; }
  .skill-alias-field .form-control { width: 100%; }
  .skill-note-actions { flex: 1; }
}
</style>
