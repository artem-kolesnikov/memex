/**
 * How long a note's description should be.
 *
 * ## Why there is a number at all
 *
 * A summary exists so that somebody can tell what a note is about in five or
 * ten seconds (operator, 2026-08-23). Nothing enforced that, and nothing even
 * suggested it: the MCP `summary` parameter accepts 5,000 characters, the
 * editor field is a bare textarea, and assistants write to the space they are
 * given. The result is descriptions of one paragraph of about
 * four thousand characters, which is an abstract of the note rather than a
 * hint about it, and which tells a reader scanning a list nothing at all.
 *
 * ## Soft, and what that means here
 *
 * 350 characters, two or three sentences. It is a target, not a validator:
 *
 * - the MCP tool descriptions state it, so an assistant writes to it — this is
 *   the one that actually changes what gets stored, because assistants are
 *   what write most descriptions;
 * - the editor shows a count that goes amber past it;
 * - the note page clamps a longer one to a few lines behind "Show more".
 *
 * Nothing REFUSES a longer summary. A hard limit here would reject an import,
 * truncate somebody's sentence mid-word, or fail a write for a reason that has
 * nothing to do with correctness. {@link SUMMARY_HARD_CAP} stays where it was,
 * as the point at which a "summary" is being used as storage.
 */
import { i18n } from '@/i18n'

export const SUMMARY_SOFT_CAP = 350

/** The server's actual refusal, mirrored here so the editor can say so. */
export const SUMMARY_HARD_CAP = 5000

/** One sentence for humans, reused by the editor and by the MCP descriptions. */
export function summaryGuidance(): string {
  return i18n.global.t('editor.summary_guidance', { n: SUMMARY_SOFT_CAP })
}
