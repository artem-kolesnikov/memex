<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import type { Core, ElementDefinition, LayoutOptions, StylesheetJsonBlock } from 'cytoscape'
import { api, type NoteGraphData } from '@/api/client'
import { loadCytoscape } from '@/lib/cytoscapeGraph'
import { groupColour, type MapEmphasis } from '@/lib/mapRadar'
import { mixColour } from '@/lib/mapColour'
import HelpTip from '@/components/HelpTip.vue'

const props = withDefaults(
  defineProps<{
    mode?: 'note' | 'map'
    noteId?: number
    height?: string
    compact?: boolean
    /** A tap selects instead of navigating, and the caller says what to show. */
    selectable?: boolean
    selectedId?: number | null
    /** Ids that survive the caller's filter; everything else is drawn back. */
    matched?: number[] | null
    /** Ask the collection map for its "close in meaning" layer as well. */
    semantic?: boolean
    /** What the active view mode wants drawn bigger, alike, or in front. */
    emphasis?: MapEmphasis | null
    /** Settings → General → Map. Both are the account's, not this view's. */
    nodeShape?: 'dot' | 'square'
    linkStyle?: 'line' | 'curve' | 'arrow'
  }>(),
  {
    mode: 'note',
    height: '260px',
    compact: false,
    selectable: false,
    selectedId: null,
    matched: null,
    semantic: false,
    emphasis: null,
    nodeShape: 'dot',
    linkStyle: 'curve',
  },
)

const emit = defineEmits<{ select: [id: number | null]; loaded: [graph: NoteGraphData | null] }>()

const BASE_NODE_PX = 16
const SELECTED_MIN_PX = 24
/** Two taps on one node inside this are a double-tap, on a mouse or a finger. */
const DOUBLE_TAP_MS = 350

const router = useRouter()
const canvas = ref<HTMLElement | null>(null)
const data = ref<NoteGraphData | null>(null)
const loading = ref(true)
const failed = ref(false)
const depth = ref(1)
const hovered = ref<string>('')
const picked = ref<{ id: number; title: string } | null>(null)

let cy: Core | null = null
let themeWatcher: MutationObserver | null = null
let labelFrame = 0
let alive = true
// Every load and every draw carries the number of the request that started it;
// a slower earlier one finding a newer number has been overtaken and stops.
let generation = 0
// `loaded` is emitted before the graph is drawn, so a caller that focuses on
// arrival asks for a node the layout has not placed yet. Hold it for the draw.
let pendingFocus: number | null = null
let lastTap: { id: number; at: number } = { id: -1, at: -Infinity }

const FIT_MAX_ZOOM = 1.5

const neighbours = computed(() => {
  const centre = data.value?.center
  return (data.value?.nodes ?? []).filter((n) => n.id !== centre)
})

const isEmpty = computed(() => !loading.value && !failed.value && neighbours.value.length === 0)

function palette() {
  const s = getComputedStyle(document.documentElement)
  const v = (name: string, fallback: string) => s.getPropertyValue(name).trim() || fallback
  return {
    ink: v('--mm-ink', '#1f2933'),
    muted: v('--mm-muted', '#7b8794'),
    line: v('--mm-map-edge', '#cbd2d9'),
    node: v('--mm-map-node', '#9db0ea'),
    centre: v('--mm-primary', '#3a52a4'),
    defect: v('--mm-map-defect', '#b02a37'),
    flag: v('--mm-map-flag', '#ffc107'),
  }
}

function elements(graph: NoteGraphData): ElementDefinition[] {
  const present = new Set(graph.nodes.map((n) => n.id))
  const nodes: ElementDefinition[] = graph.nodes.map((n) => {
    const classes: string[] = []
    if (n.id === graph.center) classes.push('centre')
    if (n.flagged) classes.push('flagged')
    else if (n.defects.length > 0) classes.push('defect')
    if (n.status === 'pending') classes.push('pending')
    const keepLabel = n.id === graph.center ? true : undefined
    return {
      data: { id: String(n.id), label: n.title, title: n.title, keepLabel, size: BASE_NODE_PX },
      classes: classes.join(' '),
    }
  })
  const edges: ElementDefinition[] = graph.edges
    .filter((e) => present.has(e.from) && present.has(e.to))
    .map((e) => ({
      data: { id: `${e.kind}-${e.from}-${e.to}`, source: String(e.from), target: String(e.to) },
      classes: e.kind,
    }))

  return [...nodes, ...edges]
}

