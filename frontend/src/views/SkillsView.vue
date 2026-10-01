<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, ApiError, type SkillConnection, type SkillPatch, type SkillRow } from '@/api/client'
import SkillsCards from '@/components/skills/SkillsCards.vue'
import SkillPanel from '@/components/skills/SkillPanel.vue'

const router = useRouter()
const { t } = useI18n()
const rows = ref<SkillRow[]>([])
const connections = ref<SkillConnection[]>([])
const openRow = ref<SkillRow | null>(null)
const loadFailed = ref(false)
const loading = ref(true)
const importing = ref(false)
const importReport = ref<{ created: number; errors: { file: string; error: string }[]; ignored: string[] } | null>(null)
const addFailed = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
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

async function onFiles(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  if (!files.length) return
  importing.value = true
  try {
    const r = await api.importSkills(files)
    importReport.value = { created: r.created.length, errors: r.errors, ignored: r.ignored }
    await load()
  } catch (err) {
    if (err instanceof ApiError && Array.isArray(err.data.errors)) {
      importReport.value = { created: 0, errors: err.data.errors as { file: string; error: string }[], ignored: (err.data.ignored as string[] | undefined) ?? [] }
    } else {
      importReport.value = { created: 0, errors: [{ file: files.map((f) => f.name).join(', '), error: err instanceof Error ? err.message : '' }], ignored: [] }
    }
  } finally {
    importing.value = false
  }
}
</script>

<template>
  <div class="container">
    <header class="app-page-head d-flex flex-wrap align-items-start gap-2">
      <div class="me-auto mm-skills-intro">
        <h1>{{ $t('skills.title') }}</h1>
        <p class="app-page-lede">{{ $t('skills.intro') }}</p>
      </div>
      <div class="d-flex gap-2 flex-shrink-0">
        <button type="button" class="btn btn-sm btn-primary" @click="router.push({ name: 'note-new', query: { skill: '1' } })">{{ $t('skills.new') }}</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="importing" @click="fileInput?.click()">{{ $t('skills.import') }}</button>
      </div>
      <input ref="fileInput" type="file" class="d-none" accept=".md,.markdown,.zip" multiple @change="onFiles" />
    </header>

    <p class="alert alert-warning" v-if="loadFailed">{{ $t('skills.load_failed') }}</p>
    <p class="alert alert-warning" v-if="addFailed">{{ $t('skills.catalogue.add_failed') }}</p>

    <div class="alert alert-light border" v-if="importReport">
      <div>{{ $t('skills.import_report.created', importReport.created) }}</div>
      <ul class="mb-0" v-if="importReport.errors.length">
        <li v-for="e in importReport.errors" :key="e.file"><code>{{ e.file }}</code> — {{ e.error }}</li>
      </ul>
      <div class="small text-muted" v-if="importReport.ignored.length">{{ $t('skills.import_report.ignored', { files: importReport.ignored.join(', ') }) }}</div>
    </div>

    <p v-if="loading" class="text-muted" role="status">{{ $t('common.loading') }}</p>
    <SkillsCards v-if="!loading && !loadFailed" :rows="rows" :saving-id="savingId" :save-error="saveError" @open="openRow = $event" @add="add" @toggle="save($event, $event.enabled && ($event.auto || $event.command) ? { enabled: false } : { enabled: true, auto: true, command: true })" />

    <SkillPanel v-if="openRow" :key="openRow.note_id ?? openRow.slug" :skill="openRow" :connections="connections"
                @close="openRow = null" @updated="updated" />
  </div>
</template>

<style scoped>
.mm-skills-intro {
  flex: 1 1 24rem;
}
</style>
