<script setup lang="ts">
// The desktop workspace shell: a sidebar carrying the destinations, the
// workspaces and the account control, and a canvas beside it under a header
// holding Docs, the theme and Settings.
//
// Below `lg` the sidebar is a sheet rather than a rail — 232px of chrome
// against a 390px screen leaves 158px for the page — so it slides in from a
// top bar and the shell is one column.
import { computed, onMounted, onUnmounted, ref, shallowRef, watch, type Component } from 'vue'
import { START_LOCATION, useRoute, useRouter } from 'vue-router'
import Toast from 'primevue/toast'
import AgentMark from '@/components/AgentMark.vue'
import BrandMark from '@/components/BrandMark.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import PresetDialog from '@/components/PresetDialog.vue'
import { toastError } from '@/components/toastService'
import { useAuthStore } from '@/stores/auth'
import { useInboxStore } from '@/stores/inbox'
import { useLayoutStore } from '@/stores/layout'
import PaneToggle from '@/components/PaneToggle.vue'
import ShellTools from '@/components/ShellTools.vue'
import { useWelcomeStore } from '@/stores/welcome'
import { presetQuery, routeCriteria, sameCriteria, usePresetStore } from '@/stores/presets'
import type { SearchPreset } from '@/api/client'
import { useI18n } from 'vue-i18n'
import { editions } from '@/editions'

declare global {
  interface Window {
    setPreferredTheme?: (theme: 'light' | 'dark') => void
    // Reading preferences, applied on <html> before the app mounts. See
    // public/theme-boot.js: note text size, and the typeface of rendered markdown.
    getAppearance?: (which: 'text' | 'font') => string
    setAppearance?: (which: 'text' | 'font', value: string) => void
    // The three colours are stored per theme, so every one of these takes the
    // theme it applies to rather than the one currently showing.
    getResolvedTheme?: () => 'light' | 'dark'
    getThemed?: (which: 'accent' | 'link' | 'bodyText', theme?: 'light' | 'dark') => string
    setThemed?: (which: 'accent' | 'link' | 'bodyText', theme: 'light' | 'dark', value: string) => boolean
    resetThemed?: () => void
    themePalette?: (theme: 'light' | 'dark') => Record<string, string>
    inkOn?: (hex: string) => string | null
    hoverOn?: (hex: string, theme: 'light' | 'dark') => string | null
    accentContrast?: (hex: string, theme?: 'light' | 'dark') => { page: number; ink: number } | null
    adoptAppearance?: (prefs: unknown) => void
    bootstrap: {
      Modal: {
        new (el: Element, options?: { backdrop?: boolean | 'static'; keyboard?: boolean }): {
          show(): void
          hide(): void
          dispose(): void
        }
        getInstance(el: Element): { hide(): void } | null
      }
      Dropdown: { getInstance(el: Element): { hide(): void } | null }
      Tooltip: new (el: Element) => unknown
      Collapse: { getInstance(el: Element): { hide(): void } | null }
    }
  }
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const showChrome = computed(() => route.name !== 'login' && auth.user !== null)

// Settings is a dialog rather than a destination, so the stage keeps showing a
// page while it is open. Only the routes that read nothing from the URL can
// stand in for one: with Settings matched, `useRoute()` hands them its params,
// and these answer that with their own defaults instead of a wrong fetch.
const SETTINGS_BACKDROP_ROUTES = ['search', 'inbox', 'activity']
// The first-run wizard is the second dialog rendered this way: a new account
// is sent to it before it has seen any page, so the backdrop is the notes list.
const isSettings = computed(() => route.name === 'settings' || route.name === 'welcome')
// Empty until a page worth drawing has been visited. NOT resolved here: the
// notes list is inside the reader's own knowledge base, and at setup there is
// no session yet to say which — resolving would throw for a missing handle.
// The literal '/notes' it held before matched no route at all, so a hard load
// of Settings drew the not-found page behind it.
const backdropPath = ref('')
const backdropView = shallowRef<Component | null>(null)

watch(
  () => route.fullPath,
  () => {
    if (SETTINGS_BACKDROP_ROUTES.includes(String(route.name))) backdropPath.value = route.fullPath
  },
  { immediate: true },
)

// The record still holds the import function until the router has visited that
// route once, and RouterView is not in play here to resolve it.
watch(
  isSettings,
  async (open) => {
    if (!open) return
    // The LAST matched record, not the first: every page is nested inside the
    // handle route, whose record is a grouping with no component of its own, so
    // matched[0] resolved to undefined and the backdrop went blank.
    // By now there is a session, so the notes list has an address to fall back
    // on: Settings is only reachable signed in.
    const path = backdropPath.value || router.resolve({ name: 'search' }).fullPath
    const matched = router.resolve(path).matched
    const held = matched[matched.length - 1]?.components?.default
    if (typeof held !== 'function') {
      backdropView.value = (held as Component) ?? null
      return
    }
    const loaded = (await (held as () => Promise<{ default: Component }>)())
    backdropView.value = loaded.default ?? (loaded as unknown as Component)
  },
  { immediate: true },
)

// Notes owns every note route, so reading or editing one still lights Notes
// rather than leaving the sidebar with nothing selected.
const NOTE_ROUTES = ['search', 'note', 'note-new', 'note-edit']
function isActive(name: string) {
  const current = String(route.name ?? '')
  return name === 'search' ? NOTE_ROUTES.includes(current) : current === name
}

/**
 * The pages a signed-out stranger meets, which are pinned to the landing
 * page's light palette whatever theme the browser has stored. The footer is
 * outside the routed view and would otherwise be the one dark strip under an
 * eggshell page.
 */
const isPublicPage = computed(() => route.meta.public === true)
const currentYear = new Date().getFullYear()
const footerLinks = editions.flatMap((edition) => edition.footerLinks ?? [])

// Header inbox badge. The count lives in a store rather than here because the
// screen that CHANGES it is a routed view that never navigates: deciding an
// item in the inbox left this number stale until a reload or the next poll
// (operator, 2026-08-23). See stores/inbox.ts.
const inbox = useInboxStore()
const layout = useLayoutStore()
const refreshInbox = () => {
  inbox.refresh()
}
// Route watch covers SPA navigation; the auth watch covers the initial full
// page load, where onMounted fires before the session user has resolved.
watch(() => route.fullPath, refreshInbox)
watch(() => auth.user, refreshInbox)
let inboxTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  refreshInbox()
  inboxTimer = setInterval(refreshInbox, 120_000)
})
onUnmounted(() => clearInterval(inboxTimer))

