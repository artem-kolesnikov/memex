<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, type GraphNode, type NoteGraphData } from '@/api/client'
import { loadForceGraph3d } from '@/lib/forceGraph3d'
import { edgeKey, groupColour, type MapEmphasis } from '@/lib/mapRadar'
import { mixColour, parseColour } from '@/lib/mapColour'
import HelpTip from '@/components/HelpTip.vue'

export type NodeShape = 'dot' | 'square'
export type LinkStyle = 'line' | 'curve' | 'arrow'
export type LabelField = 'title' | 'id' | 'none'

const props = withDefaults(
  defineProps<{
    mode?: 'note' | 'map'
    noteId?: number
    height?: string
    compact?: boolean
    selectable?: boolean
    selectedId?: number | null
    matched?: number[] | null
    semantic?: boolean
    /** What the active view mode wants drawn bigger, alike, or in front. */
    emphasis?: MapEmphasis | null
    nodeShape?: NodeShape
    linkStyle?: LinkStyle
    labelField?: LabelField
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
    labelField: 'title',
  },
)

const emit = defineEmits<{ select: [id: number | null]; loaded: [graph: NoteGraphData | null] }>()

type Vec = { x: number; y: number; z: number }
type Node3d = GraphNode & Partial<Vec> & { __label?: string }
type Link3d = { source: number | Node3d; target: number | Node3d; kind: 'link' | 'semantic' }

interface Graph3d {
  graphData(data: { nodes: Node3d[]; links: Link3d[] }): Graph3d
  backgroundColor(c: string): Graph3d
  width(w: number): Graph3d
  height(h: number): Graph3d
  nodeRelSize(n: number): Graph3d
  nodeVal(fn: (n: Node3d) => number): Graph3d
  nodeColor(fn: (n: Node3d) => string): Graph3d
  nodeLabel(fn: (n: Node3d) => string): Graph3d
  nodeThreeObject(fn: ((n: Node3d) => unknown) | null): Graph3d
  nodeResolution(n: number): Graph3d
  nodeThreeObjectExtend(v: boolean): Graph3d
  linkColor(fn: (l: Link3d) => string): Graph3d
  linkWidth(fn: (l: Link3d) => number): Graph3d
  linkCurvature(fn: (l: Link3d) => number): Graph3d
  linkOpacity(v: number): Graph3d
  linkDirectionalArrowLength(fn: (l: Link3d) => number): Graph3d
  linkDirectionalArrowColor(fn: (l: Link3d) => string): Graph3d
  linkDirectionalArrowRelPos(v: number): Graph3d
  enableNodeDrag(v: boolean): Graph3d
  controls(): object
  onNodeClick(fn: (n: Node3d) => void): Graph3d
  onBackgroundClick(fn: () => void): Graph3d
  onEngineStop(fn: () => void): Graph3d
  cameraPosition(pos?: Partial<Vec>, lookAt?: Vec, ms?: number): Vec & Graph3d
  zoomToFit(ms?: number, padding?: number): Graph3d
  graphData(): { nodes: Node3d[]; links: Link3d[] }
  d3Force(name: string): { strength(v: number): unknown; distanceMax(v: number): unknown; distance(v: number): unknown } | undefined
  cooldownTime(ms: number): Graph3d
  showNavInfo(v: boolean): Graph3d
  refresh(): Graph3d
  pauseAnimation(): Graph3d
  _destructor(): void
}

const router = useRouter()
const canvas = ref<HTMLElement | null>(null)
const data = ref<NoteGraphData | null>(null)
const loading = ref(true)
const failed = ref(false)
const depth = ref(1)

let graph: Graph3d | null = null
let themeWatcher: MutationObserver | null = null
let sizeWatcher: ResizeObserver | null = null
let alive = true
let generation = 0
let fitted = false
let releaseGuard: (() => void) | null = null
const REFIT_QUIET_MS = 160
let refitTimer: ReturnType<typeof setTimeout> | undefined
// Set by the first pointer or wheel on the canvas. Framing the view is a
// courtesy for a reader who has not looked anywhere yet; once they have, moving
// the camera under them is not.
let steered = false
// `loaded` is emitted before the graph is drawn, so a caller that focuses on
// arrival asks for a node that has no position yet. It is aimed at as soon as
// there is a position, then aimed at again once the layout settles, because
// until then the node it names is still moving.
let focusTarget: number | null = null
const labelled = new Map<number, HTMLElement>()

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
    bg: v('--mm-bg', '#f7f9fb'),
  }
}