function stylesheet(): StylesheetJsonBlock[] {
  const c = palette()
  return [
    {
      selector: 'node',
      style: {
        'background-color': c.node,
        shape: props.nodeShape === 'square' ? 'round-rectangle' : 'ellipse',
        width: 'data(size)',
        height: 'data(size)',
        label: 'data(label)',
        'font-size': 9,
        color: c.ink,
        'text-valign': 'bottom',
        'text-margin-y': 4,
        'text-wrap': 'ellipsis',
        'text-max-width': '90px',
      },
    },
    // The centre is marked by a RING rather than a fill, so that a note which
    // needs work still says so while you are looking at it.
    {
      selector: 'node.centre',
      style: {
        width: 22,
        height: 22,
        'font-size': 11,
        'outline-width': 3,
        'outline-color': c.centre,
        'outline-offset': 3,
      },
    },
    { selector: 'node.defect', style: { 'background-color': c.defect } },
    { selector: 'node.flagged', style: { 'background-color': c.flag } },
    // Islands answer a question about SHAPE, so while that mode is on the
    // colour says which piece a note is in rather than what it needs.
    { selector: 'node.grouped[groupColour]', style: { 'background-color': 'data(groupColour)' } },
    // Hubs: the busiest notes go dark as well as large, so the ranking survives
    // a screenshot at a size where two dots differ by three pixels.
    { selector: 'node.shaded[shadeColour]', style: { 'background-color': 'data(shadeColour)' } },
    { selector: 'node.pending', style: { 'border-width': 2, 'border-style': 'dashed', 'border-color': c.muted, opacity: 0.65 } },
    { selector: 'node.nolabel', style: { label: '' } },
    // The collection map has no caption line to name the dot under the pointer,
    // so the dot names itself, over whatever the zoom level hides.
    {
      selector: 'node.hover',
      style: {
        'border-width': 3,
        'border-color': c.ink,
        'border-style': 'solid',
        ...(props.mode === 'map' ? { label: 'data(label)', 'z-index': 30 } : {}),
      },
    },
    // The selected note keeps its own colour and gains a ring and its name,
    // for the same reason the centre does: what a note needs is not something
    // looking at it should hide.
    {
      selector: 'node.selected',
      style: {
        label: 'data(label)',
        'font-size': 11,
        'font-weight': 700,
        'z-index': 20,
        'outline-width': 3,
        'outline-color': c.centre,
        'outline-offset': 3,
      },
    },
    // Drawn back rather than removed: a filter that deletes the rest of the
    // collection answers "where does this sit" with "nowhere".
    { selector: '.dim', style: { opacity: 0.1, 'text-opacity': 0, 'z-index': 0 } },
    {
      selector: 'edge',
      style: {
        width: 1.4,
        'line-color': c.line,
        'target-arrow-color': c.line,
        'target-arrow-shape': props.linkStyle === 'line' ? 'none' : 'triangle',
        'arrow-scale': props.linkStyle === 'arrow' ? 1 : 0.6,
        'curve-style': props.linkStyle === 'curve' ? 'bezier' : 'straight',
      },
    },
    { selector: 'edge.semantic', style: { 'line-style': 'dashed', 'line-color': c.muted, 'target-arrow-shape': 'none' } },
    // The selected note's own links, so its neighbourhood reads out of a
    // crowd without the reader having to trace a hairline.
    { selector: 'edge.incident', style: { width: 2.4, 'line-color': c.centre, 'target-arrow-color': c.centre, 'z-index': 10 } },
    // Blast radius: the link along which the change actually travelled.
    { selector: 'edge.hot', style: { width: 2.6, 'line-color': c.defect, 'target-arrow-color': c.defect, 'z-index': 12 } },
  ]
}