// The WORKSPACE is where the work happens; the ACCOUNT menu is the things you
// do to your memex rather than in it. The map is a view of Notes, not a place
// of its own (operator, 2026-09-26); the activity log is in the header.
const WORKSPACE_LINKS = [
  { name: 'search', labelKey: 'app.nav.notes', icon: 'fa-solid fa-file-lines' },
  { name: 'skills', labelKey: 'app.nav.skills', icon: 'fa-solid fa-graduation-cap' },
  { name: 'inbox', labelKey: 'app.nav.review_inbox', icon: 'fa-solid fa-inbox' },
] as const

// Saved filters, pinned under the destinations. Loaded once per sign-in; the
// notes page adds to the same store when a filter is saved there.
const presets = usePresetStore()
watch(() => auth.user, () => presets.load(), { immediate: true })

const presetMenu = ref<number | null>(null)
// Fixed rather than absolute: the list scrolls, and a menu positioned inside
// a scroller is clipped by it.
const presetMenuAt = ref({ top: 0, left: 0 })
const PRESET_MENU_WIDTH = 190
const PRESET_MENU_HEIGHT = 90
const openPreset = computed(() => presets.presets.find((preset) => preset.id === presetMenu.value) ?? null)
const editingPreset = ref<SearchPreset | null>(null)
const removingPreset = ref<SearchPreset | null>(null)
const removeBusy = ref(false)

