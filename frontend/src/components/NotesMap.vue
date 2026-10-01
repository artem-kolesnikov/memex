<script setup lang="ts">
// The notes page's second view: the same search, drawn as the collection
// rather than listed. The page owns the search — its box, tags and status dim
// whatever they leave out — and this box owns what only a picture can ask.
//
// Each mode asks one question, says in a line why that question matters, and
// draws the answer rather than listing it. One floating box carries the words
// the picture cannot: the note picked, or the list the mode draws. The map
// keeps the full width underneath it.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, MAP_DEFAULTS, readMapPrefs, type BlastRadiusData, type GraphNode, type NoteGraphData } from '@/api/client'
import { toastError } from '@/components/toastService'
import { useAuthStore } from '@/stores/auth'
import HelpTip from '@/components/HelpTip.vue'
import NoteMap from '@/components/NoteMap.vue'
import NoteMap3d from '@/components/NoteMap3d.vue'
import { reasonWords } from '@/lib/curationReasons'
import {
  components,
  edgeKey,
  groupColour,
  hopScale,
  hopsFrom,
  linkDegree,
  orphans,
  weightedRatio,
  weightedScale,
  type MapEmphasis,
  type MapMode,
} from '@/lib/mapRadar'

const props = defineProps<{
  /** The notes the page's search matched, or null when nothing narrows it. */
  filterIds: number[] | null
  /** Why that search matched nothing, when it did. */
  notice?: string
  /** A search is on its way, so `filterIds` still answers the previous one. */
  searching?: boolean
}>()

const emit = defineEmits<{ 'clear-filters': []; tag: [name: string] }>()

const auth = useAuthStore()
const route = useRoute()
const { t } = useI18n()
// A flag rather than "the first graph to arrive": a failed load empties `graph`,
// and reading emptiness as first-ness replays the URL note over whatever the
// reader had selected by then.
let queryNoteRead = false
const graph = ref<NoteGraphData | null>(null)
const selectedId = ref<number | null>(null)
const mode = ref<MapMode>('all')
const island = ref<number | null>(null)
const semantic = ref(false)
const map = ref<InstanceType<typeof NoteMap> | null>(null)
const map3d = ref<InstanceType<typeof NoteMap3d> | null>(null)
// Settings → General → Map owns all four; this view only reads them.
const mapPrefs = computed(() => readMapPrefs(auth.user?.map))
const renderer = computed(() => mapPrefs.value.view)

/**
 * Flat or 3D, on the map rather than only in Settings: it is the one map
 * setting a reader changes while looking at the map, and it writes to the same
 * account preference the Settings row does — two controls, one answer.
 */
const savingView = ref(false)

