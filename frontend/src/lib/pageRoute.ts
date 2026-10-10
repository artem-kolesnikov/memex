import { inject, provide, reactive, type ComputedRef, type InjectionKey } from 'vue'
import { useRoute, type RouteLocationNormalizedLoaded } from 'vue-router'

/**
 * The route a page reads, which is not always the route the router is on.
 *
 * Settings, the Activity log and the welcome wizard are dialogs over the page
 * that was showing, and that page stays mounted under them. Read through
 * `useRoute()` it would see the dialog's address — no query, another name —
 * and answer it: a search cleared, a note closed, the reading frame emptied. The shell provides the
 * page's own route instead, held as it was while a dialog is up.
 */
/** The routes drawn as a dialog over the page that was showing. */
export const DIALOG_ROUTES = ['settings', 'welcome', 'activity']

const PAGE_ROUTE: InjectionKey<ComputedRef<RouteLocationNormalizedLoaded>> = Symbol('page-route')

export function providePageRoute(route: ComputedRef<RouteLocationNormalizedLoaded>): void {
  provide(PAGE_ROUTE, route)
}

export function usePageRoute(): RouteLocationNormalizedLoaded {
  const route = useRoute()
  const page = inject(PAGE_ROUTE, null)
  if (page === null) return route
  return reactive({
    get name() { return page.value.name },
    get params() { return page.value.params },
    get query() { return page.value.query },
    get hash() { return page.value.hash },
    get path() { return page.value.path },
    get fullPath() { return page.value.fullPath },
    get matched() { return page.value.matched },
    get meta() { return page.value.meta },
    get redirectedFrom() { return page.value.redirectedFrom },
  }) as RouteLocationNormalizedLoaded
}
