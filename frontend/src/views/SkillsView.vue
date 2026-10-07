<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type SkillConnection, type SkillPatch, type SkillRow } from '@/api/client'
import SkillsCards from '@/components/skills/SkillsCards.vue'
import SkillPanel from '@/components/skills/SkillPanel.vue'

const { t } = useI18n()
const rows = ref<SkillRow[]>([])
const connections = ref<SkillConnection[]>([])
const openRow = ref<SkillRow | null>(null)
const loadFailed = ref(false)
const loading = ref(true)
const addFailed = ref<string | null>(null)
const savingId = ref<number | null>(null)
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

async function add(row: SkillRow) {
  addFailed.value = null
  try {
    await api.addSkill(row.slug)
    await load()
  } catch {
    addFailed.value = row.slug
  }
}
</script>

<template>
  <div class="container">
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
    <p class="alert alert-warning" v-if="addFailed">{{ $t('skills.catalogue.add_failed') }}</p>

    <p v-if="loading" class="text-muted" role="status">{{ $t('common.loading') }}</p>
    <SkillsCards v-if="!loading && !loadFailed" :rows="rows" :saving-id="savingId" :save-error="saveError" @open="openRow = $event" @add="add" @toggle="save($event, $event.enabled && ($event.auto || $event.command) ? { enabled: false } : { enabled: true, auto: true, command: true })" />

    <SkillPanel v-if="openRow" :key="openRow.note_id ?? openRow.slug" :skill="openRow" :connections="connections"
                @close="openRow = null" @updated="updated" />
  </div>
</template>