function dimmed(n: Node3d): boolean {
  return props.matched !== null && !props.matched.includes(n.id)
}

function endpoint(end: number | Node3d): number {
  return typeof end === 'object' ? end.id : end
}

/** A link is only as present as its fainter end. */
function dimmedLink(l: Link3d): boolean {
  const ids = props.matched
  if (ids === null) return false

  return !ids.includes(endpoint(l.source)) || !ids.includes(endpoint(l.target))
}

/**
 * A radius multiplier as a `nodeVal`. 3d-force-graph draws a sphere of radius
 * cbrt(val) × nodeRelSize, so a scale has to be cubed on the way in or the map
 * flattens every difference the mode is drawing.
 */
const BASE_VAL = 2
const SELECTED_VAL = 9

function sizeOf(n: Node3d): number {
  const scale = props.emphasis?.scale?.[n.id]
  const picked = n.id === data.value?.center || n.id === props.selectedId
  if (scale === undefined) return picked ? SELECTED_VAL : BASE_VAL
  const val = BASE_VAL * scale ** 3

  return picked ? Math.max(val, SELECTED_VAL) : val
}

/**
 * Blast radius: the link along which the change actually travelled.
 *
 * A set rather than the list itself. Two accessors ask this of every link on
 * every repaint, so scanning the list is one comparison per link per key — at
 * the node cap that is billions of them for a picture (Codex, 2026-09-11).
 */
const hotKeys = computed(() => new Set(props.emphasis?.hot ?? []))

function hotLink(l: Link3d): boolean {
  if (l.kind !== 'link' || hotKeys.value.size === 0) return false

  return hotKeys.value.has(edgeKey(endpoint(l.source), endpoint(l.target)))
}

function colourOf(n: Node3d): string {
  const c = palette()
  if (dimmed(n)) return withAlpha(c.muted, 0.07)
  const group = props.emphasis?.group?.[n.id]
  const shade = props.emphasis?.shade?.[n.id]
  const own =
    group !== undefined
      ? groupColour(group)
      : shade !== undefined
        ? mixColour(c.node, c.centre, shade)
        : n.flagged
          ? c.flag
          : n.defects.length > 0
            ? c.defect
            : c.node
  // The note being read keeps whatever its own colour says about it — one that
  // needs work still says so while you are looking at it — and goes darker and
  // bigger instead, the way the 2D map rings the centre rather than filling it.
  const marked = n.id === props.selectedId || n.id === data.value?.center ? darken(own, 0.3) : own

  // A sphere has no outline to dash, so a note still waiting for its owner goes
  // translucent instead. It had no mark here at all until the colour key
  // claimed one (Codex, 2026-09-11).
  return n.status === 'pending' ? withAlpha(marked, 0.45) : marked
}

function darken(colour: string, amount: number): string {
  const parsed = parseColour(colour)

  return parsed === null
    ? colour
    : `rgb(${parsed.map((v) => Math.round(v * (1 - amount))).join(', ')})`
}

// three parses rgba(), so a per-node alpha is how a dimmed dot stays visible
// without the global nodeOpacity taking the whole graph with it.
function withAlpha(colour: string, alpha: number): string {
  const parsed = parseColour(colour)

  return parsed === null ? colour : `rgba(${parsed.join(', ')}, ${alpha})`
}

function labelText(n: Node3d): string {
  if (props.labelField === 'none') return ''
  if (props.labelField === 'id') return `#${n.id}`
  return n.title
}

function labelFor(n: Node3d, CSS2DObject: new (el: HTMLElement) => unknown): unknown {
  // A rebuilt node gets a fresh element; the one it replaces can outlive its
  // CSS2DObject in the DOM, and an orphan is a title stuck on screen.
  labelled.get(n.id)?.remove()

  const el = document.createElement('div')
  el.className = 'mm-map3d-node'
  el.textContent = labelText(n)
  labelled.set(n.id, el)
  mark(n.id, el)

  return new CSS2DObject(el)
}

/**
 * Which notes are named. Distance was tried and rejected: titles popping in and
 * out as the camera drifts reads as noise, and at any distance that shows more
 * than a handful they overlap anyway. So a title appears when its note is the
 * one being read or is attached to it, and the rest of the collection stays dots.
 */
