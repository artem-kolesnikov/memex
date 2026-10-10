import { nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import { useLayoutStore } from '@/stores/layout'

/** A frame is a reading region, not a dialog. Enter follows a row; F6 moves
 * between regions; closing restores the row. Modal and field keys stay local. */
export function useFrameNavigation(content: () => unknown, close: () => void) {
  const layout = useLayoutStore()
  let origin: HTMLElement | null = null
  const detail = () => document.getElementById('app-frame-detail')
  const selected = () => document.querySelector<HTMLElement>('.app-frame-list .is-open .app-note-main')
  const listTarget = () => selected() ?? (origin?.isConnected ? origin : document.querySelector<HTMLElement>('.app-frame-list .app-note-main'))
  const overlayOpen = () => Array.from(document.querySelectorAll<HTMLElement>('.modal.show, [role="dialog"], .p-overlay, .p-multiselect-panel'))
    .some((el) => el.getClientRects().length > 0)

  watch(content, async (value, previous) => {
    if (!layout.framed) return
    const active = document.activeElement as HTMLElement | null
    const wasInDetail = detail()?.contains(active)
    if (active?.closest('.app-frame-list .app-note-main')) origin = active
    await nextTick()
    // The frame scrolls as one, so what opens in it starts at its top.
    if (value != null && value !== previous) document.querySelector('.app-frame-column')?.scrollTo({ top: 0 })
    if (overlayOpen()) return
    if (value != null && active?.closest('.app-frame-list .app-note-main')) detail()?.focus({ preventScroll: true })
    else if (value == null && previous != null && (active === document.body || wasInDetail)) {
      listTarget()?.focus({ preventScroll: true })
    }
  }, { flush: 'pre' })

  function keydown(event: KeyboardEvent) {
    if (!layout.framed || content() == null || event.defaultPrevented || overlayOpen()) return
    const target = event.target instanceof HTMLElement ? event.target : null
    if (event.key === 'F6') {
      event.preventDefault()
      if (detail()?.contains(target)) listTarget()?.focus({ preventScroll: true })
      else detail()?.focus({ preventScroll: true })
    } else if (event.key === 'Escape' && !target?.closest('input, textarea, select, [contenteditable="true"], .cm-editor')) {
      event.preventDefault()
      close()
    }
  }
  onMounted(() => window.addEventListener('keydown', keydown))
  onBeforeUnmount(() => window.removeEventListener('keydown', keydown))
}
