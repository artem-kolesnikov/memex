import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

type Phase = 'opening' | 'shown' | 'hiding' | 'hidden'

/**
 * A Bootstrap modal that cannot outlive its component.
 *
 * `dispose()` nulls every field of the instance, and both of Bootstrap's fades
 * leave a callback queued that reads those fields afterwards — the show path
 * re-appends the element to <body> and the hide path sets `_element.style`.
 * Disposing mid-fade therefore either strands the dialog with its backdrop and
 * the page's scroll lock, or throws. So teardown waits for whichever fade is in
 * flight to finish; by then Bootstrap is done with the instance.
 */
export function useBootstrapModal(
  element: Ref<HTMLElement | null>,
  handlers: { onShown?: () => void; onHidden?: () => void } = {},
) {
  const shown = ref(false)
  let phase: Phase = 'opening'
  let active = true
  let hideRequested = false
  let modal: { show(): void; hide(): void; dispose?(): void } | null = null

  /**
   * Give the page back, unless some other dialog now holds it.
   *
   * Any other `.modal` in the document counts, not only one already carrying
   * `.show`: Bootstrap locks the body and raises the backdrop before it marks
   * the root shown, so a dialog opening right now would otherwise have its
   * backdrop swept out from under it.
   */
  function releasePage(el: HTMLElement | null) {
    const others = [...document.querySelectorAll('.modal')].filter((other) => other !== el)
    if (others.length > 0) return
    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove())
    document.body.classList.remove('modal-open')
    document.body.style.removeProperty('overflow')
    document.body.style.removeProperty('padding-right')
  }

  function teardown(el: HTMLElement | null) {
    modal?.dispose?.()
    modal = null
    // Bootstrap's show callback puts the element back on <body> when it finds
    // it detached, so what Vue removed has to be removed again afterwards.
    el?.remove()
    releasePage(el)
  }

  onMounted(() => {
    const el = element.value
    if (el === null) return
    modal = new window.bootstrap.Modal(el)
    el.addEventListener('shown.bs.modal', () => {
      phase = 'shown'
      if (!active) return
      shown.value = true
      if (hideRequested) {
        hideRequested = false
        modal?.hide()
      } else handlers.onShown?.()
    })
    el.addEventListener('hide.bs.modal', () => { phase = 'hiding' })
    el.addEventListener('hidden.bs.modal', () => {
      phase = 'hidden'
      if (active) handlers.onHidden?.()
    })
    modal.show()
  })

  onBeforeUnmount(() => {
    active = false
    hideRequested = false
    const el = element.value
    if (el === null || phase === 'shown' || phase === 'hidden') {
      teardown(el)
      return
    }

    const settled = phase === 'opening' ? 'shown.bs.modal' : 'hidden.bs.modal'
    el.addEventListener(settled, () => teardown(el), { once: true })

    // If that event never arrives the backdrop would sit over the page for
    // good. Sweeping is safe on its own; disposing here is not, because the
    // callback it would break is exactly the one still owed to us.
    setTimeout(() => releasePage(el), 600)
  })

  function hide() {
    if (!active) return
    // Bootstrap ignores hide() until its opening transition has completed.
    if (phase === 'opening') hideRequested = true
    else modal?.hide()
  }

  return { shown, hide }
}
