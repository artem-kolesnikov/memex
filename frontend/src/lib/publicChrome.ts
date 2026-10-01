import { onMounted, onUnmounted } from 'vue'

/**
 * Hold the DOCUMENT light for as long as a signed-out page is on screen.
 *
 * `.mm-public` pins everything drawn in CSS, but two things are painted by the
 * browser from `html`'s own `color-scheme` and cannot be reached from a
 * descendant: the scrollbar, and the overscroll gutter. On a dark-themed
 * browser both stayed black down the edge of an eggshell page.
 *
 * Restored on unmount rather than left set, or signing in would land on the
 * app with its scrollbar the wrong colour until the next full load.
 */
export function usePublicChrome(): void {
  let previous: string | null = null

  onMounted(() => {
    previous = document.documentElement.style.colorScheme || null
    document.documentElement.style.colorScheme = 'light'
  })

  onUnmounted(() => {
    if (previous === null) {
      document.documentElement.style.removeProperty('color-scheme')
    } else {
      document.documentElement.style.colorScheme = previous
    }
  })
}
