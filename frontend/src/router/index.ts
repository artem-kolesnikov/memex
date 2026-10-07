import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

// Every page of the app lives inside the knowledge base it belongs to. There
// is no handle-less spelling of any of them: note numbers restart at 1 per
// team, so `/notes/5` named a different note for every reader, and an address
// that means something different depending on who opens it is not an address.
// Applying that to `/inbox` too, where nothing is ambiguous, is what makes it
// a rule somebody can hold rather than a special case they have to remember.
const spaceRoutes: RouteRecordRaw[] = [
  // The knowledge base's own address. Carries the handle from the URL rather
  // than letting `resolve` fill in the reader's, so the guard below is the one
  // place that decides what a handle which is not theirs means.
  { path: '', redirect: (to) => ({ name: 'search', params: { handle: to.params.handle } }) },
  { path: 'notes', name: 'search', component: () => import('@/views/SearchView.vue') },
  { path: 'notes/new', name: 'note-new', component: () => import('@/views/NoteEditView.vue') },
  { path: 'notes/:id(\\d+)', name: 'note', component: () => import('@/views/NoteView.vue') },
  { path: 'notes/:id(\\d+)/edit', name: 'note-edit', component: () => import('@/views/NoteEditView.vue') },
  { path: 'inbox', name: 'inbox', component: () => import('@/views/InboxView.vue') },
  // Two views of the same history, chosen with ?view=: the journal ledger and
  // the per-pass curation digest. They were two routes until 2026-08-31.
  { path: 'activity', name: 'activity', component: () => import('@/views/ActivityView.vue') },
  // The notes tagged `skill`, which every connected assistant loads. A page of
  // its own since 2026-09-10: a skill is writing the owner does, not a setting,
  // and inside Settings › Connections nothing could link to it.
  { path: 'skills', name: 'skills', component: () => import('@/views/SkillsView.vue') },
  // The guide assistants load as the `memex-guide` skill, as a page to read.
  { path: 'docs', name: 'docs', component: () => import('@/views/DocsView.vue') },
  // The first-run wizard. Inside the handle like every signed-in page, and
  // outside the app's chrome: it is the screen somebody sees before they have
  // anything the nav points at.
  { path: 'welcome', name: 'welcome', component: () => import('@/views/WelcomeView.vue') },
  // The pane is optional so every existing { name: 'settings' } link still
  // resolves; SettingsView falls back to the first pane for an unknown one.
  { path: 'settings/:pane?', name: 'settings', component: () => import('@/views/SettingsView.vue') },
  // A page that does not exist inside a knowledge base that does. It says so
  // and stops there. Sending the reader to their list instead would be a guess
  // at what they meant, and a URL nobody can hold — the WRONG space is
  // recoverable, because there is only one they can be in; a wrong page is not.
  { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  // Vue Router matches case-insensitively by default; the vhost's spa-routes
  // allowlist is a case-sensitive `location ~`. So /NOTES opened Notes in the
  // dev server and in in-app navigation, and 404'd on a fresh production load
  // — and check-spa-routes.mjs compares literal names, so it called that
  // agreement. Matching nginx here is the direction that keeps the allowlist
  // strict.
  sensitive: true,
  // And a trailing slash is a different path, because to nginx it already is.
  // The generated allowlist serves `/login` and not `/login/`, while the router
  // accepted both — so `/login/` worked in the dev server and in in-app
  // navigation and 404'd on a fresh load, which is the exact class
  // check-spa-routes.mjs exists to catch and could not see (Codex, second
  // round).
  strict: true,
  routes: [
    // The pages somebody reaches when they have no session, and so no knowledge
    // base to be inside. These, and an edition's own (src/editions.ts), are the
    // only handle-less routes in the app.
    { path: '/login', name: 'login', meta: { public: true }, component: () => import('@/views/LoginView.vue') },
    // The team handle's shape, as App\Entity\Team generates it: twelve characters
    // of an alphabet with the ambiguous glyphs left out. Written out rather
    // than built from a constant because check-spa-routes.mjs reads these
    // literals as text and compares this one against the vhost's own regex —
    // the two have to match, and nothing else can tell you when they stop.
    { path: '/:handle([a-z2-9]{12})', children: spaceRoutes },
    // On memex.tools nginx serves `/` as the landing page, so only a push from
    // inside the app arrives here; on memex-local it is the first address an
    // owner opens. Never rendered: the guard sends it to sign-in or to the
    // reader's list, once it knows which. A redirect cannot wait for that, and
    // with no handle to inherit on a fresh load it threw and left a blank page.
    { path: '/', name: 'home', component: () => import('@/views/NotFoundView.vue') },
    // Nameless on purpose: the guard fills a missing handle by route NAME, and
    // a name here would make it try to fill one this path has nowhere to put —
    // a redirect to itself, forever. Unreachable by typing (nginx answers an
    // unknown path with its own 404), so this catches a push from inside the
    // app to a path that is not a page.
    { path: '/:pathMatch(.*)*', component: () => import('@/views/NotFoundView.vue') },
  ],
})