/**
 * Force-directed in both places, but the collection map packs its islands:
 * disconnected notes and small components flung to the edges cost the canvas
 * that the clusters need, and clusters are the finding there.
 */
function layoutFor(mode: 'note' | 'map'): LayoutOptions {
  return {
    name: 'fcose',
    animate: false,
    randomize: true,
    padding: 14,
    idealEdgeLength: () => 80,
    nodeRepulsion: () => 6000,
    ...(mode === 'map'
      ? { packComponents: true, tile: true, nodeSeparation: 60, tilingPaddingVertical: 26, tilingPaddingHorizontal: 72 }
      : {}),
  } as unknown as LayoutOptions
}

/** Repaints selection and the filter's dimming without redrawing the graph. */
function applyMarks() {
  if (cy === null) return
  const selected = props.selectedId
  const matched = props.matched
  const emphasis = props.emphasis
  cy.batch(() => {
    if (cy === null) return
    const c = palette()
    cy.elements().removeClass('dim selected incident grouped shaded hot')
    cy.nodes().forEach((node) => {
      const id = Number(node.id())
      const scale = emphasis?.scale?.[id] ?? 1
      const group = emphasis?.group?.[id]
      const shade = emphasis?.shade?.[id]
      node.data('size', id === selected ? Math.max(BASE_NODE_PX * scale, SELECTED_MIN_PX) : BASE_NODE_PX * scale)
      if (group !== undefined) {
        node.data('groupColour', groupColour(group))
        node.addClass('grouped')
      } else {
        node.removeData('groupColour')
      }
      if (shade !== undefined) {
        node.data('shadeColour', mixColour(c.node, c.centre, shade))
        node.addClass('shaded')
      } else {
        node.removeData('shadeColour')
      }
    })
    for (const key of emphasis?.hot ?? []) cy.getElementById(key).addClass('hot')
    if (matched !== null) {
      const keep = new Set(matched.map(String))
      cy.nodes().filter((n) => !keep.has(n.id())).addClass('dim')
      cy.edges().filter((e) => !keep.has(e.source().id()) || !keep.has(e.target().id())).addClass('dim')
      // A selected note is always inside the filter by then — the caller drops
      // the filter rather than let the canvas show a node it excludes — so
      // nothing here undims one.
    }
    if (selected !== null && selected !== undefined) {
      const node = cy.getElementById(String(selected))
      if (node.nonempty()) node.addClass('selected')
    }
  })
}

/** Bring a note into view and select it — how the rail's links navigate. */
function focus(id: number) {
  const node = cy?.getElementById(String(id))
  if (node === undefined || node.empty()) {
    // Queued only if this graph carries the note. Anything else would sit here
    // and fire against a later graph that happens to contain that id.
    if (data.value?.nodes.some((n) => n.id === id) === true) pendingFocus = id
    return
  }
  pendingFocus = null
  cy?.animate({ center: { eles: node }, zoom: Math.max(cy.zoom(), 1.2) }, { duration: 220 })
  emit('select', id)
}

defineExpose({ focus })