// A preset whose tags have all left the vocabulary has nothing to match on,
// and an empty filter would otherwise light up on the unfiltered list.
/** A saved filter opens in whichever view of the notes is showing. */
function presetLink(preset: SearchPreset) {
  const query = presetQuery(preset)
  // An array once the notes page has written the address itself, a string when
  // it arrived that way.
  if (route.name === 'search' && [route.query.view].flat().includes('map')) query.view = ['map']
  return { name: 'search', query }
}

function isPresetActive(preset: SearchPreset) {
  const criteria = presetQuery(preset)
  if (Object.keys(criteria).length === 0) return false
  return route.name === 'search' && sameCriteria(routeCriteria(route.query), criteria)
}

function openPresetMenu(preset: SearchPreset, event: MouseEvent) {
  if (presetMenu.value === preset.id) {
    presetMenu.value = null
    return
  }
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  presetMenuAt.value = {
    top: Math.max(8, Math.min(rect.bottom + 4, window.innerHeight - PRESET_MENU_HEIGHT - 8)),
    left: Math.max(8, Math.min(rect.right - PRESET_MENU_WIDTH, window.innerWidth - PRESET_MENU_WIDTH - 8)),
  }
  presetMenu.value = preset.id
}

function editPreset(preset: SearchPreset) {
  presetMenu.value = null
  editingPreset.value = preset
}

function askRemovePreset(preset: SearchPreset) {
  presetMenu.value = null
  removingPreset.value = preset
}

