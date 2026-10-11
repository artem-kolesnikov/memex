<script setup lang="ts">
import { useFrameNavigation } from '@/lib/frameNavigation'
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, type SkillConnection, type SkillPatch, type SkillRow } from '@/api/client'
import SkillsCards from '@/components/skills/SkillsCards.vue'
import SkillsRows from '@/components/skills/SkillsRows.vue'
import SkillFrame from '@/components/skills/SkillFrame.vue'
import { skillKey, skillOffered } from '@/components/skills/skillGroups'
import { usePageRoute } from '@/lib/pageRoute'
import { useLayoutStore } from '@/stores/layout'

const NoteView = defineAsyncComponent(() => import('@/views/NoteView.vue'))
const NoteEditView = defineAsyncComponent(() => import('@/views/NoteEditView.vue'))

const { t } = useI18n()
const layout = useLayoutStore()
const route = usePageRoute()
const router = useRouter()
const rows = ref<SkillRow[]>([])
const connections = ref<SkillConnection[]>([])
const loadFailed = ref(false)
const loading = ref(true)
const addFailed = ref<string | null>(null)
const adding = ref<string | null>(null)
const savingId = ref<number | null>(null)
const ON: SkillPatch = { enabled: true, auto: true, command: true }
const saveError = ref<{ id: number; message: string } | null>(null)
async function save(row: SkillRow, patch: SkillPatch) {
  if (row.note_id === null || savingId.value !== null) return
  savingId.value = row.note_id
  saveError.value = null
  try {
    updated((await api.updateSkill(row.note_id, patch)).skill)
  } catch (e) {
    saveError.value = { id: row.note_id, message: e instanceof Error ? e.message : t('common.unknown_error') }
  } finally {
    savingId.value = null
  }
}

