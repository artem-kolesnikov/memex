import type { Router } from 'vue-router'

/**
 * Leave the Settings dialog, which is a ROUTE rather than a component with a
 * close button behind it.
 *
 * Back is the honest close: the box is one history entry, because moving
 * between panes replaces rather than pushes. A link from inside a pane can
 * still push another, so the trail is checked before walking it.
 */
export function closeSettings(router: Router): void {
  const back = window.history.state?.back
  // Resolved to a route NAME rather than matched as a path prefix. Settings
  // lives at `/<handle>/settings/...` since every page moved inside the
  // knowledge base, so a `/settings` prefix test is never true — and closing a
  // second pane walked back into the first one instead of leaving.
  //
  // The name must also BE one. A handle-less entry left in history by an older
  // build resolves to the catch-all and has no name, and walking back to it
  // lands on the not-found page — worse than the prefix test this replaced,
  // which sent it to the notes list (Codex, second round). Anything this
  // router cannot name is not somewhere to return to.
  const name = typeof back === 'string' ? router.resolve(back).name : undefined
  if (typeof name === 'string' && name !== 'settings' && name !== 'not-found') {
    router.back()
    return
  }
  router.replace({ name: 'search' })
}