async function removePreset() {
  const preset = removingPreset.value
  if (preset === null || removeBusy.value) return
  removeBusy.value = true
  try {
    await presets.remove(preset.id)
    removingPreset.value = null
  } catch (e) {
    toastError(t('presets.remove.failed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    removeBusy.value = false
  }
}

const CONNECT_LINK = { name: 'settings', params: { pane: 'connections' }, hash: '#connect' } as const

const ACCOUNT_LINKS = [
  { key: 'docs', to: { name: 'docs' }, labelKey: 'app.nav.docs', icon: 'fa-solid fa-book-open' },
  { key: 'personalization', to: { name: 'settings', params: { pane: 'personalization' } }, labelKey: 'app.nav.personalization', icon: 'fa-solid fa-user-pen' },
  { key: 'connect', to: CONNECT_LINK, labelKey: 'app.nav.connect', icon: 'fa-solid fa-plug' },
  { key: 'settings', to: { name: 'settings' }, labelKey: 'app.nav.settings', icon: 'fa-solid fa-gear' },
] as const

// Whether an assistant has ever reached this memex, read the way the wizard
// reads it: a token pasted nowhere is not a connection. Until one has, the
// sidebar offers to connect one above the account row. Connecting happens in
// another application, so the answer is read again when the tab comes back
// into view and when Settings closes.
const welcome = useWelcomeStore()
const refreshConnected = () => {
  if (auth.user !== null) void welcome.refresh()
}
const unconnected = computed(() => welcome.facts !== null && !welcome.facts.connected)
watch(() => auth.user?.team.handle, refreshConnected, { immediate: true })
watch(isSettings, (open, was) => {
  if (was && !open) refreshConnected()
})
const onVisible = () => {
  if (document.visibilityState === 'visible') refreshConnected()
}
onMounted(() => document.addEventListener('visibilitychange', onVisible))
onUnmounted(() => document.removeEventListener('visibilitychange', onVisible))

const menuOpen = ref(false)
const accountOpen = ref(false)

// Navigating closes the sheet and the account menu. Leaving either standing
// over the page it just navigated to was the state a tap used to land in.
// The first landing is not one: the shell is up before the page's chunk arrives.
watch(
  () => router.currentRoute.value,
  (to, from) => {
    if (from === START_LOCATION || to.fullPath === from.fullPath) return
    menuOpen.value = false
    accountOpen.value = false
    presetMenu.value = null
    // The stage is the scroll container now, so arriving at a route has to
    // put it back at the top — the window scroll a browser would reset is no
    // longer the one anybody is looking at. A change of hash alone is a place
    // on the same page, which the page scrolls to itself.
    const samePage = to.path === from.path && JSON.stringify(to.query) === JSON.stringify(from.query)
    if (!isSettings.value && !samePage) document.querySelector('.app-stage')?.scrollTo({ top: 0 })
  },
)

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  menuOpen.value = false
  accountOpen.value = false
  presetMenu.value = null
}
onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

// A click anywhere else closes the account menu. It is a details-shaped
// control without being a <details>, because the menu has to escape the
// sidebar's stacking context.
function onDocumentClick(event: MouseEvent) {
  const el = event.target as HTMLElement | null
  if (accountOpen.value && el?.closest('.app-account') === null) accountOpen.value = false
  if (presetMenu.value !== null && el?.closest('.app-preset-menu') === null) {
    const row = el?.closest<HTMLElement>('.app-preset')
    if (row?.dataset.presetId !== String(presetMenu.value)) presetMenu.value = null
  }
}
onMounted(() => document.addEventListener('click', onDocumentClick))
onUnmounted(() => document.removeEventListener('click', onDocumentClick))

// The account menu grows out of the account row: from the row's own height
// (its bottom padding, which is nothing on the collapsed rail) to what it
// holds, measured, because a CSS height cannot transition to `auto`.
const menuFloor = (menu: HTMLElement) => `${parseFloat(getComputedStyle(menu).paddingBottom) || 0}px`

function growMenu(el: Element) {
  const menu = el as HTMLElement
  const full = menu.offsetHeight
  menu.style.height = menuFloor(menu)
  void menu.offsetHeight
  menu.style.height = `${full}px`
}

function settleMenu(el: Element) {
  (el as HTMLElement).style.height = ''
}

function shrinkMenu(el: Element) {
  const menu = el as HTMLElement
  menu.style.height = `${menu.offsetHeight}px`
  void menu.offsetHeight
  menu.style.height = menuFloor(menu)
}

async function logout() {
  if (await auth.logout()) router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="showChrome" class="app-shell" :class="{ 'is-collapsed': layout.sidebarCollapsed, 'is-open': menuOpen }">
    <header class="app-topbar">
      <button type="button" class="btn btn-sm btn-outline-secondary" :aria-label="$t('app.shell.open_navigation')"
              :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
        <i class="fa-solid fa-bars"></i>
      </button>
      <router-link class="app-brand" :to="{ name: 'search' }">
        <span class="app-brand-word">{{ $t('app.brand.word') }}</span>
      </router-link>
      <ShellTools class="ms-auto" />
      <router-link class="position-relative btn btn-sm btn-outline-secondary" :to="{ name: 'inbox' }"
                   :aria-label="$t('app.nav.review_inbox')">
        <i class="fa-solid fa-inbox"></i>
        <span class="app-nav-badge" v-if="inbox.count > 0">{{ inbox.count > 99 ? '99+' : inbox.count }}</span>
      </router-link>
    </header>

    <div class="app-scrim" v-if="menuOpen" @click="menuOpen = false"></div>

    <aside class="app-sidebar" :aria-label="$t('app.shell.primary_navigation')" @scroll="presetMenu = null">
      <div class="app-sidebar-head">
        <router-link class="app-brand" :to="{ name: 'search' }" :aria-label="$t('app.shell.home')">
          <BrandMark class="app-brand-mark" />
          <span class="app-brand-word">{{ $t('app.brand.word') }}</span>
        </router-link>
        <PaneToggle pane="sidebar" />
      </div>

      <nav class="app-nav" :aria-label="$t('app.shell.workspace')">
        <router-link v-for="link in WORKSPACE_LINKS" :key="link.name" class="app-nav-link"
                     :class="{ 'is-active': isActive(link.name), 'has-count': link.name === 'inbox' && inbox.count > 0 }"
                     :aria-current="isActive(link.name) ? 'page' : undefined"
                     :to="{ name: link.name }">
          <span class="app-nav-icon">
            <i :class="link.icon"></i>
          </span>
          <span class="app-nav-label">{{ $t(link.labelKey) }}</span>
          <span class="app-nav-count" v-if="link.name === 'inbox' && inbox.count > 0">
            {{ inbox.count > 99 ? '99+' : inbox.count }}
          </span>
          <span class="app-nav-tooltip">{{ $t(link.labelKey) }}</span>
        </router-link>
      </nav>

      <section class="app-presets" :aria-label="$t('app.shell.workspaces')">
        <h2 class="app-presets-head">{{ $t('app.shell.workspaces') }}</h2>
        <p class="app-presets-hint" v-if="presets.loaded && !presets.presets.length">{{ $t('app.shell.workspaces_hint') }}</p>
        <div class="app-presets-list" v-else @scroll="presetMenu = null">
          <div v-for="preset in presets.presets" :key="preset.id" class="app-preset"
               :data-preset-id="preset.id" :class="{ 'is-menu-open': presetMenu === preset.id }">
            <router-link class="app-nav-link app-preset-link"
                         :class="{ 'is-active': isPresetActive(preset) }"
                         :aria-current="isPresetActive(preset) ? 'page' : undefined"
                         :to="presetLink(preset)">
              <span class="app-nav-icon"><i :class="preset.icon_class"></i></span>
              <span class="app-nav-label">{{ preset.name }}</span>
              <span class="app-nav-tooltip">{{ preset.name }}</span>
            </router-link>
            <button type="button" class="app-preset-more" aria-haspopup="menu"
                    :aria-expanded="presetMenu === preset.id"
                    :aria-label="$t('presets.options', { name: preset.name })"
                    @click.stop="openPresetMenu(preset, $event)">
              <i class="fa-solid fa-ellipsis"></i>
            </button>
          </div>
        </div>
      </section>

      <div class="app-sidebar-spacer"></div>

      <router-link v-if="unconnected" class="btn btn-primary app-connect" :to="CONNECT_LINK">
        <i class="fa-solid fa-plug" aria-hidden="true"></i>
        <span class="app-connect-label">{{ $t('app.nav.connect') }}</span>
        <span class="app-nav-tooltip">{{ $t('app.nav.connect') }}</span>
      </router-link>

      <div class="app-account" :class="{ 'is-open': accountOpen }">
        <button type="button" class="app-account-summary" :aria-expanded="accountOpen"
                aria-haspopup="menu" @click.stop="accountOpen = !accountOpen">
          <!-- Same mark and colour the notes list uses for "you", so the
               human actor is recognisable in both places. -->
          <span class="app-account-avatar">
            <AgentMark :icon="auth.user?.icon || 'fa-solid fa-circle-user'"
                       :icon-url="auth.user?.icon_url"
                       :icon-url-dark="auth.user?.icon_url_dark"
                       :initial="auth.user?.initial" round />
          </span>
          <span class="app-account-copy">
            <strong>{{ auth.user?.name || auth.user?.email }}</strong>
            <small>{{ auth.user?.email }}</small>
          </span>
          <i class="fa-solid fa-chevron-up app-account-chevron" aria-hidden="true"></i>
        </button>

        <Transition name="app-account-menu" @enter="growMenu" @after-enter="settleMenu" @leave="shrinkMenu">
          <div class="app-account-menu" v-if="accountOpen" role="menu">
            <div class="app-account-menu-body">
              <div class="app-account-identity">
                <span class="app-account-avatar">
                  <AgentMark :icon="auth.user?.icon || 'fa-solid fa-circle-user'"
                             :icon-url="auth.user?.icon_url"
                             :icon-url-dark="auth.user?.icon_url_dark"
                             :initial="auth.user?.initial" round />
                </span>
                <span class="app-account-copy">
                  <strong>{{ auth.user?.name || auth.user?.email }}</strong>
                  <small>{{ auth.user?.email }}</small>
                </span>
              </div>
              <router-link v-for="link in ACCOUNT_LINKS" :key="link.key" role="menuitem"
                           :class="{ 'is-active': route.name === link.key }"
                           :to="link.to">
                <i :class="link.icon" class="fa-fw"></i>{{ $t(link.labelKey) }}
              </router-link>
              <hr>
              <button type="button" role="menuitem" :disabled="auth.loggingOut" @click="logout">
                <i class="fa-solid fa-right-from-bracket fa-fw"></i>{{ $t('app.shell.sign_out') }}
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </aside>

    <div class="app-stage">
      <header class="app-stage-head container">
        <ShellTools />
      </header>
      <main class="flex-grow-1">
        <div v-if="auth.logoutError" class="app-notice app-notice-danger app-logout-error" role="alert">
          <span>{{ $t('app.shell.sign_out_failed') }} {{ auth.logoutError }}</span>
          <button type="button" class="btn btn-sm btn-secondary" :disabled="auth.loggingOut" @click="logout">{{ $t('common.retry') }}</button>
        </div>
        <component :is="backdropView" v-if="isSettings && backdropView !== null" />
        <RouterView />
      </main>

      <footer class="footer py-3">
        <div class="container d-flex flex-wrap justify-content-between gap-2">
          <span class="text-muted small">{{ $t('app.footer.copyright', { year: currentYear }) }}</span>
          <span class="text-muted small">
            <template v-for="(link, i) in footerLinks" :key="link.href">
              <span v-if="i > 0" class="mx-1">|</span>
              <a :href="link.href">{{ $t(link.labelKey) }}</a>
            </template>
          </span>
        </div>
      </footer>
    </div>
  </div>

  <div v-else class="flex-shrink-0">
    <main class="pb-4">
      <RouterView />
    </main>
  </div>

  <!-- On EVERY page, signed in or out (operator, 2026-08-23). The sign-in
       screens are where a stranger meets memex and are exactly where Terms and
       Privacy need to be reachable; they were the two pages that had no footer
       at all. The edition names the links (src/editions.ts): pages beside
       the SPA, not routes, so they are plain hrefs. -->
  <footer v-if="!showChrome" class="footer mt-auto py-3" :class="{ 'mm-public': isPublicPage }"
          :data-bs-theme="isPublicPage ? 'light' : undefined">
    <!-- Copyright left, the two legal pages right (operator, 2026-08-23).
         Wraps to one stacked column on a narrow screen rather than squeezing:
         `flex-wrap` with `gap` means the links drop under the notice instead
         of colliding with it. -->
    <div class="container d-flex flex-wrap justify-content-between gap-2">
      <span class="text-muted small">{{ $t('app.footer.copyright', { year: currentYear }) }}</span>
      <span class="text-muted small">
        <template v-for="(link, i) in footerLinks" :key="link.href">
          <span v-if="i > 0" class="mx-1">|</span>
          <a :href="link.href">{{ $t(link.labelKey) }}</a>
        </template>
      </span>
    </div>
  </footer>

  <!-- On <body>, because the phone sheet is transformed and a fixed element
       inside it would measure from the sheet rather than the viewport. -->
  <Teleport to="body">
    <div class="app-preset-menu" role="menu" v-if="openPreset !== null"
         :style="{ top: presetMenuAt.top + 'px', left: presetMenuAt.left + 'px' }">
      <button type="button" role="menuitem" @click="editPreset(openPreset)">
        <i class="fa-solid fa-pen fa-fw"></i>{{ $t('common.edit') }}
      </button>
      <button type="button" role="menuitem" @click="askRemovePreset(openPreset)">
        <i class="fa-regular fa-trash-can fa-fw"></i>{{ $t('common.remove') }}
      </button>
    </div>
  </Teleport>

  <PresetDialog v-if="editingPreset !== null" :preset="editingPreset" @close="editingPreset = null" />
  <ConfirmDialog v-if="removingPreset !== null" :title="$t('presets.remove.title')" danger :busy="removeBusy"
                 :confirm-label="$t('common.remove')" @confirm="removePreset" @close="removingPreset = null">
    {{ $t('presets.remove.body', { name: removingPreset.name }) }}
  </ConfirmDialog>

  <Toast />
</template>
