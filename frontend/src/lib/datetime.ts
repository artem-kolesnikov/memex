/**
 * How dates are written, and in which zone.
 *
 * Device-local, like the rest of Preferences: stored in localStorage, never on
 * the account. A preference about how a screen reads belongs to the screen —
 * the same reason theme and text size live there — and it means no migration
 * and no round trip before the first date can be drawn.
 *
 * The refs are module-level and shared, so every component that formats a date
 * re-renders when the preference changes rather than showing two conventions on
 * one page until a reload.
 *
 * **EVERY date a person is shown goes through here** (operator, 2026-08-28).
 * The server stores and reports UTC and that is right; a timestamp rendered
 * straight from an ISO string is the bug this exists to remove, because it puts
 * one screen eight hours away from the next.
 *
 * The zone still defaults to the browser's, which already knows where the
 * reader is; the FORMAT defaults to what memex wrote before the preference
 * existed, so nobody's dates changed shape the day this shipped.
 */
import { ref } from 'vue'
import { currentLocale } from '@/i18n'

export const DATE_FORMATS = [
  { id: 'full', labelKey: 'general.date_formats.full' },
  { id: 'short', labelKey: 'general.date_formats.short' },
  { id: 'mdy', labelKey: 'general.date_formats.mdy' },
  { id: 'ymd', labelKey: 'general.date_formats.ymd' },
  { id: 'dmy', labelKey: 'general.date_formats.dmy' },
] as const

export type DateFormat = (typeof DATE_FORMATS)[number]['id']

const FORMAT_KEY = 'mm-date-format'
const ZONE_KEY = 'mm-time-zone'

function read(key: string, allowed: (v: string) => boolean, fallback: string): string {
  try {
    const stored = localStorage.getItem(key)
    return stored !== null && allowed(stored) ? stored : fallback
  } catch {
    // A browser with site data blocked throws on the accessor itself.
    return fallback
  }
}

const isFormat = (v: string) => DATE_FORMATS.some((f) => f.id === v)

/** What memex wrote before the preference existed: `Aug 12, 2026`. */
const DEFAULT_FORMAT: DateFormat = 'short'

/** Every zone the browser knows, or a short list where it cannot enumerate them. */
export function timeZones(): string[] {
  const supported = (Intl as { supportedValuesOf?: (k: string) => string[] }).supportedValuesOf
  if (typeof supported === 'function') {
    try {
      return supported('timeZone')
    } catch {
      /* falls through to the guess below */
    }
  }
  return [systemZone()]
}

export function systemZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
}

export const dateFormat = ref<DateFormat>(read(FORMAT_KEY, isFormat, DEFAULT_FORMAT) as DateFormat)
export const timeZone = ref<string>(read(ZONE_KEY, () => true, 'system'))

export function setDateFormat(value: DateFormat) {
  dateFormat.value = value
  try {
    localStorage.setItem(FORMAT_KEY, value)
  } catch {
    /* the preference simply does not persist */
  }
}

export function setTimeZone(value: string) {
  timeZone.value = value
  try {
    localStorage.setItem(ZONE_KEY, value)
  } catch {
    /* the preference simply does not persist */
  }
}

function zoneOption(): { timeZone?: string } {
  return timeZone.value === 'system' ? {} : { timeZone: timeZone.value }
}

const DATE_OPTIONS: Record<Exclude<DateFormat, 'ymd'>, Intl.DateTimeFormatOptions> = {
  full: { day: 'numeric', month: 'long', year: 'numeric' },
  short: { day: 'numeric', month: 'short', year: 'numeric' },
  mdy: { month: '2-digit', day: '2-digit', year: 'numeric' },
  dmy: { day: '2-digit', month: '2-digit', year: 'numeric' },
}

/** A date on its own. Empty string for null, so callers can render it directly. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''

  if (dateFormat.value === 'ymd') {
    // en-CA writes ISO order and is the one locale that does it reliably;
    // building the string by hand would ignore the chosen zone.
    return d.toLocaleDateString('en-CA', zoneOption())
  }
  // The numeric formats name their own order; the worded ones follow the interface language.
  const locale = dateFormat.value === 'mdy' ? 'en-US' : dateFormat.value === 'dmy' ? 'en-GB' : currentLocale()
  return d.toLocaleDateString(locale, { ...DATE_OPTIONS[dateFormat.value], ...zoneOption() })
}

/** A date with the time of day, for anything a reader needs to place within a day. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const time = d.toLocaleTimeString(currentLocale(), { hour: '2-digit', minute: '2-digit', ...zoneOption() })

  return `${formatDate(iso)}, ${time}`
}

/** The time of day alone. */
export function formatTime(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''

  return d.toLocaleTimeString(currentLocale(), { hour: '2-digit', minute: '2-digit', ...zoneOption() })
}
