<script setup lang="ts">
import { useFrameNavigation } from '@/lib/frameNavigation'
import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type SkillConnection, type SkillPatch, type SkillRow } from '@/api/client'
import SkillsCards from '@/components/skills/SkillsCards.vue'
import SkillsRows from '@/components/skills/SkillsRows.vue'
import SkillPanel from '@/components/skills/SkillPanel.vue'
import SkillFrame from '@/components/skills/SkillFrame.vue'
import { skillKey, skillOffered } from '@/components/skills/skillGroups'
import { skillDraft, type SkillDraft } from '@/components/skills/skillEditing'
import { useLayoutStore } from '@/stores/layout'

const { t } = useI18n()
const layout = useLayoutStore()
const rows = ref<SkillRow[]>([])
const connections = ref<SkillConnection[]>([])
const openRow = ref<SkillRow | null>(null)
const openKey = computed(() => openRow.value ? skillKey(openRow.value) : null)
// One draft per opened skill, kept here and not in the dialog or the frame:
// Dynamic swaps those as the window crosses its width, and an unsaved alias or
// choice of connections goes with neither.
const draft = shallowRef<SkillDraft | null>(null)
watch(openKey, () => { draft.value = openRow.value ? skillDraft(openRow.value) : null }, { immediate: true, flush: 'sync' })
const loadFailed = ref(false)
const loading = ref(true)
const addFailed = ref<string | null>(null)
const adding = ref<string | null>(null)
const savingId = ref<number | null>(null)
const savingKey = computed(() => savingId.value !== null ? `note:${savingId.value}` : adding.value !== null ? `catalogue:${adding.value}` : null)
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
  if (openRow.value && openRow.value.note_id === row.note_id) openRow.value = row
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
    if (openRow.value && openRow.value.slug === row.slug) openRow.value = taken
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
useFrameNavigation(() => openKey.value, () => { openRow.value = null })
</script>

<template>
  <div class="container mm-skills">
    <header class="app-page-head">
      <div class="app-title-line">
        <h1>{{ $t('skills.title') }}</h1>
        <router-link class="btn btn-primary" :to="{ name: 'note-new', query: { skill: '1' } }">
          <i class="fa-solid fa-plus me-1"></i> {{ $t('skills.new') }}
        </router-link>
      </div>
      <p class="app-page-lede">{{ $t('skills.intro') }}</p>
    </header>

    <p class="alert alert-warning" v-if="loadFailed">{{ $t('skills.load_failed') }}</p>
    <p class="alert alert-warning" v-if="addFailed && !layout.framed">{{ $t('skills.catalogue.add_failed') }}</p>

    <p v-if="loading" class="text-muted" role="status">{{ $t('common.loading') }}</p>
    <template v-if="!loading && !loadFailed">
      <SkillsRows v-if="layout.framed" :rows="rows" :open-key="openKey" @open="openRow = $event" />
      <SkillsCards v-else :rows="rows" :saving-id="savingId" :save-error="saveError" @open="openRow = $event" @add="add($event)" @toggle="save($event, $event.enabled && ($event.auto || $event.command) ? { enabled: false } : ON)" />
    </template>

    <SkillPanel v-if="openRow && draft && !layout.framed" :key="openKey ?? ''" :skill="openRow" :connections="connections" :draft="draft"
                @close="openRow = null" @updated="updated" />
    <Teleport v-if="layout.framed" defer to="#app-frame-detail">
      <SkillFrame v-if="openRow && draft" :key="openKey ?? ''" :skill="openRow" :connections="connections" :draft="draft" :saving="savingKey === openKey"
                  :failure="addFailed === openRow.slug ? $t('skills.catalogue.add_failed') : saveError && saveError.id === openRow.note_id ? saveError.message : null"
                  @close="openRow = null" @updated="updated" @toggle="toggle" />
      <div v-else class="app-frame-empty">
        <i class="fa-solid fa-graduation-cap app-frame-empty-icon" aria-hidden="true"></i>
        <p class="app-frame-empty-title">{{ $t('skills.frame_empty') }}</p>
      </div>
    </Teleport>
  </div>
</template>