async function load() {
  try {
    const r = await api.skills()
    rows.value = r.skills
    connections.value = r.connections
    loadFailed.value = false
  } catch {
    rows.value = []
    connections.value = []
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}
onMounted(load)

function updated(row: SkillRow) {
  const idx = rows.value.findIndex((r) => r.note_id !== null && r.note_id === row.note_id)
  if (idx !== -1) rows.value[idx] = row
}

// What the frame shows, read off the address as the notes list does: one of
// the owner's skills is its note (`open` and `edit` carry its number), memex's
// own and the templates are the skill alone (`open` carries the slug).
type Frame = { kind: 'read'; id: number } | { kind: 'edit'; id: number | null } | { kind: 'skill'; row: SkillRow }
const first = (key: string) => [route.query[key]].flat()[0] ?? undefined
const number = (raw: string | null | undefined) => raw && /^\d+$/.test(raw) ? Number(raw) : null
const frame = computed<Frame | null>(() => {
  const edit = first('edit')
  if (edit === 'new') return { kind: 'edit', id: null }
  if (number(edit) !== null) return { kind: 'edit', id: number(edit) }
  const open = first('open')
  if (number(open) !== null) return { kind: 'read', id: number(open)! }
  const row = open ? rows.value.find((r) => r.note_id === null && r.slug === open) : undefined
  return row ? { kind: 'skill', row } : null
})
const openKey = computed(() => {
  const f = frame.value
  if (f === null || (f.kind === 'edit' && f.id === null)) return null
  return f.kind === 'skill' ? skillKey(f.row) : `note:${f.id}`
})
const savingKey = computed(() => savingId.value !== null ? `note:${savingId.value}` : adding.value !== null ? `catalogue:${adding.value}` : null)

function show(wanted: Record<string, string>) {
  const rest = Object.fromEntries(Object.entries(route.query).filter(([key]) => !['open', 'edit'].includes(key)))
  router.replace({ query: { ...rest, ...wanted } })
}
const close = () => show({})

function openRow(row: SkillRow) {
  if (row.note_id !== null) show({ open: String(row.note_id) })
  else show({ open: row.slug })
}

// A link out of a skill to another note opens that note under Notes.
function openNote(id: number) {
  if (rows.value.some((r) => r.kind === 'note' && r.note_id === id)) show({ open: String(id) })
  else router.push({ name: 'note', params: { id } })
}

function onSaved(id: number) {
  void load()
  show({ open: String(id) })
}

function onEditCancelled() {
  const f = frame.value
  if (f?.kind === 'edit' && f.id !== null) show({ open: String(f.id) })
  else close()
}

function onDeleted() {
  void load()
  close()
}

// A template switched on is written into the knowledge base as the owner's
// note, on, and stays open as that note if it was open.
async function add(row: SkillRow, switchOn = false) {
  addFailed.value = null
  adding.value = row.slug
  try {
    await api.addSkill(row.slug)
    await load()
    const taken = rows.value.find((r) => r.kind === 'note' && r.slug === row.slug) ?? null
    if (taken && first('open') === row.slug) show({ open: String(taken.note_id) })
    if (switchOn && taken && !skillOffered(taken)) await save(taken, ON)
  } catch {
    addFailed.value = row.slug
  } finally {
    adding.value = null
  }
}

function toggle(row: SkillRow) {
  if (row.kind === 'catalogue') void add(row, true)
  else void save(row, skillOffered(row) ? { enabled: false } : ON)
}
useFrameNavigation(() => frame.value ? `${frame.value.kind}:${openKey.value ?? 'new'}` : null, () => {
  if (frame.value?.kind === 'edit') onEditCancelled()
  else close()
})
</script>

<template>
  <div v-show="layout.framed || !frame" class="container mm-skills">
    <header class="app-page-head">
      <div class="app-title-line">
        <h1>{{ $t('skills.title') }}</h1>
        <router-link class="btn btn-primary" :to="{ name: 'skill-new' }">
          <i class="fa-solid fa-plus me-1"></i> {{ $t('skills.new') }}
        </router-link>
      </div>
      <p class="app-page-lede">{{ $t('skills.intro') }}</p>
    </header>

    <p class="alert alert-warning" v-if="loadFailed">{{ $t('skills.load_failed') }}</p>
    <p class="alert alert-warning" v-if="addFailed && !layout.framed">{{ $t('skills.catalogue.add_failed') }}</p>

    <p v-if="loading" class="text-muted" role="status">{{ $t('common.loading') }}</p>
    <template v-if="!loading && !loadFailed">
      <SkillsRows v-if="layout.framed" :rows="rows" :open-key="openKey" @open="openRow" />
      <SkillsCards v-else :rows="rows" :saving-id="savingId" :save-error="saveError"
                   @open="router.push({ name: 'skill', params: { id: $event.note_id! } })" @add="add($event)"
                   @toggle="save($event, $event.enabled && ($event.auto || $event.command) ? { enabled: false } : ON)" />
    </template>
  </div>

  <Teleport v-if="layout.framed || frame" defer to="#app-frame-detail" :disabled="!layout.framed">
    <div :class="layout.framed ? 'app-frame-content' : 'container app-frame-single'">
      <NoteEditView v-if="frame?.kind === 'edit'" :key="`edit-${frame.id ?? 'new'}`" :note-id="frame.id" in-pane skill
                    @saved="onSaved" @cancel="onEditCancelled" @deleted="onDeleted" />
      <NoteView v-else-if="frame?.kind === 'read'" :key="frame.id" :note-id="frame.id" in-pane
                @close="close" @open="openNote" @edit="show({ edit: String($event) })" @changed="load" />
      <SkillFrame v-else-if="frame?.kind === 'skill'" :key="openKey ?? ''" :skill="frame.row" :connections="connections"
                  :saving="savingKey === openKey" :failure="addFailed === frame.row.slug ? $t('skills.catalogue.add_failed') : null"
                  @close="close" @toggle="toggle" />
      <div v-else class="app-frame-empty">
        <i class="fa-solid fa-graduation-cap app-frame-empty-icon" aria-hidden="true"></i>
        <p class="app-frame-empty-title">{{ $t('skills.frame_empty') }}</p>
      </div>
    </div>
  </Teleport>
</template>
