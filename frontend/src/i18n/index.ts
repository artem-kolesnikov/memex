import { createI18n } from 'vue-i18n'
import en from '@/locales/en.json'
import { sessionEpoch } from '@/lib/operationLifetime'

export const BUNDLED = 'en'

type Messages = typeof en

// Languages that ship with the SPA beside English, each a lazy chunk: the core's
// file and an edition's own words for it. Any other code is a file the server
// has installed, fetched from /api/locales.
const shippedCore = import.meta.glob<Messages>(['../locales/*.json', '!../locales/en.json'], { import: 'default' })
const shippedEdition = import.meta.glob<Messages>(['../edition/*/*.json', '!../edition/*/en.json'], { import: 'default' })
const codeOf = (path: string) => path.slice(path.lastIndexOf('/') + 1, -'.json'.length)

/** The languages every server offers, English aside: Russian. */
export const SHIPPED: string[] = Object.keys(shippedCore).map(codeOf).sort()

/** The core's words for a shipped language, then each edition's to merge over them. */
function shippedMessages(code: string): Promise<Messages[]> {
  const core = Object.entries(shippedCore).find(([path]) => codeOf(path) === code)
  if (!core) return Promise.reject(new Error(code))
  const editions = Object.entries(shippedEdition).filter(([path]) => codeOf(path) === code)
  return Promise.all([core[1](), ...editions.map(([, load]) => load())])
}

type Edits = (code: string) => Promise<Record<string, unknown> | null>
const editSources: Edits[] = []
const edited = new Map<string, Promise<void>>()

/** A server's own words for some of those the app ships, laid over them whenever a language loads. */
export function addEdits(source: Edits): void {
  editSources.push(source)
}

/** Lay every source's edits for a language over its words, once. */
export function loadEdits(code: string): Promise<void> {
  let loading = edited.get(code)
  if (!loading) {
    loading = Promise.all(editSources.map((source) => source(code).catch(() => null))).then((trees) => {
      for (const tree of trees) if (tree) i18n.global.mergeLocaleMessage(code, tree)
    })
    edited.set(code, loading)
  }
  return loading
}

const STORE_KEY = 'mm-locale'
export const LOCALE_CODE = /^[a-z]{2,3}(-[a-z]{2,4})?$/
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
  messages: { en } as Record<string, Messages>,
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
    return stored !== null && LOCALE_CODE.test(stored) ? stored : BUNDLED
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
  if (code !== BUNDLED && !LOCALE_CODE.test(code)) return false
  const request = ++localeRequest
  const session = sessionEpoch.value
  const current = () => request === localeRequest && session === sessionEpoch.value
  if (code !== BUNDLED && !i18n.global.availableLocales.includes(code)) {
    let messages: unknown[]
    try {
      if (SHIPPED.includes(code)) {
        messages = await shippedMessages(code)
      } else {
        const res = await fetch(`/api/locales/${code}`, {
          credentials: 'same-origin',
          signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
        })
        if (!res.ok) throw new Error(String(res.status))
        messages = [await res.json()]
      }
    } catch {
      // A language this server cannot serve is not one to keep asking for on
      // every load; the account's choice, if any, is re-applied after sign-in.
      if (current()) remember(BUNDLED)
      return false
    }
    if (!current()) return false
    const [first, ...more] = messages as [Messages, ...Messages[]]
    i18n.global.setLocaleMessage(code, first)
    for (const own of more) i18n.global.mergeLocaleMessage(code, own)
  }
  await Promise.all([loadEdits(BUNDLED), loadEdits(code)])
  if (!current()) return false
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