async function chooseView(view: '2d' | '3d') {
  if (renderer.value === view || savingView.value) return
  savingView.value = true
  try {
    // The default is sent as null, so an account that chose its way back to it
    // keeps an empty row rather than a blob restating the default.
    await auth.chooseMapSetting(view === MAP_DEFAULTS.view ? { view: null } : { view })
  } catch (e: unknown) {
    toastError(t('content.map.not_saved'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    savingView.value = false
  }
}

const nodes = computed(() => graph.value?.nodes ?? [])
const edges = computed(() => graph.value?.edges ?? [])
const byId = computed(() => new Map(nodes.value.map((n) => [n.id, n])))

/** How many links each note carries, either way. Suggestions are not links. */
const degree = computed(() => linkDegree(edges.value))

/** Every note, zero included: the hub scale is relative to the whole map. */
const degreeOfAll = computed(() => {
  const counts = new Map<number, number>()
  for (const n of nodes.value) counts.set(n.id, degree.value.get(n.id) ?? 0)
  return counts
})

const orphanIds = computed(() => orphans(nodes.value, degree.value))

/** The notes carrying the most links: where the collection actually hangs. */
const hubs = computed(() =>
  [...nodes.value]
    .map((n) => ({ node: n, links: degree.value.get(n.id) ?? 0 }))
    .filter((row) => row.links > 0)
    .sort((a, b) => b.links - a.links || a.node.title.localeCompare(b.node.title))
    .slice(0, 12),
)

/** Islands: the pieces the linked collection falls into, largest first. */
const pieces = computed(() => components(nodes.value, edges.value))
const islands = computed(() => pieces.value.filter((ids) => ids.length > 1))

/** An island's name is its most-linked member — what the piece is about. */
function islandTitle(ids: number[]): string {
  const best = [...ids].sort((a, b) => (degree.value.get(b) ?? 0) - (degree.value.get(a) ?? 0))[0]
  return best === undefined ? '' : byId.value.get(best)?.title ?? ''
}

const hops = computed(() =>
  selectedId.value === null ? null : hopsFrom(edges.value, selectedId.value, 2),
)
const hopRows = computed(() => {
  const found = hops.value
  if (found === null) return { one: [] as GraphNode[], two: [] as GraphNode[] }
  const at = (hop: number) =>
    [...found.entries()]
      .filter(([, h]) => h === hop)
      .map(([id]) => byId.value.get(id))
      .filter((n): n is GraphNode => n !== undefined)
  return { one: at(1), two: at(2) }
})

// ---- blast radius: the one mode the browser cannot work out for itself -----

const BLAST_WINDOWS = [7, 14, 30, 90]
const blastDays = ref(14)
const blast = ref<BlastRadiusData | null>(null)
const blastLoading = ref(false)
const blastFailed = ref(false)
// Numbered rather than guarded by the loading flag. A flag refuses the second
// request, so a window changed mid-flight was answered by neither: the first
// reply was discarded as stale and the second never left.
let blastAsked = 0

async function loadBlast() {
  const mine = ++blastAsked
  blastLoading.value = true
  blastFailed.value = false
  try {
    const answer = await api.graphBlast(blastDays.value)
    if (mine !== blastAsked) return
    blast.value = answer
  } catch {
    if (mine !== blastAsked) return
    blast.value = null
    blastFailed.value = true
  } finally {
    if (mine === blastAsked) blastLoading.value = false
  }
}

/** Only the affected notes the map actually draws; the rest cannot be pointed at. */
const blastRows = computed(() =>
  (blast.value?.notes ?? []).filter((row) => byId.value.has(row.note_id)),
)

watch([mode, blastDays], ([m]) => {
  if (m === 'blast') loadBlast()
})

// ---- what each mode draws --------------------------------------------------

const MODES: { id: MapMode; labelKey: string }[] = [
  { id: 'all', labelKey: 'map.mode.all' },
  { id: 'orphans', labelKey: 'map.mode.orphans' },
  { id: 'hubs', labelKey: 'map.mode.hubs' },
  { id: 'neighbourhood', labelKey: 'map.mode.neighbourhood' },
  { id: 'blast', labelKey: 'map.mode.blast' },
  { id: 'islands', labelKey: 'map.mode.islands' },
]

/**
 * The notes a mode is about, or null for "the mode dims nothing" — which hubs
 * and islands both want, because their finding is where a note sits among all
 * the others rather than which few survive.
 */
const modeSet = computed<number[] | null>(() => {
  switch (mode.value) {
    case 'orphans':
      return orphanIds.value
    case 'neighbourhood':
      return hops.value === null ? null : [...hops.value.keys()]
    case 'blast':
      // The notes that CHANGED stay lit beside the ones they may have left
      // behind: a stale note dimmed away from its cause says that something
      // moved but never which thing, and the link between them is the answer.
      //
      // The affected notes are added on their own account rather than as one
      // end of a drawable pair. A note whose changed neighbours are all off the
      // map has no pair, and reading the set out of the pairs dropped it from
      // the answer entirely while the list still counted it (Codex, 2026-09-11).
      return blast.value === null
        ? null
        : [...new Set([...blastRows.value.map((row) => row.note_id), ...blastPairs().map(([, source]) => source)])]
    case 'islands':
      return island.value === null ? null : (islands.value[island.value] ?? null)
    default:
      return null
  }
})

const emphasis = computed<MapEmphasis | null>(() => {
  switch (mode.value) {
    case 'orphans':
      return { scale: Object.fromEntries(orphanIds.value.map((id) => [id, 1.35])) }
    case 'hubs':
      return { scale: weightedScale(degreeOfAll.value), shade: weightedRatio(degreeOfAll.value) }
    case 'neighbourhood':
      return hops.value === null ? null : { scale: hopScale(hops.value) }
    case 'blast':
      return blastEmphasis()
    case 'islands':
      return { group: islandGroups() }
    default:
      return null
  }
})

/** Each affected note against a change that may have left it behind, on the map. */
function blastPairs(): [number, number][] {
  const pairs: [number, number][] = []
  for (const row of blastRows.value) {
    for (const source of row.changed) {
      if (byId.value.has(source.note_id)) pairs.push([row.note_id, source.note_id])
    }
  }

  return pairs
}

function blastEmphasis(): MapEmphasis | null {
  if (blast.value === null) return null
  const counts = new Map<number, number>()
  const hot: string[] = []
  for (const row of blastRows.value) counts.set(row.note_id, row.changed_neighbours)
  for (const [affected, source] of blastPairs()) {
    // A note that only CHANGED is drawn at the floor, so size still means "how
    // much of this is out of date" rather than "took part".
    if (!counts.has(source)) counts.set(source, 0)
    // The payload names the pair, not which way the link is written, and an
    // edge id carries the direction — so both readings are offered and the
    // canvas lights whichever one it holds.
    hot.push(edgeKey(affected, source), edgeKey(source, affected))
  }

  return { scale: weightedScale(counts), hot }
}

function islandGroups(): Record<number, number> {
  const out: Record<number, number> = {}
  islands.value.forEach((ids, index) => {
    for (const id of ids) out[id] = index
  })

  return out
}

// ---- the page's search, which is a different question from the mode --------

/** Null means no filter at all, which is what the canvas reads as "dim nothing". */
const matched = computed<number[] | null>(() => {
  const byMode = modeSet.value
  if (byMode === null) return props.filterIds
  if (props.filterIds === null) return byMode
  const keep = new Set(props.filterIds)

  return byMode.filter((id) => keep.has(id))
})

const selected = computed(() => (selectedId.value === null ? null : byId.value.get(selectedId.value) ?? null))

/**
 * Matches the map cannot light. A collection past the node cap is drawn in
 * part, so the page's count can name notes with no dot to show for them; that
 * is said rather than left as a map with nothing lit.
 */
const offMap = computed(() => {
  if (props.filterIds === null || graph.value?.truncated !== true) return 0
  return props.filterIds.filter((id) => !byId.value.has(id)).length
})

function related(kind: 'out' | 'in' | 'near'): GraphNode[] {
  const id = selectedId.value
  if (id === null) return []
  const ids: number[] = []
  for (const edge of edges.value) {
    if (kind === 'near') {
      if (edge.kind !== 'semantic') continue
      if (edge.from === id) ids.push(edge.to)
      else if (edge.to === id) ids.push(edge.from)
      continue
    }
    if (edge.kind !== 'link') continue
    if (kind === 'out' && edge.from === id) ids.push(edge.to)
    if (kind === 'in' && edge.to === id) ids.push(edge.from)
  }
  return [...new Set(ids)].map((n) => byId.value.get(n)).filter((n): n is GraphNode => n !== undefined)
}

const linksOut = computed(() => related('out'))
const linksIn = computed(() => related('in'))
const nearby = computed(() => related('near'))

/**
 * A note's own map hands the whole collection over with that note picked, so
 * the neighbourhood you were reading is where you land rather than somewhere to
 * hunt for. Read once, on the first graph to arrive.
 */
function openAtQueryNote(data: NoteGraphData) {
  const asked = Number(route.query.note)
  if (!Number.isInteger(asked) || !data.nodes.some((n) => n.id === asked)) return
  reveal(asked)
}

function onLoaded(data: NoteGraphData | null) {
  graph.value = data
  if (data === null) {
    selectedId.value = null
    return
  }
  if (!queryNoteRead) {
    queryNoteRead = true
    openAtQueryNote(data)
  }
  // A note that did not survive the reload would leave the box describing
  // something no longer on the map.
  if (selectedId.value !== null && !data.nodes.some((n) => n.id === selectedId.value)) {
    selectedId.value = null
  }
}

function pickMode(id: MapMode) {
  mode.value = id
  panelOpen.value = true
}

function pickIsland(index: number) {
  island.value = island.value === index ? null : index
}

/**
 * Select a note, dropping whatever hides it (operator, 2026-09-02).
 *
 * The box lists a note's real neighbours, and a search routinely hides some of
 * them. Lighting one up inside the dimming left the canvas showing notes the
 * search says are out, which makes the page's count a lie — so the page is
 * asked to clear its search. The mode is dropped on the same grounds — except
 * in the neighbourhood, where picking a note IS how the mode is re-rooted, so
 * its set already contains what was clicked by the time it is drawn.
 *
 * EVERY selection comes through here, the canvas's own taps included. Wiring
 * the canvas straight to `selectedId` walked around the policy, and the watcher
 * below did not catch it: `matched` never changed, so nothing fired, and the
 * box described a note the canvas had dimmed away (Codex, 2026-09-11).
 */
function select(id: number | null) {
  if (id === null) {
    selectedId.value = null
    return
  }
  // Not while a search is on its way: the ids in hand answer the one before
  // it, and a note outside THAT is no reason to drop the one just asked.
  if (!props.searching && props.filterIds !== null && !props.filterIds.includes(id)) emit('clear-filters')
  // Widening to every island is enough; the mode itself still answers.
  if (island.value !== null && !(islands.value[island.value] ?? []).includes(id)) island.value = null
  if (mode.value !== 'neighbourhood' && modeSet.value !== null && !modeSet.value.includes(id)) {
    mode.value = 'all'
  }
  selectedId.value = id
  panelOpen.value = true
}

/** Select, and bring the view to it — what a link in the box and a second tap do. */
function reveal(id: number) {
  select(id)
  nextTick(() => (renderer.value === '2d' ? map.value : map3d.value)?.focus(id))
}

// A note the search has just hidden must not stay in the box: the box is a
// caption for the picture, and the picture no longer shows it.
watch(matched, (ids) => {
  if (ids !== null && selectedId.value !== null && !ids.includes(selectedId.value)) {
    selectedId.value = null
  }
})

// ---- the floating box --------------------------------------------------------

/** The modes whose answer is also a list worth reading. */
const LISTED: MapMode[] = ['orphans', 'hubs', 'blast', 'islands']

/** The bar's name for a mode's list, and how long it is when that is news. */
const listHead = computed<{ labelKey: string; count: number | null }>(() => {
  switch (mode.value) {
    case 'orphans':
      return { labelKey: 'map.mode.orphans', count: orphanIds.value.length }
    case 'hubs':
      return { labelKey: 'map.rail.most_connected', count: null }
    case 'blast':
      return { labelKey: 'map.blast.affected', count: blastRows.value.length }
    default:
      return { labelKey: 'map.mode.islands', count: islands.value.length }
  }
})

/** What the box would say now: the note picked, else the mode's list, else nothing. */
const panelContent = computed<'note' | 'list' | null>(() => {
  if (selected.value !== null) return 'note'
  return LISTED.includes(mode.value) ? 'list' : null
})

// Closed by its own button and opened again by the next thing clicked — a note
// or a mode. Where it was dragged is kept until it is closed; closing puts it
// back in the corner it opens in.
const panelOpen = ref(true)
const panelAt = ref<{ left: number; top: number } | null>(null)
const stage = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
let drag: { pointer: number; dx: number; dy: number } | null = null

function closePanel() {
  panelOpen.value = false
  panelAt.value = null
}

/** Put the box's corner at a point in the page, kept inside the map. */
function place(clientLeft: number, clientTop: number) {
  if (stage.value === null || panel.value === null) return
  const box = stage.value.getBoundingClientRect()
  const own = panel.value.getBoundingClientRect()
  const clamp = (v: number, max: number) => Math.min(Math.max(v, 0), Math.max(max, 0))
  panelAt.value = {
    left: clamp(clientLeft - box.left, box.width - own.width),
    top: clamp(clientTop - box.top, box.height - own.height),
  }
}

function startDrag(event: PointerEvent) {
  if (event.button !== 0 || panel.value === null) return
  if ((event.target as HTMLElement).closest('button')) return
  const own = panel.value.getBoundingClientRect()
  drag = { pointer: event.pointerId, dx: event.clientX - own.left, dy: event.clientY - own.top }
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  event.preventDefault()
}

function moveDrag(event: PointerEvent) {
  if (drag === null || event.pointerId !== drag.pointer) return
  place(event.clientX - drag.dx, event.clientY - drag.dy)
}

function endDrag(event: PointerEvent) {
  if (drag !== null && event.pointerId === drag.pointer) drag = null
}

const panelStyle = computed(() =>
  panelAt.value === null
    ? undefined
    : {
        left: `${panelAt.value.left}px`,
        top: `${panelAt.value.top}px`,
        right: 'auto',
        maxHeight: `calc(100% - ${panelAt.value.top + 12}px)`,
      },
)

// ---- the desk fills the window, so the page never scrolls for a map ---------

const desk = ref<HTMLElement | null>(null)
const deskHeight = ref<number | null>(null)
const DESK_BOTTOM = 24
const DESK_MIN = 460
let deskWatcher: ResizeObserver | null = null

function fitDesk() {
  if (desk.value === null) return
  // Below the desktop layout the map takes a share of the screen instead, and
  // the page scrolls past it (app.css).
  if (window.innerWidth < 992) {
    deskHeight.value = null
    return
  }
  const top = desk.value.getBoundingClientRect().top + window.scrollY
  deskHeight.value = Math.max(DESK_MIN, Math.round(window.innerHeight - top - DESK_BOTTOM))
}

function keepPanelInside() {
  if (panelAt.value === null || stage.value === null || panel.value === null) return
  const box = stage.value.getBoundingClientRect()
  place(box.left + panelAt.value.left, box.top + panelAt.value.top)
}

onMounted(() => {
  fitDesk()
  window.addEventListener('resize', fitDesk)
  // What sits above the map — the filter chips — comes and goes, and the desk
  // follows it rather than pushing the page into a scroll.
  deskWatcher = new ResizeObserver(() => {
    fitDesk()
    keepPanelInside()
  })
  if (desk.value?.parentElement) deskWatcher.observe(desk.value.parentElement)
  if (stage.value) deskWatcher.observe(stage.value)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', fitDesk)
  deskWatcher?.disconnect()
})
</script>

<template>
  <div ref="desk" class="app-paper mm-notes-map" :style="deskHeight === null ? undefined : { height: `${deskHeight}px` }">
    <div class="mm-map-toolbar">
      <div class="mm-map-modes" role="group" :aria-label="$t('map.mode_label')">
        <button v-for="m in MODES" :key="m.id" type="button" class="mm-map-lens"
                :class="{ 'is-on': mode === m.id }" :aria-pressed="mode === m.id"
                @click="pickMode(m.id)">
          {{ $t(m.labelKey) }}
        </button>
      </div>

      <div class="mm-map-settings-row">
        <select v-if="mode === 'blast'" v-model.number="blastDays"
                class="form-select form-select-sm mm-map-window" :aria-label="$t('map.blast.window_label')">
          <option v-for="d in BLAST_WINDOWS" :key="d" :value="d">{{ $t('map.blast.window', { n: d }) }}</option>
        </select>

        <div class="form-check form-switch mm-map-suggest">
          <input id="map-semantic" v-model="semantic" class="form-check-input" type="checkbox">
          <label for="map-semantic" class="form-check-label small">{{ $t('map.suggested_links') }}</label>
        </div>

        <div class="app-segmented mm-map-renderer" role="group" :aria-label="$t('content.map.view')">
          <button v-for="view in (['2d', '3d'] as const)" :key="view" type="button" class="app-segment"
                  :class="{ 'is-active': renderer === view }" :aria-pressed="renderer === view"
                  :disabled="savingView" @click="chooseView(view)">
            {{ $t(`content.map.view_${view}`) }}
          </button>
        </div>

        <HelpTip :label="$t('map.about.label')">
          <p>{{ $t('map.about.map_scope') }}</p>
          <p>{{ $t('map.about.colours') }}</p>
          <p class="mb-0">{{ $t(renderer === '2d' ? 'map.about.lines' : 'map.about.lines_3d') }}</p>
        </HelpTip>
      </div>
    </div>

    <p v-if="notice" class="mm-map-why">{{ notice }}</p>
    <p v-else-if="offMap" class="mm-map-why">
      {{ $t('map.off_map', { count: offMap, drawn: nodes.length, total: graph?.total_notes }, offMap) }}
    </p>
    <p v-else-if="mode !== 'all'" class="mm-map-why">
      <template v-if="mode === 'neighbourhood' && selectedId === null">{{ $t('map.why.neighbourhood_pick') }}</template>
      <template v-else-if="mode === 'blast' && blastLoading">{{ $t('map.blast.loading') }}</template>
      <template v-else-if="mode === 'blast' && blastFailed">{{ $t('map.blast.failed') }}</template>
      <template v-else>{{ $t(`map.why.${mode}`) }}</template>
    </p>

    <div ref="stage" class="mm-notes-map-stage">
      <NoteMap v-if="renderer === '2d'" ref="map" mode="map" height="" selectable
               :semantic="semantic" :selected-id="selectedId" :matched="matched" :emphasis="emphasis"
               :node-shape="mapPrefs.nodeShape" :link-style="mapPrefs.links"
               @loaded="onLoaded" @select="select($event)" />
      <NoteMap3d v-else ref="map3d" mode="map" height="" selectable
                 :semantic="semantic" :selected-id="selectedId" :matched="matched" :emphasis="emphasis"
                 :node-shape="mapPrefs.nodeShape" :link-style="mapPrefs.links"
                 :label-field="mapPrefs.labels"
                 @loaded="onLoaded" @select="select($event)" />

      <aside v-if="panelOpen && panelContent !== null" ref="panel" class="mm-map-panel" :style="panelStyle"
             :aria-label="panelContent === 'note' ? $t('map.rail.label') : $t(`map.mode.${mode}`)"
             @keydown.esc="closePanel">
        <div class="mm-map-panel-bar" :title="$t('map.panel.move')"
             @pointerdown="startDrag" @pointermove="moveDrag" @pointerup="endDrag" @pointercancel="endDrag">
          <button v-if="panelContent === 'note' && LISTED.includes(mode)" type="button"
                  class="mm-map-panel-back" @click="selectedId = null">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>{{ $t(`map.mode.${mode}`) }}
          </button>
          <span v-else-if="panelContent === 'note'" class="mm-map-panel-kicker">{{ $t('map.panel.note') }}</span>
          <span v-else class="mm-map-panel-kicker">
            {{ $t(listHead.labelKey) }}<b v-if="listHead.count !== null">{{ listHead.count }}</b>
          </span>
          <button type="button" class="mm-map-panel-close" :aria-label="$t('map.panel.close')"
                  :title="$t('map.panel.close')" @click="closePanel">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>

        <div class="mm-map-panel-body">
          <template v-if="selected">
            <h2 class="mm-map-panel-title">{{ selected.title }}</h2>
            <p class="mm-map-panel-note" v-if="selected.summary">{{ selected.summary }}</p>
            <p class="mm-map-panel-note is-empty" v-else>{{ $t('map.rail.no_description') }}</p>

            <div class="mm-map-panel-tags" v-if="selected.tags.length">
              <button v-for="name in selected.tags" :key="name" type="button" class="mm-tag"
                      @click="emit('tag', name)">{{ name }}</button>
            </div>

            <p class="mm-map-panel-flags" v-if="selected.flagged || selected.defects.length">
              <span class="mm-map-panel-flag is-flag" v-if="selected.flagged">{{ $t('map.rail.flagged') }}</span>
              <span class="mm-map-panel-flag" v-for="d in selected.defects" :key="d">{{ reasonWords(d) }}</span>
            </p>

            <router-link class="btn btn-primary btn-sm w-100 my-3"
                         :to="{ name: 'note', params: { id: selected.id } }">
              {{ $t('map.rail.open_note') }}
            </router-link>

            <!-- Close in meaning is omitted rather than shown empty when the
                 suggestion layer is off: it would read as "nothing is close to
                 this note", which is not what an unasked question answers. -->
            <section class="mm-map-panel-links" v-for="group in [
              { key: 'out', labelKey: 'map.rail.links_to', rows: linksOut },
              { key: 'in', labelKey: 'map.rail.linked_from', rows: linksIn },
              ...(mode === 'neighbourhood' ? [{ key: 'two', labelKey: 'map.rail.two_hops', rows: hopRows.two }] : []),
              ...(semantic ? [{ key: 'near', labelKey: 'map.rail.close_in_meaning', rows: nearby }] : []),
            ]" :key="group.key">
              <h3 class="mm-map-panel-label">{{ $t(group.labelKey) }} <span>{{ group.rows.length }}</span></h3>
              <ul v-if="group.rows.length">
                <li v-for="n in group.rows" :key="n.id">
                  <button type="button" @click="reveal(n.id)">{{ n.title }}</button>
                </li>
              </ul>
              <p class="mm-map-panel-note is-empty" v-else>{{ $t('map.rail.none') }}</p>
            </section>
          </template>

          <section class="mm-map-panel-links" v-else-if="mode === 'orphans'">
            <ul v-if="orphanIds.length">
              <li v-for="id in orphanIds" :key="id">
                <button type="button" @click="reveal(id)">{{ byId.get(id)?.title }}</button>
              </li>
            </ul>
            <p class="mm-map-panel-note is-empty" v-else>{{ $t('map.rail.no_orphans') }}</p>
          </section>

          <section class="mm-map-panel-links" v-else-if="mode === 'hubs'">
            <ul v-if="hubs.length">
              <li v-for="row in hubs" :key="row.node.id">
                <button type="button" @click="reveal(row.node.id)">
                  {{ row.node.title }}<b>{{ row.links }}</b>
                </button>
              </li>
            </ul>
            <p class="mm-map-panel-note is-empty" v-else>{{ $t('map.rail.no_hubs') }}</p>
          </section>

          <section class="mm-map-panel-links" v-else-if="mode === 'blast'">
            <p class="mm-map-panel-note is-empty" v-if="blastLoading">{{ $t('map.blast.loading') }}</p>
            <p class="mm-map-panel-note is-empty" v-else-if="blastFailed">{{ $t('map.blast.failed') }}</p>
            <ul v-else-if="blastRows.length">
              <li v-for="row in blastRows" :key="row.note_id">
                <button type="button" @click="reveal(row.note_id)">
                  {{ row.title }}<b>{{ row.changed_neighbours }}</b>
                </button>
              </li>
            </ul>
            <p class="mm-map-panel-note is-empty" v-else-if="blast && blast.total > 0">
              {{ $t('map.blast.off_map', { n: blast.total }) }}
            </p>
            <p class="mm-map-panel-note is-empty" v-else>{{ $t('map.blast.none') }}</p>
          </section>

          <section class="mm-map-panel-links" v-else-if="mode === 'islands'">
            <ul v-if="islands.length" class="mm-map-island-list">
              <li v-for="(ids, index) in islands" :key="index">
                <button type="button" :class="{ 'is-on': island === index }" @click="pickIsland(index)">
                  <i class="mm-map-island-key" :style="{ background: groupColour(index) }"></i>
                  <span>{{ islandTitle(ids) }}</span><b>{{ ids.length }}</b>
                </button>
              </li>
            </ul>
            <p class="mm-map-panel-note is-empty" v-else>{{ $t('map.rail.no_islands') }}</p>
            <p class="mm-map-panel-note" v-if="orphanIds.length">
              {{ $t('map.rail.alone', orphanIds.length) }}
            </p>
          </section>
        </div>
      </aside>
    </div>
  </div>
</template>