const named = computed(() => {
  const centre = props.selectedId ?? data.value?.center ?? null
  if (centre === null) return new Set<number>()
  const set = new Set<number>([centre])
  for (const edge of data.value?.edges ?? []) {
    if (edge.from === centre) set.add(edge.to)
    else if (edge.to === centre) set.add(edge.from)
  }

  return set
})

// Labels are created hidden and named here rather than only in showLabels():
// the node objects are built after graphData() returns, so a pass that ran
// before them would leave every title showing.
function mark(id: number, el: HTMLElement) {
  const shown = named.value.has(id) && props.labelField !== 'none'
  el.classList.toggle('is-named', shown && (props.matched === null || props.matched.includes(id)))
  el.classList.toggle('is-selected', id === props.selectedId || id === data.value?.center)
}

function showLabels() {
  for (const [id, el] of labelled) mark(id, el)
}

/** Two clicks on one node inside this are a double-click. */
const DOUBLE_CLICK_MS = 350
let lastClick: { id: number; at: number } = { id: -1, at: -Infinity }

function choose(n: Node3d) {
  if (props.selectable) {
    // The 2D map's rule, and for the same reason: a second click brings the
    // camera to the note and re-roots the neighbourhood mode around it, while
    // opening stays the rail's button.
    const now = performance.now()
    const again = lastClick.id === n.id && now - lastClick.at < DOUBLE_CLICK_MS
    lastClick = { id: n.id, at: now }
    if (again) focus(n.id)
    else emit('select', n.id)
    return
  }
  router.push({ name: 'note', params: { id: n.id } })
}

function elements(source: NoteGraphData): { nodes: Node3d[]; links: Link3d[] } {
  const present = new Set(source.nodes.map((n) => n.id))
  return {
    nodes: source.nodes.map((n) => ({ ...n })),
    links: source.edges
      .filter((e) => present.has(e.from) && present.has(e.to))
      .map((e) => ({ source: e.from, target: e.to, kind: e.kind })),
  }
}

async function draw(mine: number) {
  const source = data.value
  if (source === null || canvas.value === null) return

  const { ForceGraph3D, CSS2DRenderer, CSS2DObject } = await loadForceGraph3d()
  if (!alive || mine !== generation || canvas.value === null) return

  graph?._destructor()
  canvas.value.replaceChildren()
  fitted = false
  steered = false
  labelled.clear()

  const labels = new CSS2DRenderer()
  labels.domElement.style.position = 'absolute'
  labels.domElement.style.top = '0'
  labels.domElement.style.pointerEvents = 'none'

  const c = palette()
  graph = (ForceGraph3D as unknown as (o: object) => (el: HTMLElement) => Graph3d)({
    extraRenderers: [labels],
    controlType: 'orbit',
  })(canvas.value)

  graph
    .backgroundColor(c.bg)
    .width(canvas.value.clientWidth)
    .height(canvas.value.clientHeight)
    .showNavInfo(false)
    .nodeRelSize(5)
    // A sphere at resolution 4 is an octahedron seen face-on, which is as close
    // to a cube as a graph of spheres gets without a geometry of its own.
    .nodeResolution(props.nodeShape === 'square' ? 4 : 12)
    .nodeVal(sizeOf)
    .nodeColor(colourOf)
    .nodeLabel((n) => n.title)
    .onNodeClick(choose)
    .onBackgroundClick(() => props.selectable && emit('select', null))
    // The default cooldown is fifteen seconds, which is fifteen seconds before
    // the view is framed at all.
    .cooldownTime(4000)
    .onEngineStop(() => {
      if (fitted) return
      fitted = true
      const waiting = focusTarget
      focusTarget = null
      if (steered) return
      if (waiting === null || findNode(waiting) === null) graph?.zoomToFit(400, 60)
      else focus(waiting)
    })

  // Charge with an unbounded reach throws every unlinked note and every small
  // island to the far edge, and the clusters — the finding here — end up a
  // speck in the middle. Capping the reach keeps repulsion local.
  const charge = graph.d3Force('charge')
  charge?.strength(-70)
  charge?.distanceMax(180)
  graph.d3Force('link')?.distance(38)

  // Set ONCE. Reassigning nodeThreeObject rebuilds every node, and the CSS2D
  // objects it discards stay in the scene — the renderer re-attaches their
  // elements on the next frame, so each rebuild left another 119 labels behind.
  guardOrbitControls()
  graph.nodeThreeObjectExtend(true).nodeThreeObject((n) => labelFor(n, CSS2DObject))
  applyLinks()
  graph.graphData(elements(source))
  showLabels()
}

