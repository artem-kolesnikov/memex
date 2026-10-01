<script setup lang="ts">
// Settings is a dialog over the workspace, not a page of its own: a fixed box
// with a flat menu of panes on the left and one scrolling pane on the right.
//
// The pane is still a route parameter so onboarding, and any explanation that
// needs to point at one of these, can link straight to it. App.vue renders the
// page underneath; this component teleports the box over it.
import { computed, nextTick, onBeforeUnmount, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { closeSettings } from '@/lib/closeSettings'
import { useI18n } from 'vue-i18n'
import AccountPane from '@/components/settings/AccountPane.vue'
import PersonalizationPane from '@/components/settings/PersonalizationPane.vue'
import GeneralPane from '@/components/settings/GeneralPane.vue'
import ContentPane from '@/components/settings/ContentPane.vue'
import ConnectionsPane from '@/components/settings/ConnectionsPane.vue'
import AutomationPane from '@/components/settings/AutomationPane.vue'

interface Pane {
  id: string
  labelKey: string
  group: 'personal' | 'memex'
}

const PANES: Pane[] = [
  { id: 'account', group: 'personal', labelKey: 'settings.panes.account.label' },
  { id: 'memex', group: 'personal', labelKey: 'settings.panes.general.label' },
  { id: 'personalization', group: 'personal', labelKey: 'settings.panes.personalization.label' },
  { id: 'content', group: 'memex', labelKey: 'settings.panes.content.label' },
  { id: 'connections', group: 'memex', labelKey: 'settings.panes.connections.label' },
  { id: 'automation', group: 'memex', labelKey: 'settings.panes.automation.label' },
]
const GROUPS = [
  { id: 'personal', labelKey: 'settings.groups.personal' },
  { id: 'memex', labelKey: 'settings.groups.memex' },
]

const COMPONENTS = {
  'account': AccountPane,
  'memex': GeneralPane,
  'personalization': PersonalizationPane,
  'content': ContentPane,
  'connections': ConnectionsPane,
  'automation': AutomationPane,
}
type PaneId = keyof typeof COMPONENTS

// Every identifier this pane has ever had, and where the thing it named
// actually went. A stale bookmark or an old link in a note lands on the right
// page rather than on the first one with no explanation.
//
// `general` resolves to ACCOUNT, not to the pane now labelled General. Social
// sign-in callbacks that were issued before 2026-08-28 return to
// /settings/general expecting account identity, and reusing the word would
// land somebody's finished Google link on a page about typefaces.
const MOVED: Record<string, PaneId> = {
  profile: 'account',
  you: 'account',
  general: 'account',
  security: 'account',
  data: 'account',
  'knowledge-base': 'content',
  appearance: 'memex',
  preferences: 'memex',
  import: 'content',
  tags: 'content',
  'banned-tags': 'content',
  deleted: 'content',
  mcp: 'connections',
  assistants: 'connections',
  advanced: 'automation',
  developer: 'automation',
  curation: 'connections',
}

const MOVED_ANCHOR: Record<string, string> = {
  advanced: 'enrichment',
  developer: 'enrichment',
  curation: 'curation',
  deleted: 'deleted',
  import: 'import',
  tags: 'tags',
  'banned-tags': 'blocked-tags',
}

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const groups = GROUPS.map((group) => ({ ...group, panes: PANES.filter((p) => p.group === group.id) }))

// An unknown pane in the URL lands on Account rather than on nothing: the
// route has no catch-all, and a blank screen is the worst answer to a typo.
const pane = computed<PaneId>(() => {
  const wanted = typeof route.params.pane === 'string' ? route.params.pane : ''
  if ((MOVED[wanted] ?? wanted) === 'automation' && route.hash === '#curation') return 'connections'
  if (PANES.some((p) => p.id === wanted)) return wanted as PaneId
  const moved = MOVED[wanted]
  if (moved !== undefined && PANES.some((p) => p.id === moved)) return moved

  return 'account'
})

const paneLabel = computed(() =>
  t(PANES.find((p) => p.id === pane.value)?.labelKey ?? 'settings.panes.account.label'),
)


// A historical identifier that named a section rather than a pane keeps its
// place: /settings/curation opens the assistant maintenance section. An explicit
// #hash in the URL wins, because somebody wrote it.
const anchor = computed(() => {
  const explicit = route.hash.replace('#', '')
  if (explicit !== '') return explicit
  const wanted = typeof route.params.pane === 'string' ? route.params.pane : ''
  return MOVED_ANCHOR[wanted] ?? ''
})

const paneScroll = ref<HTMLElement | null>(null)
let anchorFrame = 0

/**
 * Every pane fetches after it paints, so a single scroll to an anchor aims at
 * a pane that is still short: /deleted landed on Content with the block it
 * named 1300px below the fold. The scroll is repeated while the offset is
 * still moving — a ResizeObserver does not help, because the scroller has a
 * fixed height and only its CONTENT grows — and abandoned after a moment, so
 * a slow fetch cannot yank somebody who has started reading.
 */
function chase(id: string) {
  cancelAnimationFrame(anchorFrame)
  const pane = paneScroll.value
  if (pane === null) return
  // The clock starts when the target EXISTS, so a pane whose block arrives
  // late still gets scrolled to rather than being given up on unseen.
  let until = performance.now() + 1500
  let last = -1
  let mine = -1
  let found = false
  const step = () => {
    // Somebody who has started reading owns the scroll. Without this, a block
    // ABOVE the anchor finishing its fetch moved the offset and pulled the
    // pane back down under them.
    if (mine >= 0 && Math.abs(pane.scrollTop - mine) > 2) return
    const target = document.getElementById(id)
    if (target !== null && !found) {
      found = true
      until = performance.now() + 1500
    }
    // Measured against the scroller rather than read off `offsetTop`, whose
    // offsetParent is not guaranteed to be the pane.
    const offset =
      target === null
        ? -1
        : Math.round(pane.scrollTop + target.getBoundingClientRect().top - pane.getBoundingClientRect().top)
    if (offset >= 0 && offset !== last) {
      pane.scrollTop = offset
      last = offset
      mine = Math.round(pane.scrollTop)
    }
    if (performance.now() < until) anchorFrame = requestAnimationFrame(step)
  }
  step()
}

watch(
  [pane, anchor],
  async ([, wantedAnchor]) => {
    await nextTick()
    if (wantedAnchor !== '') {
      chase(wantedAnchor)
      return
    }
    cancelAnimationFrame(anchorFrame)
    // The pane scrolls rather than the window, so arriving at one has to put
    // the box back at the top itself — on the first render as well as a later
    // one, which is why `previous` is not consulted.
    if (paneScroll.value !== null) paneScroll.value.scrollTop = 0
  },
  { immediate: true },
)

const dialog = ref<HTMLElement | null>(null)
let modal: { show(): void; hide(): void } | null = null
// True once the route has already left Settings, so the hide that tidies the
// backdrop up does not try to navigate a second time.
let leaving = false

// Bootstrap's own `modal-open` on <body> is not a reliable flag here: a dialog
// a pane opens strips it again when IT closes, while this box is still up.
const OPEN_CLASS = 'app-settings-open'

onMounted(() => {
  document.body.classList.add(OPEN_CLASS)
  if (dialog.value === null) return
  // Static backdrop: a click outside does not dismiss the box (operator,
  // 2026-09-01). Escape and the close button do.
  //
  // `keyboard: false` because Bootstrap's own Escape closes whichever modal
  // holds focus, and focus stays on THIS box when a pane opens a dialog — so
  // dismissing "Change email" took the whole of Settings with it. Escape is
  // handled below instead, where the topmost dialog can be respected.
  modal = new window.bootstrap.Modal(dialog.value, { backdrop: 'static', keyboard: false })
  dialog.value.addEventListener('hidden.bs.modal', (event) => {
    if (event.target !== dialog.value) return
    if (!leaving) close()
  })
  modal.show()
})

// Deliberately un-animated: Bootstrap defers the teardown of an animated modal
// to `transitionend`, which never arrives when the element is unmounted by a
// route change mid-transition, and the backdrop is left over the app. The
// entrance is a CSS keyframe on the box instead.
onBeforeUnmount(() => {
  leaving = true
  modal?.hide()
  cancelAnimationFrame(anchorFrame)
  document.body.classList.remove(OPEN_CLASS)
})

// Bootstrap's scrollbar helper counts open dialogs, and a dialog this box
// opened can be disposed while it is still closing — which leaves the inline
// lock it put on <body> with nothing left to take it off. A page that cannot
// scroll is worse than the stranded backdrop the disposal is there to avoid.
//
// After a frame rather than in `onBeforeUnmount`: the dialogs this box opened
// are still in the document at that point, so the last-one-out test would see
// them and decline every time.
onUnmounted(() => {
  requestAnimationFrame(() => {
    if (document.querySelector('.modal.show') !== null) return
    document.body.style.removeProperty('overflow')
    document.body.style.removeProperty('padding-right')
  })
})

function close() {
  closeSettings(router)
}

function requestClose() {
  modal?.hide()
}

/**
 * Escape closes the topmost dialog, not whichever one holds focus.
 *
 * Focus stays on this box when a pane opens a dialog, so Bootstrap's own
 * handler answered for the wrong one — dismissing "Change email" closed the
 * whole of Settings. Both are driven from here instead.
 */
function onEscape(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  // The LAST match, not the first. `querySelector` answers in document order,
  // and a dialog teleports to <body> when it opens — so with a confirmation
  // over Deleted Notes over Settings, one Escape hid the confirmation through
  // Bootstrap's own handler and Deleted Notes through this one.
  const nested = document.querySelectorAll('.modal.show:not(.app-settings-modal)')
  const topmost = nested[nested.length - 1]
  if (topmost !== undefined) {
    window.bootstrap.Modal.getInstance(topmost)?.hide()
    return
  }
  requestClose()
}
onMounted(() => document.addEventListener('keydown', onEscape))
onBeforeUnmount(() => document.removeEventListener('keydown', onEscape))

// On a phone the same destinations are a select, not a stack (operator,
// 2026-08-23). A vertical menu on a 390px screen pushes the pane it is a menu
// FOR entirely below the fold, so every visit starts by scrolling past the
// navigation to reach the thing you came for.
function choosePane(event: Event) {
  router.replace({ name: 'settings', params: { pane: (event.target as HTMLSelectElement).value } })
}
</script>

<template>
  <Teleport to="body">
    <div class="modal app-settings-modal" tabindex="-1" ref="dialog"
         aria-labelledby="app-settings-title">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <header class="app-settings-head">
            <h1 id="app-settings-title">{{ $t('settings.title') }}</h1>
            <button type="button" class="btn-close" :aria-label="$t('settings.close')"
                    @click="requestClose"></button>
          </header>

          <div class="app-settings-layout">
            <select class="form-select app-settings-select" :value="pane" @change="choosePane"
                    :aria-label="$t('settings.section')">
              <optgroup v-for="group in groups" :key="group.id" :label="$t(group.labelKey)">
                <option v-for="p in group.panes" :key="p.id" :value="p.id">{{ $t(p.labelKey) }}</option>
              </optgroup>
            </select>

            <nav class="app-settings-nav" :aria-label="$t('settings.sections')">
              <div v-for="group in groups" :key="group.id" class="app-settings-nav-group">
                <h2 class="app-settings-group-title">{{ $t(group.labelKey) }}</h2>
              <router-link v-for="p in group.panes" :key="p.id" class="app-nav-link app-settings-nav-item" replace
                           :class="{ 'is-active': pane === p.id }"
                           :aria-current="pane === p.id ? 'page' : undefined"
                           :to="{ name: 'settings', params: { pane: p.id } }">
                <span>
                  <b>{{ $t(p.labelKey) }}</b>
                </span>
              </router-link>
              </div>
            </nav>

            <div class="app-settings-pane" ref="paneScroll">
              <div class="app-settings-content">
                <h2 class="app-settings-pane-title">{{ paneLabel }}</h2>
                <component :is="COMPONENTS[pane]" :key="pane" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