async function draw(mine: number) {
  const graph = data.value
  if (graph === null || canvas.value === null) return

  // The chunk may be cold, so this await can outlive the component.
  const cytoscape = await loadCytoscape()
  if (!alive || mine !== generation || canvas.value === null) return

  cy?.destroy()
  cy = cytoscape({
    container: canvas.value,
    elements: elements(graph),
    style: stylesheet(),
    // Positions are laid out fresh on every open rather than stored: at this
    // size the layout costs less than a round trip would, and a saved position
    // is a schema for a picture.
    layout: layoutFor(props.mode),
    minZoom: 0.2,
    maxZoom: 3,
  })

  cy.on('tap', 'node', (event) => {
    const id = Number(event.target.id())
    const title = String(event.target.data('title'))
    if (props.selectable) {
      // Selecting reads a note; a second tap on the same one brings the view to
      // it — and in neighbourhood mode re-roots what is drawn around it. Opening
      // stays the rail's button, so the map never navigates out from under a tap.
      const again = lastTap.id === id && event.timeStamp - lastTap.at < DOUBLE_TAP_MS
      lastTap = { id, at: event.timeStamp }
      if (again) focus(id)
      else emit('select', id)
      return
    }
    // A finger has no hover, and at this size the labels are off — so the first
    // tap NAMES the dot and the second opens it, rather than opening whatever
    // was under the thumb. Read from the event rather than from the device:
    // a laptop with a touchscreen is both, and which one is in use changes
    // between one tap and the next.
    const touch = (event.originalEvent as PointerEvent | undefined)?.pointerType === 'touch'
    if (touch && picked.value?.id !== id) {
      picked.value = { id, title }
      return
    }
    router.push({ name: 'note', params: { id } })
  })
  cy.on('tap', (event) => {
    if (props.selectable && event.target === cy) emit('select', null)
  })
  cy.on('mouseover', 'node', (event) => {
    hovered.value = String(event.target.data('title'))
    event.target.addClass('hover')
  })
  cy.on('mouseout', 'node', (event) => {
    hovered.value = ''
    event.target.removeClass('hover')
  })

  // Titles collide long before the nodes do — seventeen of them do not fit in a
  // sidebar rail at any font size — so the labels come back as you zoom in, and
  // hovering names one without zooming at all.
  const labelFloor = props.mode === 'map' ? 0.8 : 1.15
  const toggleLabels = () => {
    labelFrame = 0
    const hide = (cy?.zoom() ?? 1) < labelFloor
    cy?.batch(() => cy?.nodes('[!keepLabel]').toggleClass('nolabel', hide))
  }
  toggleLabels()
  cy.on('zoom', () => {
    if (labelFrame === 0) labelFrame = requestAnimationFrame(toggleLabels)
  })

  applyMarks()

  if (pendingFocus !== null) {
    const id = pendingFocus
    pendingFocus = null
    focus(id)
  }
}

async function load() {
  const mine = ++generation
  loading.value = true
  // Cleared per load, not per draw: the caller focuses on `loaded`, which is
  // emitted between the two.
  pendingFocus = null
  failed.value = false
  try {
    picked.value = null
    const graph =
      props.mode === 'map' || props.noteId === undefined
        ? await api.graphMap(props.semantic)
        : await api.noteGraph(props.noteId, depth.value)
    if (!alive || mine !== generation) return
    data.value = graph
    emit('loaded', graph)
    loading.value = false
    // The container is behind `v-if="!loading"`, so it does not exist until
    // Vue has patched the DOM with that flag cleared.
    await nextTick()
    await draw(mine)
  } catch {
    if (mine !== generation) return
    failed.value = true
    // The caller is describing a graph that is no longer on screen. Saying so
    // is the difference between an empty rail and a rail captioning a picture
    // the canvas has replaced with "the map could not be drawn".
    data.value = null
    emit('loaded', null)
  } finally {
    if (mine === generation) loading.value = false
  }
}

onMounted(() => {
  alive = true
  load()
  themeWatcher = new MutationObserver(() => cy?.style(stylesheet()))
  // The theme flips an attribute; the accent rewrites the inline custom
  // properties the palette is read from (public/theme-boot.js). Both repaint.
  themeWatcher.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme', 'style'] })
})

onBeforeUnmount(() => {
  alive = false
  ++generation
  if (labelFrame !== 0) cancelAnimationFrame(labelFrame)
  themeWatcher?.disconnect()
  cy?.destroy()
  cy = null
})

watch(() => props.noteId, load)
watch(depth, load)
watch(() => props.semantic, load)
watch([() => props.nodeShape, () => props.linkStyle], () => cy?.style(stylesheet()))
watch([() => props.selectedId, () => props.matched, () => props.emphasis], applyMarks)