/**
 * Lets a node be dragged without killing the orbit.
 *
 * At dragend 3d-force-graph dispatches a synthetic `pointerup` carrying no
 * pointerId. OrbitControls drops pointer 0, finds the real pointer still on its
 * list, and reads a position for it out of `_pointerPositions` — which it only
 * ever fills for TOUCH pointers, so with a mouse the read throws. The pointer is
 * then never released and the controls believe a button is held down for good.
 * Recording a position for every pointer gives that branch something to read.
 */
function guardOrbitControls() {
  releaseGuard?.()
  releaseGuard = null

  const controls = graph?.controls() as { _trackPointer?: (e: PointerEvent) => void } | undefined
  const track = controls?._trackPointer
  const el = canvas.value
  if (track === undefined || el === null) return

  const onPointerDown = (event: PointerEvent) => {
    steered = true
    track.call(controls, event)
  }
  const onWheel = () => {
    steered = true
  }
  el.addEventListener('pointerdown', onPointerDown, { capture: true })
  el.addEventListener('wheel', onWheel, { capture: true, passive: true })
  // The controls are rebuilt with the graph, so the guard is rebound on every
  // draw and has to take the previous pair off with it.
  releaseGuard = () => {
    el.removeEventListener('pointerdown', onPointerDown, { capture: true })
    el.removeEventListener('wheel', onWheel, { capture: true })
  }
}

function applyLinks() {
  const c = palette()
  graph
    ?.linkColor((l) => {
      if (dimmedLink(l)) return withAlpha(c.muted, 0.08)
      if (hotLink(l)) return c.defect

      return l.kind === 'semantic' ? withAlpha(c.muted, 0.45) : c.line
    })
    .linkWidth((l) => (hotLink(l) ? 2.4 : l.kind === 'semantic' ? 0.4 : 1))
    .linkOpacity(0.5)
    .linkCurvature(() => (props.linkStyle === 'curve' ? 0.25 : 0))
    .linkDirectionalArrowLength((l) =>
      props.linkStyle === 'arrow' && l.kind === 'link' && !dimmedLink(l) ? 4 : 0,
    )
    // Arrowheads are opaque where the lines are not, so left at the link colour
    // they read as solid white cones. And at relPos 1 the head sits inside the
    // node it points at, which is where it is least visible.
    .linkDirectionalArrowColor(() => darken(c.line, 0.25))
    .linkDirectionalArrowRelPos(0.92)
}

/** Selection, the filter and the label field repaint in place — nothing rebuilds. */
function applyMarks() {
  if (graph === null) return
  applyLinks()
  graph.nodeColor(colourOf).nodeVal(sizeOf)
  for (const node of graph.graphData().nodes) {
    const el = labelled.get(node.id)
    if (el !== undefined) el.textContent = labelText(node)
  }
  showLabels()
}

function focus(id: number) {
  const node = findNode(id)
  if (node === null || graph === null) {
    // Queue it only if it is a note this graph carries; anything else would
    // suppress the fit forever in favour of a node that never arrives.
    if (data.value?.nodes.some((n) => n.id === id) === true) focusTarget = id
    return
  }
  focusTarget = null
  const { x = 0, y = 0, z = 0 } = node
  const distance = 170
  const ratio = 1 + distance / Math.hypot(x, y, z || 1)
  graph.cameraPosition({ x: x * ratio, y: y * ratio, z: z * ratio }, { x, y, z }, 500)
  emit('select', id)
}

function findNode(id: number): Node3d | null {
  const current = (graph as unknown as { graphData(): { nodes: Node3d[] } } | null)?.graphData()
  return current?.nodes.find((n) => n.id === id) ?? null
}

defineExpose({ focus })

