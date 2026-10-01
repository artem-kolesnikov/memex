import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

export type Theme = 'light' | 'dark'

/**
 * The resolved app theme as a ref, kept current while the component lives.
 * Writing goes through `theme-boot.js`, which repaints the document and
 * stores the choice per browser; this only reads it back.
 */
export function useTheme(): { theme: Ref<Theme>; set: (value: Theme) => void } {
  const theme = ref<Theme>(window.getResolvedTheme?.() ?? 'light')
  const reload = () => {
    theme.value = window.getResolvedTheme?.() ?? 'light'
  }
  const media = window.matchMedia('(prefers-color-scheme: dark)')
  const observer = new MutationObserver(reload)

  onMounted(() => {
    media.addEventListener('change', reload)
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] })
  })
  onBeforeUnmount(() => {
    media.removeEventListener('change', reload)
    observer.disconnect()
  })

  function set(value: Theme) {
    window.setPreferredTheme?.(value)
    reload()
  }

  return { theme, set }
}
