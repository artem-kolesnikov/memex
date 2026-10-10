import { defineStore } from 'pinia'
import { computed, reactive, ref } from 'vue'

export type RailPage = 'note' | 'editor' | 'pane'
/** A page at a time, two frames, or frames only on a window wide enough. */
export type LayoutMode = 'windowed' | 'framed' | 'dynamic'
const LAYOUT_MODES: LayoutMode[] = ['windowed', 'framed', 'dynamic']

const SIDEBAR_KEY = 'mm-sidebar-collapsed'
const LAYOUT_KEY = 'mm-layout'
const LIST_WIDTH_KEY = 'mm-list-width'
const RAIL_KEYS: Record<RailPage, string> = {
  note: 'mm-note-rail-hidden',
  editor: 'mm-editor-rail-hidden',
  pane: 'mm-pane-rail-hidden',
}

export const LIST_WIDTH_DEFAULT = 420

function read(key: string): boolean {
  try {
    return localStorage.getItem(key) === '1'
  } catch {
    return false
  }
}

function readLayout(): LayoutMode {
  try {
    const stored = localStorage.getItem(LAYOUT_KEY)
    return LAYOUT_MODES.find((mode) => mode === stored) ?? 'windowed'
  } catch {
    return 'windowed'
  }
}

function write(key: string, value: boolean): void {
  try {
    localStorage.setItem(key, value ? '1' : '0')
  } catch {
    // A browser refusing storage is not a reason to lose the toggle.
  }
}

function readWidth(): number {
  try {
    const stored = Number.parseInt(localStorage.getItem(LIST_WIDTH_KEY) ?? '', 10)
    return Number.isInteger(stored) && stored > 0 ? stored : LIST_WIDTH_DEFAULT
  } catch {
    return LIST_WIDTH_DEFAULT
  }
}

export const useLayoutStore = defineStore('layout', () => {
  const sidebarCollapsed = ref(read(SIDEBAR_KEY))
  const railHidden = reactive<Record<RailPage, boolean>>({
    note: read(RAIL_KEYS.note),
    editor: read(RAIL_KEYS.editor),
    pane: read(RAIL_KEYS.pane),
  })

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
    write(SIDEBAR_KEY, sidebarCollapsed.value)
  }

  function toggleRail(page: RailPage): void {
    railHidden[page] = !railHidden[page]
    write(RAIL_KEYS[page], railHidden[page])
  }

  // The reader's choice, per device like the sidebar: a page at a time, a
  // list page as two frames, or two frames only on a window wide enough for
  // both. `framed` is whether the stage is showing them now, and `frameWidth`
  // the list frame's width as drawn — the width the reader dragged it to,
  // held inside what the window leaves.
  const layoutMode = ref<LayoutMode>(readLayout())
  const stageWidth = ref<number | null>(null)
  // Routing also needs the preference before the shell's first measurement.
  const framesEnabled = computed(() => {
    const sidebar = window.innerWidth < 992 ? 0 : Number.parseFloat(getComputedStyle(document.documentElement)
      .getPropertyValue(sidebarCollapsed.value ? '--app-sidebar-collapsed' : '--app-sidebar-expanded'))
    const width = stageWidth.value ?? window.innerWidth - sidebar
    return layoutMode.value === 'framed' || (layoutMode.value === 'dynamic' && width >= 1100)
  })
  const framed = ref(false)
  const listWidth = ref(readWidth())
  const frameWidth = ref(LIST_WIDTH_DEFAULT)

  function setLayoutMode(mode: LayoutMode): void {
    layoutMode.value = mode
    try {
      localStorage.setItem(LAYOUT_KEY, mode)
    } catch {
      // The choice still holds for this visit.
    }
  }

  function setListWidth(width: number, remember: boolean): void {
    listWidth.value = width
    if (!remember) return
    try {
      localStorage.setItem(LIST_WIDTH_KEY, String(width))
    } catch {
      // As above: the width still holds for this visit.
    }
  }

  return {
    sidebarCollapsed, railHidden, toggleSidebar, toggleRail,
    layoutMode, framesEnabled, stageWidth, framed, listWidth, frameWidth, setLayoutMode, setListWidth,
  }
})