async function load() {
  const mine = ++generation
  loading.value = true
  failed.value = false
  // Cleared here rather than in draw(): the caller that asks for a note focuses
  // it on `loaded`, which is emitted between the two, so a reset in draw would
  // throw away the request that arrival just made.
  focusTarget = null
  try {
    const result =
      props.mode === 'map' || props.noteId === undefined
        ? await api.graphMap(props.semantic)
        : await api.noteGraph(props.noteId, depth.value)
    if (!alive || mine !== generation) return
    data.value = result
    emit('loaded', result)
    loading.value = false
    await nextTick()
    await draw(mine)
  } catch {
    if (mine !== generation) return
    failed.value = true
    data.value = null
    emit('loaded', null)
  } finally {
    if (mine === generation) loading.value = false
  }
}

onMounted(() => {
  alive = true
  load()
  themeWatcher = new MutationObserver(() => {
    graph?.backgroundColor(palette().bg)
    applyLinks()
    applyMarks()
  })
  themeWatcher.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme', 'style'] })
  sizeWatcher = new ResizeObserver(() => {
    if (canvas.value === null || graph === null) return
    graph.width(canvas.value.clientWidth).height(canvas.value.clientHeight)
    // Setting either dimension re-runs the update cycle and the layout expands
    // from wherever it had settled, while the camera stays where it was — so
    // the map fills with half a dozen enormous spheres. The frame is asked for
    // directly rather than by clearing `fitted` and trusting the engine to stop
    // again, which is a promise the library does not make (Codex, 2026-09-11).
    // Debounced because a window drag fires this per frame, and `steered` still
    // protects a reader who has moved the camera themselves.
    if (steered) return
    clearTimeout(refitTimer)
    refitTimer = setTimeout(() => {
      if (!steered) graph?.zoomToFit(400, 60)
    }, REFIT_QUIET_MS)
  })
})

watch(canvas, (el) => {
  sizeWatcher?.disconnect()
  if (el !== null) sizeWatcher?.observe(el)
})

onBeforeUnmount(() => {
  alive = false
  ++generation
  clearTimeout(refitTimer)
  themeWatcher?.disconnect()
  sizeWatcher?.disconnect()
  releaseGuard?.()
  releaseGuard = null
  for (const el of labelled.values()) el.remove()
  labelled.clear()
  graph?.pauseAnimation()
  graph?._destructor()
  graph = null
})

watch(() => props.noteId, load)
watch(depth, load)
watch(() => props.semantic, load)
watch(() => props.linkStyle, applyLinks)
// The shape is baked into the geometry, so it is the one control that has to
// rebuild the scene — from the graph already in hand, not by fetching it again.
watch(() => props.nodeShape, () => {
  if (data.value !== null) draw(++generation)
})
watch(() => props.labelField, applyMarks)
watch([() => props.selectedId, () => props.matched, () => props.emphasis], applyMarks)
</script>

<template>
  <div class="mm-map mm-map3d" :class="{ 'is-compact': compact }">
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
        <div ref="canvas" class="mm-map-canvas mm-map3d-canvas" :style="height ? { height } : undefined" aria-hidden="true"></div>
        <ul class="visually-hidden">
          <li v-for="n in neighbours" :key="n.id">
            <router-link :to="{ name: 'note', params: { id: n.id } }">{{ n.title }}</router-link>
          </li>
        </ul>
        <!-- The 3D map names a node on hover in its own tooltip, so the caption
             here only ever counts, and only on a note's own map. -->
        <div v-if="mode === 'note'" class="mm-map-caption small text-muted">
          <span class="mm-map-caption-text">{{ $t('map.caption.around', neighbours.length) }}</span>
          <slot name="caption-action" />
        </div>
      </template>
      <div class="mm-map-controls" v-if="mode === 'note' && !compact && !isEmpty">
        <div class="btn-group btn-group-sm" role="group" :aria-label="$t('map.depth.label')">
          <button
            v-for="hop in [1, 2]"
            :key="hop"
            type="button"
            class="btn"
            :class="depth === hop ? 'btn-primary' : 'btn-outline-secondary'"
            :aria-pressed="depth === hop"
            @click="depth = hop"
          >
            {{ $t('map.depth.hops', hop) }}
          </button>
        </div>
        <slot name="controls" />
        <HelpTip :label="$t('map.about.label')">
          <p>{{ $t('map.about.note_scope') }}</p>
          <p>{{ $t('map.about.colours') }}</p>
          <p class="mb-0">{{ $t('map.about.lines_3d') }}</p>
        </HelpTip>
      </div>
    </template>
  </div>
</template>