// A filter that dims the rest but leaves the view where it was answers "which
// eight" with eight faint dots in a corner. Selection deliberately does not
// move the view — that is `focus`, which the caller asks for.
watch(
  () => props.matched,
  (ids) => {
    if (cy === null) return
    if (ids === null) {
      cy.animate({ fit: { eles: cy.elements(), padding: 24 } }, { duration: 220 })
      return
    }
    const wanted = new Set(ids.map(String))
    const kept = cy.nodes().filter((n) => wanted.has(n.id()))
    if (kept.empty()) return
    // Framed by hand rather than by `fit`, which happily zooms eight notes to
    // 3x and puts their titles through each other. FIT_MAX_ZOOM is about where
    // a 90px label still clears its neighbour.
    const box = kept.boundingBox()
    const pad = 60
    const level = Math.min(
      (cy.width() - pad * 2) / Math.max(box.w, 1),
      (cy.height() - pad * 2) / Math.max(box.h, 1),
      FIT_MAX_ZOOM,
    )
    cy.animate({ zoom: Math.max(level, cy.minZoom()), center: { eles: kept } }, { duration: 220 })
  },
)
</script>

<template>
  <div class="mm-map" :class="{ 'is-compact': compact }">
    <div v-if="loading" class="text-muted small">{{ $t('map.drawing') }}</div>
    <div v-else-if="failed" class="text-muted small">{{ $t('map.failed') }}</div>
    <template v-else>
      <div v-if="isEmpty && mode === 'map'" class="mm-map-blank">
        <p class="mb-0">{{ $t('map.blank.title') }}</p>
        <p class="mb-0">{{ $t('map.blank.body') }}</p>
        <router-link class="btn btn-sm btn-primary" :to="{ name: 'note-new' }">
          <i class="fa-solid fa-plus me-1"></i> {{ $t('map.blank.new_note') }}
        </router-link>
      </div>
      <p v-else-if="isEmpty" class="text-muted small mb-0">{{ $t('map.empty_note') }}</p>
      <template v-else>
        <div ref="canvas" class="mm-map-canvas" :style="height ? { height } : undefined" aria-hidden="true"></div>
        <ul class="visually-hidden">
          <li v-for="n in neighbours" :key="n.id">
            <router-link :to="{ name: 'note', params: { id: n.id } }">{{ n.title }}</router-link>
          </li>
        </ul>
        <div v-if="mode === 'note'" class="mm-map-caption small text-muted">
          <span class="mm-map-caption-text">
            <router-link v-if="picked" :to="{ name: 'note', params: { id: picked.id } }">
              {{ $t('map.caption.open', { title: picked.title }) }}
            </router-link>
            <template v-else-if="hovered">{{ hovered }}</template>
            <template v-else>
              {{ $t('map.caption.around', neighbours.length) }}
            </template>
          </span>
          <slot name="caption-action" />
        </div>
      </template>
      <div class="mm-map-controls" v-if="mode === 'note' && !compact && !isEmpty">
        <div class="btn-group btn-group-sm" role="group" :aria-label="$t('map.depth.label')">
          <button
            type="button"
            class="btn"
            :class="depth === 1 ? 'btn-primary' : 'btn-outline-secondary'"
            :aria-pressed="depth === 1"
            @click="depth = 1"
          >
            {{ $t('map.depth.hops', 1) }}
          </button>
          <button
            type="button"
            class="btn"
            :class="depth === 2 ? 'btn-primary' : 'btn-outline-secondary'"
            :aria-pressed="depth === 2"
            @click="depth = 2"
          >
            {{ $t('map.depth.hops', 2) }}
          </button>
        </div>
        <slot name="controls" />
        <HelpTip :label="$t('map.about.label')">
          <p>{{ $t('map.about.note_scope') }}</p>
          <p>{{ $t('map.about.colours') }}</p>
          <p class="mb-0">{{ $t('map.about.lines') }}</p>
        </HelpTip>
      </div>
    </template>
  </div>
</template>
