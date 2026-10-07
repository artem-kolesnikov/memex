import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { LocationQuery } from 'vue-router'
import { api, type IconChoice, type SearchPreset, type SearchPresetInput } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

export type UrlCriteria = Record<string, string[]>

/** The address-bar shape of a preset: the same four keys SearchView writes. */
export function presetQuery(preset: Pick<SearchPreset, 'q' | 'tags' | 'status' | 'added_by'>): UrlCriteria {
  const next: UrlCriteria = {}
  if (preset.q) next.q = [preset.q]
  if (preset.tags.length) next.tag = preset.tags.map((t) => t.name)
  if (preset.status) next.status = [preset.status]
  if (preset.added_by !== null) next.added_by = [String(preset.added_by)]
  return next
}

/** The same four keys as they stand in a route's query, same shape. */
export function routeCriteria(query: LocationQuery): UrlCriteria {
  const current: UrlCriteria = {}
  for (const key of ['q', 'tag', 'status', 'added_by']) {
    const raw = query[key]
    const values = (Array.isArray(raw) ? raw : [raw]).filter(
      (v): v is string => typeof v === 'string' && v !== '',
    )
    if (values.length) current[key] = values
  }
  return current
}

export function sameCriteria(a: UrlCriteria, b: UrlCriteria): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

/**
 * The workspaces (saved filters) pinned to the sidebar. One list, because the
 * sidebar draws it and the notes page adds to it.
 */
export const usePresetStore = defineStore('presets', () => {
  const presets = ref<SearchPreset[]>([])
  const icons = ref<IconChoice[]>([])
  /** The list has been read once, so an empty one means none rather than not yet. */
  const loaded = ref(false)

  const byName = (list: SearchPreset[]) => [...list].sort((a, b) => a.name.localeCompare(b.name))

  async function load(): Promise<void> {
    if (useAuthStore().user === null) {
      presets.value = []
      loaded.value = false
      return
    }
    try {
      const answer = await api.presets()
      presets.value = byName(answer.presets)
      icons.value = answer.icons
      loaded.value = true
    } catch {
      /* the sidebar keeps what it has */
    }
  }

  async function create(input: SearchPresetInput): Promise<SearchPreset> {
    const { preset } = await api.createPreset(input)
    presets.value = byName([...presets.value, preset])
    return preset
  }

  async function update(id: number, input: SearchPresetInput): Promise<SearchPreset> {
    const { preset } = await api.updatePreset(id, input)
    presets.value = byName(presets.value.map((p) => (p.id === id ? preset : p)))
    return preset
  }

  async function remove(id: number): Promise<void> {
    await api.removePreset(id)
    presets.value = presets.value.filter((p) => p.id !== id)
  }

  return { presets, icons, loaded, load, create, update, remove }
})
