import { createI18n } from 'vue-i18n'
import en from '@/locales/en.json'
import { sessionEpoch } from '@/lib/operationLifetime'

export const BUNDLED = 'en'
const STORE_KEY = 'mm-locale'
const CODE = /^[a-z]{2,3}(-[a-z]{2,4})?$/
const FETCH_TIMEOUT_MS = 5000
let localeRequest = 0

// Zero, one, few (2-4), many — the four forms a Russian noun takes after a
// number. vue-i18n's default rule knows only one and other.
function slavic(choice: number, choicesLength: number): number {
  if (choice === 0) return 0
  const teen = choice > 10 && choice < 20
  const endsWithOne = choice % 10 === 1
  if (choicesLength < 4) return !teen && endsWithOne ? 1 : 2
  if (!teen && endsWithOne) return 1
  if (!teen && choice % 10 >= 2 && choice % 10 <= 4) return 2
  return 3
}

export const i18n = createI18n({
  legacy: false,
  locale: BUNDLED as string,
  fallbackLocale: BUNDLED,
  messages: { en } as Record<string, typeof en>,
  missingWarn: false,
  fallbackWarn: false,
  pluralRules: { ru: slavic, uk: slavic, be: slavic },
})

export function currentLocale(): string {
  return i18n.global.locale.value
}

export function storedLocale(): string {
  try {
    const stored = localStorage.getItem(STORE_KEY)
    return stored !== null && CODE.test(stored) ? stored : BUNDLED
  } catch {
    return BUNDLED
  }
}

function remember(code: string) {
  try {
    if (code === BUNDLED) localStorage.removeItem(STORE_KEY)
    else localStorage.setItem(STORE_KEY, code)
  } catch {
    /* the choice still applies to this page */
  }
}

/**
 * Switch the interface to a language, fetching its file the first time.
 * False when the server has no such language, in which case nothing changes.
 */
export async function setLocale(code: string): Promise<boolean> {
  if (code !== BUNDLED && !CODE.test(code)) return false
  const request = ++localeRequest
  const session = sessionEpoch.value
  const current = () => request === localeRequest && session === sessionEpoch.value
  if (code !== BUNDLED && !i18n.global.availableLocales.includes(code)) {
    let messages: unknown
    try {
      const res = await fetch(`/api/locales/${code}`, {
        credentials: 'same-origin',
        signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
      })
      if (!res.ok) throw new Error(String(res.status))
      messages = await res.json()
    } catch {
      // A language this server cannot serve is not one to keep asking for on
      // every load; the account's choice, if any, is re-applied after sign-in.
      if (current()) remember(BUNDLED)
      return false
    }
    if (!current()) return false
    i18n.global.setLocaleMessage(code, messages as typeof en)
  }
  i18n.global.locale.value = code
  document.documentElement.lang = code
  remember(code)
  return true
}

/** A language's own name for itself — "Русский" for ru — or the code where the browser cannot say. */
export function languageName(code: string): string {
  try {
    const name = new Intl.DisplayNames([code], { type: 'language' }).of(code)
    return name ? name.charAt(0).toUpperCase() + name.slice(1) : code
  } catch {
    return code
  }
}

/** Every dotted key the bundled English carries, for measuring an installed file against it. */
export function bundledKeys(): string[] {
  const out: string[] = []
  const walk = (tree: Record<string, unknown>, prefix: string) => {
    for (const [key, value] of Object.entries(tree)) {
      const path = prefix ? `${prefix}.${key}` : key
      if (value && typeof value === 'object') walk(value as Record<string, unknown>, path)
      else out.push(path)
    }
  }
  walk(en as Record<string, unknown>, '')
  return out
}
