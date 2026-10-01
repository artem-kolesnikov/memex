/**
 * The curation queue's defect keys in words.
 *
 * `App\Service\CurationQueue::PREDICATES` is the vocabulary; these are the two
 * shapes the interface reads it in — one about a single note, one about a
 * count of them. Held in one place because three screens carried their own
 * copy and had already drifted on `operator_flag`.
 */
import { i18n } from '@/i18n'

const ABOUT_A_NOTE: Record<string, string> = {
  operator_flag: 'activity.reasons.note.operator_flag',
  untagged: 'activity.reasons.note.untagged',
  no_summary: 'activity.reasons.note.no_summary',
  not_embedded: 'activity.reasons.note.not_embedded',
  disconnected: 'activity.reasons.note.disconnected',
  dangling_links: 'activity.reasons.note.dangling_links',
  nonstandard_tags: 'activity.reasons.note.nonstandard_tags',
  weak_title: 'activity.reasons.note.weak_title',
}

const ABOUT_A_COUNT: Record<string, string> = {
  operator_flag: 'activity.reasons.count.operator_flag',
  untagged: 'activity.reasons.count.untagged',
  no_summary: 'activity.reasons.count.no_summary',
  not_embedded: 'activity.reasons.count.not_embedded',
  disconnected: 'activity.reasons.count.disconnected',
  dangling_links: 'activity.reasons.count.dangling_links',
  nonstandard_tags: 'activity.reasons.count.nonstandard_tags',
  weak_title: 'activity.reasons.count.weak_title',
}

const words = (keys: Record<string, string>, reason: string): string => {
  const key = keys[reason]
  return key === undefined ? reason.replace(/_/g, ' ') : i18n.global.t(key)
}

/** What this defect means about one note. */
export const reasonWords = (reason: string): string => words(ABOUT_A_NOTE, reason)

/** What this defect means about a number of notes. */
export const reasonCountWords = (reason: string): string => words(ABOUT_A_COUNT, reason)
