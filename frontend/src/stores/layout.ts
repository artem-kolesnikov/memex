import { defineStore } from 'pinia'
import { reactive, ref } from 'vue'

export type RailPage = 'note' | 'editor'

const SIDEBAR_KEY = 'mm-sidebar-collapsed'
const RAIL_KEYS: Record<RailPage, string> = {
  note: 'mm-note-rail-hidden',
  editor: 'mm-editor-rail-hidden',
}

function read(key: string): boolean {
  try {
    return localStorage.getItem(key) === '1'
  } catch {
    return false
  }
}

function write(key: string, value: boolean): void {
  try {
    localStorage.setItem(key, value ? '1' : '0')
  } catch {
    // A browser refusing storage is not a reason to lose the toggle.
  }
}

export const useLayoutStore = defineStore('layout', () => {
  const sidebarCollapsed = ref(read(SIDEBAR_KEY))
  const railHidden = reactive<Record<RailPage, boolean>>({
    note: read(RAIL_KEYS.note),
    editor: read(RAIL_KEYS.editor),
  })

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
    write(SIDEBAR_KEY, sidebarCollapsed.value)
  }

  function toggleRail(page: RailPage): void {
    railHidden[page] = !railHidden[page]
    write(RAIL_KEYS[page], railHidden[page])
  }

  return { sidebarCollapsed, railHidden, toggleSidebar, toggleRail }
})