/** The named routes that live inside a knowledge base, read off the table
 *  rather than listed again: a route added to `spaceRoutes` is covered by the
 *  patch below without anybody remembering to come back here. */
const inSpace = new Set(
  spaceRoutes.map((r) => r.name).filter((n): n is string => typeof n === 'string')
)

// Every link into the app carries the knowledge base, not just the ones
// somebody navigates to. Filled here rather than at the ~25 call sites for the
// reason the codebase gives elsewhere for single producers: a link added later
// cannot forget what it never had to remember. This is the ONLY place a missing
// handle is supplied — the navigation guard below has no branch for one,
// because resolution throws before it could run.
//
// PATCHED IN THREE PLACES, because vue-router has three doors and they do not
// share one. RouterLink builds its href through `router.resolve`, so that alone
// covers every rendered link — but `push` and `replace` resolve INTERNALLY and
// never call the property, so patching `resolve` by itself left them throwing
// "missing required param: handle" from any page without a handle to inherit.
const withHandle = <T,>(to: T): T => {
  if (to === null || typeof to !== 'object' || !('name' in to)) return to
  const named = to as { name?: unknown; params?: Record<string, unknown> }
  if (typeof named.name !== 'string' || !inSpace.has(named.name)) return to
  const params = named.params ?? {}
  if (params.handle) return to
  // Before pinia is installed — a resolve during app setup — there is no store
  // to ask, and the guard rewrites the URL on arrival anyway.
  let handle: string | undefined
  try {
    handle = useAuthStore().user?.team.handle ?? undefined
  } catch {
    handle = undefined
  }
  if (!handle) return to
  // Object.assign rather than a spread: `check-spa-routes.mjs` refuses any
  // spread in this file, because one in the routes array would be a route whose
  // URL it cannot see.
  return Object.assign({}, to, { params: Object.assign({}, params, { handle }) })
}

const resolve = router.resolve.bind(router)
router.resolve = ((to: Parameters<typeof resolve>[0], current?: Parameters<typeof resolve>[1]) =>
  resolve(withHandle(to), current)) as typeof router.resolve

const push = router.push.bind(router)
router.push = ((to: Parameters<typeof push>[0]) => push(withHandle(to))) as typeof router.push

const replace = router.replace.bind(router)
router.replace = ((to: Parameters<typeof replace>[0]) => replace(withHandle(to))) as typeof router.replace

// Keeps unauthenticated users off app pages without a flash of protected UI;
// the API client additionally redirects to /login on any 401.
router.beforeEach(async (to) => {
  if (to.meta.public === true) return true
  const auth = useAuthStore()
  if (!auth.checked) {
    await auth.check()
  }
  // Signed out, whatever was asked for. The destination is deliberately not
  // remembered: sign-in lands on the reader's own list, because a link that
  // sent them here may well name a knowledge base that is not theirs, and
  // returning them to it would only bounce again.
  if (auth.user === null) return auth.suspended ? { name: 'login', query: { error: 'suspended' } } : { name: 'login' }

  const handle = auth.user.team.handle
  if (to.name === 'home') return { name: 'search', params: { handle } }
  const asked = typeof to.params.handle === 'string' ? to.params.handle : ''

  // A knowledge base that is not this reader's. They go to their own list,
  // because there is exactly one they can be in and the page they asked for
  // cannot be answered: the same note number means a different note in every
  // account, so serving theirs at that number is the silent wrong answer this
  // whole address shape exists to prevent. Compared against their OWN handle
  // and nothing else — no request is made about the one in the URL, so this
  // says only "not yours" and cannot tell anybody which handles exist.
  if (asked !== '' && asked !== handle) return { name: 'search', params: { handle } }

  // No branch for a MISSING handle: there is nowhere one can arrive from. A
  // named push has it filled in by `withHandle` before resolution, and
  // resolution throws without it rather than reaching here; a typed URL cannot
  // omit it, because nginx answers a handle-less path with its own 404. The
  // guard that used to sit here read as a safety net and was dead code.
  return true
})

// A deploy deletes the previous build's files, so a tab opened before it asks
// for pages that are no longer on the box: Edit, Activity and Skills stopped
// opening in an open tab until it was reloaded (2026-09-28). Vite reports each file
// that failed to load; the navigation that hit one is finished as a full load
// of the same address, which fetches the new build. Once per address, so a file
// missing from the new build too fails as it did instead of reloading forever.
const staleFiles = new WeakSet<object>()
window.addEventListener('vite:preloadError', (event) => {
  if (event.payload !== null && typeof event.payload === 'object') staleFiles.add(event.payload)
})

const RELOADED_FOR = 'memex:reloaded-for'

router.onError((error, to) => {
  if (error === null || typeof error !== 'object' || !staleFiles.has(error)) return
  try {
    if (sessionStorage.getItem(RELOADED_FOR) === to.fullPath) return
    sessionStorage.setItem(RELOADED_FOR, to.fullPath)
  } catch {
    return
  }
  window.location.assign(router.resolve(to.fullPath).href)
})

router.afterEach(() => {
  try {
    sessionStorage.removeItem(RELOADED_FOR)
  } catch {
    // Nothing was recorded, so there is nothing to clear.
  }
})

export default router
